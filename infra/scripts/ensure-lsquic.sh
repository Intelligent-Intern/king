#!/usr/bin/env bash

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
MANIFEST_PATH="${1:-}"

if [[ -n "${MANIFEST_PATH}" ]] && [[ ! -f "${MANIFEST_PATH}" ]]; then
    echo "Toolchain check for lsquic manifest failed before build." >&2
    exit 1
fi

if ! command -v cargo >/dev/null 2>&1 || ! command -v rustc >/dev/null 2>&1; then
    echo "Cargo/rustc are missing in PATH." >&2
    exit 1
fi

if [[ -f "${MANIFEST_PATH}" ]]; then
    if [[ -x "${SCRIPT_DIR}/cargo-build-compat.sh" ]]; then
        set +e
        KING_LSQUIC_TOOLCHAIN_CONFIRM="${KING_LSQUIC_TOOLCHAIN_CONFIRM:-no}" \
            "${SCRIPT_DIR}/cargo-build-compat.sh" check "${MANIFEST_PATH}" >/dev/null 2>&1
        compat_status=$?
        set -e
        
        if [[ $compat_status -ne 0 ]]; then
            if [[ "${KING_LSQUIC_TOOLCHAIN_CONFIRM:-}" == "no" ]]; then
                echo "Cargo toolchain does not support lockfile-v4 cleanly." >&2
                echo "Aborted per confirmation (KING_LSQUIC_TOOLCHAIN_CONFIRM=no)." >&2
                exit 1
            fi
        fi
    fi
fi

exit 0