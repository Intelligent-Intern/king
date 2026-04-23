#!/usr/bin/env bash

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT_DIR="$(cd "${SCRIPT_DIR}/../.." && pwd)"
EXT_DIR="${ROOT_DIR}/extension"
PHP_BIN="${PHP_BIN:-php}"
EXT_SO="${EXT_DIR}/modules/king.so"
PROFILE_RUNTIME_DIR="${EXT_DIR}/build/profiles/release/runtime"
SHARD_TOTAL="${SHARD_TOTAL:-1}"
SHARD_INDEX="${SHARD_INDEX:-1}"
KING_PREBUILD_HTTP3_TEST_HELPERS="${KING_PREBUILD_HTTP3_TEST_HELPERS:-0}"

if [[ ! -f "${EXT_SO}" ]]; then
    echo "Missing extension binary: ${EXT_SO}" >&2
    echo "Run ./infra/scripts/build-extension.sh first." >&2
    exit 1
fi

cd "${EXT_DIR}"

if [[ -z "${KING_LSQUIC_LIBRARY:-}" && -f "${PROFILE_RUNTIME_DIR}/liblsquic.so" ]]; then
    export KING_LSQUIC_LIBRARY="${PROFILE_RUNTIME_DIR}/liblsquic.so"
fi

if [[ -n "${KING_LSQUIC_LIBRARY:-}" && -f "${KING_LSQUIC_LIBRARY}" ]]; then
    KING_LSQUIC_LIBRARY_DIR="$(cd "$(dirname "${KING_LSQUIC_LIBRARY}")" && pwd)"
    export LD_LIBRARY_PATH="${KING_LSQUIC_LIBRARY_DIR}${LD_LIBRARY_PATH:+:${LD_LIBRARY_PATH}}"
fi

if [[ "${KING_PREBUILD_HTTP3_TEST_HELPERS}" == "1" ]]; then
    "${SCRIPT_DIR}/prebuild-http3-test-helpers.sh"
fi

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
