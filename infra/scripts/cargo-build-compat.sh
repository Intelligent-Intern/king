#!/usr/bin/env bash

set -euo pipefail

if [[ $# -lt 1 ]]; then
    echo "Usage: $(basename "$0") <cargo-command...>" >&2
    echo "Example: $(basename "$0") cargo build --manifest-path ... --locked" >&2
    exit 2
fi

cmd=( "$@" )
last_status=0
status=0
retry_status=0
retry_output=""
confirm="${KING_LSQUIC_TOOLCHAIN_CONFIRM:-prompt}"
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
MANIFEST_PATH=""

ensure_confirmation() {
    local response=""

    if [[ "${confirm}" != "yes" && "${confirm}" != "no" && "${confirm}" != "prompt" ]]; then
        echo "Invalid KING_LSQUIC_TOOLCHAIN_CONFIRM='${confirm}'. Use 'prompt', 'yes' or 'no'." >&2
        return 1
    fi

    if [[ "${confirm}" == "yes" ]]; then
        return 0
    fi

    if [[ "${confirm}" == "no" ]]; then
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

print_install_solution() {
    cat <<'EOF'
Missing compatible Rust toolchain for this cargo command.

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

After installation, rerun the command.
EOF
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

    if [[ ${installed} -eq 1 ]] && command -v cargo >/dev/null 2>&1 && command -v rustc >/dev/null 2>&1; then
        if [[ "${ensure_nightly}" -eq 1 ]] && ! rustup toolchain list --installed 2>/dev/null | awk '{print $1}' | grep -q '^nightly'; then
            echo "Warning: nightly toolchain installation did not complete; lockfile-v4 fallback will likely fail." >&2
        fi

        rustup default stable
        return 0
    fi

    return 1
}

extract_manifest_path() {
    local i=0
    for (( i = 0; i < ${#cmd[@]}; i++ )); do
        if [[ "${cmd[$i]}" == "--manifest-path" ]]; then
            MANIFEST_PATH="${cmd[$((i + 1))]:-}"
            return 0
        fi
    done
    return 1
}

attempt_toolchain_fixup() {
    if [[ -z "${MANIFEST_PATH}" ]]; then
        return 1
    fi

    if [[ -n "${MANIFEST_PATH}" ]] && [[ ! -f "${MANIFEST_PATH}" ]]; then
        echo "Missing manifest path for lockfile compatibility check: ${MANIFEST_PATH}" >&2
        return 1
    fi

    if [[ ! -x "${SCRIPT_DIR}/ensure-lsquic.sh" ]]; then
        if ! command -v cargo >/dev/null 2>&1 || ! command -v rustc >/dev/null 2>&1; then
            echo "cargo/rustc are missing in PATH. King requires a compatible Rust toolchain." >&2
            print_install_solution >&2
            if ! ensure_confirmation; then
                echo "Aborted per confirmation (KING_LSQUIC_TOOLCHAIN_CONFIRM=${confirm})." >&2
                return 1
            fi
            if ! run_rust_upgrade; then
                return 1
            fi
        elif ! ensure_confirmation; then
            echo "Aborted per confirmation (KING_LSQUIC_TOOLCHAIN_CONFIRM=${confirm})." >&2
            return 1
        else
            if ! run_rust_upgrade; then
                echo "Failed to auto-upgrade rust toolchain for lockfile-v4 compatibility." >&2
                return 1
            fi
        fi
        return 0
    fi

    KING_LSQUIC_TOOLCHAIN_CONFIRM="${confirm}" \
        "${SCRIPT_DIR}/ensure-lsquic.sh" "${MANIFEST_PATH}"
}

is_lockfile_version4_error() {
    local output="$1"

    if printf '%s\n' "${output}" | grep -qi "lock file version 4"; then
        if printf '%s\n' "${output}" | grep -qiE "next-lockfile-bump|requires.*next.*lockfile|unknown lockfile version"; then
            return 0
        fi
    fi

    return 1
}

run_with_nightly_lockfile_bump() {
    local -n _cmd_ref="$1"
    local _status_var="$2"
    local _output_var="$3"
    local -a command_prefix=()
    local -a fallback_cmd=()
    local has_next_lockfile_bump=0
    local capture_file=""
    local tmp_status=0
    local tmp_output=""
    local i=0
    local start_at=1

    if [[ "${_cmd_ref[0]}" != "cargo" ]]; then
        return 1
    fi

    if [[ "${_cmd_ref[1]:-}" == +* ]]; then
        start_at=2
    fi

    for (( i = start_at; i < ${#_cmd_ref[@]}; i++ )); do
        if [[ "${_cmd_ref[$i]}" == "-Z" && "${_cmd_ref[$((i + 1))]:-}" == "next-lockfile-bump" ]]; then
            has_next_lockfile_bump=1
        fi
    done

    command_prefix=( "${_cmd_ref[0]}" "+nightly" )
    fallback_cmd=( "${command_prefix[@]}" )
    if [[ "${has_next_lockfile_bump}" -eq 0 ]]; then
        fallback_cmd+=( "-Z" "next-lockfile-bump" )
    fi

    fallback_cmd+=( "${_cmd_ref[@]:start_at}" )

    capture_file="$(mktemp)"
    run_command_capture tmp_status "${capture_file}" "${fallback_cmd[@]}"
    tmp_output="$(cat "${capture_file}")"
    rm -f "${capture_file}"

    printf -v "${_status_var}" '%s' "${tmp_status}"
    printf -v "${_output_var}" '%s' "${tmp_output}"

    return "${tmp_status}"
}

run_command_capture() {
    local -n _status_ref="$1"
    local _capture_file="$2"
    shift 2
    local -a _command=( "$@" )

    set +e
    "${_command[@]}" 2>"${_capture_file}"
    _status_ref=$?
    set -e
}

tmp="$(mktemp)"
run_command_capture last_status "${tmp}" "${cmd[@]}"
if [[ "${last_status}" -ne 0 ]]; then
    status="${last_status}"
    error_output="$(cat "${tmp}")"
    rm -f "${tmp}"

    if is_lockfile_version4_error "${error_output}"; then
        echo "Cargo lockfile format is unsupported with current toolchain; retrying with nightly lockfile-bump compatibility." >&2

        extract_manifest_path
        if ! attempt_toolchain_fixup; then
            printf 'Fallback cargo command failed with exit code %s.\n' "${last_status}" >&2
            printf '%s\n' "${error_output}" >&2
            cat <<'EOF' >&2
This project requires a cargo toolchain that can read lockfile v4.
Either install a modern rust toolchain (or nightly with `-Z next-lockfile-bump` support)
or rerun with a compatible build environment.
EOF
            exit 1
        fi
        run_with_nightly_lockfile_bump cmd retry_status retry_output
        status="${retry_status}"

        if [[ "${status}" -eq 0 ]]; then
            exit 0
        fi

        printf 'Fallback cargo command failed with exit code %s.\n' "${status}" >&2
        printf '%s\n' "${retry_output}" >&2

        cat <<'EOF' >&2
This project requires a cargo toolchain that can read lockfile v4.
Either install a modern rust toolchain (or nightly with `-Z next-lockfile-bump` support)
or prebuild the release artifacts on a toolchain with lockfile-v4 support.
EOF
        exit "${status}"
    fi

    printf '%s\n' "${error_output}" >&2
    exit "${status}"
fi

rm -f "${tmp}"
