#!/usr/bin/env bash

set -euo pipefail

usage() {
    cat <<'EOF'
Usage: ./infra/scripts/classify-phpt-flakes.sh --failed-tests FILE --output-dir DIR [options]

Reruns failed PHPT cases one-by-one and classifies them as:
  - flaky (passes on rerun)
  - deterministic (fails again on rerun)

Options:
  --failed-tests FILE   Path to failed-tests.txt produced by collect-phpt-failure-artifacts.sh (required)
  --output-dir DIR      Destination directory for classification artifacts (required)
  --job-name NAME       Logical label for summary output (default: canonical-baseline)
  --php-bin BIN         PHP binary for reruns (default: php)
  -h, --help            Show this help
EOF
}

resolve_abs_path() {
    local path="$1"
    if [[ "${path}" == /* ]]; then
        printf '%s\n' "${path}"
        return 0
    fi
    printf '%s/%s\n' "$(pwd)" "${path}"
}

FAILED_TESTS_FILE=""
OUTPUT_DIR=""
JOB_NAME="canonical-baseline"
PHP_BIN="${PHP_BIN:-php}"

while [[ $# -gt 0 ]]; do
    case "$1" in
        --failed-tests)
            if [[ $# -lt 2 || -z "${2:-}" ]]; then
                echo "Missing value for --failed-tests." >&2
                exit 1
            fi
            FAILED_TESTS_FILE="$2"
            shift 2
            ;;
        --output-dir)
            if [[ $# -lt 2 || -z "${2:-}" ]]; then
                echo "Missing value for --output-dir." >&2
                exit 1
            fi
            OUTPUT_DIR="$2"
            shift 2
            ;;
        --job-name)
            if [[ $# -lt 2 || -z "${2:-}" ]]; then
                echo "Missing value for --job-name." >&2
                exit 1
            fi
            JOB_NAME="$2"
            shift 2
            ;;
        --php-bin)
            if [[ $# -lt 2 || -z "${2:-}" ]]; then
                echo "Missing value for --php-bin." >&2
                exit 1
            fi
            PHP_BIN="$2"
            shift 2
            ;;
        -h|--help)
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

if [[ -z "${FAILED_TESTS_FILE}" || -z "${OUTPUT_DIR}" ]]; then
    usage >&2
    exit 1
fi

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT_DIR="$(cd "${SCRIPT_DIR}/../.." && pwd)"
EXT_DIR="${ROOT_DIR}/extension"
EXT_SO="${EXT_DIR}/modules/king.so"
QUICHE_LIB="${ROOT_DIR}/quiche/target/release/libquiche.so"
QUICHE_SERVER="${ROOT_DIR}/quiche/target/release/quiche-server"

FAILED_TESTS_FILE="$(resolve_abs_path "${FAILED_TESTS_FILE}")"
OUTPUT_DIR="$(resolve_abs_path "${OUTPUT_DIR}")"

rm -rf "${OUTPUT_DIR}"
mkdir -p "${OUTPUT_DIR}/rerun-logs" "${OUTPUT_DIR}/rerun-phpt-files"

FLAKY_FILE="${OUTPUT_DIR}/flaky-tests.txt"
DETERMINISTIC_FILE="${OUTPUT_DIR}/deterministic-tests.txt"
SKIPPED_FILE="${OUTPUT_DIR}/skipped-tests.txt"
RERUN_INPUT_FILE="${OUTPUT_DIR}/rerun-input-tests.txt"
SUMMARY_FILE="${OUTPUT_DIR}/summary.txt"

: > "${FLAKY_FILE}"
: > "${DETERMINISTIC_FILE}"
: > "${SKIPPED_FILE}"
: > "${RERUN_INPUT_FILE}"

if [[ ! -f "${FAILED_TESTS_FILE}" ]]; then
    cat > "${SUMMARY_FILE}" <<EOF
format=king_phpt_flake_classification_v1
job_name=${JOB_NAME}
status=missing_failed_tests_file
failed_tests_file=${FAILED_TESTS_FILE}
input_failed_count=0
rerun_candidate_count=0
rerun_executed_count=0
flaky_count=0
deterministic_count=0
skipped_count=0
EOF
    exit 0
fi

mapfile -t INPUT_FAILED_TESTS < <(awk 'NF {print $0}' "${FAILED_TESTS_FILE}" | LC_ALL=C sort -u)
INPUT_FAILED_COUNT="${#INPUT_FAILED_TESTS[@]}"

if (( INPUT_FAILED_COUNT == 0 )); then
    cat > "${SUMMARY_FILE}" <<EOF
format=king_phpt_flake_classification_v1
job_name=${JOB_NAME}
status=no_failed_tests
failed_tests_file=${FAILED_TESTS_FILE}
input_failed_count=0
rerun_candidate_count=0
rerun_executed_count=0
flaky_count=0
deterministic_count=0
skipped_count=0
EOF
    exit 0
fi

if [[ ! -f "${EXT_SO}" || ! -f "${QUICHE_LIB}" || ! -x "${QUICHE_SERVER}" ]]; then
    printf '%s\n' "${INPUT_FAILED_TESTS[@]}" > "${SKIPPED_FILE}"
    cat > "${SUMMARY_FILE}" <<EOF
format=king_phpt_flake_classification_v1
job_name=${JOB_NAME}
status=rerun_environment_unavailable
failed_tests_file=${FAILED_TESTS_FILE}
input_failed_count=${INPUT_FAILED_COUNT}
rerun_candidate_count=0
rerun_executed_count=0
flaky_count=0
deterministic_count=0
skipped_count=${INPUT_FAILED_COUNT}
EOF
    exit 0
fi

RERUN_TESTS=()
for test_entry in "${INPUT_FAILED_TESTS[@]}"; do
    test_entry="${test_entry#"${test_entry%%[![:space:]]*}"}"
    test_entry="${test_entry%"${test_entry##*[![:space:]]}"}"
    if [[ -z "${test_entry}" ]]; then
        continue
    fi

    if [[ -f "${EXT_DIR}/${test_entry}" ]]; then
        RERUN_TESTS+=("${test_entry}")
        continue
    fi
    if [[ -f "${EXT_DIR}/tests/${test_entry}" ]]; then
        RERUN_TESTS+=("tests/${test_entry}")
        continue
    fi

    printf '%s\n' "${test_entry}" >> "${SKIPPED_FILE}"
done

if (( ${#RERUN_TESTS[@]} == 0 )); then
    SKIPPED_COUNT="$(wc -l < "${SKIPPED_FILE}" | tr -d '[:space:]')"
    cat > "${SUMMARY_FILE}" <<EOF
format=king_phpt_flake_classification_v1
job_name=${JOB_NAME}
status=no_rerun_candidates
failed_tests_file=${FAILED_TESTS_FILE}
input_failed_count=${INPUT_FAILED_COUNT}
rerun_candidate_count=0
rerun_executed_count=0
flaky_count=0
deterministic_count=0
skipped_count=${SKIPPED_COUNT}
EOF
    exit 0
fi

printf '%s\n' "${RERUN_TESTS[@]}" > "${RERUN_INPUT_FILE}"

export KING_QUICHE_LIBRARY="${QUICHE_LIB}"
export KING_QUICHE_SERVER="${QUICHE_SERVER}"
export LD_LIBRARY_PATH="${ROOT_DIR}/quiche/target/release${LD_LIBRARY_PATH:+:${LD_LIBRARY_PATH}}"

(
    cd "${EXT_DIR}"
    "${SCRIPT_DIR}/prebuild-http3-test-helpers.sh"
)

RERUN_EXECUTED_COUNT=0
FLAKY_COUNT=0
DETERMINISTIC_COUNT=0

for relative_test in "${RERUN_TESTS[@]}"; do
    test_basename="$(basename "${relative_test}")"
    test_name="${test_basename%.phpt}"
    log_name="${test_name}.rerun.log"
    log_path="${OUTPUT_DIR}/rerun-logs/${log_name}"

    RERUN_EXECUTED_COUNT=$((RERUN_EXECUTED_COUNT + 1))

    rm -f "${EXT_DIR}/tests/"*.diff "${EXT_DIR}/tests/"*.exp "${EXT_DIR}/tests/"*.log "${EXT_DIR}/tests/"*.out

    if (
        cd "${EXT_DIR}"
        "${PHP_BIN}" run-tests.php -q -d "extension=${EXT_SO}" "${relative_test}" > "${log_path}" 2>&1
    ); then
        printf '%s\n' "${relative_test}" >> "${FLAKY_FILE}"
        FLAKY_COUNT=$((FLAKY_COUNT + 1))
    else
        printf '%s\n' "${relative_test}" >> "${DETERMINISTIC_FILE}"
        DETERMINISTIC_COUNT=$((DETERMINISTIC_COUNT + 1))
    fi

    for ext in diff exp log out; do
        artifact_path="${EXT_DIR}/${relative_test}.${ext}"
        if [[ -f "${artifact_path}" ]]; then
            cp "${artifact_path}" "${OUTPUT_DIR}/rerun-phpt-files/${test_name}.phpt.${ext}"
        fi
    done
done

LC_ALL=C sort -u -o "${FLAKY_FILE}" "${FLAKY_FILE}"
LC_ALL=C sort -u -o "${DETERMINISTIC_FILE}" "${DETERMINISTIC_FILE}"
LC_ALL=C sort -u -o "${SKIPPED_FILE}" "${SKIPPED_FILE}"

SKIPPED_COUNT="$(wc -l < "${SKIPPED_FILE}" | tr -d '[:space:]')"

if (( DETERMINISTIC_COUNT > 0 )); then
    STATUS="deterministic_failures_present"
elif (( FLAKY_COUNT > 0 )); then
    STATUS="all_failures_flaky_on_rerun"
else
    STATUS="rerun_completed_no_classification"
fi

find "${OUTPUT_DIR}/rerun-logs" -type f -printf '%P\n' | LC_ALL=C sort > "${OUTPUT_DIR}/rerun-logs-manifest.txt"
find "${OUTPUT_DIR}/rerun-phpt-files" -type f -printf '%P\n' | LC_ALL=C sort > "${OUTPUT_DIR}/rerun-phpt-files-manifest.txt"

cat > "${SUMMARY_FILE}" <<EOF
format=king_phpt_flake_classification_v1
job_name=${JOB_NAME}
status=${STATUS}
failed_tests_file=${FAILED_TESTS_FILE}
input_failed_count=${INPUT_FAILED_COUNT}
rerun_candidate_count=${#RERUN_TESTS[@]}
rerun_executed_count=${RERUN_EXECUTED_COUNT}
flaky_count=${FLAKY_COUNT}
deterministic_count=${DETERMINISTIC_COUNT}
skipped_count=${SKIPPED_COUNT}
EOF

exit 0

