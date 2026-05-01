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
  --allow-legacy-http3-metadata  Permit compatibility archives with legacy Quiche HTTP/3 manifest metadata
EOF
}

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ARCHIVE_PATH=""
PHP_BIN="${PHP_BIN:-php}"
PACKAGE_DIR=""
ALLOW_MISSING_PROVENANCE=0
ALLOW_LEGACY_HTTP3_METADATA=0

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
        --allow-legacy-http3-metadata)
            ALLOW_LEGACY_HTTP3_METADATA=1
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

required_dirs=(
    "bin"
    "docs"
    "modules"
)
required_paths=(
    "SHA256SUMS"
    "bin/smoke.php"
    "bin/smoke.sh"
    "docs/INSTALL.md"
    "manifest.json"
    "modules/king.so"
)

if [[ "${ALLOW_MISSING_PROVENANCE}" != "1" ]]; then
    required_dirs+=("runtime")
    required_paths+=("runtime/liblsquic.so")
fi

for required_dir in "${required_dirs[@]}"; do
    assert_package_directory "${required_dir}"
done

for required_path in "${required_paths[@]}"; do
    assert_package_regular_file "${required_path}"
done

if [[ ! -x "${PACKAGE_DIR}/bin/smoke.sh" ]]; then
    echo "Packaged smoke script is not executable." >&2
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
ALLOW_LEGACY_HTTP3_METADATA="${ALLOW_LEGACY_HTTP3_METADATA}" \
"${PHP_BIN}" <<'PHP'
<?php

declare(strict_types=1);

$packageDir = getenv('PACKAGE_DIR');
$allowMissingProvenance = getenv('ALLOW_MISSING_PROVENANCE') === '1';
$allowLegacyHttp3Metadata = getenv('ALLOW_LEGACY_HTTP3_METADATA') === '1';
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

$legacyHttp3Tokens = [
    'qui' . 'che',
    'KING_' . 'QUI' . 'CHE',
    'lib' . 'qui' . 'che',
    'qui' . 'che-server',
    'qui' . 'che-bootstrap',
    'qui' . 'che-workspace',
    'Cargo.toml',
    'Cargo.lock',
];

$containsLegacyHttp3Metadata = static function (mixed $value) use (&$containsLegacyHttp3Metadata, $legacyHttp3Tokens): bool {
    if (is_string($value)) {
        foreach ($legacyHttp3Tokens as $token) {
            if (stripos($value, $token) !== false) {
                return true;
            }
        }

        return false;
    }

    if (!is_array($value)) {
        return false;
    }

    foreach ($value as $key => $child) {
        if (is_string($key) && $containsLegacyHttp3Metadata($key)) {
            return true;
        }

        if ($containsLegacyHttp3Metadata($child)) {
            return true;
        }
    }

    return false;
};

$assertLegacyHttp3Free = static function (mixed $value, string $path) use (&$assertLegacyHttp3Free, $legacyHttp3Tokens): void {
    if (is_string($value)) {
        foreach ($legacyHttp3Tokens as $token) {
            if (stripos($value, $token) !== false) {
                fwrite(STDERR, "Manifest contains legacy HTTP/3 metadata at {$path}.\n");
                exit(1);
            }
        }

        return;
    }

    if (!is_array($value)) {
        return;
    }

    foreach ($value as $key => $child) {
        $keyPath = $path . '[' . (is_int($key) ? $key : (string) $key) . ']';
        if (is_string($key)) {
            $assertLegacyHttp3Free($key, $keyPath . ':key');
        }

        $assertLegacyHttp3Free($child, $keyPath);
    }
};

$manifestHasLegacyHttp3Metadata = $containsLegacyHttp3Metadata($manifest);
if (!$allowLegacyHttp3Metadata) {
    $assertLegacyHttp3Free($manifest, 'manifest');
}

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

$expectedComponents = ['lsquic', 'boringssl', 'ls-qpack', 'ls-hpack'];
$dependencyProvenanceHashKeys = [
    'lsquic' => 'lsquic_archive_sha256',
    'boringssl' => 'boringssl_archive_sha256',
    'ls-qpack' => 'ls_qpack_archive_sha256',
    'ls-hpack' => 'ls_hpack_archive_sha256',
];
$provenance = $manifest['provenance'] ?? null;
if (!is_array($provenance)) {
    if ($allowMissingProvenance) {
        $provenance = null;
    } else {
        fwrite(STDERR, "Manifest provenance metadata is missing.\n");
        exit(1);
    }
}

if (is_array($provenance) && $manifestHasLegacyHttp3Metadata) {
    if (!$allowLegacyHttp3Metadata) {
        fwrite(STDERR, "Manifest contains legacy HTTP/3 metadata.\n");
        exit(1);
    }
} elseif (is_array($provenance)) {
    $artifacts = $manifest['artifacts'] ?? null;
    if (!is_array($artifacts)) {
        fwrite(STDERR, "Manifest artifacts metadata is missing.\n");
        exit(1);
    }

    $kingModuleArtifact = $artifacts['modules/king.so'] ?? null;
    if (
        !is_array($kingModuleArtifact)
        || ($kingModuleArtifact['kind'] ?? null) !== 'php_extension_module'
        || ($kingModuleArtifact['http3_stack'] ?? null) !== 'lsquic-boringssl'
    ) {
        fwrite(STDERR, "Manifest king.so artifact metadata is invalid.\n");
        exit(1);
    }

    $lsquicRuntimeArtifact = $artifacts['runtime/liblsquic.so'] ?? null;
    if (!is_array($lsquicRuntimeArtifact)) {
        if (!$allowMissingProvenance) {
            fwrite(STDERR, "Manifest LSQUIC runtime artifact metadata is missing.\n");
            exit(1);
        }
    } else {
        if (
            ($lsquicRuntimeArtifact['kind'] ?? null) !== 'http3_transport_runtime'
            || ($lsquicRuntimeArtifact['tls'] ?? null) !== 'boringssl'
            || !in_array('lsquic', $lsquicRuntimeArtifact['provides'] ?? [], true)
        ) {
            fwrite(STDERR, "Manifest LSQUIC runtime artifact metadata is invalid.\n");
            exit(1);
        }
    }

    $http3Stack = $manifest['http3_stack'] ?? null;
    if (
        !is_array($http3Stack)
        || ($http3Stack['transport'] ?? null) !== 'lsquic'
        || ($http3Stack['tls'] ?? null) !== 'boringssl'
        || ($http3Stack['bootstrap_lock'] ?? null) !== 'infra/scripts/lsquic-bootstrap.lock'
        || ($http3Stack['bootstrap_script'] ?? null) !== 'infra/scripts/bootstrap-lsquic.sh'
        || !is_array($http3Stack['components'] ?? null)
    ) {
        fwrite(STDERR, "Manifest HTTP/3 stack metadata is invalid.\n");
        exit(1);
    }

    if ($http3Stack['components'] !== $expectedComponents) {
        fwrite(STDERR, "Manifest HTTP/3 component list is invalid.\n");
        exit(1);
    }

    foreach ([
        'lsquic_bootstrap_lock_sha256',
        'lsquic_archive_sha256',
        'boringssl_archive_sha256',
        'ls_qpack_archive_sha256',
        'ls_hpack_archive_sha256',
    ] as $provenanceKey) {
        $value = $provenance[$provenanceKey] ?? null;
        if (!is_string($value) || preg_match('/^[A-Fa-f0-9]{64}$/', $value) !== 1) {
            fwrite(STDERR, "Manifest provenance hash is invalid for {$provenanceKey}.\n");
            exit(1);
        }
        $provenance[$provenanceKey] = strtolower($value);
    }

    $dependencyProvenance = $manifest['dependency_provenance'] ?? null;
    if (!is_array($dependencyProvenance)) {
        fwrite(STDERR, "Manifest dependency_provenance metadata is missing.\n");
        exit(1);
    }

    if (array_keys($dependencyProvenance) !== $expectedComponents) {
        fwrite(STDERR, "Manifest dependency provenance component list is invalid.\n");
        exit(1);
    }

    foreach ($expectedComponents as $componentName) {
        $component = $dependencyProvenance[$componentName] ?? null;
        if (!is_array($component)) {
            fwrite(STDERR, "Manifest dependency provenance is missing for {$componentName}.\n");
            exit(1);
        }

        foreach (['name', 'repo_url', 'version', 'commit', 'archive_url', 'archive_sha256'] as $requiredKey) {
            if (!is_string($component[$requiredKey] ?? null) || $component[$requiredKey] === '') {
                fwrite(STDERR, "Manifest dependency provenance key {$componentName}.{$requiredKey} is invalid.\n");
                exit(1);
            }
        }

        $componentCommit = (string) $component['commit'];
        if (preg_match('/^[A-Fa-f0-9]{40}$/', $componentCommit) !== 1) {
            fwrite(STDERR, "Manifest dependency provenance commit is invalid for {$componentName}.\n");
            exit(1);
        }
        $component['commit'] = strtolower($componentCommit);

        $componentArchiveHash = (string) $component['archive_sha256'];
        if (preg_match('/^[A-Fa-f0-9]{64}$/', $componentArchiveHash) !== 1) {
            fwrite(STDERR, "Manifest dependency provenance archive hash is invalid for {$componentName}.\n");
            exit(1);
        }
        $component['archive_sha256'] = strtolower($componentArchiveHash);

        $provenanceKey = $dependencyProvenanceHashKeys[$componentName];
        if (($provenance[$provenanceKey] ?? null) !== $component['archive_sha256']) {
            fwrite(STDERR, "Manifest dependency provenance hash does not match top-level provenance for {$componentName}.\n");
            exit(1);
        }

        if (!is_int($component['archive_bytes'] ?? null) || $component['archive_bytes'] <= 0) {
            fwrite(STDERR, "Manifest dependency provenance archive size is invalid for {$componentName}.\n");
            exit(1);
        }

        if (!is_array($component['license_files'] ?? null) || count($component['license_files']) < 1) {
            fwrite(STDERR, "Manifest dependency provenance license files are invalid for {$componentName}.\n");
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
