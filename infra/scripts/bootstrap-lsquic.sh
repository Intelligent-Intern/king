#!/usr/bin/env bash

set -euo pipefail

usage() {
    cat <<'USAGE'
Usage: ./infra/scripts/bootstrap-lsquic.sh [--verify-lock|--verify-current|--print-source-plan]

Bootstraps the pinned LSQUIC/BoringSSL source archives used by the King HTTP/3
migration. The script is driven only by infra/scripts/lsquic-bootstrap.lock and
stores fetched sources under .cache/ by default so generated dependency payloads
are never committed.

Environment variables:
  KING_LSQUIC_SOURCE_CACHE  Archive/source cache root. Default: .cache/king/lsquic
  KING_LSQUIC_SOURCE_DIR    Extracted source root. Default: $KING_LSQUIC_SOURCE_CACHE/src
  KING_LSQUIC_ARCHIVE_MIRROR_BASE
                            Optional HTTPS mirror containing archives named
                            like <component>-<commit>.tar.gz.
USAGE
}

if [[ "${1:-}" == "--help" || "${1:-}" == "-h" ]]; then
    usage
    exit 0
fi

MODE="${1:-bootstrap}"
case "${MODE}" in
    bootstrap|--verify-lock|--verify-current|--print-source-plan)
        ;;
    *)
        echo "Unknown mode: ${MODE}" >&2
        usage >&2
        exit 1
        ;;
esac

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT_DIR="$(cd "${SCRIPT_DIR}/../.." && pwd)"
LOCK_FILE="${SCRIPT_DIR}/lsquic-bootstrap.lock"
CHECK_SCRIPT="${SCRIPT_DIR}/check-lsquic-bootstrap.sh"
CACHE_DIR="${KING_LSQUIC_SOURCE_CACHE:-${ROOT_DIR}/.cache/king/lsquic}"
SOURCE_DIR="${KING_LSQUIC_SOURCE_DIR:-${CACHE_DIR}/src}"
ARCHIVE_DIR="${CACHE_DIR}/archives"

if [[ ! -f "${LOCK_FILE}" ]]; then
    echo "Missing LSQUIC bootstrap lock file: ${LOCK_FILE}" >&2
    exit 1
fi

if [[ ! -x "${CHECK_SCRIPT}" ]]; then
    echo "Missing executable LSQUIC lock checker: ${CHECK_SCRIPT}" >&2
    exit 1
fi

# shellcheck source=/dev/null
source "${LOCK_FILE}"

require_tool() {
    if ! command -v "$1" >/dev/null 2>&1; then
        echo "Required tool '$1' is not installed." >&2
        exit 1
    fi
}

archive_name() {
    local component="$1"
    local reference="$2"

    printf '%s-%s.tar.gz\n' "${component}" "${reference}"
}

archive_path() {
    local component="$1"
    local reference="$2"

    printf '%s/%s\n' "${ARCHIVE_DIR}" "$(archive_name "${component}" "${reference}")"
}

curl_retry_flags() {
    local flags=(-fsSL --retry 5 --retry-delay 2 --retry-connrefused)

    if curl --help all 2>/dev/null | grep -q -- '--retry-all-errors'; then
        flags+=(--retry-all-errors)
    fi

    printf '%s\n' "${flags[@]}"
}

github_codeload_url() {
    local url="$1"
    local repo=""
    local ref=""

    case "${url}" in
        https://github.com/*/*/archive/*.tar.gz)
            repo="${url#https://github.com/}"
            repo="${repo%%/archive/*}"
            ref="${url#https://github.com/${repo}/archive/}"
            ref="${ref%.tar.gz}"
            printf 'https://codeload.github.com/%s/tar.gz/%s\n' "${repo}" "${ref}"
            ;;
    esac
}

archive_candidate_urls() {
    local component="$1"
    local url="$2"
    local reference="$3"
    local mirror_base="${KING_LSQUIC_ARCHIVE_MIRROR_BASE:-}"
    local codeload_url=""

    if [[ -n "${mirror_base}" ]]; then
        printf '%s/%s\n' "${mirror_base%/}" "$(archive_name "${component}" "${reference}")"
    fi

    printf '%s\n' "${url}"

    codeload_url="$(github_codeload_url "${url}")"
    if [[ -n "${codeload_url}" && "${codeload_url}" != "${url}" ]]; then
        printf '%s\n' "${codeload_url}"
    fi
}

verify_archive() {
    local component="$1" file="$2"
    [[ -s "${file}" ]] && tar -tzf "${file}" >/dev/null 2>&1 || {
        echo "Missing or invalid ${component} source archive: ${file}" >&2
        return 1
    }
}

fetch_archive() {
    local component="$1"
    local url="$2"
    local reference="$3"
    local destination=""
    local tmp=""
    local candidate=""
    local curl_flags=()

    destination="$(archive_path "${component}" "${reference}")"
    mkdir -p "${ARCHIVE_DIR}"

    if verify_archive "${component}" "${destination}" >/dev/null 2>&1; then
        printf '%s\n' "${destination}"
        return
    fi

    tmp="${destination}.tmp.$$"
    # shellcheck disable=SC2207
    curl_flags=($(curl_retry_flags))

    while IFS= read -r candidate; do
        [[ -n "${candidate}" ]] || continue
        rm -f "${tmp}"
        if ! curl "${curl_flags[@]}" "${candidate}" -o "${tmp}"; then
            echo "Failed to fetch ${component} archive candidate: ${candidate}" >&2
            continue
        fi
        if ! verify_archive "${component}" "${tmp}"; then
            rm -f "${tmp}"
            echo "Rejected ${component} archive candidate after verification: ${candidate}" >&2
            continue
        fi
        mv "${tmp}" "${destination}"
        printf '%s\n' "${destination}"
        return
    done < <(archive_candidate_urls "${component}" "${url}" "${reference}")

    echo "Failed to fetch ${component} archive from all deterministic candidates for: ${url}" >&2
    return 1
}

extract_archive_root() {
    local archive="$1"
    local tmp_dir="$2"
    local root=""

    mkdir -p "${tmp_dir}"
    tar -xzf "${archive}" -C "${tmp_dir}"
    root="$(find "${tmp_dir}" -mindepth 1 -maxdepth 1 -type d | head -n 1)"
    if [[ -z "${root}" ]]; then
        echo "Archive did not extract to a directory: ${archive}" >&2
        exit 1
    fi
    printf '%s\n' "${root}"
}

install_archive_root() {
    local archive="$1"
    local destination="$2"
    local tmp_dir=""
    local root=""

    tmp_dir="$(mktemp -d)"
    root="$(extract_archive_root "${archive}" "${tmp_dir}")"
    rm -rf "${destination}"
    mkdir -p "$(dirname "${destination}")"
    mv "${root}" "${destination}"
    rm -rf "${tmp_dir}"
}

print_source_plan() {
    "${CHECK_SCRIPT}" --print-source-plan
}

verify_lock() {
    "${CHECK_SCRIPT}"
}

source_versions() {
    printf '%s\n' "${KING_LSQUIC_COMMIT}" "${KING_LSQUIC_BORINGSSL_COMMIT}" \
        "${KING_LSQUIC_LS_QPACK_COMMIT}" "${KING_LSQUIC_LS_HPACK_COMMIT}"
}

verify_current() {
    verify_lock >/dev/null
    [[ -f "${SOURCE_DIR}/king-source-versions" ]] || return 1
    [[ "$(cat "${SOURCE_DIR}/king-source-versions")" == "$(source_versions)" ]] || return 1
    test -f "${SOURCE_DIR}/lsquic/include/lsquic.h"
    test -f "${SOURCE_DIR}/boringssl/include/openssl/ssl.h"
    test -f "${SOURCE_DIR}/lsquic/${KING_LSQUIC_LS_QPACK_PATH}/lsqpack.h"
    test -f "${SOURCE_DIR}/lsquic/${KING_LSQUIC_LS_HPACK_PATH}/lshpack.h"
}

case "${MODE}" in
    --verify-lock)
        verify_lock
        exit 0
        ;;
    --verify-current)
        verify_current
        echo "LSQUIC source cache is pinned and current: ${SOURCE_DIR}"
        exit 0
        ;;
    --print-source-plan)
        print_source_plan
        exit 0
        ;;
esac

require_tool curl
require_tool tar

lsquic_archive="$(fetch_archive lsquic "${KING_LSQUIC_ARCHIVE_URL}" "${KING_LSQUIC_COMMIT}")"
boringssl_archive="$(fetch_archive boringssl "${KING_LSQUIC_BORINGSSL_ARCHIVE_URL}" "${KING_LSQUIC_BORINGSSL_COMMIT}")"
qpack_archive="$(fetch_archive ls-qpack "${KING_LSQUIC_LS_QPACK_ARCHIVE_URL}" "${KING_LSQUIC_LS_QPACK_COMMIT}")"
hpack_archive="$(fetch_archive ls-hpack "${KING_LSQUIC_LS_HPACK_ARCHIVE_URL}" "${KING_LSQUIC_LS_HPACK_COMMIT}")"

install_archive_root "${lsquic_archive}" "${SOURCE_DIR}/lsquic"
install_archive_root "${boringssl_archive}" "${SOURCE_DIR}/boringssl"
install_archive_root "${qpack_archive}" "${SOURCE_DIR}/lsquic/${KING_LSQUIC_LS_QPACK_PATH}"
install_archive_root "${hpack_archive}" "${SOURCE_DIR}/lsquic/${KING_LSQUIC_LS_HPACK_PATH}"

source_versions > "${SOURCE_DIR}/king-source-versions"
verify_current
cat <<EOF_DONE
LSQUIC source cache prepared under ${SOURCE_DIR}
LSQUIC: ${KING_LSQUIC_COMMIT}
BoringSSL: ${KING_LSQUIC_BORINGSSL_COMMIT}
ls-qpack: ${KING_LSQUIC_LS_QPACK_COMMIT}
ls-hpack: ${KING_LSQUIC_LS_HPACK_COMMIT}
EOF_DONE
