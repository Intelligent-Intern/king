#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
DEFAULT_MODEL_DIR="${ROOT_DIR}/backend-king-php/.local/models/whisper.cpp"
MODEL_NAME="${VIDEOCHAT_STT_INSTALL_MODEL_NAME:-tiny.en}"
MODEL_DIR="${VIDEOCHAT_STT_INSTALL_MODEL_DIR:-${DEFAULT_MODEL_DIR}}"
DRY_RUN=0
FORCE=0

usage() {
  cat <<'USAGE'
Usage: install-whisper-model.sh [--model tiny.en|tiny|base.en|base] [--model-dir DIR] [--dry-run] [--force]

Downloads a small whisper.cpp-compatible GGML model for local CPU STT setup.
Defaults to tiny.en, suitable for fast CPU smoke/demo use.
USAGE
}

while [[ "$#" -gt 0 ]]; do
  case "$1" in
    --model)
      MODEL_NAME="${2:-}"
      shift 2
      ;;
    --model-dir)
      MODEL_DIR="${2:-}"
      shift 2
      ;;
    --dry-run)
      DRY_RUN=1
      shift
      ;;
    --force)
      FORCE=1
      shift
      ;;
    -h|--help)
      usage
      exit 0
      ;;
    *)
      echo "[video-chat-stt-model] unknown argument: $1" >&2
      usage >&2
      exit 2
      ;;
  esac
done

case "${MODEL_NAME}" in
  tiny.en)
    MODEL_FILE="ggml-tiny.en.bin"
    ;;
  tiny)
    MODEL_FILE="ggml-tiny.bin"
    ;;
  base.en)
    MODEL_FILE="ggml-base.en.bin"
    ;;
  base)
    MODEL_FILE="ggml-base.bin"
    ;;
  *)
    echo "[video-chat-stt-model] unsupported model '${MODEL_NAME}'. Use tiny.en, tiny, base.en, or base." >&2
    exit 2
    ;;
esac

MODEL_URL="${VIDEOCHAT_STT_MODEL_URL:-https://huggingface.co/ggerganov/whisper.cpp/resolve/main/${MODEL_FILE}}"
MODEL_PATH="${MODEL_DIR%/}/${MODEL_FILE}"
PARTIAL_PATH="${MODEL_PATH}.part"
SHA256_EXPECTED="${VIDEOCHAT_STT_MODEL_SHA256:-}"

echo "[video-chat-stt-model] model=${MODEL_NAME}"
echo "[video-chat-stt-model] url=${MODEL_URL}"
echo "[video-chat-stt-model] path=${MODEL_PATH}"

if [[ "${DRY_RUN}" == "1" ]]; then
  echo "[video-chat-stt-model] dry-run: no directories created and no download attempted"
  echo "VIDEOCHAT_STT_MODEL=${MODEL_PATH}"
  exit 0
fi

mkdir -p "${MODEL_DIR}"

if [[ -f "${MODEL_PATH}" && "${FORCE}" != "1" ]]; then
  echo "[video-chat-stt-model] exists: ${MODEL_PATH}"
  echo "VIDEOCHAT_STT_MODEL=${MODEL_PATH}"
  exit 0
fi

rm -f "${PARTIAL_PATH}"

if command -v curl >/dev/null 2>&1; then
  curl -fL --retry 3 --connect-timeout 15 -o "${PARTIAL_PATH}" "${MODEL_URL}"
elif command -v wget >/dev/null 2>&1; then
  wget -O "${PARTIAL_PATH}" "${MODEL_URL}"
else
  echo "[video-chat-stt-model] curl or wget is required to download the model." >&2
  exit 1
fi

if [[ ! -s "${PARTIAL_PATH}" ]]; then
  echo "[video-chat-stt-model] downloaded model is empty: ${PARTIAL_PATH}" >&2
  exit 1
fi

if [[ -n "${SHA256_EXPECTED}" ]]; then
  if ! command -v sha256sum >/dev/null 2>&1; then
    echo "[video-chat-stt-model] sha256sum is required when VIDEOCHAT_STT_MODEL_SHA256 is set." >&2
    exit 1
  fi
  SHA256_ACTUAL="$(sha256sum "${PARTIAL_PATH}" | awk '{print $1}')"
  if [[ "${SHA256_ACTUAL}" != "${SHA256_EXPECTED}" ]]; then
    echo "[video-chat-stt-model] checksum mismatch for ${MODEL_FILE}" >&2
    echo "[video-chat-stt-model] expected ${SHA256_EXPECTED}" >&2
    echo "[video-chat-stt-model] actual   ${SHA256_ACTUAL}" >&2
    exit 1
  fi
fi

mv "${PARTIAL_PATH}" "${MODEL_PATH}"
echo "[video-chat-stt-model] installed: ${MODEL_PATH}"
echo "VIDEOCHAT_STT_MODEL=${MODEL_PATH}"
