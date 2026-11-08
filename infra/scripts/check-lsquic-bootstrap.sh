#!/usr/bin/env bash

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT_DIR="$(cd "${SCRIPT_DIR}/../.." && pwd)"
LOCK_FILE="${SCRIPT_DIR}/lsquic-bootstrap.lock"
MODE="${1:---check}"

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

validate_source_ref() {
    local name="$1"
    local value="${!name}"

    require_regex "${value}" '^https://github[.]com/[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+[.]git$' "${name}"
}

validate_archive() {
    local prefix="$1"
    local url_var="${prefix}_ARCHIVE_URL"

    require_regex "${!url_var}" '^https://github[.]com/[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+/archive/.+[.]tar[.]gz$' "${url_var}"
}

validate_commit() {
    local name="$1"

    require_regex "${!name}" '^[0-9a-f]{40}$' "${name}"
}

require_file "${LOCK_FILE}" "LSQUIC bootstrap lock file"

case "${MODE}" in
    --check|--print-source-plan)
        ;;
    *)
        fail "unsupported mode ${MODE}; expected --check or --print-source-plan"
        ;;
esac

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
    KING_LSQUIC_LICENSE_FILES
    KING_LSQUIC_BORINGSSL_REPO_URL
    KING_LSQUIC_BORINGSSL_TAG
    KING_LSQUIC_BORINGSSL_COMMIT
    KING_LSQUIC_BORINGSSL_ARCHIVE_URL
    KING_LSQUIC_BORINGSSL_LICENSE_FILES
    KING_LSQUIC_LS_QPACK_PATH
    KING_LSQUIC_LS_QPACK_REPO_URL
    KING_LSQUIC_LS_QPACK_COMMIT
    KING_LSQUIC_LS_QPACK_ARCHIVE_URL
    KING_LSQUIC_LS_QPACK_LICENSE_FILES
    KING_LSQUIC_LS_HPACK_PATH
    KING_LSQUIC_LS_HPACK_REPO_URL
    KING_LSQUIC_LS_HPACK_COMMIT
    KING_LSQUIC_LS_HPACK_ARCHIVE_URL
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

if [[ "${MODE}" == "--print-source-plan" ]]; then
    printf 'component\tarchive_url\tcommit\n'
    printf 'lsquic\t%s\t%s\n' "${KING_LSQUIC_ARCHIVE_URL}" "${KING_LSQUIC_COMMIT}"
    printf 'boringssl\t%s\t%s\n' "${KING_LSQUIC_BORINGSSL_ARCHIVE_URL}" "${KING_LSQUIC_BORINGSSL_COMMIT}"
    printf 'ls-qpack\t%s\t%s\n' "${KING_LSQUIC_LS_QPACK_ARCHIVE_URL}" "${KING_LSQUIC_LS_QPACK_COMMIT}"
    printf 'ls-hpack\t%s\t%s\n' "${KING_LSQUIC_LS_HPACK_ARCHIVE_URL}" "${KING_LSQUIC_LS_HPACK_COMMIT}"
else
    echo "LSQUIC source versions are pinned."
fi
