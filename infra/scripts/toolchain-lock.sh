#!/usr/bin/env bash

set -euo pipefail

usage() {
    cat <<'EOF'
Usage:
  ./infra/scripts/toolchain-lock.sh --verify-rust
  ./infra/scripts/toolchain-lock.sh --github-output <path>

Reads pinned toolchain values from infra/scripts/toolchain.lock.

Options:
  --verify-rust         Fail unless rustc/cargo exactly match the pinned version
  --github-output PATH  Emit pinned values as GitHub Actions step outputs
  -h, --help            Show this help
EOF
}

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
LOCK_FILE="${SCRIPT_DIR}/toolchain.lock"

if [[ ! -f "${LOCK_FILE}" ]]; then
    echo "Missing toolchain lock file: ${LOCK_FILE}" >&2
    exit 1
fi

# shellcheck source=/dev/null
source "${LOCK_FILE}"

if [[ -z "${KING_CANONICAL_PHP_VERSION:-}" ]]; then
    echo "KING_CANONICAL_PHP_VERSION is empty in ${LOCK_FILE}." >&2
    exit 1
fi

if [[ -z "${KING_RUST_TOOLCHAIN_VERSION:-}" ]]; then
    echo "KING_RUST_TOOLCHAIN_VERSION is empty in ${LOCK_FILE}." >&2
    exit 1
fi

if [[ ! "${KING_CANONICAL_PHP_VERSION}" =~ ^[0-9]+\.[0-9]+$ ]]; then
    echo "KING_CANONICAL_PHP_VERSION must be <major>.<minor> (got '${KING_CANONICAL_PHP_VERSION}')." >&2
    exit 1
fi

if [[ ! "${KING_RUST_TOOLCHAIN_VERSION}" =~ ^[0-9]+\.[0-9]+\.[0-9]+([-.][A-Za-z0-9._]+)?$ ]]; then
    echo "KING_RUST_TOOLCHAIN_VERSION must be semver-like (got '${KING_RUST_TOOLCHAIN_VERSION}')." >&2
    exit 1
fi

verify_rust() {
    local rustc_version=""
    local cargo_version=""

    if ! command -v rustc >/dev/null 2>&1; then
        echo "rustc is not installed. Expected pinned toolchain ${KING_RUST_TOOLCHAIN_VERSION}." >&2
        exit 1
    fi

    if ! command -v cargo >/dev/null 2>&1; then
        echo "cargo is not installed. Expected pinned toolchain ${KING_RUST_TOOLCHAIN_VERSION}." >&2
        exit 1
    fi

    rustc_version="$(rustc --version 2>/dev/null | awk '{print $2}')"
    cargo_version="$(cargo --version 2>/dev/null | awk '{print $2}')"

    if [[ "${rustc_version}" != "${KING_RUST_TOOLCHAIN_VERSION}" ]]; then
        echo "rustc version drift detected: found ${rustc_version}, expected ${KING_RUST_TOOLCHAIN_VERSION}." >&2
        echo "Install/select the pinned toolchain before building." >&2
        exit 1
    fi

    if [[ "${cargo_version}" != "${KING_RUST_TOOLCHAIN_VERSION}" ]]; then
        echo "cargo version drift detected: found ${cargo_version}, expected ${KING_RUST_TOOLCHAIN_VERSION}." >&2
        echo "Install/select the pinned toolchain before building." >&2
        exit 1
    fi
}

emit_github_output() {
    local output_path="$1"

    if [[ -z "${output_path}" ]]; then
        echo "--github-output requires a non-empty file path." >&2
        exit 1
    fi

    printf 'canonical_php_version=%s\n' "${KING_CANONICAL_PHP_VERSION}" >> "${output_path}"
    printf 'rust_toolchain=%s\n' "${KING_RUST_TOOLCHAIN_VERSION}" >> "${output_path}"
}

case "${1:-}" in
    --verify-rust)
        verify_rust
        ;;
    --github-output)
        if [[ $# -lt 2 ]]; then
            echo "Missing value for --github-output." >&2
            exit 1
        fi
        emit_github_output "$2"
        ;;
    -h|--help)
        usage
        ;;
    *)
        echo "Unknown or missing option: ${1:-<none>}" >&2
        usage >&2
        exit 1
        ;;
esac
