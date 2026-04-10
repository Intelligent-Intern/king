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

if [[ -n "${EXPECTED_GIT_COMMIT}" ]] && ! [[ "${EXPECTED_GIT_COMMIT}" =~ ^[0-9a-f]{40}$ ]]; then
    echo "Expected git commit must be a 40-character lowercase hex SHA." >&2
    exit 1
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
    if [[ ! -f "${archive}" ]]; then
        echo "Missing archive: ${archive}" >&2
        exit 1
    fi

    "${SCRIPT_DIR}/verify-release-package.sh" --archive "${archive}"

    manifest_entry="$(tar -tzf "${archive}" | LC_ALL=C grep -m1 '/manifest\.json$' || true)"
    if [[ -z "${manifest_entry}" ]]; then
        echo "Could not locate manifest.json in archive: ${archive}" >&2
        exit 1
    fi

    manifest_json="$(tar -xOf "${archive}" "${manifest_entry}")"

    MANIFEST_JSON="${manifest_json}" \
    MANIFEST_ARCHIVE_PATH="${archive}" \
    EXPECTED_GIT_COMMIT="${EXPECTED_GIT_COMMIT}" \
    ALLOW_SOURCE_DIRTY="${ALLOW_SOURCE_DIRTY}" \
    PROVENANCE_QUICHE_BOOTSTRAP_LOCK="${SCRIPT_DIR}/quiche-bootstrap.lock" \
    PROVENANCE_TOOLCHAIN_LOCK="${SCRIPT_DIR}/toolchain.lock" \
    PROVENANCE_QUICHE_WORKSPACE_LOCK="${SCRIPT_DIR}/quiche-workspace.Cargo.lock" \
    php <<'PHP'
<?php

declare(strict_types=1);

$manifestJson = getenv('MANIFEST_JSON');
$archivePath = getenv('MANIFEST_ARCHIVE_PATH');
$expectedCommit = getenv('EXPECTED_GIT_COMMIT');
$allowSourceDirty = getenv('ALLOW_SOURCE_DIRTY') === '1';

if (!is_string($manifestJson) || $manifestJson === '') {
    fwrite(STDERR, "Missing manifest payload.\n");
    exit(1);
}

$manifest = json_decode($manifestJson, true, 512, JSON_THROW_ON_ERROR);
$gitCommit = $manifest['git_commit'] ?? null;
$gitShort = $manifest['git_short'] ?? null;
$sourceDirty = $manifest['source_dirty'] ?? null;
$provenance = $manifest['provenance'] ?? null;

if (!is_string($gitCommit) || preg_match('/^[0-9a-f]{40}$/', $gitCommit) !== 1) {
    fwrite(STDERR, "Manifest git_commit is invalid for {$archivePath}.\n");
    exit(1);
}

if (!is_string($gitShort) || $gitShort !== substr($gitCommit, 0, 12)) {
    fwrite(STDERR, "Manifest git_short does not match git_commit for {$archivePath}.\n");
    exit(1);
}

if (!is_bool($sourceDirty)) {
    fwrite(STDERR, "Manifest source_dirty flag is invalid for {$archivePath}.\n");
    exit(1);
}

if (!$allowSourceDirty && $sourceDirty) {
    fwrite(STDERR, "Manifest source_dirty must be false for release artifacts: {$archivePath}.\n");
    exit(1);
}

if (is_string($expectedCommit) && $expectedCommit !== '' && $gitCommit !== $expectedCommit) {
    fwrite(STDERR, "Manifest git_commit mismatch for {$archivePath}.\n");
    exit(1);
}

if (!is_array($provenance)) {
    fwrite(STDERR, "Manifest provenance section is missing for {$archivePath}.\n");
    exit(1);
}

$required = [
    'quiche_bootstrap_lock_sha256' => getenv('PROVENANCE_QUICHE_BOOTSTRAP_LOCK'),
    'toolchain_lock_sha256' => getenv('PROVENANCE_TOOLCHAIN_LOCK'),
    'quiche_workspace_lock_sha256' => getenv('PROVENANCE_QUICHE_WORKSPACE_LOCK'),
];

foreach ($required as $key => $path) {
    if (!is_string($path) || $path === '' || !is_file($path)) {
        fwrite(STDERR, "Missing local provenance input for {$key}.\n");
        exit(1);
    }

    $expectedHash = $provenance[$key] ?? null;
    if (!is_string($expectedHash) || preg_match('/^[a-f0-9]{64}$/', $expectedHash) !== 1) {
        fwrite(STDERR, "Invalid manifest provenance hash for {$key}.\n");
        exit(1);
    }

    $actualHash = hash_file('sha256', $path);
    if ($actualHash !== $expectedHash) {
        fwrite(STDERR, "Provenance hash mismatch for {$key} in {$archivePath}.\n");
        exit(1);
    }
}

echo "supply-chain provenance ok: {$archivePath}\n";
PHP
done

echo "Release supply-chain verification passed for ${#ARCHIVES[@]} archive(s)."
