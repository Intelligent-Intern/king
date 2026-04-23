#!/usr/bin/env bash

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT_DIR="$(cd "${SCRIPT_DIR}/../.." && pwd)"
EXT_DIR="${ROOT_DIR}/extension"
PHP_BIN="${PHP_BIN:-php}"
EXT_SO="${EXT_DIR}/modules/king.so"
SHARD_TOTAL="${SHARD_TOTAL:-1}"
SHARD_INDEX="${SHARD_INDEX:-1}"
KING_PREBUILD_HTTP3_TEST_HELPERS="${KING_PREBUILD_HTTP3_TEST_HELPERS:-0}"
if [[ -z "${KING_TEST_PROFILE:-}" ]]; then
    if [[ "${CI:-}" == "true" || "${GITHUB_ACTIONS:-}" == "true" ]]; then
        KING_TEST_PROFILE="full"
    elif [[ -n "${SSH_CONNECTION:-}" || -n "${SSH_CLIENT:-}" || -n "${SSH_TTY:-}" ]]; then
        KING_TEST_PROFILE="full"
    else
        KING_TEST_PROFILE="local"
    fi
fi

if [[ ! -f "${EXT_SO}" ]]; then
    echo "Missing extension binary: ${EXT_SO}" >&2
    echo "Run ./infra/scripts/build-extension.sh first." >&2
    exit 1
fi

cd "${EXT_DIR}"

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

TEST_FILES=()
if declare -F mapfile >/dev/null 2>&1 || [[ "$(type -t mapfile 2>/dev/null || true)" == "builtin" ]]; then
    mapfile -t TEST_FILES < <(find tests -type f -name '*.phpt' | LC_ALL=C sort)
else
    while IFS= read -r test_file; do
        TEST_FILES+=("${test_file}")
    done < <(find tests -type f -name '*.phpt' | LC_ALL=C sort)
fi

if [[ "${#TEST_FILES[@]}" -eq 0 ]]; then
    echo "No PHPT files found under ${EXT_DIR}/tests." >&2
    exit 1
fi

if [[ "${KING_TEST_PROFILE}" == "local" ]]; then
    FILTERED_TEST_FILES=()
    SKIPPED_COUNT=0
    LOCAL_NETWORK_SKIP_PATTERN='(http1|http2|http3|websocket|mcp|remote|on-wire|wire|multi-host|failover|cloud|cloud_|s3|gcs|azure|distributed|admin-api|autoscaling-hetzner|semantic-dns-live|telemetry-otlp|tls|stale-peer|peer-rejoin|split-brain|reelection|mother-node|large-topology|seeded-churn)'

    for test_file in "${TEST_FILES[@]}"; do
        base_name="$(basename "${test_file}")"
        if [[ "${base_name}" =~ ${LOCAL_NETWORK_SKIP_PATTERN} ]]; then
            SKIPPED_COUNT=$((SKIPPED_COUNT + 1))
            continue
        fi
        FILTERED_TEST_FILES+=("${test_file}")
    done

    TEST_FILES=("${FILTERED_TEST_FILES[@]}")
    echo "Local PHPT profile enabled: skipped ${SKIPPED_COUNT} network/integration tests."
    echo "Set KING_TEST_PROFILE=full to run the full suite."
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
