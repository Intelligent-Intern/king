#!/usr/bin/env bash

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT_DIR="$(cd "${SCRIPT_DIR}/.." && pwd)"
EXT_DIR="${ROOT_DIR}/extension"
PHP_BIN="${PHP_BIN:-php}"
EXT_SO="${EXT_DIR}/modules/king.so"
QUICHE_DIR="${ROOT_DIR}/quiche/target/release"
QUICHE_LIB="${QUICHE_DIR}/libquiche.so"
QUICHE_SERVER="${QUICHE_DIR}/quiche-server"

if [[ ! -f "${EXT_SO}" ]]; then
    echo "Missing extension binary: ${EXT_SO}" >&2
    echo "Run ./extension/scripts/build-extension.sh first." >&2
    exit 1
fi

if [[ ! -f "${QUICHE_LIB}" ]]; then
    echo "Missing libquiche runtime: ${QUICHE_LIB}" >&2
    echo "Run ./extension/scripts/build-extension.sh first." >&2
    exit 1
fi

if [[ ! -x "${QUICHE_SERVER}" ]]; then
    echo "Missing quiche-server binary: ${QUICHE_SERVER}" >&2
    echo "Run ./extension/scripts/build-extension.sh first." >&2
    exit 1
fi

cd "${ROOT_DIR}"

export KING_QUICHE_LIBRARY="${QUICHE_LIB}"
export KING_QUICHE_SERVER="${QUICHE_SERVER}"
export LD_LIBRARY_PATH="${QUICHE_DIR}${LD_LIBRARY_PATH:+:${LD_LIBRARY_PATH}}"

exec "${PHP_BIN}" \
    -d "extension=${EXT_SO}" \
    -d "king.security_allow_config_override=1" \
    "${SCRIPT_DIR}/run.php" \
    "$@"
