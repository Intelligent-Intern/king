#!/usr/bin/env bash
# Install the optional, hash-pinned SaxonC core beside build artifacts only.
set -euo pipefail
root_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
runtime_dir="${KING_SAXONC_PREFIX:-$root_dir/extension/build/runtime/saxonc}"
if [[ "$(uname -s)/$(uname -m)" != "Linux/x86_64" ]]; then
    echo 'Pinned automatic bootstrap currently supports Linux x86_64 only. Set KING_SAXONC_LIBRARY for another platform.' >&2
    exit 1
fi
url='https://downloads.saxonica.com/SaxonC/HE/13/SaxonCHE-linux-x86_64-13-1-1.zip'
checksum='66bdeba467fcfa0be1f78c7ae477524773a5e39821a159884a7fd1aab6301d16'
mkdir -p "$runtime_dir"
work_dir="$(mktemp -d)"
trap 'rm -rf "$work_dir"' EXIT
curl --fail --location --proto '=https' --tlsv1.2 "$url" -o "$work_dir/saxon.zip"
printf '%s  %s\n' "$checksum" "$work_dir/saxon.zip" | sha256sum --check --status
unzip -q "$work_dir/saxon.zip" -d "$work_dir"
cp "$work_dir/SaxonCHE-linux-x86_64-13-1-1/SaxonCCoreHE/lib/libsaxonc-core-he.so.13.1.1" "$runtime_dir/libsaxonc-core-he.so.13.1.1"
ln -sfn libsaxonc-core-he.so.13.1.1 "$runtime_dir/libsaxonc-core-he.so"
cp -R "$work_dir/SaxonCHE-linux-x86_64-13-1-1/notices" "$runtime_dir/"
printf '%s\n' "$checksum" > "$runtime_dir/distribution.sha256"
printf 'KING_SAXONC_LIBRARY=%q\n' "$runtime_dir/libsaxonc-core-he.so"
