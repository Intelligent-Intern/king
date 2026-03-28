#!/usr/bin/env bash

set -euo pipefail

usage() {
    cat <<'EOF'
Usage: ./infra/scripts/container-smoke-matrix.sh [--php-versions 8.1,8.2,8.3,...] [--image-prefix NAME] [--artifact-root DIR]

Builds the runtime Docker image for the requested PHP versions and runs the
packaged install smoke inside each built container image using already prepared
release-package artifacts under dist/docker-packages/.
EOF
}

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT_DIR="$(cd "${SCRIPT_DIR}/../.." && pwd)"
EXT_DIR="${ROOT_DIR}/extension"
PHP_VERSIONS_CSV="${PHP_VERSIONS:-8.1,8.2,8.3,8.4,8.5}"
IMAGE_PREFIX="${IMAGE_PREFIX:-king-install-smoke}"
ARTIFACT_ROOT="${ARTIFACT_ROOT:-${ROOT_DIR}/dist/docker-packages}"

while [[ $# -gt 0 ]]; do
    case "$1" in
        --php-versions)
            if [[ $# -lt 2 ]]; then
                echo "Missing value for --php-versions." >&2
                exit 1
            fi
            PHP_VERSIONS_CSV="$2"
            shift 2
            ;;
        --image-prefix)
            if [[ $# -lt 2 ]]; then
                echo "Missing value for --image-prefix." >&2
                exit 1
            fi
            IMAGE_PREFIX="$2"
            shift 2
            ;;
        --artifact-root)
            if [[ $# -lt 2 ]]; then
                echo "Missing value for --artifact-root." >&2
                exit 1
            fi
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
    echo "Docker is required for container install smoke." >&2
    exit 1
fi

IFS=',' read -r -a php_versions <<< "${PHP_VERSIONS_CSV}"

for php_version in "${php_versions[@]}"; do
    php_version="${php_version//[[:space:]]/}"
    if [[ -z "${php_version}" ]]; then
        continue
    fi

    image_tag="${IMAGE_PREFIX}:php${php_version//./-}"
    artifact_dir="${ARTIFACT_ROOT}/php${php_version}/linux-amd64"

    if [[ ! -d "${artifact_dir}" ]]; then
        echo "Missing docker package artifacts for PHP ${php_version}: ${artifact_dir}" >&2
        exit 1
    fi

    echo "Container install smoke: PHP ${php_version}"
    docker build \
        --load \
        -f "${ROOT_DIR}/infra/php-runtime.Dockerfile" \
        -t "${image_tag}" \
        --build-arg "PHP_VERSION=${php_version}" \
        "${ROOT_DIR}"

    docker run --rm "${image_tag}" sh -lc 'PHP_BIN=php /opt/king/package/bin/smoke.sh'
    docker image rm -f "${image_tag}" >/dev/null 2>&1 || true
done
