#!/usr/bin/env bash

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT_DIR="$(cd "${SCRIPT_DIR}/../.." && pwd)"

cd "${ROOT_DIR}"

paths=(
  ".DS_Store"
  ".cache/king/lsquic/archives/lsquic-local.tar.gz"
  "demo/video-chat/frontend-vue/dist/assets/local.js"
  "demo/video-chat/frontend-vue/test-results/local/result.json"
  "extension/tests/739-websocket-large-binary-short-poll-frame-read.php"
  "extension/tests/739-websocket-large-binary-short-poll-frame-read.sh"
  "extension/tests/739-websocket-large-binary-short-poll-frame-read.log"
  "extension/tests/739-websocket-large-binary-short-poll-frame-read.diff"
  "extension/tests/739-websocket-large-binary-short-poll-frame-read.exp"
  "extension/tests/739-websocket-large-binary-short-poll-frame-read.out"
  "extension/tests/739-websocket-large-binary-short-poll-frame-read.mem"
  "extension/tmp-php.ini"
  "extension/run-test-info.php"
  "llama-fork/common/common.o"
  "node_modules/.package-lock.json"
)

failed=0
for path in "${paths[@]}"; do
  if ! git check-ignore --no-index --quiet "${path}"; then
    echo "Expected local dirt to be gitignored: ${path}" >&2
    failed=1
  fi
done

if [[ "${failed}" -ne 0 ]]; then
  exit 1
fi

echo "Local dirt gitignore contract passed."
