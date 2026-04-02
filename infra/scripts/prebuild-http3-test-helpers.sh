#!/usr/bin/env bash

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT_DIR="$(cd "${SCRIPT_DIR}/../.." && pwd)"
TEST_DIR="${ROOT_DIR}/extension/tests"
QUICHE_DIR="${ROOT_DIR}/quiche"
MANIFEST="${QUICHE_DIR}/apps/Cargo.toml"
BIN_DIR="${QUICHE_DIR}/apps/src/bin"
TARGET_DIR="${QUICHE_DIR}/target/release"

if [[ ! -f "${MANIFEST}" ]]; then
    exit 0
fi

if ! command -v cargo >/dev/null 2>&1; then
    exit 0
fi

mkdir -p "${BIN_DIR}"

helpers=(
    "king-http3-failure-peer:http3_failure_peer.rs"
    "king-http3-delayed-body-client:http3_delayed_body_client.rs"
    "king-http3-abort-client:http3_abort_client.rs"
    "king-http3-multi-peer:http3_multi_peer.rs"
)

bin_args=()

for entry in "${helpers[@]}"; do
    helper_name="${entry%%:*}"
    template_name="${entry##*:}"
    template_path="${TEST_DIR}/${template_name}"
    source_path="${BIN_DIR}/${helper_name}.rs"
    binary_path="${TARGET_DIR}/${helper_name}"

    if [[ ! -f "${template_path}" ]]; then
        echo "Missing tracked HTTP/3 helper template: ${template_path}" >&2
        exit 1
    fi

    if [[ ! -f "${source_path}" ]] || ! cmp -s "${template_path}" "${source_path}"; then
        cp "${template_path}" "${source_path}"
    fi

    if [[ ! -x "${binary_path}" || "${binary_path}" -ot "${template_path}" || "${binary_path}" -ot "${source_path}" ]]; then
        bin_args+=(--bin "${helper_name}")
    fi
done

if [[ "${#bin_args[@]}" -eq 0 ]]; then
    exit 0
fi

cargo build \
    --manifest-path "${MANIFEST}" \
    --release \
    --locked \
    "${bin_args[@]}"
