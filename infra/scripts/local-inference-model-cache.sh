#!/usr/bin/env bash

set -euo pipefail

usage() {
    cat <<'USAGE'
Usage: ./infra/scripts/local-inference-model-cache.sh resolve [--require] [--github-output PATH]

Resolves a local GGUF inference model artifact from the self-hosted runner
cache without downloading model weights or allowing model files to live in git.

Environment variables:
  KING_INFERENCE_TEST_MODEL_PATH          Explicit model artifact path.
  KING_CI_LOCAL_INFERENCE_MODEL_PATH      Explicit runner-cache model path.
  KING_CI_LOCAL_INFERENCE_MODEL_DIR       Runner model cache dir.
                                          Default: /opt/king/cache/inference-models
  KING_CI_LOCAL_INFERENCE_MODEL_NAME      Preferred file inside the cache dir.
                                          Default: gemma3-1b.gguf
  KING_CI_ALLOW_REPO_MODEL_ARTIFACT       Set to 1 only for local experiments.
USAGE
}

if [[ "${1:-}" == "--help" || "${1:-}" == "-h" ]]; then
    usage
    exit 0
fi

MODE="${1:-}"
shift || true

REQUIRE=0
GITHUB_OUTPUT_PATH=""
while [[ "$#" -gt 0 ]]; do
    case "$1" in
        --require)
            REQUIRE=1
            shift
            ;;
        --github-output)
            GITHUB_OUTPUT_PATH="${2:-}"
            shift 2
            ;;
        *)
            echo "Unknown argument: $1" >&2
            usage >&2
            exit 1
            ;;
    esac
done

if [[ "${MODE}" != "resolve" ]]; then
    echo "Mode must be resolve." >&2
    usage >&2
    exit 1
fi

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT_DIR="$(cd "${SCRIPT_DIR}/../.." && pwd)"
CACHE_DIR="${KING_CI_LOCAL_INFERENCE_MODEL_DIR:-/opt/king/cache/inference-models}"
MODEL_NAME="${KING_CI_LOCAL_INFERENCE_MODEL_NAME:-gemma3-1b.gguf}"
ALLOW_REPO_ARTIFACT="${KING_CI_ALLOW_REPO_MODEL_ARTIFACT:-0}"

write_output() {
    local key="$1"
    local value="$2"

    if [[ -n "${GITHUB_OUTPUT_PATH}" ]]; then
        printf '%s=%s\n' "${key}" "${value}" >> "${GITHUB_OUTPUT_PATH}"
    fi
}

assert_no_tracked_model_weights() {
    local tracked=""

    if ! command -v git >/dev/null 2>&1 || ! git -C "${ROOT_DIR}" rev-parse --is-inside-work-tree >/dev/null 2>&1; then
        return 0
    fi

    tracked="$(
        git -C "${ROOT_DIR}" ls-files \
            '*.gguf' '*.safetensors' '*.pt' '*.pth' '*.ckpt' '*.onnx' '*.tflite' 2>/dev/null || true
    )"
    if [[ -n "${tracked}" ]]; then
        echo "Model artifact files are tracked by git, which is forbidden:" >&2
        printf '%s\n' "${tracked}" >&2
        exit 1
    fi
}

resolve_path() {
    local path="$1"

    if [[ -z "${path}" || "${path}" == *"://"* ]]; then
        return 1
    fi
    if [[ -f "${path}" && -r "${path}" ]]; then
        printf '%s\n' "${path}"
        return 0
    fi
    return 1
}

gguf_magic_ok() {
    local path="$1"
    local magic=""

    magic="$(LC_ALL=C head -c 4 "${path}" 2>/dev/null || true)"
    [[ "${magic}" == "GGUF" ]]
}

repo_relative_path() {
    local real_path="$1"

    case "${real_path}" in
        "${ROOT_DIR}"/*)
            printf '%s\n' "${real_path#"${ROOT_DIR}/"}"
            return 0
            ;;
    esac
    return 1
}

reject_git_artifact() {
    local source_path="$1"
    local real_path="$2"
    local rel=""

    if [[ "${ALLOW_REPO_ARTIFACT}" == "1" ]]; then
        return 0
    fi

    if rel="$(repo_relative_path "${real_path}")"; then
        echo "Refusing inference model inside repository checkout: ${rel}" >&2
        echo "Model weights must live in a runner cache or external object store, not git." >&2
        exit 1
    fi

    if [[ "${source_path}" == "${ROOT_DIR}"/* ]] && [[ ! -L "${source_path}" ]]; then
        rel="${source_path#"${ROOT_DIR}/"}"
        echo "Refusing non-symlink inference model inside repository checkout: ${rel}" >&2
        exit 1
    fi
}

candidate_paths() {
    local explicit=""

    explicit="${KING_INFERENCE_TEST_MODEL_PATH:-}"
    [[ -n "${explicit}" ]] && printf '%s\t%s\n' "KING_INFERENCE_TEST_MODEL_PATH" "${explicit}"

    explicit="${KING_CI_LOCAL_INFERENCE_MODEL_PATH:-}"
    [[ -n "${explicit}" ]] && printf '%s\t%s\n' "KING_CI_LOCAL_INFERENCE_MODEL_PATH" "${explicit}"

    printf '%s\t%s\n' "KING_CI_LOCAL_INFERENCE_MODEL_DIR/${MODEL_NAME}" "${CACHE_DIR%/}/${MODEL_NAME}"

    if [[ -d "${CACHE_DIR}" ]]; then
        find "${CACHE_DIR}" -maxdepth 2 -type f -name '*.gguf' -print \
            | LC_ALL=C sort \
            | awk '{print "KING_CI_LOCAL_INFERENCE_MODEL_DIR/*.gguf\t" $0}'
    fi

    printf '%s\t%s\n' "repo-local symlink var/inference-models/gemma3-1b.gguf" "${ROOT_DIR}/var/inference-models/gemma3-1b.gguf"
}

assert_no_tracked_model_weights

FOUND_PATH=""
FOUND_SOURCE=""
while IFS=$'\t' read -r source path; do
    if resolved="$(resolve_path "${path}")"; then
        FOUND_PATH="${resolved}"
        FOUND_SOURCE="${source}"
        break
    fi
done < <(candidate_paths)

if [[ -z "${FOUND_PATH}" ]]; then
    write_output "model-present" "false"
    write_output "model-path" ""
    write_output "model-realpath" ""
    write_output "model-source" ""
    write_output "model-cache-dir" "${CACHE_DIR}"
    if [[ "${REQUIRE}" == "1" ]]; then
        echo "No readable local GGUF inference model found." >&2
        echo "Checked explicit env paths, ${CACHE_DIR}, and repo-local symlink fallback." >&2
        exit 1
    fi
    echo "No readable local GGUF inference model found."
    exit 0
fi

REAL_PATH="$(realpath "${FOUND_PATH}")"
reject_git_artifact "${FOUND_PATH}" "${REAL_PATH}"

if ! gguf_magic_ok "${FOUND_PATH}"; then
    echo "Resolved inference artifact is not a GGUF file: ${FOUND_PATH}" >&2
    exit 1
fi

BYTES="$(wc -c < "${FOUND_PATH}" | tr -d '[:space:]')"
write_output "model-present" "true"
write_output "model-path" "${FOUND_PATH}"
write_output "model-realpath" "${REAL_PATH}"
write_output "model-source" "${FOUND_SOURCE}"
write_output "model-cache-dir" "${CACHE_DIR}"
write_output "model-bytes" "${BYTES}"

echo "Resolved local GGUF inference model: ${FOUND_PATH}"
echo "Real path: ${REAL_PATH}"
echo "Source: ${FOUND_SOURCE}"
echo "Bytes: ${BYTES}"
