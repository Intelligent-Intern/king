#!/usr/bin/env bash

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT_DIR="$(cd "${SCRIPT_DIR}/../.." && pwd)"
EXT_DIR="${ROOT_DIR}/extension"
PHP_BIN="${PHP_BIN:-php}"
EXT_SO="${EXT_DIR}/modules/king.so"
LSQUIC_SHIM="${ROOT_DIR}/lsquic/release/liblsquic-shim.so"
LSQUIC_SERVER="${ROOT_DIR}/lsquic/release/lsquic"

if [[ ! -f "${EXT_SO}" ]]; then
    echo "Missing extension binary: ${EXT_SO}" >&2
    echo "Run ./infra/scripts/build-extension.sh first." >&2
    exit 1
fi

if [[ ! -f "${LSQUIC_SHIM}" ]]; then
    echo "Missing liblsquic-shim runtime: ${LSQUIC_SHIM}" >&2
    echo "Run ./infra/scripts/build-extension.sh first." >&2
    exit 1
fi

if [[ ! -x "${LSQUIC_SERVER}" ]]; then
    echo "Missing lsquic binary: ${LSQUIC_SERVER}" >&2
    echo "Run ./infra/scripts/build-extension.sh first." >&2
    exit 1
fi

cd "${ROOT_DIR}"

export KING_LSQUIC_SHIMRARY="${LSQUIC_SHIM}"
export KING_LSQUIC_SERVER="${LSQUIC_SERVER}"
export LD_LIBRARY_PATH="${ROOT_DIR}/lsquic/release${LD_LIBRARY_PATH:+:${LD_LIBRARY_PATH}}"

exec "${PHP_BIN}" \
    -d "extension=${EXT_SO}" \
    "${SCRIPT_DIR}/check-stub-parity.php" \
    "$@"
