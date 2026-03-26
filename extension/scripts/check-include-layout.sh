#!/usr/bin/env bash

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
EXT_DIR="$(cd "${SCRIPT_DIR}/.." && pwd)"
ROOT_DIR="$(cd "${EXT_DIR}/.." && pwd)"

cd "${ROOT_DIR}"

mapfile -t misplaced_headers < <(
    find extension -type f -name '*.h' \
        ! -path 'extension/include/*' \
        ! -path 'extension/config.h' \
        | sort
)

if [[ "${#misplaced_headers[@]}" -ne 0 ]]; then
    echo "Project-owned extension headers must live under extension/include." >&2
    echo "The only normal exception is generated extension/config.h." >&2
    printf ' - %s\n' "${misplaced_headers[@]}" >&2
    exit 1
fi

header_count="$(find extension/include -type f -name '*.h' | wc -l | tr -d '[:space:]')"
echo "Include layout check passed (${header_count} headers under extension/include)."
