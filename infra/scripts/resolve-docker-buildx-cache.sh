#!/usr/bin/env bash

set -euo pipefail

usage() {
    cat <<'USAGE'
Usage: ./infra/scripts/resolve-docker-buildx-cache.sh --scope NAME --github-output PATH

Resolves Docker Buildx cache inputs for GitHub Actions. GitHub-hosted runners
use the GitHub Actions cache. Self-hosted runners can additionally use a
persistent local BuildKit cache when KING_CI_LOCAL_DOCKER_BUILDX_CACHE_DIR is a
writable directory.

Environment variables:
  KING_CI_LOCAL_DOCKER_BUILDX_CACHE_DIR  Persistent local Buildx cache root.
USAGE
}

SCOPE=""
GITHUB_OUTPUT_PATH=""

while [[ "$#" -gt 0 ]]; do
    case "$1" in
        --scope)
            SCOPE="${2:-}"
            shift 2
            ;;
        --github-output)
            GITHUB_OUTPUT_PATH="${2:-}"
            shift 2
            ;;
        --help|-h)
            usage
            exit 0
            ;;
        *)
            echo "Unknown argument: $1" >&2
            usage >&2
            exit 1
            ;;
    esac
done

if [[ -z "${SCOPE}" ]]; then
    echo "--scope is required." >&2
    exit 1
fi

if [[ -z "${GITHUB_OUTPUT_PATH}" ]]; then
    echo "--github-output is required." >&2
    exit 1
fi

if [[ ! "${SCOPE}" =~ ^[A-Za-z0-9._-]+$ ]]; then
    echo "--scope must contain only letters, numbers, dot, underscore, and dash." >&2
    exit 1
fi

if [[ "${SCOPE}" == "." || "${SCOPE}" == ".." ]]; then
    echo "--scope must not be a relative path segment." >&2
    exit 1
fi

write_output() {
    local key="$1"
    local value="$2"

    {
        printf '%s<<EOF\n' "${key}"
        printf '%s\n' "${value}"
        printf 'EOF\n'
    } >> "${GITHUB_OUTPUT_PATH}"
}

write_scalar_output() {
    local key="$1"
    local value="$2"

    printf '%s=%s\n' "${key}" "${value}" >> "${GITHUB_OUTPUT_PATH}"
}

cache_from="type=gha,scope=${SCOPE}"
cache_to="type=gha,mode=max,scope=${SCOPE},ignore-error=true"
local_root="${KING_CI_LOCAL_DOCKER_BUILDX_CACHE_DIR:-}"
local_root_real=""
use_local="false"
local_dir=""
local_next_dir=""

if [[ -n "${local_root}" && -d "${local_root}" && -w "${local_root}" ]]; then
    local_root_real="$(realpath -m "${local_root}")"
    if [[ "${local_root_real}" == "/" ]]; then
        echo "Refusing to use filesystem root as Docker Buildx local cache root." >&2
        exit 1
    fi

    local_dir="${local_root_real}/${SCOPE}"
    local_next_dir="${local_root_real}/${SCOPE}.next"

    case "${local_dir}" in
        "${local_root_real}/"*) ;;
        *)
            echo "Resolved local cache directory escaped the configured cache root." >&2
            exit 1
            ;;
    esac

    case "${local_next_dir}" in
        "${local_root_real}/"*) ;;
        *)
            echo "Resolved local cache next directory escaped the configured cache root." >&2
            exit 1
            ;;
    esac

    mkdir -p "${local_dir}"
    rm -rf -- "${local_next_dir}"
    mkdir -p "${local_next_dir}"

    cache_from=$(printf '%s\n%s' "type=local,src=${local_dir}" "${cache_from}")
    cache_to=$(printf '%s\n%s' "type=local,dest=${local_next_dir},mode=max" "${cache_to}")
    use_local="true"
fi

write_output "cache-from" "${cache_from}"
write_output "cache-to" "${cache_to}"
write_scalar_output "use-local-cache" "${use_local}"
write_scalar_output "local-cache-dir" "${local_dir}"
write_scalar_output "local-cache-next-dir" "${local_next_dir}"

if [[ "${use_local}" == "true" ]]; then
    echo "Using local Docker Buildx cache scope ${SCOPE}: ${local_dir}"
else
    echo "Using GitHub Docker Buildx cache scope ${SCOPE}"
fi
