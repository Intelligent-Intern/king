#!/usr/bin/env bash

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT_DIR="$(cd "${SCRIPT_DIR}/../.." && pwd)"
EXT_DIR="${ROOT_DIR}/extension"
PHP_BIN="${PHP_BIN:-/usr/local/bin/php}"
EXT_SO="${EXT_DIR}/modules/king.so"
SHARD_TOTAL="${SHARD_TOTAL:-1}"
SHARD_INDEX="${SHARD_INDEX:-1}"
RUN_MOCK_TESTS="${KING_PHPT_RUN_MOCK_TESTS:-0}"
ONLY_MOCK_TESTS="${KING_PHPT_ONLY_MOCK_TESTS:-0}"
MOCK_MODE="${KING_PHPT_MOCK_MODE:-auto}"
LIVE_ACCESS_HINT="${KING_PHPT_LIVE_ACCESS:-auto}"

if [[ ! -f "${EXT_SO}" ]]; then
    echo "Missing extension binary: ${EXT_SO}" >&2
    echo "Run ./infra/scripts/build-extension.sh first." >&2
    exit 1
fi

cd "${EXT_DIR}"

"${SCRIPT_DIR}/prebuild-http3-test-helpers.sh"

if [[ "$#" -gt 0 ]]; then
    exec "${PHP_BIN}" -n run-tests.php -q "$@"
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
while IFS= read -r line; do
  TEST_FILES+=("$line")
done < <(find tests -type f -name '*.phpt' | LC_ALL=C sort)

if [[ "${#TEST_FILES[@]}" -eq 0 ]]; then
    echo "No PHPT files found under ${EXT_DIR}/tests." >&2
    exit 1
fi

MOCK_TEST_FILES=()
NON_MOCK_TEST_FILES=()
for test_file in "${TEST_FILES[@]}"; do
    if grep -Eqi '\\bmock\\b|mock_|autoscaling_hetzner_mock_helper\\.inc|object_store_s3_mock_helper\\.inc|mcp_test_helper\\.inc|telemetry_otlp_test_helper\\.inc|multi_host_test_helper\\.inc|cdn_origin_http_test_helper\\.inc|semantic_dns_live_probe_helper\\.inc' "${test_file}"; then
        MOCK_TEST_FILES+=("${test_file}")
    else
        NON_MOCK_TEST_FILES+=("${test_file}")
    fi
done

is_truthy() {
    local value="${1:-}"
    case "${value}" in
        1|true|TRUE|yes|YES|on|ON) return 0 ;;
        *) return 1 ;;
    esac
}

detect_ci_environment() {
    if is_truthy "${CI:-}" \
        || is_truthy "${GITHUB_ACTIONS:-}" \
        || is_truthy "${GITLAB_CI:-}" \
        || is_truthy "${BUILDKITE:-}"; then
        return 0
    fi

    return 1
}

detect_live_credentials() {
    local credential_vars=(
        HCLOUD_TOKEN
        HETZNER_API_TOKEN
        KING_HETZNER_API_TOKEN
        AWS_ACCESS_KEY_ID
        AWS_SECRET_ACCESS_KEY
        AWS_SESSION_TOKEN
        AZURE_STORAGE_CONNECTION_STRING
        AZURE_STORAGE_ACCOUNT
        GOOGLE_APPLICATION_CREDENTIALS
        GCP_SERVICE_ACCOUNT_JSON
        GOOGLE_CLOUD_PROJECT
    )
    local name
    for name in "${credential_vars[@]}"; do
        if [[ -n "${!name:-}" ]]; then
            return 0
        fi
    done

    return 1
}

CI_ENVIRONMENT=0
if detect_ci_environment; then
    CI_ENVIRONMENT=1
fi

LIVE_CREDENTIALS_PRESENT=0
if detect_live_credentials; then
    LIVE_CREDENTIALS_PRESENT=1
fi

LIVE_ACCESS_AVAILABLE=0
if [[ "${LIVE_ACCESS_HINT}" == "1" ]] || is_truthy "${LIVE_ACCESS_HINT}"; then
    LIVE_ACCESS_AVAILABLE=1
elif [[ "${LIVE_ACCESS_HINT}" == "0" ]] \
    || [[ "${LIVE_ACCESS_HINT}" == "false" ]] \
    || [[ "${LIVE_ACCESS_HINT}" == "FALSE" ]]; then
    LIVE_ACCESS_AVAILABLE=0
elif (( LIVE_CREDENTIALS_PRESENT == 1 )); then
    LIVE_ACCESS_AVAILABLE=1
fi

# Backward-compat for older flags:
# - KING_PHPT_ONLY_MOCK_TESTS=1 => mode=only
# - KING_PHPT_RUN_MOCK_TESTS=1 => mode=include
if [[ "${ONLY_MOCK_TESTS}" == "1" ]]; then
    MOCK_MODE="only"
elif [[ "${RUN_MOCK_TESTS}" == "1" ]]; then
    MOCK_MODE="include"
fi

if [[ "${MOCK_MODE}" == "auto" ]]; then
    if (( CI_ENVIRONMENT == 1 )); then
        MOCK_MODE="exclude"
        if (( LIVE_ACCESS_AVAILABLE == 1 )); then
            echo "Auto mock mode: CI + live credentials/access detected -> excluding mock-backed tests (opt-in with KING_PHPT_MOCK_MODE=include or only)."
        else
            echo "Auto mock mode: CI without confirmed live credentials/access -> excluding mock-backed tests (opt-in with KING_PHPT_MOCK_MODE=include or only)."
        fi
    else
        MOCK_MODE="include"
        if (( LIVE_ACCESS_AVAILABLE == 1 )); then
            echo "Auto mock mode: non-CI with live credentials/access detected -> running full suite."
        else
            echo "Auto mock mode: non-CI without live credentials/access -> running full suite (mocks included)."
        fi
    fi
fi

case "${MOCK_MODE}" in
    include)
        echo "Mock mode '${MOCK_MODE}': running full suite (${#MOCK_TEST_FILES[@]} mock-backed tests auto-detected)."
        ;;
    exclude)
        TEST_FILES=("${NON_MOCK_TEST_FILES[@]}")
        echo "Mock mode 'exclude': ${#MOCK_TEST_FILES[@]} mock-backed tests skipped."
        ;;
    only)
        TEST_FILES=("${MOCK_TEST_FILES[@]}")
        echo "Mock mode 'only': ${#TEST_FILES[@]} mock-backed tests selected."
        ;;
    *)
        echo "Invalid KING_PHPT_MOCK_MODE='${MOCK_MODE}'. Use one of: auto, include, exclude, only." >&2
        exit 1
        ;;
esac

if [[ "${#TEST_FILES[@]}" -eq 0 ]]; then
    echo "No PHPT files selected after mock-test filtering." >&2
    exit 0
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
    exec "${PHP_BIN}" -n run-tests.php -q "${SHARD_TEST_FILES[@]}"
fi

exec "${PHP_BIN}" -n run-tests.php -q "${TEST_FILES[@]}"
