#!/usr/bin/env bash

set -euo pipefail

usage() {
    cat <<'EOF'
Usage: ./infra/scripts/fuzz-runtime.sh [--suite NAME]...
       ./infra/scripts/fuzz-runtime.sh [run-tests.php args...]

Runs deterministic fuzz/stress PHPT subsets for release gates.

Suites:
  transport     Seeded transport/parser/admission fuzz subset
  object-store  Seeded object-store stress + negative-input subset
  mcp           Seeded MCP/transfer boundary subset

If no --suite is provided, all suites plus the orchestrator seeded churn case
run by default. If positional arguments are provided without --suite, they are
forwarded directly to run-tests.php for ad-hoc execution.
EOF
}

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT_DIR="$(cd "${SCRIPT_DIR}/../.." && pwd)"
EXT_DIR="${ROOT_DIR}/extension"
PHP_BIN="${PHP_BIN:-php}"
EXT_SO="${EXT_DIR}/modules/king.so"
declare -a REQUESTED_SUITES=()
declare -a PASSTHROUGH_ARGS=()
DEFAULT_SUITE_MODE=0

while [[ $# -gt 0 ]]; do
    case "$1" in
        --suite)
            if [[ $# -lt 2 || -z "${2:-}" ]]; then
                echo "Missing value for --suite." >&2
                exit 1
            fi
            REQUESTED_SUITES+=("$2")
            shift 2
            ;;
        -h|--help)
            usage
            exit 0
            ;;
        *)
            PASSTHROUGH_ARGS+=("$1")
            shift
            ;;
    esac
done

if [[ ! -f "${EXT_SO}" ]]; then
    echo "Missing extension binary: ${EXT_SO}" >&2
    echo "Run ./infra/scripts/build-extension.sh first." >&2
    exit 1
fi

cd "${EXT_DIR}"

if [[ "${#REQUESTED_SUITES[@]}" -eq 0 && "${#PASSTHROUGH_ARGS[@]}" -gt 0 ]]; then
    exec "${PHP_BIN}" run-tests.php -q -d "extension=${EXT_SO}" "${PASSTHROUGH_ARGS[@]}"
fi

if [[ "${#REQUESTED_SUITES[@]}" -gt 0 && "${#PASSTHROUGH_ARGS[@]}" -gt 0 ]]; then
    echo "Cannot combine --suite with direct run-tests.php arguments." >&2
    exit 1
fi

declare -a TRANSPORT_TEST_FILES=(
    tests/291-proto-seeded-fuzz-stability.phpt
    tests/292-semantic-dns-seeded-churn.phpt
    tests/650-transport-untrusted-input-url-matrix.phpt
)

declare -a OBJECT_STORE_TEST_FILES=(
    tests/120-object-store-stress-throughput.phpt
    tests/290-object-store-stress-edge-cases.phpt
    tests/651-object-store-negative-input-matrix-contract.phpt
)

declare -a MCP_TEST_FILES=(
    tests/293-mcp-transfer-churn-and-boundary-validation.phpt
    tests/396-mcp-request-miss-hardening.phpt
)

declare -a DEFAULT_EXTRA_TEST_FILES=(
    tests/294-orchestrator-seeded-registry-churn.phpt
)

declare -a SELECTED_TEST_FILES=()
declare -A SEEN_TEST_FILES=()

append_suite_tests() {
    local suite="$1"
    local test_file=""
    local -a suite_tests=()

    case "${suite}" in
        transport)
            suite_tests=("${TRANSPORT_TEST_FILES[@]}")
            ;;
        object-store)
            suite_tests=("${OBJECT_STORE_TEST_FILES[@]}")
            ;;
        mcp)
            suite_tests=("${MCP_TEST_FILES[@]}")
            ;;
        *)
            echo "Unknown fuzz suite: ${suite}" >&2
            exit 1
            ;;
    esac

    for test_file in "${suite_tests[@]}"; do
        if [[ -z "${SEEN_TEST_FILES["${test_file}"]+x}" ]]; then
            SELECTED_TEST_FILES+=("${test_file}")
            SEEN_TEST_FILES["${test_file}"]=1
        fi
    done
}

if [[ "${#REQUESTED_SUITES[@]}" -eq 0 ]]; then
    DEFAULT_SUITE_MODE=1
    REQUESTED_SUITES=(transport object-store mcp)
fi

for suite in "${REQUESTED_SUITES[@]}"; do
    append_suite_tests "${suite}"
done

if [[ "${DEFAULT_SUITE_MODE}" == "1" ]]; then
    for test_file in "${DEFAULT_EXTRA_TEST_FILES[@]}"; do
        if [[ -z "${SEEN_TEST_FILES["${test_file}"]+x}" ]]; then
            SELECTED_TEST_FILES+=("${test_file}")
            SEEN_TEST_FILES["${test_file}"]=1
        fi
    done
fi

for test_file in "${SELECTED_TEST_FILES[@]}"; do
    if [[ ! -f "${test_file}" ]]; then
        echo "Missing fuzz/stress test file: ${test_file}" >&2
        exit 1
    fi
done

echo "Running fuzz suites: ${REQUESTED_SUITES[*]}"
exec "${PHP_BIN}" run-tests.php -q -d "extension=${EXT_SO}" "${SELECTED_TEST_FILES[@]}"
