#!/usr/bin/env bash

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT_DIR="$(cd "${SCRIPT_DIR}/../.." && pwd)"
LOCK_FILE="${SCRIPT_DIR}/lsquic-bootstrap.lock"
DOC_FILE="${ROOT_DIR}/DEPENDENCY_PROVENANCE.md"

fail() {
    echo "LSQUIC bootstrap lock check failed: $*" >&2
    exit 1
}

require_file() {
    local file="$1"
    local label="$2"

    [[ -f "${file}" ]] || fail "missing ${label}: ${file}"
}

require_var() {
    local name="$1"
    local value="${!name:-}"

    [[ -n "${value}" ]] || fail "missing ${name}"
}

require_regex() {
    local value="$1"
    local regex="$2"
    local label="$3"

    [[ "${value}" =~ ${regex} ]] || fail "invalid ${label}: ${value}"
}

require_doc_literal() {
    local value="$1"
    local label="$2"

    if ! grep -Fq "\`${value}\`" "${DOC_FILE}"; then
        fail "dependency provenance doc drift for ${label}; expected ${value}"
    fi
}

validate_source_ref() {
    local name="$1"
    local value="${!name}"

    require_regex "${value}" '^https://github[.]com/[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+[.]git$' "${name}"
}

validate_archive() {
    local prefix="$1"
    local url_var="${prefix}_ARCHIVE_URL"
    local sha_var="${prefix}_ARCHIVE_SHA256"
    local bytes_var="${prefix}_ARCHIVE_BYTES"

    require_regex "${!url_var}" '^https://github[.]com/[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+/archive/.+[.]tar[.]gz$' "${url_var}"
    require_regex "${!sha_var}" '^[0-9a-f]{64}$' "${sha_var}"
    require_regex "${!bytes_var}" '^[1-9][0-9]*$' "${bytes_var}"
    require_doc_literal "${!url_var}" "${url_var}"
    require_doc_literal "${!sha_var}" "${sha_var}"
    require_doc_literal "${!bytes_var}" "${bytes_var}"
}

validate_commit() {
    local name="$1"

    require_regex "${!name}" '^[0-9a-f]{40}$' "${name}"
    require_doc_literal "${!name}" "${name}"
}

require_file "${LOCK_FILE}" "LSQUIC bootstrap lock file"
require_file "${DOC_FILE}" "dependency provenance document"

if grep -Eq '(^|[[:space:]])(master|main|branch[[:space:]]*=)|/opt/homebrew|/usr/local/Cellar|file://|~/' "${LOCK_FILE}"; then
    fail "lock file contains a floating branch or local path"
fi

# shellcheck source=/dev/null
source "${LOCK_FILE}"

required_vars=(
    KING_LSQUIC_REPO_URL
    KING_LSQUIC_TAG
    KING_LSQUIC_COMMIT
    KING_LSQUIC_ARCHIVE_URL
    KING_LSQUIC_ARCHIVE_SHA256
    KING_LSQUIC_ARCHIVE_BYTES
    KING_LSQUIC_LICENSE_FILES
    KING_LSQUIC_BORINGSSL_REPO_URL
    KING_LSQUIC_BORINGSSL_TAG
    KING_LSQUIC_BORINGSSL_COMMIT
    KING_LSQUIC_BORINGSSL_ARCHIVE_URL
    KING_LSQUIC_BORINGSSL_ARCHIVE_SHA256
    KING_LSQUIC_BORINGSSL_ARCHIVE_BYTES
    KING_LSQUIC_BORINGSSL_LICENSE_FILES
    KING_LSQUIC_LS_QPACK_PATH
    KING_LSQUIC_LS_QPACK_REPO_URL
    KING_LSQUIC_LS_QPACK_COMMIT
    KING_LSQUIC_LS_QPACK_ARCHIVE_URL
    KING_LSQUIC_LS_QPACK_ARCHIVE_SHA256
    KING_LSQUIC_LS_QPACK_ARCHIVE_BYTES
    KING_LSQUIC_LS_QPACK_LICENSE_FILES
    KING_LSQUIC_LS_HPACK_PATH
    KING_LSQUIC_LS_HPACK_REPO_URL
    KING_LSQUIC_LS_HPACK_COMMIT
    KING_LSQUIC_LS_HPACK_ARCHIVE_URL
    KING_LSQUIC_LS_HPACK_ARCHIVE_SHA256
    KING_LSQUIC_LS_HPACK_ARCHIVE_BYTES
    KING_LSQUIC_LS_HPACK_LICENSE_FILES
)

for var in "${required_vars[@]}"; do
    require_var "${var}"
done

validate_source_ref KING_LSQUIC_REPO_URL
validate_source_ref KING_LSQUIC_BORINGSSL_REPO_URL
validate_source_ref KING_LSQUIC_LS_QPACK_REPO_URL
validate_source_ref KING_LSQUIC_LS_HPACK_REPO_URL

require_regex "${KING_LSQUIC_TAG}" '^v[0-9]+[.][0-9]+[.][0-9]+$' "KING_LSQUIC_TAG"
require_regex "${KING_LSQUIC_BORINGSSL_TAG}" '^[0-9]+[.][0-9]{8}[.][0-9]+$' "KING_LSQUIC_BORINGSSL_TAG"
require_doc_literal "${KING_LSQUIC_TAG}" "KING_LSQUIC_TAG"
require_doc_literal "${KING_LSQUIC_BORINGSSL_TAG}" "KING_LSQUIC_BORINGSSL_TAG"

validate_commit KING_LSQUIC_COMMIT
validate_commit KING_LSQUIC_BORINGSSL_COMMIT
validate_commit KING_LSQUIC_LS_QPACK_COMMIT
validate_commit KING_LSQUIC_LS_HPACK_COMMIT

validate_archive KING_LSQUIC
validate_archive KING_LSQUIC_BORINGSSL
validate_archive KING_LSQUIC_LS_QPACK
validate_archive KING_LSQUIC_LS_HPACK

require_regex "${KING_LSQUIC_LS_QPACK_PATH}" '^src/liblsquic/ls-qpack$' "KING_LSQUIC_LS_QPACK_PATH"
require_regex "${KING_LSQUIC_LS_HPACK_PATH}" '^src/lshpack$' "KING_LSQUIC_LS_HPACK_PATH"

echo "LSQUIC bootstrap lock is deterministic and documented."
