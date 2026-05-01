#!/usr/bin/env bash

set -euo pipefail

usage() {
    cat <<'EOF'
Usage: ./infra/scripts/package-pie-source.sh [--output-dir DIR] [--release-tag TAG]

Creates the PIE pre-packaged source asset:
  dist/php_king-<version>-src.tgz

The archive contains the King source tree plus pinned LSQUIC/BoringSSL
provenance so PIE can build the extension from source with
`build-path = extension`.
EOF
}

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT_DIR="$(cd "${SCRIPT_DIR}/../.." && pwd)"
OUTPUT_DIR="${ROOT_DIR}/dist"
RELEASE_TAG="${KING_PIE_RELEASE_TAG:-}"

while [[ $# -gt 0 ]]; do
    case "$1" in
        --output-dir)
            if [[ $# -lt 2 ]]; then
                echo "Missing value for --output-dir." >&2
                exit 1
            fi
            OUTPUT_DIR="$2"
            shift 2
            ;;
        --release-tag)
            if [[ $# -lt 2 ]]; then
                echo "Missing value for --release-tag." >&2
                exit 1
            fi
            RELEASE_TAG="$2"
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

resolve_version() {
    sed -n 's/^#  define PHP_KING_VERSION[[:space:]]*"\(.*\)"/\1/p' \
        "${ROOT_DIR}/extension/include/php_king.h" | head -n 1
}

resolve_source_epoch() {
    git -C "${ROOT_DIR}" show -s --format=%ct HEAD 2>/dev/null || printf '%s\n' "0"
}

VERSION="$(resolve_version)"
if [[ -n "${RELEASE_TAG}" ]]; then
    VERSION="${RELEASE_TAG}"
fi
if [[ -z "${VERSION}" ]]; then
    echo "Failed to resolve PHP_KING_VERSION." >&2
    exit 1
fi

if [[ ! -f "${ROOT_DIR}/infra/scripts/lsquic-bootstrap.lock" ]]; then
    echo "Missing pinned LSQUIC lock file: ${ROOT_DIR}/infra/scripts/lsquic-bootstrap.lock" >&2
    exit 1
fi

if [[ ! -x "${ROOT_DIR}/infra/scripts/bootstrap-lsquic.sh" ]]; then
    echo "Missing executable LSQUIC bootstrap script: ${ROOT_DIR}/infra/scripts/bootstrap-lsquic.sh" >&2
    exit 1
fi

"${ROOT_DIR}/infra/scripts/bootstrap-lsquic.sh" --verify-lock
if ! "${ROOT_DIR}/infra/scripts/bootstrap-lsquic.sh" --verify-current; then
    "${ROOT_DIR}/infra/scripts/bootstrap-lsquic.sh"
fi

for required in \
    "${ROOT_DIR}/composer.json" \
    "${ROOT_DIR}/extension/config.m4" \
    "${ROOT_DIR}/extension/Makefile.frag" \
    "${ROOT_DIR}/infra/scripts/lsquic-bootstrap.lock" \
    "${ROOT_DIR}/infra/scripts/bootstrap-lsquic.sh"; do
    if [[ ! -e "${required}" ]]; then
        echo "Missing required PIE source-package input: ${required}" >&2
        exit 1
    fi
done

mkdir -p "${OUTPUT_DIR}"
TMP_DIR="$(mktemp -d)"
trap 'rm -rf "${TMP_DIR}"' EXIT

STAGE_ROOT="${TMP_DIR}/king-${VERSION}-src"
ARCHIVE_NAME="php_king-${VERSION}-src.tgz"
ARCHIVE_PATH="${OUTPUT_DIR}/${ARCHIVE_NAME}"
SOURCE_DATE_EPOCH="$(resolve_source_epoch)"

mkdir -p "${STAGE_ROOT}"

tar \
    --exclude-vcs \
    --exclude='./dist' \
    --exclude='./compat-artifacts' \
    --exclude='./.cargo' \
    --exclude='./extension/build' \
    --exclude='./extension/quiche/target' \
    --exclude='./extension/modules' \
    --exclude='./extension/Makefile' \
    --exclude='./extension/config.cache' \
    --exclude='./extension/config.log' \
    --exclude='./extension/config.status' \
    --exclude='./quiche/target' \
    --exclude='./demo/video-chat/node_modules' \
    -C "${ROOT_DIR}" \
    -cf - . | tar -C "${STAGE_ROOT}" -xf -

tar \
    --sort=name \
    --mtime="@${SOURCE_DATE_EPOCH}" \
    --owner=0 \
    --group=0 \
    --numeric-owner \
    -C "${TMP_DIR}" \
    -czf "${ARCHIVE_PATH}" \
    "king-${VERSION}-src"

echo "Created PIE source package: ${ARCHIVE_PATH}"
