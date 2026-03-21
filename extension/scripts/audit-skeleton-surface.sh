#!/usr/bin/env bash

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
EXT_DIR="$(cd "${SCRIPT_DIR}/.." && pwd)"

cd "${EXT_DIR}"

if rg -n 'src_bak/.*\.(c|lo|dep)' config.m4 Makefile Makefile.objects >/dev/null 2>&1; then
    echo "Active build files still reference src_bak/ sources." >&2
    rg -n 'src_bak/.*\.(c|lo|dep)' config.m4 Makefile Makefile.objects >&2
    exit 1
fi

mapfile -t build_sources < <(rg -o 'src/[A-Za-z0-9_./-]+\.c' config.m4 | sort -u)

missing_sources=0
for source_file in "${build_sources[@]}"; do
    if [[ ! -f "${source_file}" ]]; then
        echo "Missing compiled source: ${source_file}" >&2
        missing_sources=1
    fi
done

echo "Compiled source files: ${#build_sources[@]}"
echo "Compiled top-level source groups:"
printf '%s\n' "${build_sources[@]}" | awk -F/ '{print $2}' | sort -u | sed 's/^/ - /'

echo "Header-only include roots without a matching src/<root>/ directory:"
while IFS= read -r include_root; do
    case "${include_root}" in
        config|validation)
            continue
            ;;
    esac

    if [[ ! -d "src/${include_root}" ]]; then
        echo " - ${include_root}"
    fi
done < <(find include -mindepth 1 -maxdepth 1 -type d -printf '%f\n' | sort)

echo "Stubbed entry points:"
rg '^PHP_FUNCTION\(([^)]+)\)' -or '$1' src/stubs/all_stubs.c | sed 's/^/ - /'

if [[ "${missing_sources}" -ne 0 ]]; then
    exit 1
fi
