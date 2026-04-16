#!/usr/bin/env bash

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT_DIR="$(cd "${SCRIPT_DIR}/.." && pwd)"
EXT_DIR="${ROOT_DIR}/extension"
PHP_BIN="${PHP_BIN:-php}"
EXT_SO="${EXT_DIR}/modules/king.so"
LSQUIC_DIR="${ROOT_DIR}/lsquic/target/release"
LSQUIC_LIB="${LSQUIC_DIR}/liblsquic.so"
LSQUIC_SERVER="${LSQUIC_DIR}/lsquic-server"

if [[ ! -f "${EXT_SO}" ]]; then
    echo "Missing extension binary: ${EXT_SO}" >&2
    echo "Run ./infra/scripts/build-extension.sh first." >&2
    exit 1
fi

if [[ ! -f "${LSQUIC_LIB}" ]]; then
    echo "Missing liblsquic runtime: ${LSQUIC_LIB}" >&2
    echo "Run ./infra/scripts/build-extension.sh first." >&2
    exit 1
fi

if [[ ! -x "${LSQUIC_SERVER}" ]]; then
    echo "Missing lsquic-server binary: ${LSQUIC_SERVER}" >&2
    echo "Run ./infra/scripts/build-extension.sh first." >&2
    exit 1
fi

cd "${ROOT_DIR}"

export KING_LSQUIC_LIBRARY="${LSQUIC_LIB}"
export KING_LSQUIC_SERVER="${LSQUIC_SERVER}"
export LD_LIBRARY_PATH="${LSQUIC_DIR}${LD_LIBRARY_PATH:+:${LD_LIBRARY_PATH}}"

exec "${PHP_BIN}" \
    -d "extension=${EXT_SO}" \
    -d "king.security_allow_config_override=1" \
    "${SCRIPT_DIR}/run.php" \
    "$@"
