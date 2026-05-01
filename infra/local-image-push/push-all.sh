#!/usr/bin/env bash

set -euo pipefail

usage() {
    cat <<'USAGE'
Usage:
  ./infra/local-image-push/push-all.sh --release-version vX.Y.Z[-suffix] [options]

Builds and pushes King images from the local machine using docker buildx.
It runs in parallel and keeps logs per job under infra/local-image-push/logs/.

What it can push:
- GHCR PHP base images       (ghcr.io/<owner>/<repo>-php-base:phpX-main)
- GHCR runtime images        (ghcr.io/<owner>/<repo>:phpX, sha-...-phpX, [main/latest])
- GHCR demo images           (ghcr.io/<owner>/<repo>-demo:phpX, sha-...-phpX, [main/latest])
- GHCR video-chat images     (ghcr.io/<owner>/<repo>-videochat-{frontend,backend}:<release>,sha-...,[latest])
- Optional Docker Hub runtime image (intelligentintern/king:<release>,[latest])

Options:
  --release-version VALUE     Required. Example: v1.0.6-beta.1
  --registry VALUE            Container registry for GHCR-style images (default: ghcr.io)
  --owner VALUE               Image owner/namespace (default: derived from git remote)
  --repo VALUE                Image repo name (default: derived from git remote)
  --php-versions CSV          PHP matrix (default: 8.1,8.2,8.3,8.4,8.5)
  --platforms CSV             buildx target platforms (default: linux/amd64,linux/arm64)
  --parallel N                Max parallel jobs (default: 4)
  --builder NAME              buildx builder name (default: king-local-push)
  --log-dir DIR               Job logs dir (default: infra/local-image-push/logs)
  --iibin-source SPEC         Override IIBIN_SOURCE build arg for video-chat images

  --skip-base                 Skip base-image phase
  --skip-runtime              Skip runtime images
  --skip-demo                 Skip demo images
  --skip-videochat            Skip video-chat images
  --include-dockerhub-runtime Also push Docker Hub runtime image
  --dockerhub-image NAME      Docker Hub target image (default: intelligentintern/king)
  --no-latest-tags            Do not publish main/latest convenience tags

  -h, --help                  Show this help

Notes:
- Runtime/demo/video-chat-backend builds require release-package artifacts in:
  dist/docker-packages/php<version>/linux-{amd64,arm64}/*.tar.gz
- Log in first, for example:
  docker login ghcr.io
  docker login
USAGE
}

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT_DIR="$(cd "${SCRIPT_DIR}/../.." && pwd)"

RELEASE_VERSION="${RELEASE_VERSION:-}"
REGISTRY="${REGISTRY:-ghcr.io}"
IMAGE_OWNER="${IMAGE_OWNER:-}"
IMAGE_REPO="${IMAGE_REPO:-}"
PHP_VERSIONS_CSV="${PHP_VERSIONS:-8.1,8.2,8.3,8.4,8.5}"
PLATFORMS_CSV="${PLATFORMS:-linux/amd64,linux/arm64}"
PARALLELISM="${PARALLELISM:-4}"
BUILDER_NAME="${BUILDER_NAME:-king-local-push}"
LOG_DIR="${LOG_DIR:-${ROOT_DIR}/infra/local-image-push/logs}"
IIBIN_SOURCE="${IIBIN_SOURCE:-}"
DOCKERHUB_IMAGE="${DOCKERHUB_IMAGE:-intelligentintern/king}"
VIDEOCHAT_BACKEND_PHP_VERSION="${VIDEOCHAT_BACKEND_PHP_VERSION:-8.4}"

ENABLE_BASE=1
ENABLE_RUNTIME=1
ENABLE_DEMO=1
ENABLE_VIDEOCHAT=1
ENABLE_DOCKERHUB_RUNTIME=0
ENABLE_LATEST_TAGS=1

while [[ $# -gt 0 ]]; do
    case "$1" in
        --release-version)
            [[ $# -ge 2 ]] || { echo "Missing value for --release-version" >&2; exit 1; }
            RELEASE_VERSION="$2"
            shift 2
            ;;
        --registry)
            [[ $# -ge 2 ]] || { echo "Missing value for --registry" >&2; exit 1; }
            REGISTRY="$2"
            shift 2
            ;;
        --owner)
            [[ $# -ge 2 ]] || { echo "Missing value for --owner" >&2; exit 1; }
            IMAGE_OWNER="$2"
            shift 2
            ;;
        --repo)
            [[ $# -ge 2 ]] || { echo "Missing value for --repo" >&2; exit 1; }
            IMAGE_REPO="$2"
            shift 2
            ;;
        --php-versions)
            [[ $# -ge 2 ]] || { echo "Missing value for --php-versions" >&2; exit 1; }
            PHP_VERSIONS_CSV="$2"
            shift 2
            ;;
        --platforms)
            [[ $# -ge 2 ]] || { echo "Missing value for --platforms" >&2; exit 1; }
            PLATFORMS_CSV="$2"
            shift 2
            ;;
        --parallel)
            [[ $# -ge 2 ]] || { echo "Missing value for --parallel" >&2; exit 1; }
            PARALLELISM="$2"
            shift 2
            ;;
        --builder)
            [[ $# -ge 2 ]] || { echo "Missing value for --builder" >&2; exit 1; }
            BUILDER_NAME="$2"
            shift 2
            ;;
        --log-dir)
            [[ $# -ge 2 ]] || { echo "Missing value for --log-dir" >&2; exit 1; }
            LOG_DIR="$2"
            shift 2
            ;;
        --iibin-source)
            [[ $# -ge 2 ]] || { echo "Missing value for --iibin-source" >&2; exit 1; }
            IIBIN_SOURCE="$2"
            shift 2
            ;;
        --skip-base)
            ENABLE_BASE=0
            shift
            ;;
        --skip-runtime)
            ENABLE_RUNTIME=0
            shift
            ;;
        --skip-demo)
            ENABLE_DEMO=0
            shift
            ;;
        --skip-videochat)
            ENABLE_VIDEOCHAT=0
            shift
            ;;
        --include-dockerhub-runtime)
            ENABLE_DOCKERHUB_RUNTIME=1
            shift
            ;;
        --dockerhub-image)
            [[ $# -ge 2 ]] || { echo "Missing value for --dockerhub-image" >&2; exit 1; }
            DOCKERHUB_IMAGE="$2"
            shift 2
            ;;
        --no-latest-tags)
            ENABLE_LATEST_TAGS=0
            shift
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

if [[ -z "${RELEASE_VERSION}" ]]; then
    current_branch="$(git -C "${ROOT_DIR}" rev-parse --abbrev-ref HEAD 2>/dev/null || true)"
    if [[ "${current_branch}" == develop/v* ]]; then
        RELEASE_VERSION="${current_branch#develop/}"
        echo "[info] --release-version not provided; using ${RELEASE_VERSION} from branch ${current_branch}" >&2
    else
        echo "--release-version is required (or run from develop/v* branch)." >&2
        exit 1
    fi
fi

if ! [[ "${PARALLELISM}" =~ ^[0-9]+$ ]] || [[ "${PARALLELISM}" -lt 1 ]]; then
    echo "--parallel must be a positive integer." >&2
    exit 1
fi

if ! command -v docker >/dev/null 2>&1; then
    echo "docker is required." >&2
    exit 1
fi
if ! command -v git >/dev/null 2>&1; then
    echo "git is required." >&2
    exit 1
fi
if ! docker buildx version >/dev/null 2>&1; then
    echo "docker buildx is required." >&2
    exit 1
fi

derive_owner_repo_from_git() {
    local remote_url
    remote_url="$(git -C "${ROOT_DIR}" remote get-url origin 2>/dev/null || true)"
    if [[ -z "${remote_url}" ]]; then
        return 1
    fi
    if [[ "${remote_url}" =~ github\.com[:/]([^/]+)/([^/.]+)(\.git)?$ ]]; then
        IMAGE_OWNER="${BASH_REMATCH[1]}"
        IMAGE_REPO="${BASH_REMATCH[2]}"
        return 0
    fi
    return 1
}

if [[ -z "${IMAGE_OWNER}" || -z "${IMAGE_REPO}" ]]; then
    if ! derive_owner_repo_from_git; then
        echo "Could not derive owner/repo from git remote. Pass --owner and --repo." >&2
        exit 1
    fi
fi

OWNER_LC="$(printf '%s' "${IMAGE_OWNER}" | tr '[:upper:]' '[:lower:]')"
REPO_LC="$(printf '%s' "${IMAGE_REPO}" | tr '[:upper:]' '[:lower:]')"
REVISION="$(git -C "${ROOT_DIR}" rev-parse HEAD)"
SHORT_SHA="$(printf '%s' "${REVISION}" | cut -c1-12)"
CREATED_AT="$(date -u +%Y-%m-%dT%H:%M:%SZ)"

if [[ -z "${IIBIN_SOURCE}" ]]; then
    IIBIN_SOURCE="@intelligentintern/iibin@${RELEASE_VERSION#v}"
fi

IFS=',' read -r -a _raw_php_versions <<< "${PHP_VERSIONS_CSV}"
PHP_VERSIONS=()
for php in "${_raw_php_versions[@]}"; do
    php="${php//[[:space:]]/}"
    [[ -n "${php}" ]] || continue
    if ! [[ "${php}" =~ ^[0-9]+\.[0-9]+$ ]]; then
        echo "Invalid PHP version entry: ${php}" >&2
        exit 1
    fi
    PHP_VERSIONS+=("${php}")
done
if [[ ${#PHP_VERSIONS[@]} -eq 0 ]]; then
    echo "No PHP versions resolved from --php-versions." >&2
    exit 1
fi

platform_to_arch_label() {
    case "$1" in
        linux/amd64) echo "linux-amd64" ;;
        linux/arm64) echo "linux-arm64" ;;
        *) return 1 ;;
    esac
}

IFS=',' read -r -a _raw_platforms <<< "${PLATFORMS_CSV}"
PLATFORMS=()
declare -A REQUIRED_ARCHES=()
for platform in "${_raw_platforms[@]}"; do
    platform="${platform//[[:space:]]/}"
    [[ -n "${platform}" ]] || continue
    arch_label="$(platform_to_arch_label "${platform}" || true)"
    if [[ -z "${arch_label}" ]]; then
        echo "Unsupported platform: ${platform} (supported: linux/amd64,linux/arm64)" >&2
        exit 1
    fi
    PLATFORMS+=("${platform}")
    REQUIRED_ARCHES["${arch_label}"]=1
done
if [[ ${#PLATFORMS[@]} -eq 0 ]]; then
    echo "No platforms resolved from --platforms." >&2
    exit 1
fi
PLATFORMS_JOINED="$(IFS=,; printf '%s' "${PLATFORMS[*]}")"

mkdir -p "${LOG_DIR}"

require_package_artifact() {
    local php_version="$1"
    local arch_label="$2"
    local package_dir="${ROOT_DIR}/dist/docker-packages/php${php_version}/${arch_label}"
    local archive
    archive="$(find "${package_dir}" -maxdepth 1 -type f -name '*.tar.gz' 2>/dev/null | head -n 1 || true)"
    if [[ -z "${archive}" ]]; then
        echo "${package_dir}"
        return 1
    fi
    return 0
}

missing_artifacts=()
if [[ "${ENABLE_RUNTIME}" -eq 1 || "${ENABLE_DEMO}" -eq 1 ]]; then
    for php in "${PHP_VERSIONS[@]}"; do
        for arch_label in "${!REQUIRED_ARCHES[@]}"; do
            if ! dir="$(require_package_artifact "${php}" "${arch_label}" || true)"; then
                missing_artifacts+=("${dir}")
            fi
        done
    done
fi
if [[ "${ENABLE_VIDEOCHAT}" -eq 1 ]]; then
    for arch_label in "${!REQUIRED_ARCHES[@]}"; do
        if ! dir="$(require_package_artifact "${VIDEOCHAT_BACKEND_PHP_VERSION}" "${arch_label}" || true)"; then
            missing_artifacts+=("${dir}")
        fi
    done
fi

if [[ ${#missing_artifacts[@]} -gt 0 ]]; then
    echo "Missing required release-package artifacts:" >&2
    printf '  - %s\n' "${missing_artifacts[@]}" >&2
    echo "Prepare dist/docker-packages artifacts first, then run this script again." >&2
    exit 1
fi

if docker buildx inspect "${BUILDER_NAME}" >/dev/null 2>&1; then
    docker buildx use "${BUILDER_NAME}" >/dev/null
else
    docker buildx create --name "${BUILDER_NAME}" --driver docker-container --use >/dev/null
fi

docker buildx inspect --bootstrap >/dev/null

echo "[config] release-version=${RELEASE_VERSION}"
echo "[config] registry=${REGISTRY}"
echo "[config] owner/repo=${OWNER_LC}/${REPO_LC}"
echo "[config] php-versions=${PHP_VERSIONS_CSV}"
echo "[config] platforms=${PLATFORMS_JOINED}"
echo "[config] parallel=${PARALLELISM}"
echo "[config] logs=${LOG_DIR}"

declare -A RUNNING_NAME=()
declare -A RUNNING_LOG=()

kill_running_jobs() {
    for pid in "${!RUNNING_NAME[@]}"; do
        kill "${pid}" >/dev/null 2>&1 || true
    done
    wait || true
}

wait_one_job() {
    local finished_pid=""
    local status=0
    if wait -n -p finished_pid; then
        status=0
    else
        status=$?
    fi

    local name="${RUNNING_NAME[${finished_pid}]:-unknown}"
    local log_file="${RUNNING_LOG[${finished_pid}]:-}"
    unset 'RUNNING_NAME[${finished_pid}]'
    unset 'RUNNING_LOG[${finished_pid}]'

    if [[ "${status}" -eq 0 ]]; then
        echo "[ok] ${name}"
        return 0
    fi

    echo "[fail] ${name} (exit=${status})" >&2
    if [[ -n "${log_file}" && -f "${log_file}" ]]; then
        echo "----- ${name} log tail -----" >&2
        tail -n 120 "${log_file}" >&2 || true
        echo "----- end ${name} log tail -----" >&2
    fi
    return "${status}"
}

throttle_jobs() {
    while [[ "${#RUNNING_NAME[@]}" -ge "${PARALLELISM}" ]]; do
        if ! wait_one_job; then
            kill_running_jobs
            return 1
        fi
    done
}

wait_all_jobs() {
    while [[ "${#RUNNING_NAME[@]}" -gt 0 ]]; do
        if ! wait_one_job; then
            kill_running_jobs
            return 1
        fi
    done
}

start_job() {
    local name="$1"
    shift
    local log_file="${LOG_DIR}/${name}.log"
    echo "[start] ${name}"
    (
        set -euo pipefail
        "$@"
    ) >"${log_file}" 2>&1 &
    local pid=$!
    RUNNING_NAME["${pid}"]="${name}"
    RUNNING_LOG["${pid}"]="${log_file}"
}

should_publish_latest() {
    local enabled="$1"
    local release_version="$2"
    if [[ "${enabled}" -ne 1 ]]; then
        return 1
    fi
    if [[ "${release_version}" == *-* ]]; then
        return 1
    fi
    return 0
}

build_php_base() {
    local php_version="$1"
    local image_ref="${REGISTRY}/${OWNER_LC}/${REPO_LC}-php-base:php${php_version}-main"

    docker buildx build \
        --builder "${BUILDER_NAME}" \
        --platform "${PLATFORMS_JOINED}" \
        --push \
        --file "${ROOT_DIR}/infra/php-runtime-base.Dockerfile" \
        --tag "${image_ref}" \
        --label "org.opencontainers.image.title=king-php-base" \
        --label "org.opencontainers.image.description=King shared PHP runtime base image" \
        --label "org.opencontainers.image.created=${CREATED_AT}" \
        --label "org.opencontainers.image.revision=${REVISION}" \
        --label "org.opencontainers.image.source=https://github.com/${IMAGE_OWNER}/${IMAGE_REPO}" \
        --label "org.opencontainers.image.version=php${php_version}-main" \
        --build-arg "PHP_VERSION=${php_version}" \
        "${ROOT_DIR}"
}

build_runtime_image() {
    local php_version="$1"
    local image="${REGISTRY}/${OWNER_LC}/${REPO_LC}"
    local base_image="${REGISTRY}/${OWNER_LC}/${REPO_LC}-php-base:php${php_version}-main"
    local tags=(
        "--tag" "${image}:php${php_version}"
        "--tag" "${image}:sha-${SHORT_SHA}-php${php_version}"
    )
    if [[ "${php_version}" == "8.5" ]] && should_publish_latest "${ENABLE_LATEST_TAGS}" "${RELEASE_VERSION}"; then
        tags+=("--tag" "${image}:main" "--tag" "${image}:latest")
    fi

    docker buildx build \
        --builder "${BUILDER_NAME}" \
        --platform "${PLATFORMS_JOINED}" \
        --push \
        --file "${ROOT_DIR}/infra/php-runtime.Dockerfile" \
        "${tags[@]}" \
        --label "org.opencontainers.image.title=king" \
        --label "org.opencontainers.image.description=King PHP extension runtime image" \
        --label "org.opencontainers.image.created=${CREATED_AT}" \
        --label "org.opencontainers.image.revision=${REVISION}" \
        --label "org.opencontainers.image.source=https://github.com/${IMAGE_OWNER}/${IMAGE_REPO}" \
        --build-arg "PHP_VERSION=${php_version}" \
        --build-arg "PHP_BASE_IMAGE=${base_image}" \
        --build-arg "BUILD_DATE=${CREATED_AT}" \
        --build-arg "VCS_REF=${REVISION}" \
        "${ROOT_DIR}"
}

build_demo_image() {
    local php_version="$1"
    local image="${REGISTRY}/${OWNER_LC}/${REPO_LC}-demo"
    local base_image="${REGISTRY}/${OWNER_LC}/${REPO_LC}-php-base:php${php_version}-main"
    local tags=(
        "--tag" "${image}:php${php_version}"
        "--tag" "${image}:sha-${SHORT_SHA}-php${php_version}"
    )
    if [[ "${php_version}" == "8.5" ]] && should_publish_latest "${ENABLE_LATEST_TAGS}" "${RELEASE_VERSION}"; then
        tags+=("--tag" "${image}:main" "--tag" "${image}:latest")
    fi

    docker buildx build \
        --builder "${BUILDER_NAME}" \
        --platform "${PLATFORMS_JOINED}" \
        --push \
        --file "${ROOT_DIR}/infra/demo-server/Dockerfile" \
        "${tags[@]}" \
        --label "org.opencontainers.image.title=king-demo-server" \
        --label "org.opencontainers.image.description=King demo server runtime image" \
        --label "org.opencontainers.image.created=${CREATED_AT}" \
        --label "org.opencontainers.image.revision=${REVISION}" \
        --label "org.opencontainers.image.source=https://github.com/${IMAGE_OWNER}/${IMAGE_REPO}" \
        --build-arg "PHP_VERSION=${php_version}" \
        --build-arg "PHP_BASE_IMAGE=${base_image}" \
        --build-arg "BUILD_DATE=${CREATED_AT}" \
        --build-arg "VCS_REF=${REVISION}" \
        "${ROOT_DIR}"
}

build_videochat_frontend_image() {
    local image="${REGISTRY}/${OWNER_LC}/${REPO_LC}-videochat-frontend"
    local tags=(
        "--tag" "${image}:${RELEASE_VERSION}"
        "--tag" "${image}:sha-${SHORT_SHA}"
    )
    if should_publish_latest "${ENABLE_LATEST_TAGS}" "${RELEASE_VERSION}"; then
        tags+=("--tag" "${image}:latest")
    fi

    docker buildx build \
        --builder "${BUILDER_NAME}" \
        --platform "${PLATFORMS_JOINED}" \
        --push \
        --file "${ROOT_DIR}/demo/video-chat/frontend-vue/Dockerfile" \
        "${tags[@]}" \
        --label "org.opencontainers.image.title=king-video-chat-frontend" \
        --label "org.opencontainers.image.description=King video chat frontend image" \
        --label "org.opencontainers.image.created=${CREATED_AT}" \
        --label "org.opencontainers.image.revision=${REVISION}" \
        --label "org.opencontainers.image.source=https://github.com/${IMAGE_OWNER}/${IMAGE_REPO}" \
        --label "org.opencontainers.image.version=${RELEASE_VERSION}" \
        --build-arg "IIBIN_SOURCE=${IIBIN_SOURCE}" \
        --build-arg "VIDEOCHAT_BACKEND_PHP_VERSION=${VIDEOCHAT_BACKEND_PHP_VERSION}" \
        "${ROOT_DIR}"
}

build_videochat_backend_image() {
    local image="${REGISTRY}/${OWNER_LC}/${REPO_LC}-videochat-backend"
    local tags=(
        "--tag" "${image}:${RELEASE_VERSION}"
        "--tag" "${image}:sha-${SHORT_SHA}"
    )
    if should_publish_latest "${ENABLE_LATEST_TAGS}" "${RELEASE_VERSION}"; then
        tags+=("--tag" "${image}:latest")
    fi

    docker buildx build \
        --builder "${BUILDER_NAME}" \
        --platform "${PLATFORMS_JOINED}" \
        --push \
        --file "${ROOT_DIR}/demo/video-chat/backend-king-php/Dockerfile" \
        "${tags[@]}" \
        --label "org.opencontainers.image.title=king-video-chat-backend" \
        --label "org.opencontainers.image.description=King video chat backend image" \
        --label "org.opencontainers.image.created=${CREATED_AT}" \
        --label "org.opencontainers.image.revision=${REVISION}" \
        --label "org.opencontainers.image.source=https://github.com/${IMAGE_OWNER}/${IMAGE_REPO}" \
        --label "org.opencontainers.image.version=${RELEASE_VERSION}" \
        --build-arg "IIBIN_SOURCE=${IIBIN_SOURCE}" \
        --build-arg "VIDEOCHAT_BACKEND_PHP_VERSION=${VIDEOCHAT_BACKEND_PHP_VERSION}" \
        "${ROOT_DIR}"
}

build_dockerhub_runtime_image() {
    local php_version="8.5"
    local base_image="${REGISTRY}/${OWNER_LC}/${REPO_LC}-php-base:php${php_version}-main"
    local tags=("--tag" "${DOCKERHUB_IMAGE}:${RELEASE_VERSION}")
    if should_publish_latest "${ENABLE_LATEST_TAGS}" "${RELEASE_VERSION}"; then
        tags+=("--tag" "${DOCKERHUB_IMAGE}:latest")
    fi

    docker buildx build \
        --builder "${BUILDER_NAME}" \
        --platform "${PLATFORMS_JOINED}" \
        --push \
        --file "${ROOT_DIR}/infra/php-runtime.Dockerfile" \
        "${tags[@]}" \
        --label "org.opencontainers.image.title=king" \
        --label "org.opencontainers.image.description=King PHP extension runtime image" \
        --label "org.opencontainers.image.created=${CREATED_AT}" \
        --label "org.opencontainers.image.revision=${REVISION}" \
        --label "org.opencontainers.image.source=https://github.com/${IMAGE_OWNER}/${IMAGE_REPO}" \
        --build-arg "PHP_VERSION=${php_version}" \
        --build-arg "PHP_BASE_IMAGE=${base_image}" \
        --build-arg "BUILD_DATE=${CREATED_AT}" \
        --build-arg "VCS_REF=${REVISION}" \
        "${ROOT_DIR}"
}

run_phase_base() {
    if [[ "${ENABLE_BASE}" -ne 1 ]]; then
        return 0
    fi
    echo "== Phase 1/2: build + push PHP base images =="
    for php in "${PHP_VERSIONS[@]}"; do
        throttle_jobs
        start_job "base-php${php//./-}" build_php_base "${php}"
    done
    wait_all_jobs
}

run_phase_dependents() {
    echo "== Phase 2/2: build + push dependent images =="

    if [[ "${ENABLE_RUNTIME}" -eq 1 ]]; then
        for php in "${PHP_VERSIONS[@]}"; do
            throttle_jobs
            start_job "runtime-php${php//./-}" build_runtime_image "${php}"
        done
    fi

    if [[ "${ENABLE_DEMO}" -eq 1 ]]; then
        for php in "${PHP_VERSIONS[@]}"; do
            throttle_jobs
            start_job "demo-php${php//./-}" build_demo_image "${php}"
        done
    fi

    if [[ "${ENABLE_VIDEOCHAT}" -eq 1 ]]; then
        throttle_jobs
        start_job "videochat-frontend" build_videochat_frontend_image
        throttle_jobs
        start_job "videochat-backend" build_videochat_backend_image
    fi

    if [[ "${ENABLE_DOCKERHUB_RUNTIME}" -eq 1 ]]; then
        throttle_jobs
        start_job "dockerhub-runtime" build_dockerhub_runtime_image
    fi

    wait_all_jobs
}

run_phase_base
run_phase_dependents

echo "[done] all requested image builds completed successfully."
echo "[done] logs available in ${LOG_DIR}"
