#!/usr/bin/env bash

set -euo pipefail

usage() {
    cat <<'EOF'
Usage: ./infra/scripts/install-package-matrix.sh --archive PATH [--php-bins BIN1,BIN2,...]

Verifies a packaged King release archive against one or more host PHP binaries
with the same PHP API by re-running the package verification and packaged smoke
test under each matching PHP.
EOF
}

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ARCHIVE_PATH=""
PHP_BINS_CSV="${PHP_BINS:-php}"

resolve_archive_php_api() {
    local archive_name

    archive_name="$(basename "${ARCHIVE_PATH}")"
    if [[ "${archive_name}" =~ phpapi-([0-9]+)\.tar\.gz$ ]]; then
        printf '%s\n' "${BASH_REMATCH[1]}"
        return 0
    fi

    return 1
}

resolve_php_config_bin() {
    local php_bin="$1"
    local suffix=""

    if [[ "${php_bin}" == "php" ]]; then
        printf '%s\n' "php-config"
        return 0
    fi

    if [[ "${php_bin}" =~ ^php(.+)$ ]]; then
        suffix="${BASH_REMATCH[1]}"
        printf '%s\n' "php-config${suffix}"
        return 0
    fi

    return 1
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
        --php-bins)
            if [[ $# -lt 2 ]]; then
                echo "Missing value for --php-bins." >&2
                exit 1
            fi
            PHP_BINS_CSV="$2"
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
IFS=',' read -r -a php_bins <<< "${PHP_BINS_CSV}"
ARCHIVE_PHP_API="$(resolve_archive_php_api)" || {
    echo "Failed to resolve PHP API from archive name: ${ARCHIVE_PATH}" >&2
    exit 1
}

for php_bin in "${php_bins[@]}"; do
    php_bin="${php_bin//[[:space:]]/}"
    if [[ -z "${php_bin}" ]]; then
        continue
    fi

    if ! command -v "${php_bin}" >/dev/null 2>&1; then
        echo "Missing requested PHP binary: ${php_bin}" >&2
        exit 1
    fi

    php_config_bin="$(resolve_php_config_bin "${php_bin}")" || {
        echo "Could not infer php-config companion for ${php_bin}." >&2
        exit 1
    }
    if ! command -v "${php_config_bin}" >/dev/null 2>&1; then
        echo "Missing required PHP config binary: ${php_config_bin}" >&2
        exit 1
    fi

    php_api="$("${php_config_bin}" --phpapi)"
    if [[ "${php_api}" != "${ARCHIVE_PHP_API}" ]]; then
        echo "Archive PHP API ${ARCHIVE_PHP_API} does not match ${php_bin} API ${php_api}." >&2
        echo "Build one release package per supported PHP/API combination and verify each archive against its matching runtime." >&2
        exit 1
    fi

    echo "Package install smoke: ${php_bin}"
    PHP_BIN="${php_bin}" "${SCRIPT_DIR}/verify-release-package.sh" --archive "${ARCHIVE_PATH}"
done
