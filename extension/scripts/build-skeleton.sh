#!/usr/bin/env bash

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
EXT_DIR="$(cd "${SCRIPT_DIR}/.." && pwd)"
QUICHE_DIR="$(cd "${EXT_DIR}/../quiche" && pwd)"
JOBS="${JOBS:-$(nproc)}"

if [[ ! -f "${QUICHE_DIR}/quiche/deps/boringssl/CMakeLists.txt" ]]; then
    git -C "${QUICHE_DIR}" submodule update --init --recursive quiche/deps/boringssl
fi

cargo build \
    --manifest-path "${QUICHE_DIR}/quiche/Cargo.toml" \
    --release \
    --locked \
    --features ffi

cargo build \
    --manifest-path "${QUICHE_DIR}/apps/Cargo.toml" \
    --release \
    --locked \
    --bin quiche-server

cd "${EXT_DIR}"

phpize
./configure --enable-king
make -j"${JOBS}"
