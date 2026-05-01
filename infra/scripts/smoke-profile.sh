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

resolve_clang_arch_suffix() {
    local machine=""

    machine="$(clang -dumpmachine 2>/dev/null || true)"
    case "${machine}" in
        x86_64*|amd64*)
            printf '%s\n' "x86_64"
            return 0
            ;;
        aarch64*|arm64*)
            printf '%s\n' "aarch64"
            return 0
            ;;
        armv7*|armv6*|arm*)
            printf '%s\n' "armhf"
            return 0
            ;;
        riscv64*)
            printf '%s\n' "riscv64"
            return 0
            ;;
    esac

    printf '%s\n' ""
}

resolve_sanitizer_runtime() {
    local kind="$1"
    local suffix=""
    local runtime_name=""
    local runtime_path=""
    local staged_runtime=""
    local -a candidates=()

    suffix="$(resolve_clang_arch_suffix)"

    case "${kind}" in
        asan)
            if [[ -n "${suffix}" ]]; then
                candidates+=("libclang_rt.asan-${suffix}.so")
            fi
            candidates+=("libclang_rt.asan.so")
            ;;
        ubsan)
            if [[ -n "${suffix}" ]]; then
                candidates+=("libclang_rt.ubsan_standalone-${suffix}.so")
            fi
            candidates+=("libclang_rt.ubsan_standalone.so")
            ;;
        *)
            return 1
            ;;
    esac

    for runtime_name in "${candidates[@]}"; do
        staged_runtime="${PROFILE_DIR}/${runtime_name}"
        if [[ -f "${staged_runtime}" ]]; then
            printf '%s\n' "${staged_runtime}"
            return 0
        fi

        runtime_path="$(clang -print-file-name="${runtime_name}" 2>/dev/null || true)"
        if [[ -n "${runtime_path}" && "${runtime_path}" != "${runtime_name}" && -f "${runtime_path}" ]]; then
            printf '%s\n' "${runtime_path}"
            return 0
        fi
    done

    return 1
}

if [[ ! -f "${EXT_SO}" ]]; then
    echo "Missing staged extension for profile '${PROFILE}': ${EXT_SO}" >&2
    echo "Run ./infra/scripts/build-profile.sh ${PROFILE} first." >&2
    exit 1
fi

case "${PROFILE}" in
    asan)
        asan_runtime="$(resolve_sanitizer_runtime asan)"
        export USE_ZEND_ALLOC=0
        export ASAN_OPTIONS="${ASAN_OPTIONS:-detect_leaks=0:abort_on_error=1:symbolize=1}"
        export LD_PRELOAD="${asan_runtime}${LD_PRELOAD:+ ${LD_PRELOAD}}"
        ;;
    ubsan)
        ubsan_runtime="$(resolve_sanitizer_runtime ubsan)"
        export USE_ZEND_ALLOC=0
        export UBSAN_OPTIONS="${UBSAN_OPTIONS:-print_stacktrace=1:halt_on_error=1}"
        export LD_PRELOAD="${ubsan_runtime}${LD_PRELOAD:+ ${LD_PRELOAD}}"
        ;;
esac

"${PHP_BIN}" \
    -d "extension=${EXT_SO}" \
    -d "king.security_allow_config_override=1" \
    "${SCRIPT_DIR}/runtime-install-smoke.php"
