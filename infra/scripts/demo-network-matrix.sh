#!/usr/bin/env bash

set -euo pipefail

usage() {
    cat <<'EOF'
Usage: ./infra/scripts/demo-network-matrix.sh [--php-versions 8.1,8.2,...] [--image-prefix NAME] [--artifact-root DIR]

Builds the King demo-server container for each requested PHP version and proves
real HTTP + WebSocket traffic across a docker network by running the packaged
probe against the live demo-server container.
EOF
}

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT_DIR="$(cd "${SCRIPT_DIR}/../.." && pwd)"
PHP_VERSIONS_CSV="${PHP_VERSIONS:-8.1,8.2,8.3,8.4,8.5}"
IMAGE_PREFIX="${IMAGE_PREFIX:-king-demo-server}"
ARTIFACT_ROOT="${ARTIFACT_ROOT:-${ROOT_DIR}/dist/docker-packages}"

while [[ $# -gt 0 ]]; do
    case "$1" in
        --php-versions)
            PHP_VERSIONS_CSV="$2"
            shift 2
            ;;
        --image-prefix)
            IMAGE_PREFIX="$2"
            shift 2
            ;;
        --artifact-root)
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
    echo "Docker is required for demo-network-matrix." >&2
    exit 1
fi

IFS=',' read -r -a php_versions <<< "${PHP_VERSIONS_CSV}"

for php_version in "${php_versions[@]}"; do
    php_version="${php_version//[[:space:]]/}"
    if [[ -z "${php_version}" ]]; then
        continue
    fi

    run_suffix="${$}-$(date +%s%N)"
    image_tag="${IMAGE_PREFIX}:php${php_version//./-}"
    server_name="king-demo-server-php${php_version//./-}-${run_suffix}"
    network_name="king-demo-net-php${php_version//./-}-${run_suffix}"
    artifact_dir="${ARTIFACT_ROOT}/php${php_version}/linux-amd64"

    if [[ ! -d "${artifact_dir}" ]]; then
        echo "Missing demo docker package artifacts for PHP ${php_version}: ${artifact_dir}" >&2
        exit 1
    fi

    echo "Demo network matrix: PHP ${php_version}"
    docker build \
        --load \
        -f "${ROOT_DIR}/infra/demo-server/Dockerfile" \
        -t "${image_tag}" \
        --build-arg "PHP_VERSION=${php_version}" \
        "${ROOT_DIR}"

    docker network create "${network_name}" >/dev/null
    cleanup() {
        docker rm -f "${server_name}" >/dev/null 2>&1 || true
        docker network rm "${network_name}" >/dev/null 2>&1 || true
    }
    trap cleanup EXIT

    docker run -d \
        --name "${server_name}" \
        --network "${network_name}" \
        "${image_tag}" >/dev/null

    ready=0
    for _ in $(seq 1 30); do
        if docker run --rm --network "${network_name}" "${image_tag}" \
            php -d king.security_allow_config_override=1 /opt/king/demo/probe.php \
            --health-only \
            --health-url "http://${server_name}:8080/health" >/dev/null 2>&1; then
            ready=1
            break
        fi
        sleep 1
    done

    if [[ "${ready}" != "1" ]]; then
        echo "Demo server did not become ready for PHP ${php_version}." >&2
        docker ps -a --filter "name=${server_name}" --format 'container={{.Names}} status={{.Status}}' >&2 || true
        docker logs "${server_name}" || true
        exit 1
    fi

    docker run --rm --network "${network_name}" "${image_tag}" \
        php -d king.security_allow_config_override=1 /opt/king/demo/probe.php \
        --base-url "http://${server_name}:8080" \
        --ws-url "ws://${server_name}:8080/ws"

    cleanup
    trap - EXIT
    docker image rm -f "${image_tag}" >/dev/null 2>&1 || true
done
