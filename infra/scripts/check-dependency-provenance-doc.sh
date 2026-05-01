#!/usr/bin/env bash

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT_DIR="$(cd "${SCRIPT_DIR}/../.." && pwd)"
TOOLCHAIN_LOCK="${SCRIPT_DIR}/toolchain.lock"
LSQUIC_CHECK="${SCRIPT_DIR}/check-lsquic-bootstrap.sh"
DOC_FILE="${ROOT_DIR}/documentation/dependency-provenance.md"

if [[ ! -f "${TOOLCHAIN_LOCK}" ]]; then
    echo "Missing toolchain lock file: ${TOOLCHAIN_LOCK}" >&2
    exit 1
fi

if [[ ! -x "${LSQUIC_CHECK}" ]]; then
    echo "Missing executable LSQUIC bootstrap checker: ${LSQUIC_CHECK}" >&2
    exit 1
fi

if [[ ! -f "${DOC_FILE}" ]]; then
    echo "Missing dependency provenance document: ${DOC_FILE}" >&2
    exit 1
fi

# shellcheck source=/dev/null
source "${TOOLCHAIN_LOCK}"

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

require_active_http3_provenance_quiche_free() {
    local active_section

    active_section="$(awk '
        /^## HTTP\/3 Replacement Stack Provenance Pins$/ { in_section = 1; next }
        /^## / && in_section { exit }
        in_section { print }
    ' "${DOC_FILE}")"

    if [[ -z "${active_section}" ]]; then
        echo "Missing active HTTP/3 replacement provenance section." >&2
        exit 1
    fi

    if grep -Eiq 'cloudflare/quiche|KING_QUICHE|(^|[^[:alnum:]])quiche([^[:alnum:]]|$)' <<<"${active_section}"; then
        echo "Active HTTP/3 replacement provenance still names Quiche." >&2
        exit 1
    fi
}

require_literal "${KING_CANONICAL_PHP_VERSION:-}" "KING_CANONICAL_PHP_VERSION"
require_literal "${KING_RUST_TOOLCHAIN_VERSION:-}" "KING_RUST_TOOLCHAIN_VERSION"
require_active_http3_provenance_quiche_free

"${LSQUIC_CHECK}"

echo "Dependency provenance doc is in sync with lock files."
