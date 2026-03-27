#!/usr/bin/env bash

set -euo pipefail

usage() {
    cat <<'EOF'
Usage: ./scripts/bootstrap-quiche.sh [--verify-lock|--verify-current]

Bootstraps the pinned QUIC dependency checkout used by King builds.
Without flags, the script fetches the exact pinned quiche revision, syncs the
expected BoringSSL submodule revision, and normalizes the qlog-dancer
wirefilter dependency to the pinned git revision recorded in
`quiche-bootstrap.lock`.
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
EXT_DIR="$(cd "${SCRIPT_DIR}/.." && pwd)"
ROOT_DIR="$(cd "${EXT_DIR}/.." && pwd)"
LOCK_FILE="${SCRIPT_DIR}/quiche-bootstrap.lock"
LOCK_TEMPLATE="${SCRIPT_DIR}/quiche-workspace.Cargo.lock"
QUICHE_DIR="${KING_QUICHE_DIR:-${ROOT_DIR}/quiche}"

if [[ ! -f "${LOCK_FILE}" ]]; then
    echo "Missing quiche bootstrap lock file: ${LOCK_FILE}" >&2
    exit 1
fi

if [[ ! -f "${LOCK_TEMPLATE}" ]]; then
    echo "Missing pinned quiche workspace lockfile: ${LOCK_TEMPLATE}" >&2
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
    if [[ -z "${KING_QUICHE_REPO_URL:-}" ]]; then
        echo "KING_QUICHE_REPO_URL is empty in ${LOCK_FILE}." >&2
        exit 1
    fi

    validate_hex_pin "KING_QUICHE_COMMIT" "${KING_QUICHE_COMMIT:-}"
    validate_hex_pin "KING_QUICHE_BORINGSSL_COMMIT" "${KING_QUICHE_BORINGSSL_COMMIT:-}"
    validate_hex_pin "KING_QUICHE_WIREFILTER_COMMIT" "${KING_QUICHE_WIREFILTER_COMMIT:-}"
}

ensure_quiche_git_checkout() {
    mkdir -p "$(dirname "${QUICHE_DIR}")"

    if [[ -e "${QUICHE_DIR}" && ! -d "${QUICHE_DIR}/.git" ]]; then
        rm -rf "${QUICHE_DIR}"
    fi

    if [[ ! -d "${QUICHE_DIR}/.git" ]]; then
        rm -rf "${QUICHE_DIR}"
        git init -q "${QUICHE_DIR}"
        git -C "${QUICHE_DIR}" remote add origin "${KING_QUICHE_REPO_URL}"
    else
        if git -C "${QUICHE_DIR}" remote get-url origin >/dev/null 2>&1; then
            git -C "${QUICHE_DIR}" remote set-url origin "${KING_QUICHE_REPO_URL}"
        else
            git -C "${QUICHE_DIR}" remote add origin "${KING_QUICHE_REPO_URL}"
        fi
    fi
}

checkout_pinned_quiche() {
    git -C "${QUICHE_DIR}" fetch --depth 1 origin "${KING_QUICHE_COMMIT}"
    git -C "${QUICHE_DIR}" checkout --detach --force "${KING_QUICHE_COMMIT}"
}

sync_boringssl_submodule() {
    git -C "${QUICHE_DIR}" submodule sync --recursive
    git -C "${QUICHE_DIR}" submodule update --init --recursive quiche/deps/boringssl
}

install_pinned_workspace_lock() {
    install -m 0644 "${LOCK_TEMPLATE}" "${QUICHE_DIR}/Cargo.lock"
}

apply_wirefilter_pin() {
    local manifest_path="${QUICHE_DIR}/qlog-dancer/Cargo.toml"
    local lock_path="${QUICHE_DIR}/Cargo.lock"
    local expected_manifest_line
    local expected_lock_source

    expected_manifest_line="wirefilter-engine = { git = \"https://github.com/cloudflare/wirefilter.git\", rev = \"${KING_QUICHE_WIREFILTER_COMMIT}\" }"
    expected_lock_source="git+https://github.com/cloudflare/wirefilter.git?rev=${KING_QUICHE_WIREFILTER_COMMIT}#${KING_QUICHE_WIREFILTER_COMMIT}"

    if [[ ! -f "${manifest_path}" || ! -f "${lock_path}" ]]; then
        echo "Pinned quiche checkout is incomplete under ${QUICHE_DIR}." >&2
        exit 1
    fi

    perl -0pi -e \
        "s#^wirefilter-engine\\s*=\\s*\\{[^\\n]*wirefilter\\.git[^\\n]*\\}\$#${expected_manifest_line}#mg" \
        "${manifest_path}"

    perl -0pi -e \
        "s#git\\+https://github.com/cloudflare/wirefilter\\.git\\?(?:branch=master|rev=[0-9a-f]{40})#git+https://github.com/cloudflare/wirefilter.git?rev=${KING_QUICHE_WIREFILTER_COMMIT}#g" \
        "${lock_path}"
}

verify_current_checkout() {
    local current_quiche_commit
    local current_boringssl_commit
    local manifest_path="${QUICHE_DIR}/qlog-dancer/Cargo.toml"
    local lock_path="${QUICHE_DIR}/Cargo.lock"
    local expected_manifest_line
    local expected_lock_source

    if [[ ! -d "${QUICHE_DIR}/.git" ]]; then
        echo "Pinned quiche checkout is missing: ${QUICHE_DIR}" >&2
        exit 1
    fi

    current_quiche_commit="$(git -C "${QUICHE_DIR}" rev-parse HEAD)"
    if [[ "${current_quiche_commit}" != "${KING_QUICHE_COMMIT}" ]]; then
        echo "Unexpected quiche commit: ${current_quiche_commit} (expected ${KING_QUICHE_COMMIT})." >&2
        exit 1
    fi

    if [[ ! -d "${QUICHE_DIR}/quiche/deps/boringssl" ]]; then
        echo "Pinned BoringSSL checkout is missing under ${QUICHE_DIR}." >&2
        exit 1
    fi

    current_boringssl_commit="$(git -C "${QUICHE_DIR}/quiche/deps/boringssl" rev-parse HEAD)"
    if [[ "${current_boringssl_commit}" != "${KING_QUICHE_BORINGSSL_COMMIT}" ]]; then
        echo "Unexpected BoringSSL commit: ${current_boringssl_commit} (expected ${KING_QUICHE_BORINGSSL_COMMIT})." >&2
        exit 1
    fi

    expected_manifest_line="wirefilter-engine = { git = \"https://github.com/cloudflare/wirefilter.git\", rev = \"${KING_QUICHE_WIREFILTER_COMMIT}\" }"
    expected_lock_source="git+https://github.com/cloudflare/wirefilter.git?rev=${KING_QUICHE_WIREFILTER_COMMIT}#${KING_QUICHE_WIREFILTER_COMMIT}"

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
        echo "Pinned quiche workspace lockfile drifted from the tracked template." >&2
        exit 1
    fi
}

print_verified_state() {
    echo "Pinned quiche commit: ${KING_QUICHE_COMMIT}"
    echo "Pinned boringssl commit: ${KING_QUICHE_BORINGSSL_COMMIT}"
    echo "Pinned wirefilter commit: ${KING_QUICHE_WIREFILTER_COMMIT}"
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
ensure_quiche_git_checkout
checkout_pinned_quiche
sync_boringssl_submodule
install_pinned_workspace_lock
apply_wirefilter_pin
verify_current_checkout
print_verified_state
