#!/usr/bin/env bash

set -euo pipefail

usage() {
    cat <<'EOF'
Usage: ./scripts/verify-release-package.sh --archive PATH

Verifies a packaged King release archive by checking:
  - outer archive SHA256
  - extracted file presence
  - package-local SHA256SUMS
  - manifest.json file metadata
  - packaged smoke test
EOF
}

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ARCHIVE_PATH=""
PHP_BIN="${PHP_BIN:-php}"

while [[ $# -gt 0 ]]; do
    case "$1" in
        --archive)
            if [[ $# -lt 2 ]]; then
                echo "Missing value for --archive." >&2
                exit 1
            fi
            ARCHIVE_PATH="$2"
            shift 2
            ;;
        -h|--help)
            usage
            exit 0
            ;;
        *)
            echo "Unknown option: $1" >&2
            usage >&2
            exit 1
            ;;
    esac
done

if [[ -z "${ARCHIVE_PATH}" ]]; then
    echo "The --archive option is required." >&2
    usage >&2
    exit 1
fi

ARCHIVE_PATH="$(cd "$(dirname "${ARCHIVE_PATH}")" && pwd)/$(basename "${ARCHIVE_PATH}")"
ARCHIVE_SHA_PATH="${ARCHIVE_PATH}.sha256"

if [[ ! -f "${ARCHIVE_PATH}" ]]; then
    echo "Missing archive: ${ARCHIVE_PATH}" >&2
    exit 1
fi

if [[ ! -f "${ARCHIVE_SHA_PATH}" ]]; then
    echo "Missing archive checksum file: ${ARCHIVE_SHA_PATH}" >&2
    exit 1
fi

TEMP_DIR="$(mktemp -d)"
trap 'rm -rf "${TEMP_DIR}"' EXIT

echo "Verifying archive checksum..."
(
    cd "$(dirname "${ARCHIVE_PATH}")"
    sha256sum -c "$(basename "${ARCHIVE_SHA_PATH}")"
)

echo "Extracting package..."
tar -xzf "${ARCHIVE_PATH}" -C "${TEMP_DIR}"

shopt -s nullglob
package_entries=("${TEMP_DIR}"/*)
shopt -u nullglob

if [[ "${#package_entries[@]}" -ne 1 || ! -d "${package_entries[0]}" ]]; then
    echo "Expected exactly one extracted package directory." >&2
    exit 1
fi

PACKAGE_DIR="${package_entries[0]}"

for required_path in \
    "SHA256SUMS" \
    "bin/smoke.sh" \
    "docs/INSTALL.md" \
    "manifest.json" \
    "modules/king.so" \
    "runtime/libquiche.so" \
    "runtime/quiche-server"
do
    if [[ ! -e "${PACKAGE_DIR}/${required_path}" ]]; then
        echo "Missing packaged file: ${required_path}" >&2
        exit 1
    fi
done

if [[ ! -x "${PACKAGE_DIR}/bin/smoke.sh" ]]; then
    echo "Packaged smoke script is not executable." >&2
    exit 1
fi

if [[ ! -x "${PACKAGE_DIR}/runtime/quiche-server" ]]; then
    echo "Packaged quiche-server is not executable." >&2
    exit 1
fi

echo "Verifying package-local checksums..."
(
    cd "${PACKAGE_DIR}"
    sha256sum -c SHA256SUMS
)

echo "Verifying manifest metadata..."
PACKAGE_DIR="${PACKAGE_DIR}" "${PHP_BIN}" <<'PHP'
<?php

declare(strict_types=1);

$packageDir = getenv('PACKAGE_DIR');
if (!is_string($packageDir) || $packageDir === '') {
    fwrite(STDERR, "Missing PACKAGE_DIR environment variable.\n");
    exit(1);
}

$manifestPath = $packageDir . '/manifest.json';
$manifest = json_decode((string) file_get_contents($manifestPath), true, 512, JSON_THROW_ON_ERROR);

if (($manifest['package_format'] ?? null) !== 1) {
    fwrite(STDERR, "Unexpected manifest package_format.\n");
    exit(1);
}

if (($manifest['name'] ?? null) !== 'king') {
    fwrite(STDERR, "Unexpected manifest package name.\n");
    exit(1);
}

if (!is_string($manifest['version'] ?? null) || $manifest['version'] === '') {
    fwrite(STDERR, "Manifest version is missing.\n");
    exit(1);
}

if (!is_array($manifest['platform'] ?? null) || !is_string($manifest['platform']['php_api'] ?? null) || $manifest['platform']['php_api'] === '') {
    fwrite(STDERR, "Manifest platform metadata is incomplete.\n");
    exit(1);
}

$files = $manifest['files'] ?? null;
if (!is_array($files)) {
    fwrite(STDERR, "Manifest files payload is missing.\n");
    exit(1);
}

foreach ($files as $relativePath => $meta) {
    if (!is_array($meta)) {
        fwrite(STDERR, "Invalid manifest metadata entry for {$relativePath}.\n");
        exit(1);
    }

    $fullPath = $packageDir . '/' . $relativePath;
    if (!is_file($fullPath)) {
        fwrite(STDERR, "Manifest file is missing on disk: {$relativePath}.\n");
        exit(1);
    }

    $expectedHash = $meta['sha256'] ?? null;
    $expectedSize = $meta['size'] ?? null;
    $expectedMode = $meta['mode'] ?? null;
    $actualHash = hash_file('sha256', $fullPath);
    $actualSize = filesize($fullPath);
    $actualMode = substr(sprintf('%o', fileperms($fullPath)), -4);

    if (!is_string($expectedHash) || $expectedHash !== $actualHash) {
        fwrite(STDERR, "Manifest SHA256 mismatch for {$relativePath}.\n");
        exit(1);
    }

    if (!is_int($expectedSize) || $expectedSize !== $actualSize) {
        fwrite(STDERR, "Manifest size mismatch for {$relativePath}.\n");
        exit(1);
    }

    if (!is_string($expectedMode) || $expectedMode !== $actualMode) {
        fwrite(STDERR, "Manifest mode mismatch for {$relativePath}.\n");
        exit(1);
    }
}

echo "manifest ok\n";
PHP

echo "Running packaged smoke test..."
"${PACKAGE_DIR}/bin/smoke.sh"

echo "Release package verified: ${ARCHIVE_PATH}"
