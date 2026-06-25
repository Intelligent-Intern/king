#!/usr/bin/env bash

set -euo pipefail

usage() {
    cat <<'USAGE'
Usage: ./infra/scripts/commit-docker-buildx-cache.sh --cache-dir DIR --next-dir DIR

Promotes a Docker Buildx local cache export directory after a successful build.
USAGE
}

CACHE_DIR=""
NEXT_DIR=""

while [[ "$#" -gt 0 ]]; do
    case "$1" in
        --cache-dir)
            CACHE_DIR="${2:-}"
            shift 2
            ;;
        --next-dir)
            NEXT_DIR="${2:-}"
            shift 2
            ;;
        --help|-h)
            usage
            exit 0
            ;;
        *)
            echo "Unknown argument: $1" >&2
            usage >&2
            exit 1
            ;;
    esac
done

if [[ -z "${CACHE_DIR}" || -z "${NEXT_DIR}" ]]; then
    echo "--cache-dir and --next-dir are required." >&2
    exit 1
fi

if [[ ! -d "${NEXT_DIR}" ]]; then
    echo "Next Docker Buildx cache directory does not exist: ${NEXT_DIR}" >&2
    exit 1
fi

parent_dir="$(dirname "${CACHE_DIR}")"
tmp_old="${CACHE_DIR}.old.$$"

mkdir -p "${parent_dir}"
rm -rf "${tmp_old}"

if [[ -e "${CACHE_DIR}" ]]; then
    mv "${CACHE_DIR}" "${tmp_old}"
fi

mv "${NEXT_DIR}" "${CACHE_DIR}"
rm -rf "${tmp_old}"

echo "Committed local Docker Buildx cache: ${CACHE_DIR}"
