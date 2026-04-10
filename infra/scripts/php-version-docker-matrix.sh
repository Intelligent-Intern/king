#!/usr/bin/env bash

set -euo pipefail

usage() {
    cat <<'EOF'
Usage: ./infra/scripts/php-version-docker-matrix.sh [--php-versions 8.1,8.2,...] [--build-jobs N] [--skip-container-smoke] [--skip-demo-network] [--artifact-dir DIR]

Builds a local Ubuntu 24.04 runner image for each requested PHP version, runs
the repo-local King build and PHPT suite inside that container, and then can
execute the runtime container smoke plus the real demo-server network probe for
the same PHP version.
EOF
}

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT_DIR="$(cd "${SCRIPT_DIR}/../.." && pwd)"
PHP_VERSIONS_CSV="${PHP_VERSIONS:-8.1,8.2,8.3,8.4,8.5}"
BUILD_JOBS="${BUILD_JOBS:-4}"
RUN_CONTAINER_SMOKE=1
RUN_DEMO_NETWORK=1
RUNNER_IMAGE_PREFIX="${RUNNER_IMAGE_PREFIX:-king-php-matrix-runner}"
ARTIFACT_ROOT="${ARTIFACT_ROOT:-${ROOT_DIR}/compat-artifacts/php-matrix}"
DIST_PACKAGE_ROOT="${DIST_PACKAGE_ROOT:-${ROOT_DIR}/dist/docker-packages}"
CARGO_REGISTRY_VOLUME="${CARGO_REGISTRY_VOLUME:-king-php-matrix-cargo-registry}"
CARGO_GIT_VOLUME="${CARGO_GIT_VOLUME:-king-php-matrix-cargo-git}"
ARTIFACT_CLEANUP_IMAGE="${ARTIFACT_CLEANUP_IMAGE:-ubuntu:24.04}"

run_with_retries() {
    local max_attempts="$1"
    local sleep_seconds="$2"
    local label="$3"
    shift 3

    local attempt=1
    while true; do
        if "$@"; then
            return 0
        fi

        if (( attempt >= max_attempts )); then
            echo "${label} failed after ${attempt} attempt(s)." >&2
            return 1
        fi

        docker builder prune -af >/dev/null 2>&1 || true
        docker image prune -f >/dev/null 2>&1 || true
        echo "${label} failed on attempt ${attempt}/${max_attempts}; retrying in ${sleep_seconds}s." >&2
        sleep "${sleep_seconds}"
        attempt=$((attempt + 1))
    done
}

clear_bind_mounted_directory() {
    local host_path="$1"

    mkdir -p "${host_path}"

    docker run --rm \
        -v "${host_path}:/artifacts" \
        "${ARTIFACT_CLEANUP_IMAGE}" \
        sh -lc 'find /artifacts -mindepth 1 -maxdepth 1 -exec rm -rf -- {} +'
}

while [[ $# -gt 0 ]]; do
    case "$1" in
        --php-versions)
            PHP_VERSIONS_CSV="$2"
            shift 2
            ;;
        --build-jobs)
            BUILD_JOBS="$2"
            shift 2
            ;;
        --skip-container-smoke)
            RUN_CONTAINER_SMOKE=0
            shift
            ;;
        --skip-demo-network)
            RUN_DEMO_NETWORK=0
            shift
            ;;
        --artifact-dir)
            ARTIFACT_ROOT="$2"
            shift 2
            ;;
        -h|--help)
            usage
            exit 0
            ;;
        *)
            echo "Unknown option: $1" >&2
            usage >&2
            exit 1
            ;;
    esac
done

if ! command -v docker >/dev/null 2>&1; then
    echo "Docker is required for the PHP version docker matrix." >&2
    exit 1
fi

IFS=',' read -r -a php_versions <<< "${PHP_VERSIONS_CSV}"
mkdir -p "${ARTIFACT_ROOT}"
docker volume create "${CARGO_REGISTRY_VOLUME}" >/dev/null
docker volume create "${CARGO_GIT_VOLUME}" >/dev/null

for php_version in "${php_versions[@]}"; do
    php_version="${php_version//[[:space:]]/}"
    if [[ -z "${php_version}" ]]; then
        continue
    fi

    runner_image="${RUNNER_IMAGE_PREFIX}:php${php_version//./-}"
    artifact_dir="${ARTIFACT_ROOT}/php${php_version//./-}"
    artifact_package_dir="${artifact_dir}/docker-packages/php${php_version}/linux-amd64"
    dist_package_dir="${DIST_PACKAGE_ROOT}/php${php_version}/linux-amd64"

    clear_bind_mounted_directory "${artifact_dir}"

    echo "Docker PHP matrix: PHP ${php_version}"
    docker build \
        --load \
        -f "${ROOT_DIR}/infra/php-matrix-runner.Dockerfile" \
        -t "${runner_image}" \
        --build-arg "PHP_VERSION=${php_version}" \
        "${ROOT_DIR}/infra"

    docker run --rm \
        -e BUILD_JOBS="${BUILD_JOBS}" \
        -e KING_HTTP3_TEST_URL_HOST=localhost \
        -e KING_HTTP3_TEST_BIND_HOST=127.0.0.1 \
        -e MATRIX_PHP_VERSION="${php_version}" \
        -v "${ROOT_DIR}:/src/king:ro" \
        -v "${artifact_dir}:/artifacts" \
        -v "${CARGO_REGISTRY_VOLUME}:/root/.cargo/registry" \
        -v "${CARGO_GIT_VOLUME}:/root/.cargo/git" \
        "${runner_image}" \
        bash -lc '
            set -euo pipefail
            mkdir -p /workspace /artifacts /tmp/docker-packages
            cp -a /src/king /workspace/king
            cd /workspace/king
            git config --global --add safe.directory "*"
            php --version
            node --version
            curl --version | head -n 2
            find extension/tests -maxdepth 1 \( -name "*.out" -o -name "*.diff" -o -name "*.exp" -o -name "*.log" \) -delete
            ./infra/scripts/build-extension.sh
            ./infra/scripts/check-stub-parity.sh
            if ! ./infra/scripts/test-extension.sh; then
                find extension/tests -maxdepth 1 \( -name "*.out" -o -name "*.diff" -o -name "*.exp" -o -name "*.log" \) -print0 \
                    | xargs -0 -r cp -t /artifacts
                exit 1
            fi
            package_output_dir="/tmp/docker-packages/php${MATRIX_PHP_VERSION}/linux-amd64"
            ./infra/scripts/package-release.sh --verify-reproducible --output-dir "${package_output_dir}"
            archive="$(find "${package_output_dir}" -maxdepth 1 -type f -name "*.tar.gz" | head -n 1)"
            test -n "${archive}"
            tar -tzf "${archive}" >/dev/null
            mkdir -p "/artifacts/docker-packages/php${MATRIX_PHP_VERSION}/linux-amd64"
            cp -a "${package_output_dir}/." "/artifacts/docker-packages/php${MATRIX_PHP_VERSION}/linux-amd64/"
        '

    if [[ ! -d "${artifact_package_dir}" ]]; then
        echo "Missing packaged release output for PHP ${php_version}: ${artifact_package_dir}" >&2
        exit 1
    fi

    rm -rf "${dist_package_dir}"
    mkdir -p "${dist_package_dir}"
    cp -a "${artifact_package_dir}/." "${dist_package_dir}/"

    if [[ "${RUN_CONTAINER_SMOKE}" == "1" ]]; then
        run_with_retries \
            2 \
            2 \
            "container smoke for PHP ${php_version}" \
            "${ROOT_DIR}/infra/scripts/container-smoke-matrix.sh" \
            --php-versions "${php_version}" \
            --artifact-root "${DIST_PACKAGE_ROOT}"
    fi

    if [[ "${RUN_DEMO_NETWORK}" == "1" ]]; then
        run_with_retries \
            3 \
            2 \
            "demo network matrix for PHP ${php_version}" \
            "${ROOT_DIR}/infra/scripts/demo-network-matrix.sh" \
            --php-versions "${php_version}" \
            --artifact-root "${DIST_PACKAGE_ROOT}"
    fi

    docker image rm -f "${runner_image}" >/dev/null 2>&1 || true
    docker builder prune -af >/dev/null 2>&1 || true
    docker image prune -f >/dev/null 2>&1 || true
done
