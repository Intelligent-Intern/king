#!/usr/bin/env bash

set -euo pipefail

usage() {
    cat <<'EOF'
Usage: ./infra/scripts/bootstrap-lsquic.sh [--verify-lock|--verify-current]

Bootstraps the pinned QUIC dependency checkout used by King builds.
Without flags, the script fetches the exact pinned lsquic revision, syncs the
expected BoringSSL submodule revision, and normalizes the qlog-dancer
wirefilter dependency to the pinned git revision recorded in
`lsquic-bootstrap.lock`.
EOF
}

if [[ "${1:-}" == "--help" || "${1:-}" == "-h" ]]; then
    usage
    exit 0
fi

MODE="${1:-bootstrap}"
case "${MODE}" in
    bootstrap|--verify-lock|--verify-current)
        ;;
    *)
        echo "Unknown mode: ${MODE}" >&2
        usage >&2
        exit 1
        ;;
esac

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT_DIR="$(cd "${SCRIPT_DIR}/../.." && pwd)"
EXT_DIR="${ROOT_DIR}/extension"
LOCK_FILE="${SCRIPT_DIR}/lsquic-bootstrap.lock"
LOCK_TEMPLATE="${SCRIPT_DIR}/lsquic-workspace.Cargo.lock"
LSQUIC_DIR="${KING_LSQUIC_DIR:-${ROOT_DIR}/lsquic废弃}"

if [[ ! -f "${LOCK_FILE}" ]]; then
    echo "Missing lsquic bootstrap lock file: ${LOCK_FILE}" >&2
    exit 1
fi

if [[ ! -f "${LOCK_TEMPLATE}" ]]; then
    echo "Missing pinned lsquic workspace lockfile: ${LOCK_TEMPLATE}" >&2
    exit 1
fi

# shellcheck source=/dev/null
source "${LOCK_FILE}"

require_tool() {
    if ! command -v "${1}" >/dev/null 2>&1; then
        echo "Required tool '${1}' is not installed." >&2
        exit 1
    fi
}

validate_hex_pin() {
    local name="${1}"
    local value="${2}"

    if [[ ! "${value}" =~ ^[0-9a-f]{40}$ ]]; then
        echo "${name} must be a 40-character lowercase git SHA." >&2
        exit 1
    fi
}

validate_lock_file() {
    if [[ -z "${KING_LSQUIC_REPO_URL:-}" ]]; then
        echo "KING_LSQUIC_REPO_URL is empty in ${LOCK_FILE}." >&2
        exit 1
    fi

    validate_hex_pin "KING_LSQUIC_COMMIT" "${KING_LSQUIC_COMMIT:-}"
    validate_hex_pin "KING_LSQUIC_BORINGSSL_COMMIT" "${KING_LSQUIC_BORINGSSL_COMMIT:-}"
    validate_hex_pin "KING_LSQUIC_WIREFILTER_COMMIT" "${KING_LSQUIC_WIREFILTER_COMMIT:-}"
}

ensure_lsquic_git_checkout() {
    mkdir -p "$(dirname "${LSQUIC_DIR}")"

    if [[ -e "${LSQUIC_DIR}" && ! -d "${LSQUIC_DIR}/.git" ]]; then
        rm -rf "${LSQUIC_DIR}"
    fi

    if [[ ! -d "${LSQUIC_DIR}/.git" ]]; then
        rm -rf "${LSQUIC_DIR}"
        git init -q "${LSQUIC_DIR}"
        git -C "${LSQUIC_DIR}" remote add origin "${KING_LSQUIC_REPO_URL}"
    else
        if git -C "${LSQUIC_DIR}" remote get-url origin >/dev/null 2>&1; then
            git -C "${LSQUIC_DIR}" remote set-url origin "${KING_LSQUIC_REPO_URL}"
        else
            git -C "${LSQUIC_DIR}" remote add origin "${KING_LSQUIC_REPO_URL}"
        fi
    fi
}

checkout_pinned_lsquic() {
    git -C "${LSQUIC_DIR}" fetch --depth 1 origin "${KING_LSQUIC_COMMIT}"
    git -C "${LSQUIC_DIR}" checkout --detach --force "${KING_LSQUIC_COMMIT}"
}

sync_boringssl_submodule() {
    git -C "${LSQUIC_DIR}" submodule sync --recursive
    git -C "${LSQUIC_DIR}" submodule update --init --recursive lsquic/deps/boringssl
}

install_pinned_workspace_lock() {
    install -m 0644 "${LOCK_TEMPLATE}" "${LSQUIC_DIR}/Cargo.lock"
}

apply_wirefilter_pin() {
    local manifest_path="${LSQUIC_DIR}/qlog-dancer/Cargo.toml"
    local lock_path="${LSQUIC_DIR}/Cargo.lock"
    local expected_manifest_line
    local expected_lock_source

    expected_manifest_line="wirefilter-engine = { git = \"https://github.com/cloudflare/wirefilter.git\", rev = \"${KING_LSQUIC_WIREFILTER_COMMIT}\" }"
    expected_lock_source="git+https://github.com/cloudflare/wirefilter.git?rev=${KING_LSQUIC_WIREFILTER_COMMIT}#${KING_LSQUIC_WIREFILTER_COMMIT}"

    if [[ ! -f "${manifest_path}" || ! -f "${lock_path}" ]]; then
        echo "Pinned lsquic checkout is incomplete under ${LSQUIC_DIR}." >&2
        exit 1
    fi

    perl -0pi -e \
        "s#^wirefilter-engine\\s*=\\s*\\{[^\\n]*wirefilter\\.git[^\\n]*\\}\$#${expected_manifest_line}#mg" \
        "${manifest_path}"

    perl -0pi -e \
        "s#git\\+https://github.com/cloudflare/wirefilter\\.git\\?(?:branch=master|rev=[0-9a-f]{40})#git+https://github.com/cloudflare/wirefilter.git?rev=${KING_LSQUIC_WIREFILTER_COMMIT}#g" \
        "${lock_path}"
}

verify_current_checkout() {
    local current_lsquic_commit
    local current_boringssl_commit
    local manifest_path="${LSQUIC_DIR}/qlog-dancer/Cargo.toml"
    local lock_path="${LSQUIC_DIR}/Cargo.lock"
    local expected_manifest_line
    local expected_lock_source

    if [[ ! -d "${LSQUIC_DIR}/.git" ]]; then
        echo "Pinned lsquic checkout is missing: ${LSQUIC_DIR}" >&2
        exit 1
    fi

    current_lsquic_commit="$(git -C "${LSQUIC_DIR}" rev-parse HEAD)"
    if [[ "${current_lsquic_commit}" != "${KING_LSQUIC_COMMIT}" ]]; then
        echo "Unexpected lsquic commit: ${current_lsquic_commit} (expected ${KING_LSQUIC_COMMIT})." >&2
        exit 1
    fi

    if [[ ! -d "${LSQUIC_DIR}/lsquic/deps/boringssl" ]]; then
        echo "Pinned BoringSSL checkout is missing under ${LSQUIC_DIR}." >&2
        exit 1
    fi

    current_boringssl_commit="$(git -C "${LSQUIC_DIR}/lsquic/deps/boringssl" rev-parse HEAD)"
    if [[ "${current_boringssl_commit}" != "${KING_LSQUIC_BORINGSSL_COMMIT}" ]]; then
        echo "Unexpected BoringSSL commit: ${current_boringssl_commit} (expected ${KING_LSQUIC_BORINGSSL_COMMIT})." >&2
        exit 1
    fi

    expected_manifest_line="wirefilter-engine = { git = \"https://github.com/cloudflare/wirefilter.git\", rev = \"${KING_LSQUIC_WIREFILTER_COMMIT}\" }"
    expected_lock_source="git+https://github.com/cloudflare/wirefilter.git?rev=${KING_LSQUIC_WIREFILTER_COMMIT}#${KING_LSQUIC_WIREFILTER_COMMIT}"

    if ! grep -Fq "${expected_manifest_line}" "${manifest_path}"; then
        echo "qlog-dancer manifest is not pinned to the expected wirefilter revision." >&2
        exit 1
    fi

    if grep -Fq 'branch = "master"' "${manifest_path}"; then
        echo "qlog-dancer manifest still contains a branch-based wirefilter dependency." >&2
        exit 1
    fi

    if ! grep -Fq "${expected_lock_source}" "${lock_path}"; then
        echo "Cargo.lock is not pinned to the expected wirefilter revision." >&2
        exit 1
    fi

    if ! cmp -s <(grep -v 'wirefilter.git?' "${LOCK_TEMPLATE}") <(grep -v 'wirefilter.git?' "${lock_path}"); then
        echo "Pinned lsquic workspace lockfile drifted from the tracked template." >&2
        exit 1
    fi
}

print_verified_state() {
    echo "Pinned lsquic commit: ${KING_LSQUIC_COMMIT}"
    echo "Pinned boringssl commit: ${KING_LSQUIC_BORINGSSL_COMMIT}"
    echo "Pinned wirefilter commit: ${KING_LSQUIC_WIREFILTER_COMMIT}"
}

validate_lock_file

case "${MODE}" in
    --verify-lock)
        print_verified_state
        exit 0
        ;;
    --verify-current)
        verify_current_checkout
        print_verified_state
        exit 0
        ;;
esac

require_tool git
ensure_lsquic_git_checkout
checkout_pinned_lsquic
sync_boringssl_submodule
install_pinned_workspace_lock
apply_wirefilter_pin
verify_current_checkout
print_verified_state
