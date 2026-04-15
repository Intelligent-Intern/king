#!/usr/bin/env bash

set -euo pipefail

usage() {
    cat <<'EOF'
Usage: ./infra/scripts/verify-release-package.sh --archive PATH

Verifies a packaged King release archive by checking:
  - outer archive SHA256
  - extracted file presence
  - package-local SHA256SUMS
  - manifest.json file metadata
  - packaged smoke test

Options:
  --archive PATH                 Archive path to verify
  --allow-missing-provenance     Permit legacy manifests without provenance metadata
EOF
}

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ARCHIVE_PATH=""
PHP_BIN="${PHP_BIN:-php}"
PACKAGE_DIR=""
ALLOW_MISSING_PROVENANCE=0

archive_entry_path_is_safe() {
    local entry="$1"
    local normalized_entry=""

    if [[ -z "${entry}" ]]; then
        return 1
    fi

    if [[ "${entry}" == /* ]]; then
        return 1
    fi

    normalized_entry="${entry#./}"

    if [[ "${normalized_entry}" == -* ]]; then
        return 1
    fi

    if [[ "${normalized_entry}" =~ (^|/)-[^/]+(/|$) ]]; then
        return 1
    fi

    if [[ "${entry}" =~ (^|/)\.\.(/|$) ]]; then
        return 1
    fi

    if [[ "${entry}" == *$'\n'* || "${entry}" == *$'\r'* ]]; then
        return 1
    fi

    return 0
}

validate_archive_entries() {
    local archive_entries_output=""
    local archive_listing_output=""
    local entry=""
    local line=""
    local entry_type=""

    archive_entries_output="$(tar -tzf "${ARCHIVE_PATH}")" || return 1
    archive_listing_output="$(tar -tvzf "${ARCHIVE_PATH}")" || return 1

    while IFS= read -r entry; do
        if ! archive_entry_path_is_safe "${entry}"; then
            echo "Archive contains unsafe path entry: ${entry}" >&2
            return 1
        fi
    done <<< "${archive_entries_output}"

    while IFS= read -r line; do
        entry_type="${line:0:1}"
        case "${entry_type}" in
            l|h)
                echo "Archive contains disallowed link entry." >&2
                return 1
                ;;
        esac
    done <<< "${archive_listing_output}"

    return 0
}

package_path_is_within_root() {
    local path="$1"
    local resolved_path=""
    local resolved_root=""

    resolved_path="$(realpath -e "${path}")" || return 1
    resolved_root="$(realpath -e "${PACKAGE_DIR}")" || return 1

    case "${resolved_path}" in
        "${resolved_root}"|"${resolved_root}/"*)
            return 0
            ;;
    esac

    return 1
}

assert_package_directory() {
    local relative_path="$1"
    local full_path="${PACKAGE_DIR}/${relative_path}"

    if [[ -L "${full_path}" || ! -d "${full_path}" ]]; then
        echo "Packaged directory is invalid: ${relative_path}" >&2
        exit 1
    fi

    if ! package_path_is_within_root "${full_path}"; then
        echo "Packaged directory escapes the extracted package root: ${relative_path}" >&2
        exit 1
    fi
}

assert_package_regular_file() {
    local relative_path="$1"
    local full_path="${PACKAGE_DIR}/${relative_path}"

    if [[ -L "${full_path}" || ! -f "${full_path}" ]]; then
        echo "Packaged file is invalid: ${relative_path}" >&2
        exit 1
    fi

    if ! package_path_is_within_root "${full_path}"; then
        echo "Packaged file escapes the extracted package root: ${relative_path}" >&2
        exit 1
    fi
}

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
        --allow-missing-provenance)
            ALLOW_MISSING_PROVENANCE=1
            shift
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

echo "Validating archive entries..."
validate_archive_entries

echo "Extracting package..."
tar -xzf "${ARCHIVE_PATH}" -C "${TEMP_DIR}"

shopt -s nullglob
package_entries=("${TEMP_DIR}"/*)
shopt -u nullglob

if [[ "${#package_entries[@]}" -ne 1 || -L "${package_entries[0]}" || ! -d "${package_entries[0]}" ]]; then
    echo "Expected exactly one extracted package directory." >&2
    exit 1
fi

PACKAGE_DIR="${package_entries[0]}"

for required_dir in \
    "bin" \
    "docs" \
    "modules" \
    "runtime"
do
    assert_package_directory "${required_dir}"
done

for required_path in \
    "SHA256SUMS" \
    "bin/smoke.php" \
    "bin/smoke.sh" \
    "docs/INSTALL.md" \
    "manifest.json" \
    "modules/king.so" \
    "runtime/liblsquic-shim.so" \
    "runtime/lsquic"
do
    assert_package_regular_file "${required_path}"
done

if [[ ! -x "${PACKAGE_DIR}/bin/smoke.sh" ]]; then
    echo "Packaged smoke script is not executable." >&2
    exit 1
fi

if [[ ! -x "${PACKAGE_DIR}/runtime/lsquic" ]]; then
    echo "Packaged lsquic is not executable." >&2
    exit 1
fi

echo "Verifying package-local checksums..."
(
    cd "${PACKAGE_DIR}"
    sha256sum -c SHA256SUMS
)

echo "Verifying manifest metadata..."
PACKAGE_DIR="${PACKAGE_DIR}" \
ALLOW_MISSING_PROVENANCE="${ALLOW_MISSING_PROVENANCE}" \
"${PHP_BIN}" <<'PHP'
<?php

declare(strict_types=1);

$packageDir = getenv('PACKAGE_DIR');
$allowMissingProvenance = getenv('ALLOW_MISSING_PROVENANCE') === '1';
if (!is_string($packageDir) || $packageDir === '') {
    fwrite(STDERR, "Missing PACKAGE_DIR environment variable.\n");
    exit(1);
}

$resolvedPackageDir = realpath($packageDir);
if (!is_string($resolvedPackageDir) || $resolvedPackageDir === '') {
    fwrite(STDERR, "Failed to resolve extracted package directory.\n");
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

$provenance = $manifest['provenance'] ?? null;
if (!is_array($provenance)) {
    if ($allowMissingProvenance) {
        $provenance = null;
    } else {
        fwrite(STDERR, "Manifest provenance metadata is missing.\n");
        exit(1);
    }
}

if (is_array($provenance)) {
    foreach ([
        'lsquic_bootstrap_lock_sha256',
        'toolchain_lock_sha256',
        'lsquic_workspace_lock_sha256',
    ] as $provenanceKey) {
        $value = $provenance[$provenanceKey] ?? null;
        if (!is_string($value) || preg_match('/^[a-f0-9]{64}$/', $value) !== 1) {
            fwrite(STDERR, "Manifest provenance hash is invalid for {$provenanceKey}.\n");
            exit(1);
        }
    }
}

$files = $manifest['files'] ?? null;
if (!is_array($files)) {
    fwrite(STDERR, "Manifest files payload is missing.\n");
    exit(1);
}

foreach ($files as $relativePath => $meta) {
    if (
        !is_string($relativePath)
        || $relativePath === ''
        || str_starts_with($relativePath, '/')
        || preg_match('#(^|/)\.\.(/|$)#', $relativePath) === 1
    ) {
        fwrite(STDERR, "Manifest path is unsafe: {$relativePath}.\n");
        exit(1);
    }

    if (!is_array($meta)) {
        fwrite(STDERR, "Invalid manifest metadata entry for {$relativePath}.\n");
        exit(1);
    }

    $fullPath = $packageDir . '/' . $relativePath;
    if (is_link($fullPath) || !is_file($fullPath)) {
        fwrite(STDERR, "Manifest file is missing on disk: {$relativePath}.\n");
        exit(1);
    }

    $resolvedPath = realpath($fullPath);
    if (
        !is_string($resolvedPath)
        || !str_starts_with($resolvedPath, $resolvedPackageDir . DIRECTORY_SEPARATOR)
    ) {
        fwrite(STDERR, "Manifest file escapes the extracted package root: {$relativePath}.\n");
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
