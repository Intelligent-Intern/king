#!/usr/bin/env bash

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT_DIR="$(cd "${SCRIPT_DIR}/../.." && pwd)"

echo "Cleaning repo-local King build output"

if [[ -f "${ROOT_DIR}/extension/Makefile" ]]; then
    (cd "${ROOT_DIR}/extension" && make clean >/dev/null 2>&1 || true)
fi

rm -rf \
    "${ROOT_DIR}/dist" \
    "${ROOT_DIR}/compat-artifacts" \
    "${ROOT_DIR}/soak-artifacts" \
    "${ROOT_DIR}/extension/modules" \
    "${ROOT_DIR}/extension/build/profiles" \
    "${ROOT_DIR}/extension/build/soak" \
    "${ROOT_DIR}/demo/video-chat/dist"

echo "Clean complete"
