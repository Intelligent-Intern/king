#!/usr/bin/env bash

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT_DIR="$(cd "${SCRIPT_DIR}/../.." && pwd)"
EXT_DIR="${ROOT_DIR}/extension"

cd "${ROOT_DIR}"

shopt -s nullglob nocaseglob
 misplaced_headers=()
for f in extension/src/**/*.h extension/src/*.h; do
    if [[ "$f" != "extension/include/"* ]] && [[ "$f" != "extension/config.h" ]]; then
        misplaced_headers+=("$f")
    fi
done
for f in extension/lsquic; do
    if [[ -d "$f" ]]; then
        for h in "$f"/**/*.h; do
            :
        done
    fi
done
shopt -u nullglob nocaseglob

if [[ ${#misplaced_headers[@]} -ne 0 ]]; then
    echo "Project-owned extension headers must live under extension/include." >&2
    echo "The only normal exception is generated extension/config.h." >&2
    for h in "${misplaced_headers[@]}"; do
        printf ' - %s\n' "$h" >&2
    done
    exit 1
fi

header_count="$(find extension/include -type f -name '*.h' 2>/dev/null | wc -l | tr -d '[:space:]')"
echo "Include layout check passed (${header_count} headers under extension/include)."