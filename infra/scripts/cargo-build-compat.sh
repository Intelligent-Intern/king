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
lockfile_path=""
removed_lockfile_error=""

is_lockfile_version4_error() {
    local output="$1"

    if printf '%s\n' "${output}" | grep -qi "lock file version 4"; then
        if printf '%s\n' "${output}" | grep -qiE "next-lockfile-bump|requires.*next.*lockfile|unknown lockfile version"; then
            return 0
        fi
    fi

    return 1
}

run_without_lockfile_once() {
    local -n _cmd_ref="$1"
    local -n _lockfile_ref="$2"
    local _status=0
    local capture_file=""

    if [[ -z "${_lockfile_ref}" || ! -f "${_lockfile_ref}" ]]; then
        return 1
    fi

    if ! mv "${_lockfile_ref}" "${_lockfile_ref}.king-backup"; then
        return 1
    fi

    capture_file="$(mktemp)"
    run_command_capture _status "${capture_file}" "${_cmd_ref[@]}"
    removed_lockfile_error="$(cat "${capture_file}")"
    rm -f "${capture_file}"

    mv "${_lockfile_ref}.king-backup" "${_lockfile_ref}"

    return "${_status}"
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

extract_manifest_path() {
    local -n _cmd_ref="$1"
    local i=0
    local arg

    lockfile_path=""

    for i in "${!_cmd_ref[@]}"; do
        arg="${_cmd_ref[$i]}"

        if [[ "${arg}" == --manifest-path ]]; then
            lockfile_path="${_cmd_ref[$((i + 1))]:-}"
            break
        fi

        if [[ "${arg}" == --manifest-path=* ]]; then
            lockfile_path="${arg#--manifest-path=}"
            break
        fi
    done

    if [[ -n "${lockfile_path}" ]]; then
        lockfile_path="${lockfile_path%/*}/Cargo.lock"
    fi
}

tmp="$(mktemp)"
run_command_capture last_status "${tmp}" "${cmd[@]}"
if [[ "${last_status}" -ne 0 ]]; then
    status="${last_status}"
    error_output="$(cat "${tmp}")"
    rm -f "${tmp}"

    if is_lockfile_version4_error "${error_output}"; then
        echo "Cargo lockfile format is unsupported with current toolchain; retrying without --locked." >&2

        fallback_cmd=()
        for arg in "${cmd[@]}"; do
            if [[ "${arg}" == "--locked" ]]; then
                continue
            fi
            fallback_cmd+=( "${arg}" )
        done

        if [[ "${#fallback_cmd[@]}" -eq 0 ]]; then
            echo "Failed to construct fallback cargo command." >&2
            printf '%s\n' "${error_output}" >&2
            exit "${status}"
        fi

        tmp="$(mktemp)"
        run_command_capture status "${tmp}" "${fallback_cmd[@]}"
        error_output="$(cat "${tmp}")"

        if [[ "${status}" -ne 0 ]]; then
            printf 'Fallback cargo command failed with exit code %s.\n' "${status}" >&2
            rm -f "${tmp}"

            extract_manifest_path fallback_cmd
            if run_without_lockfile_once fallback_cmd lockfile_path; then
                echo "Retrying without lockfile constraint and without lockfile file." >&2
                exit 0
            fi
            if [[ -n "${removed_lockfile_error}" ]]; then
                printf '%s\n' "${removed_lockfile_error}" >&2
            else
                printf '%s\n' "${error_output}" >&2
            fi

            exit "${status}"
        fi

        rm -f "${tmp}"
        exit 0
    fi

    printf '%s\n' "${error_output}" >&2
    exit "${status}"
fi

rm -f "${tmp}"
