#!/usr/bin/env bash

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT_DIR="$(cd "${SCRIPT_DIR}/../.." && pwd)"
EXT_DIR="${ROOT_DIR}/extension"
PHP_BIN="${PHP_BIN:-php}"
EXT_SO="${EXT_DIR}/modules/king.so"
QUICHE_LIB="${ROOT_DIR}/quiche/target/release/libquiche.so"
QUICHE_SERVER="${ROOT_DIR}/quiche/target/release/quiche-server"

if [[ ! -f "${EXT_SO}" ]]; then
    echo "Missing extension binary: ${EXT_SO}" >&2
    echo "Run ./infra/scripts/build-extension.sh first." >&2
    exit 1
fi

if [[ ! -f "${QUICHE_LIB}" ]]; then
    echo "Missing libquiche runtime: ${QUICHE_LIB}" >&2
    echo "Run ./infra/scripts/build-extension.sh first." >&2
    exit 1
fi

if [[ ! -x "${QUICHE_SERVER}" ]]; then
    echo "Missing quiche-server binary: ${QUICHE_SERVER}" >&2
    echo "Run ./infra/scripts/build-extension.sh first." >&2
    exit 1
fi

cd "${EXT_DIR}"

export KING_QUICHE_LIBRARY="${QUICHE_LIB}"
export KING_QUICHE_SERVER="${QUICHE_SERVER}"
export LD_LIBRARY_PATH="${EXT_DIR}/../quiche/target/release${LD_LIBRARY_PATH:+:${LD_LIBRARY_PATH}}"

if [[ "$#" -gt 0 ]]; then
    exec "${PHP_BIN}" run-tests.php -q -d "extension=${EXT_SO}" "$@"
fi

TEST_FILES=(
    tests/120-object-store-stress-throughput.phpt
    tests/290-object-store-stress-edge-cases.phpt
    tests/291-proto-seeded-fuzz-stability.phpt
    tests/292-semantic-dns-seeded-churn.phpt
    tests/293-mcp-transfer-churn-and-boundary-validation.phpt
    tests/294-orchestrator-seeded-registry-churn.phpt
)

for test_file in "${TEST_FILES[@]}"; do
    if [[ ! -f "${test_file}" ]]; then
        echo "Missing fuzz/stress test file: ${test_file}" >&2
        exit 1
    fi
done

exec "${PHP_BIN}" run-tests.php -q -d "extension=${EXT_SO}" "${TEST_FILES[@]}"
