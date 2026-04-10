#!/usr/bin/env bash

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT_DIR="$(cd "${SCRIPT_DIR}/../.." && pwd)"
TOOLCHAIN_LOCK="${SCRIPT_DIR}/toolchain.lock"
QUICHE_LOCK="${SCRIPT_DIR}/quiche-bootstrap.lock"
DOC_FILE="${ROOT_DIR}/DEPENDENCY_PROVENANCE.md"

if [[ ! -f "${TOOLCHAIN_LOCK}" ]]; then
    echo "Missing toolchain lock file: ${TOOLCHAIN_LOCK}" >&2
    exit 1
fi

if [[ ! -f "${QUICHE_LOCK}" ]]; then
    echo "Missing quiche bootstrap lock file: ${QUICHE_LOCK}" >&2
    exit 1
fi

if [[ ! -f "${DOC_FILE}" ]]; then
    echo "Missing dependency provenance document: ${DOC_FILE}" >&2
    exit 1
fi

# shellcheck source=/dev/null
source "${TOOLCHAIN_LOCK}"
# shellcheck source=/dev/null
source "${QUICHE_LOCK}"

require_literal() {
    local value="$1"
    local label="$2"

    if [[ -z "${value}" ]]; then
        echo "Missing expected value for ${label}." >&2
        exit 1
    fi

    if ! grep -Fq "\`${value}\`" "${DOC_FILE}"; then
        echo "Dependency provenance doc drift for ${label}." >&2
        echo "Expected to find value: ${value}" >&2
        exit 1
    fi
}

require_literal "${KING_CANONICAL_PHP_VERSION:-}" "KING_CANONICAL_PHP_VERSION"
require_literal "${KING_RUST_TOOLCHAIN_VERSION:-}" "KING_RUST_TOOLCHAIN_VERSION"
require_literal "${KING_QUICHE_REPO_URL:-}" "KING_QUICHE_REPO_URL"
require_literal "${KING_QUICHE_COMMIT:-}" "KING_QUICHE_COMMIT"
require_literal "${KING_QUICHE_BORINGSSL_COMMIT:-}" "KING_QUICHE_BORINGSSL_COMMIT"
require_literal "${KING_QUICHE_WIREFILTER_COMMIT:-}" "KING_QUICHE_WIREFILTER_COMMIT"

echo "Dependency provenance doc is in sync with lock files."
