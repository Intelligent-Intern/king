#!/usr/bin/env bash

set -euo pipefail

usage() {
    cat <<'EOF'
Usage: ./infra/scripts/verify-release-supply-chain.sh [--archive PATH | --artifact-dir DIR] [options]

Runs release-artifact supply-chain verification by combining:
  - deterministic archive + package checksum verification
  - manifest integrity verification
  - provenance hash verification against repo lock inputs

Options:
  --archive PATH              Verify one release archive
  --artifact-dir DIR          Verify every *.tar.gz archive in DIR
  --expected-git-commit SHA   Require manifest git_commit to equal SHA
  --allow-source-dirty        Allow manifest source_dirty=true (default: fail)
  -h, --help                  Show this help
EOF
}

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT_DIR="$(cd "${SCRIPT_DIR}/../.." && pwd)"
ARCHIVE_PATH=""
ARTIFACT_DIR=""
EXPECTED_GIT_COMMIT=""
ALLOW_SOURCE_DIRTY=0
PHP_BIN="${PHP_BIN:-php}"

archive_entry_path_is_safe() {
    local entry="$1"
    local normalized_entry=""

    if [[ -z "${entry}" || "${entry}" == /* ]]; then
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

while [[ $# -gt 0 ]]; do
    case "$1" in
        --archive)
            if [[ $# -lt 2 || -z "${2:-}" ]]; then
                echo "Missing value for --archive." >&2
                exit 1
            fi
            ARCHIVE_PATH="$2"
            shift 2
            ;;
        --artifact-dir)
            if [[ $# -lt 2 || -z "${2:-}" ]]; then
                echo "Missing value for --artifact-dir." >&2
                exit 1
            fi
            ARTIFACT_DIR="$2"
            shift 2
            ;;
        --expected-git-commit)
            if [[ $# -lt 2 || -z "${2:-}" ]]; then
                echo "Missing value for --expected-git-commit." >&2
                exit 1
            fi
            EXPECTED_GIT_COMMIT="$2"
            shift 2
            ;;
        --allow-source-dirty)
            ALLOW_SOURCE_DIRTY=1
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

if [[ -n "${ARCHIVE_PATH}" && -n "${ARTIFACT_DIR}" ]]; then
    echo "Use either --archive or --artifact-dir, not both." >&2
    exit 1
fi

if [[ -z "${ARCHIVE_PATH}" && -z "${ARTIFACT_DIR}" ]]; then
    echo "One of --archive or --artifact-dir is required." >&2
    usage >&2
    exit 1
fi

if ! command -v "${PHP_BIN}" >/dev/null 2>&1; then
    echo "Missing requested PHP binary: ${PHP_BIN}" >&2
    exit 1
fi

if [[ -n "${EXPECTED_GIT_COMMIT}" ]] && ! [[ "${EXPECTED_GIT_COMMIT}" =~ ^[0-9a-fA-F]{40}$ ]]; then
    echo "Expected git commit must be a 40-character hex SHA." >&2
    exit 1
fi

if [[ -n "${EXPECTED_GIT_COMMIT}" ]]; then
    EXPECTED_GIT_COMMIT="$(printf '%s' "${EXPECTED_GIT_COMMIT}" | tr '[:upper:]' '[:lower:]')"
fi

declare -a ARCHIVES=()

if [[ -n "${ARCHIVE_PATH}" ]]; then
    ARCHIVE_PATH="$(cd "$(dirname "${ARCHIVE_PATH}")" && pwd)/$(basename "${ARCHIVE_PATH}")"
    ARCHIVES+=("${ARCHIVE_PATH}")
else
    if [[ "${ARTIFACT_DIR}" != /* ]]; then
        ARTIFACT_DIR="${ROOT_DIR}/${ARTIFACT_DIR}"
    fi
    if [[ ! -d "${ARTIFACT_DIR}" ]]; then
        echo "Artifact directory does not exist: ${ARTIFACT_DIR}" >&2
        exit 1
    fi
    mapfile -t ARCHIVES < <(find "${ARTIFACT_DIR}" -maxdepth 1 -type f -name '*.tar.gz' | LC_ALL=C sort)
fi

if [[ "${#ARCHIVES[@]}" -eq 0 ]]; then
    echo "No release archives found to verify." >&2
    exit 1
fi

for archive in "${ARCHIVES[@]}"; do
    manifest_listing=""
    manifest_entry=""

    if [[ ! -f "${archive}" ]]; then
        echo "Missing archive: ${archive}" >&2
        exit 1
    fi

    PHP_BIN="${PHP_BIN}" "${SCRIPT_DIR}/verify-release-package.sh" --archive "${archive}"

    manifest_listing="$(tar -tzf "${archive}")"
    manifest_entry="$(printf '%s\n' "${manifest_listing}" | LC_ALL=C sed -n '/\/manifest\.json$/p' | head -n 1)"
    if [[ -z "${manifest_entry}" ]]; then
        echo "Could not locate manifest.json in archive: ${archive}" >&2
        exit 1
    fi

    if ! archive_entry_path_is_safe "${manifest_entry}"; then
        echo "Unsafe manifest entry path: ${manifest_entry}" >&2
        exit 1
    fi

    manifest_json="$(tar -xOf "${archive}" -- "${manifest_entry}")"

    MANIFEST_JSON="${manifest_json}" \
    MANIFEST_ARCHIVE_PATH="${archive}" \
    EXPECTED_GIT_COMMIT="${EXPECTED_GIT_COMMIT}" \
    ALLOW_SOURCE_DIRTY="${ALLOW_SOURCE_DIRTY}" \
    PROVENANCE_LSQUIC_BOOTSTRAP_LOCK="${SCRIPT_DIR}/lsquic-bootstrap.lock" \
    "${PHP_BIN}" <<'PHP'
<?php

declare(strict_types=1);

$manifestJson = getenv('MANIFEST_JSON');
$archivePath = getenv('MANIFEST_ARCHIVE_PATH');
$expectedCommit = getenv('EXPECTED_GIT_COMMIT');
$allowSourceDirty = getenv('ALLOW_SOURCE_DIRTY') === '1';
$stderr = defined('STDERR') ? STDERR : fopen('php://stderr', 'wb');

$fail = static function (string $message) use ($stderr): void {
    if (is_resource($stderr)) {
        fwrite($stderr, $message);
    } else {
        echo $message;
    }
    exit(1);
};

if (!is_string($manifestJson) || $manifestJson === '') {
    $fail("Missing manifest payload.\n");
}

$manifest = json_decode($manifestJson, true, 512, JSON_THROW_ON_ERROR);
$gitCommit = $manifest['git_commit'] ?? null;
$gitShort = $manifest['git_short'] ?? null;
$sourceDirty = $manifest['source_dirty'] ?? null;
$provenance = $manifest['provenance'] ?? null;
$dependencyProvenance = $manifest['dependency_provenance'] ?? null;
$http3Stack = $manifest['http3_stack'] ?? null;

if (!is_string($gitCommit) || preg_match('/^[0-9a-f]{40}$/', $gitCommit) !== 1) {
    $fail("Manifest git_commit is invalid for {$archivePath}.\n");
}

if (!is_string($gitShort) || $gitShort !== substr($gitCommit, 0, 12)) {
    $fail("Manifest git_short does not match git_commit for {$archivePath}.\n");
}

if (!is_bool($sourceDirty)) {
    $fail("Manifest source_dirty flag is invalid for {$archivePath}.\n");
}

if (!$allowSourceDirty && $sourceDirty) {
    $fail("Manifest source_dirty must be false for release artifacts: {$archivePath}.\n");
}

if (is_string($expectedCommit) && $expectedCommit !== '' && $gitCommit !== $expectedCommit) {
    $fail("Manifest git_commit mismatch for {$archivePath}.\n");
}

if (!is_array($provenance)) {
    $fail("Manifest provenance section is missing for {$archivePath}.\n");
}

$lockPath = getenv('PROVENANCE_LSQUIC_BOOTSTRAP_LOCK');
if (!is_string($lockPath) || $lockPath === '' || !is_file($lockPath)) {
    $fail("Missing local provenance input for lsquic_bootstrap_lock_sha256.\n");
}

$lockValues = [];
foreach (file($lockPath, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
    if (preg_match('/^([A-Z0-9_]+)="([^"]*)"$/', $line, $matches) === 1) {
        $lockValues[$matches[1]] = $matches[2];
    }
}

$readLock = static function (string $key) use ($lockValues, $fail): string {
    $value = $lockValues[$key] ?? null;
    if (!is_string($value) || $value === '') {
        $fail("Missing lock value for {$key}.\n");
    }

    return $value;
};

$required = [
    'lsquic_bootstrap_lock_sha256' => hash_file('sha256', $lockPath),
    'lsquic_archive_sha256' => $readLock('KING_LSQUIC_ARCHIVE_SHA256'),
    'boringssl_archive_sha256' => $readLock('KING_LSQUIC_BORINGSSL_ARCHIVE_SHA256'),
    'ls_qpack_archive_sha256' => $readLock('KING_LSQUIC_LS_QPACK_ARCHIVE_SHA256'),
    'ls_hpack_archive_sha256' => $readLock('KING_LSQUIC_LS_HPACK_ARCHIVE_SHA256'),
];

foreach ($required as $key => $actualHash) {
    $expectedHash = $provenance[$key] ?? null;
    if (!is_string($expectedHash) || preg_match('/^[A-Fa-f0-9]{64}$/', $expectedHash) !== 1) {
        $fail("Invalid manifest provenance hash for {$key}.\n");
    }
    $expectedHash = strtolower($expectedHash);

    if ($actualHash !== $expectedHash) {
        $fail("Provenance hash mismatch for {$key} in {$archivePath}.\n");
    }
}

$expectedComponents = ['lsquic', 'boringssl', 'ls-qpack', 'ls-hpack'];
if (
    !is_array($http3Stack)
    || ($http3Stack['transport'] ?? null) !== 'lsquic'
    || ($http3Stack['tls'] ?? null) !== 'boringssl'
    || ($http3Stack['components'] ?? null) !== $expectedComponents
) {
    $fail("Manifest HTTP/3 stack metadata is invalid for {$archivePath}.\n");
}

if (!is_array($dependencyProvenance)) {
    $fail("Manifest dependency_provenance section is missing for {$archivePath}.\n");
}

$expectedDependencyProvenance = [
    'lsquic' => [
        'repo_url' => $readLock('KING_LSQUIC_REPO_URL'),
        'version' => $readLock('KING_LSQUIC_TAG'),
        'commit' => $readLock('KING_LSQUIC_COMMIT'),
        'archive_url' => $readLock('KING_LSQUIC_ARCHIVE_URL'),
        'archive_sha256' => $readLock('KING_LSQUIC_ARCHIVE_SHA256'),
        'archive_bytes' => (int) $readLock('KING_LSQUIC_ARCHIVE_BYTES'),
    ],
    'boringssl' => [
        'repo_url' => $readLock('KING_LSQUIC_BORINGSSL_REPO_URL'),
        'version' => $readLock('KING_LSQUIC_BORINGSSL_TAG'),
        'commit' => $readLock('KING_LSQUIC_BORINGSSL_COMMIT'),
        'archive_url' => $readLock('KING_LSQUIC_BORINGSSL_ARCHIVE_URL'),
        'archive_sha256' => $readLock('KING_LSQUIC_BORINGSSL_ARCHIVE_SHA256'),
        'archive_bytes' => (int) $readLock('KING_LSQUIC_BORINGSSL_ARCHIVE_BYTES'),
    ],
    'ls-qpack' => [
        'path' => $readLock('KING_LSQUIC_LS_QPACK_PATH'),
        'repo_url' => $readLock('KING_LSQUIC_LS_QPACK_REPO_URL'),
        'version' => 'gitlink',
        'commit' => $readLock('KING_LSQUIC_LS_QPACK_COMMIT'),
        'archive_url' => $readLock('KING_LSQUIC_LS_QPACK_ARCHIVE_URL'),
        'archive_sha256' => $readLock('KING_LSQUIC_LS_QPACK_ARCHIVE_SHA256'),
        'archive_bytes' => (int) $readLock('KING_LSQUIC_LS_QPACK_ARCHIVE_BYTES'),
    ],
    'ls-hpack' => [
        'path' => $readLock('KING_LSQUIC_LS_HPACK_PATH'),
        'repo_url' => $readLock('KING_LSQUIC_LS_HPACK_REPO_URL'),
        'version' => 'gitlink',
        'commit' => $readLock('KING_LSQUIC_LS_HPACK_COMMIT'),
        'archive_url' => $readLock('KING_LSQUIC_LS_HPACK_ARCHIVE_URL'),
        'archive_sha256' => $readLock('KING_LSQUIC_LS_HPACK_ARCHIVE_SHA256'),
        'archive_bytes' => (int) $readLock('KING_LSQUIC_LS_HPACK_ARCHIVE_BYTES'),
    ],
];

foreach ($expectedDependencyProvenance as $componentName => $expectedValues) {
    $component = $dependencyProvenance[$componentName] ?? null;
    if (!is_array($component)) {
        $fail("Manifest dependency provenance is missing for {$componentName} in {$archivePath}.\n");
    }

    foreach ($expectedValues as $key => $expectedValue) {
        if (($component[$key] ?? null) !== $expectedValue) {
            $fail("Manifest dependency provenance mismatch for {$componentName}.{$key} in {$archivePath}.\n");
        }
    }
}

echo "supply-chain provenance ok: {$archivePath}\n";
PHP
done

echo "Release supply-chain verification passed for ${#ARCHIVES[@]} archive(s)."
