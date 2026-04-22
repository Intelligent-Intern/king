#!/usr/bin/env bash

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT_DIR="$(cd "${SCRIPT_DIR}/../.." && pwd)"

cd "${ROOT_DIR}"

mapfile -t tracked_files < <(git ls-files)

allowed_cargo_manifests=(
  "extension/tests/http3_ticket_server/Cargo.lock"
  "extension/tests/http3_ticket_server/Cargo.toml"
)

is_allowed_tracked_file() {
  local regex="$1"
  local path="$2"

  if [[ "${regex}" == '(^|/)Cargo\.(toml|lock)$' ]]; then
    for allowed in "${allowed_cargo_manifests[@]}"; do
      if [[ "${path}" == "${allowed}" ]]; then
        return 0
      fi
    done
  fi

  return 1
}

descriptions=(
  "Vite cache directories must not be versioned (.vite)"
  "CMake build directories must not be versioned (CMakeFiles)"
  "CMake cache files must not be versioned (CMakeCache.txt)"
  "CMake build output roots must not be versioned (cmake-build-*)"
  "CMake install manifests must not be versioned (cmake_install.cmake)"
  "CMake compile database must not be versioned (compile_commands.json)"
  "Transient native build binaries must not be versioned"
  "Quiche source/vendor trees must not be versioned"
  "Cargo home/vendor caches must not be versioned"
  "Cargo build target directories must not be versioned"
  "Legacy Quiche runtime artifacts must not be versioned"
  "Unclassified Cargo manifests/locks must not be versioned"
)

patterns=(
  '(^|/)\.vite/'
  '(^|/)CMakeFiles/'
  '(^|/)CMakeCache\.txt$'
  '(^|/)cmake-build-[^/]+/'
  '(^|/)cmake_install\.cmake$'
  '(^|/)compile_commands\.json$'
  '(^|/)(build|target|modules)/.*\.(o|obj|lo|la|a|so|dylib|dll|exe)$'
  '(^|/)quiche(/|$)|(^|/)extension/quiche(/|$)'
  '(^|/)\.cargo(/|$)|(^|/)cargo-(home|registry|git)(/|$)'
  '(^|/)target/'
  '(^|/)(libquiche\.(so|dylib|dll|a|la)|quiche-server(\.exe)?)$'
  '(^|/)Cargo\.(toml|lock)$'
)

failed=0
total_matches=0

for i in "${!descriptions[@]}"; do
  desc="${descriptions[i]}"
  regex="${patterns[i]}"

  mapfile -t raw_matches < <(printf '%s\n' "${tracked_files[@]}" | grep -E "${regex}" || true)
  matches=()
  for path in "${raw_matches[@]}"; do
    if is_allowed_tracked_file "${regex}" "${path}"; then
      continue
    fi
    matches+=("${path}")
  done

  if [[ "${#matches[@]}" -eq 0 ]]; then
    continue
  fi

  failed=1
  total_matches=$((total_matches + ${#matches[@]}))
  echo "${desc}" >&2
  printf ' - %s\n' "${matches[@]}" >&2
done

if [[ "${failed}" -ne 0 ]]; then
  echo "Repo artifact hygiene check failed with ${total_matches} offending tracked path(s)." >&2
  exit 1
fi

echo "Repo artifact hygiene check passed."
