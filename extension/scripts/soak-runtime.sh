#!/usr/bin/env bash

set -euo pipefail

usage() {
    cat <<'EOF'
Usage: ./scripts/soak-runtime.sh <asan|ubsan|leak> [--iterations N] [--artifacts-dir DIR]

Runs the long-duration sanitizer-oriented soak subset against a staged profile
artifact under:
  extension/build/profiles/<profile>/

Modes:
  asan   AddressSanitizer soak with leak detection disabled
  ubsan  UndefinedBehaviorSanitizer soak
  leak   Leak-oriented soak using the ASan profile with leak detection enabled

Options:
  --iterations N      Number of soak iterations to execute (default: 5)
  --artifacts-dir DIR Directory that receives summaries, per-iteration logs,
                      and retained failure diagnostics
EOF
}

if [[ "${1:-}" == "--help" || "${1:-}" == "-h" ]]; then
    usage
    exit 0
fi

MODE="${1:-}"
if [[ -z "${MODE}" ]]; then
    usage >&2
    exit 1
fi
shift

ITERATIONS=5
ARTIFACTS_DIR=""

while [[ "$#" -gt 0 ]]; do
    case "$1" in
        --iterations)
            ITERATIONS="${2:-}"
            shift 2
            ;;
        --artifacts-dir)
            ARTIFACTS_DIR="${2:-}"
            shift 2
            ;;
        --help|-h)
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

case "${MODE}" in
    asan|ubsan|leak)
        ;;
    *)
        echo "Unknown mode: ${MODE}" >&2
        usage >&2
        exit 1
        ;;
esac

if ! [[ "${ITERATIONS}" =~ ^[1-9][0-9]*$ ]]; then
    echo "--iterations must be a positive integer." >&2
    exit 1
fi

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
EXT_DIR="$(cd "${SCRIPT_DIR}/.." && pwd)"
CALLER_PWD="$(pwd)"
PHP_BIN="${PHP_BIN:-php}"

PROFILE="asan"
if [[ "${MODE}" == "ubsan" ]]; then
    PROFILE="ubsan"
fi

PROFILE_DIR="${EXT_DIR}/build/profiles/${PROFILE}"
EXT_SO="${PROFILE_DIR}/king.so"
QUICHE_LIB="${PROFILE_DIR}/libquiche.so"
QUICHE_SERVER="${PROFILE_DIR}/quiche-server"

if [[ -z "${ARTIFACTS_DIR}" ]]; then
    ARTIFACTS_DIR="${EXT_DIR}/build/soak/${MODE}"
elif [[ "${ARTIFACTS_DIR}" != /* ]]; then
    ARTIFACTS_DIR="${CALLER_PWD}/${ARTIFACTS_DIR}"
fi

LOG_DIR="${ARTIFACTS_DIR}/logs"
SUMMARY_FILE="${ARTIFACTS_DIR}/summary.txt"
TEST_LIST_FILE="${ARTIFACTS_DIR}/test-list.txt"

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

require_profile_artifact() {
    local path="$1"
    local message="$2"

    if [[ ! -e "${path}" ]]; then
        echo "${message}: ${path}" >&2
        echo "Run ./scripts/build-profile.sh ${PROFILE} first." >&2
        exit 1
    fi
}

cleanup_test_artifacts() {
    rm -f tests/*.diff tests/*.exp tests/*.log tests/*.out
}

copy_failure_artifacts() {
    local destination_dir="$1"
    local pattern=""
    local file=""

    mkdir -p "${destination_dir}"
    cp "${SUMMARY_FILE}" "${destination_dir}/summary.txt"
    cp "${TEST_LIST_FILE}" "${destination_dir}/test-list.txt"
    cp "${current_log}" "${destination_dir}/run-tests.log"

    if [[ -f "${EXT_DIR}/config.log" ]]; then
        cp "${EXT_DIR}/config.log" "${destination_dir}/config.log"
    fi

    cp "${EXT_SO}" "${destination_dir}/king.so"
    cp "${QUICHE_LIB}" "${destination_dir}/libquiche.so"
    cp "${QUICHE_SERVER}" "${destination_dir}/quiche-server"
    if [[ -n "${SANITIZER_RUNTIME_PATH}" ]]; then
        cp "${SANITIZER_RUNTIME_PATH}" "${destination_dir}/$(basename "${SANITIZER_RUNTIME_PATH}")"
    fi

    shopt -s nullglob
    for pattern in tests/*.diff tests/*.exp tests/*.log tests/*.out; do
        for file in ${pattern}; do
            cp "${file}" "${destination_dir}/$(basename "${file}")"
        done
    done
    shopt -u nullglob
}

TEST_FILES=(
    tests/120-object-store-stress-throughput.phpt
    tests/290-object-store-stress-edge-cases.phpt
    tests/291-proto-seeded-fuzz-stability.phpt
    tests/292-semantic-dns-seeded-churn.phpt
    tests/293-mcp-transfer-churn-and-boundary-validation.phpt
    tests/294-orchestrator-seeded-registry-churn.phpt
    tests/320-telemetry-failover-recovery-harness.phpt
    tests/335-http1-server-websocket-wire-soak.phpt
    tests/336-server-session-control-soak.phpt
    tests/337-http2-multi-fairness-backpressure.phpt
    tests/339-websocket-multi-connection-churn.phpt
    tests/352-orchestrator-file-worker-fairness-contention.phpt
)

require_profile_artifact "${EXT_SO}" "Missing staged extension for profile '${PROFILE}'"
require_profile_artifact "${QUICHE_LIB}" "Missing staged libquiche for profile '${PROFILE}'"
if [[ ! -x "${QUICHE_SERVER}" ]]; then
    echo "Missing staged quiche-server for profile '${PROFILE}': ${QUICHE_SERVER}" >&2
    exit 1
fi

for test_file in "${TEST_FILES[@]}"; do
    if [[ ! -f "${EXT_DIR}/${test_file}" ]]; then
        echo "Missing soak test file: ${test_file}" >&2
        exit 1
    fi
done

mkdir -p "${LOG_DIR}"
printf 'mode=%s\nprofile=%s\niterations=%s\nstarted_at=%s\n' \
    "${MODE}" \
    "${PROFILE}" \
    "${ITERATIONS}" \
    "$(date -u +"%Y-%m-%dT%H:%M:%SZ")" > "${SUMMARY_FILE}"
printf '%s\n' "${TEST_FILES[@]}" > "${TEST_LIST_FILE}"

export KING_QUICHE_LIBRARY="${QUICHE_LIB}"
export KING_QUICHE_SERVER="${QUICHE_SERVER}"
export LD_LIBRARY_PATH="${PROFILE_DIR}${LD_LIBRARY_PATH:+:${LD_LIBRARY_PATH}}"
export USE_ZEND_ALLOC=0

SANITIZER_RUNTIME_PATH=""
case "${MODE}" in
    asan)
        SANITIZER_RUNTIME_PATH="$(resolve_sanitizer_runtime libclang_rt.asan-x86_64.so)"
        export ASAN_OPTIONS="${ASAN_OPTIONS:-detect_leaks=0:abort_on_error=1:symbolize=1}"
        export LD_PRELOAD="${SANITIZER_RUNTIME_PATH}${LD_PRELOAD:+ ${LD_PRELOAD}}"
        ;;
    leak)
        SANITIZER_RUNTIME_PATH="$(resolve_sanitizer_runtime libclang_rt.asan-x86_64.so)"
        export ASAN_OPTIONS="${ASAN_OPTIONS:-detect_leaks=1:abort_on_error=1:symbolize=1}"
        export LD_PRELOAD="${SANITIZER_RUNTIME_PATH}${LD_PRELOAD:+ ${LD_PRELOAD}}"
        ;;
    ubsan)
        SANITIZER_RUNTIME_PATH="$(resolve_sanitizer_runtime libclang_rt.ubsan_standalone-x86_64.so)"
        export UBSAN_OPTIONS="${UBSAN_OPTIONS:-print_stacktrace=1:halt_on_error=1}"
        export LD_PRELOAD="${SANITIZER_RUNTIME_PATH}${LD_PRELOAD:+ ${LD_PRELOAD}}"
        ;;
esac

cd "${EXT_DIR}"

for ((iteration = 1; iteration <= ITERATIONS; iteration++)); do
    cleanup_test_artifacts
    current_log="${LOG_DIR}/iteration-${iteration}.log"

    if "${PHP_BIN}" run-tests.php -q -d "extension=${EXT_SO}" "${TEST_FILES[@]}" >"${current_log}" 2>&1; then
        printf 'iteration=%d status=pass\n' "${iteration}" >> "${SUMMARY_FILE}"
        continue
    fi

    printf 'iteration=%d status=fail\n' "${iteration}" >> "${SUMMARY_FILE}"
    copy_failure_artifacts "${ARTIFACTS_DIR}/failure-iteration-${iteration}"
    echo "Sanitizer soak failed: ${MODE} iteration ${iteration}" >&2
    tail -n 200 "${current_log}" >&2 || true
    exit 1
done

printf 'finished_at=%s\nresult=pass\n' "$(date -u +"%Y-%m-%dT%H:%M:%SZ")" >> "${SUMMARY_FILE}"
echo "sanitizer soak ok: ${MODE} (${ITERATIONS} iterations)"
