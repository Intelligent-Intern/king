#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
BACKEND_DIR="$(cd "${SCRIPT_DIR}/.." && pwd)"
REPO_ROOT="$(cd "${BACKEND_DIR}/../../.." && pwd)"
PHP_BIN="${PHP_BIN:-php}"
DEFAULT_EXT="${REPO_ROOT}/extension/modules/king.so"
KING_EXTENSION_PATH="${KING_EXTENSION_PATH:-${DEFAULT_EXT}}"

LLAMA_CPP_HOME="${LLAMA_CPP_HOME:-/opt/llama-cpp/llama-b8802}"
GGUF_FIXTURE_PATH="${MODEL_INFERENCE_GGUF_FIXTURE_PATH:-${BACKEND_DIR}/.local/fixtures/SmolLM2-135M-Instruct-Q4_K_S.gguf}"

if [[ ! -x "${LLAMA_CPP_HOME}/llama-server" ]]; then
  echo "[llama-cpp-worker-contract] SKIP: llama.cpp runtime not installed (${LLAMA_CPP_HOME}/llama-server absent). Run ${BACKEND_DIR}/scripts/install-llama-runtime.sh binary to install." >&2
  exit 0
fi
if [[ ! -f "${GGUF_FIXTURE_PATH}" ]]; then
  echo "[llama-cpp-worker-contract] SKIP: GGUF fixture not installed (${GGUF_FIXTURE_PATH} absent). Run ${BACKEND_DIR}/scripts/install-llama-runtime.sh fixture to install." >&2
  exit 0
fi

php_args=()
if "${PHP_BIN}" -m | grep -Eiq '^king$'; then
  :
elif [[ -f "${KING_EXTENSION_PATH}" ]]; then
  php_args+=("-d" "extension=${KING_EXTENSION_PATH}")
fi

export LLAMA_CPP_HOME
export MODEL_INFERENCE_GGUF_FIXTURE_PATH="${GGUF_FIXTURE_PATH}"
"${PHP_BIN}" "${php_args[@]}" "${SCRIPT_DIR}/llama-cpp-worker-contract.php"
