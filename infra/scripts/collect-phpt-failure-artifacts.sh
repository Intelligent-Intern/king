#!/usr/bin/env bash

set -euo pipefail

usage() {
    cat <<'EOF'
Usage: ./infra/scripts/collect-phpt-failure-artifacts.sh --output-dir DIR [options]

Collects deterministic PHPT failure diagnostics:
  - structured summary.txt
  - failed-tests.txt
  - phpt payload files (*.diff, *.exp, *.log, *.out)
  - optional config and run logs

Options:
  --output-dir DIR   Destination directory for diagnostic bundle (required)
  --tests-dir DIR    PHPT artifact source directory (default: tests)
  --job-name NAME    Logical job/shard label (default: unknown)
  --config-log FILE  Optional config.log path (default: config.log)
  --run-log FILE     Optional run log path; may be provided multiple times
  -h, --help         Show this help
EOF
}

resolve_path() {
    local path="$1"
    if [[ "${path}" == /* ]]; then
        printf '%s\n' "${path}"
        return 0
    fi
    printf '%s/%s\n' "$(pwd)" "${path}"
}

OUTPUT_DIR=""
TESTS_DIR="tests"
JOB_NAME="unknown"
CONFIG_LOG="config.log"
RUN_LOGS=()

while [[ $# -gt 0 ]]; do
    case "$1" in
        --output-dir)
            if [[ $# -lt 2 || -z "${2:-}" ]]; then
                echo "Missing value for --output-dir." >&2
                exit 1
            fi
            OUTPUT_DIR="$2"
            shift 2
            ;;
        --tests-dir)
            if [[ $# -lt 2 || -z "${2:-}" ]]; then
                echo "Missing value for --tests-dir." >&2
                exit 1
            fi
            TESTS_DIR="$2"
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
        --config-log)
            if [[ $# -lt 2 || -z "${2:-}" ]]; then
                echo "Missing value for --config-log." >&2
                exit 1
            fi
            CONFIG_LOG="$2"
            shift 2
            ;;
        --run-log)
            if [[ $# -lt 2 || -z "${2:-}" ]]; then
                echo "Missing value for --run-log." >&2
                exit 1
            fi
            RUN_LOGS+=("$2")
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

if [[ -z "${OUTPUT_DIR}" ]]; then
    echo "The --output-dir option is required." >&2
    usage >&2
    exit 1
fi

OUTPUT_DIR="$(resolve_path "${OUTPUT_DIR}")"
TESTS_DIR="$(resolve_path "${TESTS_DIR}")"
CONFIG_LOG="$(resolve_path "${CONFIG_LOG}")"

rm -rf "${OUTPUT_DIR}"
mkdir -p "${OUTPUT_DIR}/phpt-files" "${OUTPUT_DIR}/run-logs"

diff_count=0
exp_count=0
log_count=0
out_count=0
run_log_count=0
config_log_present=0

declare -a copied_payloads=()
declare -a failed_tests=()
declare -a copied_run_logs=()

if [[ -d "${TESTS_DIR}" ]]; then
    while IFS= read -r -d '' file; do
        rel="${file#${TESTS_DIR}/}"
        dest="${OUTPUT_DIR}/phpt-files/${rel}"
        mkdir -p "$(dirname "${dest}")"
        cp "${file}" "${dest}"
        copied_payloads+=("phpt-files/${rel}")

        base="$(basename "${file}")"
        failed_tests+=("${base%.*}.phpt")

        case "${file##*.}" in
            diff) diff_count=$((diff_count + 1)) ;;
            exp) exp_count=$((exp_count + 1)) ;;
            log) log_count=$((log_count + 1)) ;;
            out) out_count=$((out_count + 1)) ;;
        esac
    done < <(
        find "${TESTS_DIR}" -type f \
            \( -name '*.diff' -o -name '*.exp' -o -name '*.log' -o -name '*.out' \) \
            -print0 \
            | LC_ALL=C sort -z
    )
fi

if [[ ${#copied_payloads[@]} -gt 0 ]]; then
    printf '%s\n' "${copied_payloads[@]}" > "${OUTPUT_DIR}/phpt-files-manifest.txt"
else
    : > "${OUTPUT_DIR}/phpt-files-manifest.txt"
fi

if [[ ${#failed_tests[@]} -gt 0 ]]; then
    printf '%s\n' "${failed_tests[@]}" | LC_ALL=C sort -u > "${OUTPUT_DIR}/failed-tests.txt"
else
    : > "${OUTPUT_DIR}/failed-tests.txt"
fi

if [[ -f "${CONFIG_LOG}" ]]; then
    cp "${CONFIG_LOG}" "${OUTPUT_DIR}/config.log"
    config_log_present=1
fi

run_log_index=0
for run_log in "${RUN_LOGS[@]}"; do
    run_log_path="$(resolve_path "${run_log}")"
    if [[ ! -f "${run_log_path}" ]]; then
        continue
    fi

    run_log_index=$((run_log_index + 1))
    run_log_target="$(printf '%02d-%s' "${run_log_index}" "$(basename "${run_log_path}")")"
    cp "${run_log_path}" "${OUTPUT_DIR}/run-logs/${run_log_target}"
    copied_run_logs+=("run-logs/${run_log_target}")
    run_log_count=$((run_log_count + 1))
done

if [[ ${#copied_run_logs[@]} -gt 0 ]]; then
    printf '%s\n' "${copied_run_logs[@]}" > "${OUTPUT_DIR}/run-logs-manifest.txt"
else
    : > "${OUTPUT_DIR}/run-logs-manifest.txt"
fi

failed_test_count="$(wc -l < "${OUTPUT_DIR}/failed-tests.txt" | tr -d '[:space:]')"
phpt_payload_count=$((diff_count + exp_count + log_count + out_count))

cat > "${OUTPUT_DIR}/summary.txt" <<EOF
format=king_phpt_failure_diagnostics_v1
job_name=${JOB_NAME}
tests_dir=${TESTS_DIR}
phpt_payload_count=${phpt_payload_count}
diff_count=${diff_count}
exp_count=${exp_count}
log_count=${log_count}
out_count=${out_count}
failed_test_count=${failed_test_count}
config_log_present=${config_log_present}
run_log_count=${run_log_count}
EOF

