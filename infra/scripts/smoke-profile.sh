#!/usr/bin/env bash

set -euo pipefail

usage() {
    cat <<'EOF'
Usage: ./infra/scripts/smoke-profile.sh <release|debug|asan|ubsan>

Runs a focused smoke test against the staged profile artifact under:
  extension/build/profiles/<profile>/
EOF
}

if [[ "${1:-}" == "--help" || "${1:-}" == "-h" ]]; then
    usage
    exit 0
fi

PROFILE="${1:-release}"

case "${PROFILE}" in
    release|debug|asan|ubsan)
        ;;
    *)
        echo "Unknown profile: ${PROFILE}" >&2
        usage >&2
        exit 1
        ;;
esac

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT_DIR="$(cd "${SCRIPT_DIR}/../.." && pwd)"
EXT_DIR="${ROOT_DIR}/extension"
PROFILE_DIR="${EXT_DIR}/build/profiles/${PROFILE}"
PHP_BIN="${PHP_BIN:-php}"
EXT_SO="${PROFILE_DIR}/king.so"
QUICHE_LIB="${PROFILE_DIR}/libquiche.so"
QUICHE_SERVER="${PROFILE_DIR}/quiche-server"

resolve_sanitizer_runtime() {
    local runtime_name="$1"
    local staged_runtime="${PROFILE_DIR}/${runtime_name}"
    local runtime_path=""

    if [[ -f "${staged_runtime}" ]]; then
        printf '%s\n' "${staged_runtime}"
        return 0
    fi

    runtime_path="$(clang -print-file-name="${runtime_name}")"
    if [[ -n "${runtime_path}" && "${runtime_path}" != "${runtime_name}" && -f "${runtime_path}" ]]; then
        printf '%s\n' "${runtime_path}"
        return 0
    fi

    return 1
}

if [[ ! -f "${EXT_SO}" ]]; then
    echo "Missing staged extension for profile '${PROFILE}': ${EXT_SO}" >&2
    echo "Run ./infra/scripts/build-profile.sh ${PROFILE} first." >&2
    exit 1
fi

if [[ ! -f "${QUICHE_LIB}" ]]; then
    echo "Missing staged libquiche for profile '${PROFILE}': ${QUICHE_LIB}" >&2
    exit 1
fi

if [[ ! -x "${QUICHE_SERVER}" ]]; then
    echo "Missing staged quiche-server for profile '${PROFILE}': ${QUICHE_SERVER}" >&2
    exit 1
fi

export KING_QUICHE_LIBRARY="${QUICHE_LIB}"
export KING_QUICHE_SERVER="${QUICHE_SERVER}"
export LD_LIBRARY_PATH="${PROFILE_DIR}${LD_LIBRARY_PATH:+:${LD_LIBRARY_PATH}}"

case "${PROFILE}" in
    asan)
        asan_runtime="$(resolve_sanitizer_runtime libclang_rt.asan-x86_64.so)"
        export USE_ZEND_ALLOC=0
        export ASAN_OPTIONS="${ASAN_OPTIONS:-detect_leaks=0:abort_on_error=1:symbolize=1}"
        export LD_PRELOAD="${asan_runtime}${LD_PRELOAD:+ ${LD_PRELOAD}}"
        ;;
    ubsan)
        ubsan_runtime="$(resolve_sanitizer_runtime libclang_rt.ubsan_standalone-x86_64.so)"
        export USE_ZEND_ALLOC=0
        export UBSAN_OPTIONS="${UBSAN_OPTIONS:-print_stacktrace=1:halt_on_error=1}"
        export LD_PRELOAD="${ubsan_runtime}${LD_PRELOAD:+ ${LD_PRELOAD}}"
        ;;
esac

"${PHP_BIN}" \
    -d "extension=${EXT_SO}" \
    -d "king.security_allow_config_override=1" \
    "${SCRIPT_DIR}/runtime-install-smoke.php"
