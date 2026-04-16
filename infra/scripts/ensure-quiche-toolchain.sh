#!/usr/bin/env bash

set -euo pipefail

usage() {
    cat <<'EOF'
Usage: ensure-lsquic.sh <manifest-path>

Checks whether the current environment can execute the pinned lsquic build.
If cargo/rustc are missing or lockfile-v4 support is missing, the user is
prompted for upgrade confirmation unless KING_LSQUIC_TOOLCHAIN_CONFIRM is set.
If unset, KING_LSQUIC_TOOLCHAIN_CONFIRM defaults to `prompt`.

Accepted values:
  prompt: Ask the user (TTY only, non-interactive aborts)
  yes:    Attempt automatic toolchain setup/upgrade
  no:     Fail fast with install instructions
EOF
}

if [[ "${1:-}" == "" || "${1:-}" == "--help" || "${1:-}" == "-h" ]]; then
    usage
    exit 0
fi

MANIFEST_PATH="$1"
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
CARGO_COMPAT_SCRIPT="${SCRIPT_DIR}/cargo-build-compat.sh"

print_install_solution() {
    cat <<'EOF'
Missing compatible Rust toolchain for QUIC build.

Use one of:

  1) Fast path (recommended):
     curl --proto '=https' --tlsv1.2 -sSf https://sh.rustup.rs | sh -s -- -y
     . "$HOME/.cargo/env"
     rustup update stable
     rustup toolchain install nightly
     rustup default stable

  2) Or install your distro package (examples):
     - apt: sudo apt-get install -y rustc cargo
     - dnf: sudo dnf install -y rust cargo
     - pacman: sudo pacman -S --noconfirm rust

After installation, rerun make.
EOF
}

prompt_or_default() {
    local action="${KING_LSQUIC_TOOLCHAIN_CONFIRM:-prompt}"
    local response=""

    if [[ "${action}" != "yes" && "${action}" != "no" && "${action}" != "prompt" ]]; then
        echo "Invalid KING_LSQUIC_TOOLCHAIN_CONFIRM='${action}'. Use 'prompt', 'yes' or 'no'." >&2
        return 1
    fi

    if [[ "${action}" == "yes" ]]; then
        return 0
    fi

    if [[ "${action}" == "no" ]]; then
        return 1
    fi

    if [[ ! -t 0 ]]; then
        return 1
    fi

    while true; do
        read -r -p "Do you want to retry after setting up/upgrading Rust now? [y/N]: " response
        case "${response}" in
            [Yy]|[Yy][Ee][Ss])
                return 0
                ;;
            [Nn]|[Nn][Oo]|"")
                return 1
                ;;
            *)
                echo "Please answer y or n."
                ;;
        esac
    done
}

run_rust_upgrade() {
    local installed=0
    local ensure_nightly=0

    if command -v rustup >/dev/null 2>&1; then
        rustup update stable
        if ! rustup toolchain list --installed 2>/dev/null | awk '{print $1}' | grep -q '^nightly'; then
            rustup toolchain install nightly
            ensure_nightly=1
        fi
        installed=1
    elif command -v curl >/dev/null 2>&1; then
        curl --proto '=https' --tlsv1.2 -sSf https://sh.rustup.rs | sh -s -- -y
        installed=1
    else
        echo "Cannot auto-upgrade: rustup/curl are not available." >&2
        return 1
    fi

    if [[ -f "${HOME}/.cargo/env" ]]; then
        # shellcheck disable=SC1091
        . "${HOME}/.cargo/env"
    fi

    if [[ "${installed}" -eq 1 ]] && command -v cargo >/dev/null 2>&1 && command -v rustc >/dev/null 2>&1; then
        if [[ "${ensure_nightly}" -eq 1 ]] && ! rustup toolchain list --installed 2>/dev/null | awk '{print $1}' | grep -q '^nightly'; then
            echo "Warning: nightly toolchain installation did not complete; lockfile-v4 fallback likely still fails." >&2
        fi
        rustup default stable
        return 0
    fi

    return 1
}

check_lockfile_compat() {
    local toolchain="${1:-}"
    local -a cargo_cmd=("${CARGO_COMPAT_SCRIPT}")
    local tmp_file
    local cargo_status=0

    if [[ ! -f "${MANIFEST_PATH}" ]]; then
        echo "Missing manifest: ${MANIFEST_PATH}" >&2
        return 1
    fi

    if [[ -n "${toolchain}" ]]; then
        cargo_cmd+=( "${toolchain}" )
    fi

    cargo_cmd+=( cargo metadata --locked --format-version=1 --manifest-path "${MANIFEST_PATH}" )

    tmp_file="$(mktemp)"
    set +e
    CARGO_TARGET_DIR="$(dirname "${MANIFEST_PATH}")/../target" \
        "${cargo_cmd[@]}" \
        >"${tmp_file}" 2>&1
    cargo_status=$?
    set -e

    if [[ ${cargo_status} -eq 0 ]]; then
        rm -f "${tmp_file}"
        return 0
    fi

    if grep -qi "lock file version 4" "${tmp_file}"; then
        rm -f "${tmp_file}"
        return 2
    fi

    echo "cargo metadata failed for ${MANIFEST_PATH}." >&2
    cat "${tmp_file}" >&2
    rm -f "${tmp_file}"
    return 1
}

if ! command -v cargo >/dev/null 2>&1 || ! command -v rustc >/dev/null 2>&1; then
    echo "cargo/rustc are missing in PATH. King requires a compatible Rust toolchain to build QUIC artifacts." >&2
    print_install_solution >&2
    if ! prompt_or_default; then
        echo "Aborted per confirmation (KING_LSQUIC_TOOLCHAIN_CONFIRM=${KING_LSQUIC_TOOLCHAIN_CONFIRM:-prompt})." >&2
        exit 1
    fi
    if ! run_rust_upgrade; then
        exit 1
    fi
fi

check_lockfile_compat_status=0
if check_lockfile_compat; then
    :
else
    check_lockfile_compat_status=$?
    case ${check_lockfile_compat_status} in
        2)
            echo "Cargo toolchain does not support lockfile-v4 cleanly." >&2
            print_install_solution >&2
            if ! prompt_or_default; then
                echo "Aborted per confirmation (KING_LSQUIC_TOOLCHAIN_CONFIRM=${KING_LSQUIC_TOOLCHAIN_CONFIRM:-prompt})." >&2
                exit 1
            fi
            if ! run_rust_upgrade; then
                exit 1
            fi
            if ! check_lockfile_compat; then
                if ! check_lockfile_compat "+nightly"; then
                    echo "Toolchain still cannot process lockfile-v4 after upgrade." >&2
                    exit 1
                fi
            fi
            ;;
        *)
            echo "Toolchain check for lsquic manifest failed before build." >&2
            exit 1
            ;;
    esac
fi

exit 0
