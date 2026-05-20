#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT_DIR="$(cd "${SCRIPT_DIR}/.." && pwd)"
VIDEOCHAT_DIR="$(cd "${ROOT_DIR}/.." && pwd)"
PHP_BIN="${PHP_BIN:-php}"

"${PHP_BIN}" "${SCRIPT_DIR}/stt-config-contract.php"

DRY_RUN_OUTPUT="$("${VIDEOCHAT_DIR}/scripts/install-whisper-model.sh" --dry-run --model tiny.en --model-dir /tmp/videochat-stt-contract-models)"

if ! grep -q 'dry-run: no directories created and no download attempted' <<<"${DRY_RUN_OUTPUT}"; then
  echo "[stt-config-contract] FAIL: downloader dry-run did not report no-download behavior" >&2
  exit 1
fi

if ! grep -q 'VIDEOCHAT_STT_MODEL=/tmp/videochat-stt-contract-models/ggml-tiny.en.bin' <<<"${DRY_RUN_OUTPUT}"; then
  echo "[stt-config-contract] FAIL: downloader dry-run did not emit expected VIDEOCHAT_STT_MODEL path" >&2
  exit 1
fi

if [[ -e /tmp/videochat-stt-contract-models/ggml-tiny.en.bin ]]; then
  echo "[stt-config-contract] FAIL: downloader dry-run created a model file" >&2
  exit 1
fi

echo "[stt-config-contract] downloader dry-run PASS"
