#!/usr/bin/env bash

set -euo pipefail

usage() {
    cat <<'USAGE'
Usage: ./infra/scripts/local-lsquic-runtime-cache.sh restore|save [--github-output PATH]

Restores or saves the pinned LSQUIC runtime from a persistent local runner cache.
The cache key is derived from host architecture and infra/scripts/lsquic-bootstrap.lock.

Environment variables:
  KING_CI_LOCAL_LSQUIC_CACHE_DIR  Persistent cache root.
  KING_LSQUIC_RUNTIME_PREFIX      Runtime install prefix.
USAGE
}

if [[ "${1:-}" == "--help" || "${1:-}" == "-h" ]]; then
    usage
    exit 0
fi

MODE="${1:-}"
shift || true

GITHUB_OUTPUT_PATH=""
while [[ "$#" -gt 0 ]]; do
    case "$1" in
        --github-output)
            GITHUB_OUTPUT_PATH="${2:-}"
            shift 2
            ;;
        *)
            echo "Unknown argument: $1" >&2
            usage >&2
            exit 1
            ;;
    esac
done

case "${MODE}" in
    restore|save)
        ;;
    *)
        echo "Mode must be restore or save." >&2
        usage >&2
        exit 1
        ;;
esac

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT_DIR="$(cd "${SCRIPT_DIR}/../.." && pwd)"
LOCK_FILE="${SCRIPT_DIR}/lsquic-bootstrap.lock"
BUILD_SCRIPT="${SCRIPT_DIR}/build-lsquic-runtime.sh"
CACHE_ROOT="${KING_CI_LOCAL_LSQUIC_CACHE_DIR:-}"
PREFIX_DIR="${KING_LSQUIC_RUNTIME_PREFIX:-${ROOT_DIR}/.cache/king/lsquic/runtime/prefix}"

if [[ -z "${CACHE_ROOT}" ]]; then
    echo "KING_CI_LOCAL_LSQUIC_CACHE_DIR is required." >&2
    exit 1
fi

if [[ ! -f "${LOCK_FILE}" ]]; then
    echo "Missing LSQUIC bootstrap lock file: ${LOCK_FILE}" >&2
    exit 1
fi

if [[ ! -x "${BUILD_SCRIPT}" ]]; then
    echo "Missing executable LSQUIC build script: ${BUILD_SCRIPT}" >&2
    exit 1
fi

file_sha256() {
    if command -v sha256sum >/dev/null 2>&1; then
        sha256sum "$1" | awk '{print $1}'
        return
    fi

    if command -v shasum >/dev/null 2>&1; then
        shasum -a 256 "$1" | awk '{print $1}'
        return
    fi

    echo "sha256sum or shasum is required." >&2
    exit 1
}

host_os() {
    case "$(uname -s)" in
        Darwin)
            printf '%s\n' "darwin"
            ;;
        Linux)
            printf '%s\n' "linux"
            ;;
        CYGWIN*|MINGW*|MSYS*)
            printf '%s\n' "windows"
            ;;
        *)
            uname -s | tr '[:upper:]' '[:lower:]'
            ;;
    esac
}

runtime_arch() {
    local os=""

    os="$(host_os)"
    case "$(uname -m)" in
        x86_64|amd64)
            printf '%s\n' "${os}-amd64"
            ;;
        aarch64|arm64)
            printf '%s\n' "${os}-arm64"
            ;;
        *)
            printf '%s-%s\n' "${os}" "$(uname -m)"
            ;;
    esac
}

write_output() {
    local key="$1"
    local value="$2"

    if [[ -n "${GITHUB_OUTPUT_PATH}" ]]; then
        printf '%s=%s\n' "${key}" "${value}" >> "${GITHUB_OUTPUT_PATH}"
    fi
}

LOCK_SHA="$(file_sha256 "${LOCK_FILE}")"
CACHE_KEY="$(runtime_arch)-${LOCK_SHA}"
CACHE_DIR="${CACHE_ROOT%/}/${CACHE_KEY}"
CACHE_PREFIX="${CACHE_DIR}/prefix"

write_output "cache-key" "${CACHE_KEY}"

case "${MODE}" in
    restore)
        if [[ ! -d "${CACHE_PREFIX}" ]]; then
            echo "No local LSQUIC runtime cache entry: ${CACHE_DIR}"
            write_output "cache-hit" "false"
            exit 0
        fi

        rm -rf "${PREFIX_DIR}"
        mkdir -p "$(dirname "${PREFIX_DIR}")"
        cp -a "${CACHE_PREFIX}" "${PREFIX_DIR}"

        if KING_LSQUIC_RUNTIME_PREFIX="${PREFIX_DIR}" "${BUILD_SCRIPT}" --verify-current; then
            echo "Restored local LSQUIC runtime cache: ${CACHE_DIR}"
            write_output "cache-hit" "true"
            exit 0
        fi

        echo "Local LSQUIC runtime cache is stale: ${CACHE_DIR}" >&2
        rm -rf "${PREFIX_DIR}"
        write_output "cache-hit" "false"
        ;;
    save)
        KING_LSQUIC_RUNTIME_PREFIX="${PREFIX_DIR}" "${BUILD_SCRIPT}" --verify-current >/dev/null

        mkdir -p "${CACHE_ROOT}"
        TMP_DIR="${CACHE_DIR}.tmp.$$"
        rm -rf "${TMP_DIR}"
        mkdir -p "${TMP_DIR}"
        cp -a "${PREFIX_DIR}" "${TMP_DIR}/prefix"
        rm -rf "${CACHE_DIR}"
        mv "${TMP_DIR}" "${CACHE_DIR}"
        echo "Saved local LSQUIC runtime cache: ${CACHE_DIR}"
        write_output "cache-hit" "true"
        ;;
esac
