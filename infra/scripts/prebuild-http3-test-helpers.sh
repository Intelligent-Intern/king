#!/usr/bin/env bash

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT_DIR="$(cd "${SCRIPT_DIR}/../.." && pwd)"
EXT_DIR="${ROOT_DIR}/extension"

LSQUIC_SHIM="${EXT_DIR}/lsquic/liblsquic-shim.so"

if [[ -f "${LSQUIC_SHIM}" ]]; then
    export KING_LSQUIC_LIBRARY="${LSQUIC_SHIM}"
    export LD_LIBRARY_PATH="${EXT_DIR}/lsquic${LD_LIBRARY_PATH:+:${LD_LIBRARY_PATH}}"
fi