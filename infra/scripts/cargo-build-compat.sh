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
