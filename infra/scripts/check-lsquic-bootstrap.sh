#!/usr/bin/env bash

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
LOCK_FILE="${SCRIPT_DIR}/lsquic-bootstrap.lock"
EXT_DIR="${SCRIPT_DIR}/../../extension"

if [[ ! -f "${LOCK_FILE}" ]]; then
    echo "Missing lsquic bootstrap lock file: ${LOCK_FILE}" >&2
    exit 1
fi

if [[ ! -d "${EXT_DIR}/lsquic" ]]; then
    echo "WARNING: lsquic sources not found under ${EXT_DIR}/lsquic" >&2
fi

if [[ -f "${EXT_DIR}/lsquic/liblsquic.a" ]]; then
    echo "lsquic library: pre-built"
else
    echo "lsquic library: not pre-built (optional, loads at runtime)"
fi

echo "lsquic bootstrap check: OK"