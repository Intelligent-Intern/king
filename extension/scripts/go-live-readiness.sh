#!/usr/bin/env bash

set -euo pipefail

usage() {
    cat <<'EOF'
Usage: ./scripts/go-live-readiness.sh [--skip-baseline] [--output-dir DIR] [--benchmark-iterations N] [--benchmark-warmup N]

Runs the final repo-local go-live readiness gate:
  - static checks, audit, release rebuild, and canonical PHPT suite
  - canonical fuzz/stress subset
  - public stub/runtime parity verification
  - release-profile smoke
  - benchmark smoke over the canonical runtime paths
  - reproducible release packaging plus extracted-package verification

Options:
  --skip-baseline          Skip static-checks/audit/test if they already ran
  --output-dir DIR         Output directory for packaged release artifacts
  --benchmark-iterations N Iterations for benchmark smoke
  --benchmark-warmup N     Warmup iterations for benchmark smoke
  -h, --help               Show this help
EOF
}

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
EXT_DIR="$(cd "${SCRIPT_DIR}/.." && pwd)"
ROOT_DIR="$(cd "${EXT_DIR}/.." && pwd)"
OUTPUT_DIR="${ROOT_DIR}/dist"
SKIP_BASELINE=0
BENCHMARK_ITERATIONS=250
BENCHMARK_WARMUP=25

while [[ $# -gt 0 ]]; do
    case "$1" in
        --skip-baseline)
            SKIP_BASELINE=1
            shift
            ;;
        --output-dir)
            if [[ $# -lt 2 ]]; then
                echo "Missing value for --output-dir." >&2
                exit 1
            fi
            OUTPUT_DIR="$2"
            shift 2
            ;;
        --benchmark-iterations)
            if [[ $# -lt 2 ]]; then
                echo "Missing value for --benchmark-iterations." >&2
                exit 1
            fi
            BENCHMARK_ITERATIONS="$2"
            shift 2
            ;;
        --benchmark-warmup)
            if [[ $# -lt 2 ]]; then
                echo "Missing value for --benchmark-warmup." >&2
                exit 1
            fi
            BENCHMARK_WARMUP="$2"
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

ensure_release_artifacts() {
    local required_paths=(
        "${EXT_DIR}/modules/king.so"
        "${ROOT_DIR}/quiche/target/release/libquiche.so"
        "${ROOT_DIR}/quiche/target/release/quiche-server"
        "${EXT_DIR}/build/profiles/release/king.so"
        "${EXT_DIR}/build/profiles/release/libquiche.so"
        "${EXT_DIR}/build/profiles/release/quiche-server"
    )
    local path=""

    for path in "${required_paths[@]}"; do
        if [[ ! -e "${path}" ]]; then
            "${SCRIPT_DIR}/build-extension.sh"
            return 0
        fi
    done
}

mkdir -p "${OUTPUT_DIR}"

if [[ "${SKIP_BASELINE}" != "1" ]]; then
    echo "Running canonical baseline..."
    "${SCRIPT_DIR}/static-checks.sh"
    "${SCRIPT_DIR}/audit-runtime-surface.sh"
    "${SCRIPT_DIR}/build-extension.sh"
    "${SCRIPT_DIR}/test-extension.sh"
else
    echo "Skipping baseline commands; ensuring release artifacts exist..."
    ensure_release_artifacts
fi

echo "Running final readiness checks..."
"${SCRIPT_DIR}/fuzz-runtime.sh"
"${SCRIPT_DIR}/check-stub-parity.sh"
"${SCRIPT_DIR}/smoke-profile.sh" release
"${ROOT_DIR}/benchmarks/run-canonical.sh" \
    --iterations="${BENCHMARK_ITERATIONS}" \
    --warmup="${BENCHMARK_WARMUP}"

package_output="$("${SCRIPT_DIR}/package-release.sh" --verify-reproducible --output-dir "${OUTPUT_DIR}")"
printf '%s\n' "${package_output}"

archive_path="$(printf '%s\n' "${package_output}" | sed -n 's/^Package created: //p' | tail -n 1)"
if [[ -z "${archive_path}" ]]; then
    echo "Failed to resolve packaged archive path from package-release.sh output." >&2
    exit 1
fi

"${SCRIPT_DIR}/verify-release-package.sh" --archive "${archive_path}"

echo "Go-live readiness gate passed."
echo "Packaged archive: ${archive_path}"
