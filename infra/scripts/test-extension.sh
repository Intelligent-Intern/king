#!/usr/bin/env bash

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT_DIR="$(cd "${SCRIPT_DIR}/../.." && pwd)"
EXT_DIR="${ROOT_DIR}/extension"
PHP_BIN="${PHP_BIN:-php}"
EXT_SO="${EXT_DIR}/modules/king.so"
QUICHE_LIB="${ROOT_DIR}/quiche/target/release/libquiche.so"
QUICHE_SERVER="${ROOT_DIR}/quiche/target/release/quiche-server"
SHARD_TOTAL="${SHARD_TOTAL:-1}"
SHARD_INDEX="${SHARD_INDEX:-1}"

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

"${SCRIPT_DIR}/prebuild-http3-test-helpers.sh"

if [[ "$#" -gt 0 ]]; then
    exec "${PHP_BIN}" run-tests.php -q -d "extension=${EXT_SO}" "$@"
fi

if ! [[ "${SHARD_TOTAL}" =~ ^[0-9]+$ ]] || ! [[ "${SHARD_INDEX}" =~ ^[0-9]+$ ]]; then
    echo "SHARD_TOTAL and SHARD_INDEX must be positive integers." >&2
    exit 1
fi

if (( SHARD_TOTAL < 1 )); then
    echo "SHARD_TOTAL must be >= 1." >&2
    exit 1
fi

if (( SHARD_INDEX < 1 || SHARD_INDEX > SHARD_TOTAL )); then
    echo "SHARD_INDEX must be within 1..SHARD_TOTAL." >&2
    exit 1
fi

mapfile -t TEST_FILES < <(find tests -type f -name '*.phpt' | LC_ALL=C sort)

if [[ "${#TEST_FILES[@]}" -eq 0 ]]; then
    echo "No PHPT files found under ${EXT_DIR}/tests." >&2
    exit 1
fi

if (( SHARD_TOTAL > 1 )); then
    SHARD_OFFSET=$((SHARD_INDEX - 1))
    SHARD_TEST_FILES=()

    for i in "${!TEST_FILES[@]}"; do
        if (( i % SHARD_TOTAL == SHARD_OFFSET )); then
            SHARD_TEST_FILES+=("${TEST_FILES[$i]}")
        fi
    done

    if [[ "${#SHARD_TEST_FILES[@]}" -eq 0 ]]; then
        echo "No PHPT files assigned to shard ${SHARD_INDEX}/${SHARD_TOTAL}; skipping." >&2
        exit 0
    fi

    echo "Running PHPT shard ${SHARD_INDEX}/${SHARD_TOTAL} with ${#SHARD_TEST_FILES[@]} tests."
    exec "${PHP_BIN}" run-tests.php -q -d "extension=${EXT_SO}" "${SHARD_TEST_FILES[@]}"
fi

exec "${PHP_BIN}" run-tests.php -q -d "extension=${EXT_SO}" "${TEST_FILES[@]}"
