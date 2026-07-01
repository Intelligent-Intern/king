#!/usr/bin/env bash

set -euo pipefail

PHP_VERSION="${1:-}"
ONDREJ_PHP_KEY_FINGERPRINT="B8DC7E53946656EFBCE4C1DD71DAEAAB4AD4CAB6"
APT_RETRY_OPTIONS=(
    -o Acquire::Retries=5
    -o Acquire::http::Timeout=30
    -o Acquire::https::Timeout=30
    -o Acquire::ForceIPv4=true
)

if [[ -z "${PHP_VERSION}" ]]; then
    echo "Usage: install-ubuntu-php-runtime.sh <php-version>" >&2
    exit 1
fi

if [[ ! "${PHP_VERSION}" =~ ^[0-9]+(\.[0-9]+)?$ ]]; then
    echo "Invalid PHP version: ${PHP_VERSION}" >&2
    exit 1
fi

normalize_ubuntu_sources_to_scheme() {
    local scheme="$1"
    local source_file=""

    for source_file in /etc/apt/sources.list /etc/apt/sources.list.d/ubuntu.sources; do
        if [[ -f "${source_file}" ]]; then
            sed -i \
                -e "s|https\\?://archive.ubuntu.com/ubuntu|${scheme}://archive.ubuntu.com/ubuntu|g" \
                -e "s|https\\?://security.ubuntu.com/ubuntu|${scheme}://security.ubuntu.com/ubuntu|g" \
                "${source_file}"
        fi
    done
}

install_ondrej_php_keyring() {
    local key_tmp=""
    local keyring_tmp=""
    local fingerprint=""

    key_tmp="$(mktemp)"
    keyring_tmp="$(mktemp)"

    if ! curl --retry 5 --retry-delay 2 --retry-connrefused --retry-all-errors -fsSL \
        'https://keyserver.ubuntu.com/pks/lookup?op=get&search=0xB8DC7E53946656EFBCE4C1DD71DAEAAB4AD4CAB6' \
        -o "${key_tmp}"; then
        rm -f "${key_tmp}" "${keyring_tmp}"
        return 1
    fi

    fingerprint="$(
        gpg --show-keys --with-colons "${key_tmp}" \
            | awk -F: '$1 == "fpr" { print $10; exit }'
    )"

    if [[ "${fingerprint}" != "${ONDREJ_PHP_KEY_FINGERPRINT}" ]]; then
        echo "Unexpected Ondrej PHP PPA key fingerprint: ${fingerprint:-<empty>}" >&2
        rm -f "${key_tmp}" "${keyring_tmp}"
        return 1
    fi

    if ! gpg --batch --yes --dearmor -o "${keyring_tmp}" "${key_tmp}"; then
        rm -f "${key_tmp}" "${keyring_tmp}"
        return 1
    fi

    install -m 0644 "${keyring_tmp}" /usr/share/keyrings/ondrej-php.gpg
    rm -f "${key_tmp}" "${keyring_tmp}"
}

normalize_ubuntu_sources_to_scheme http

apt-get "${APT_RETRY_OPTIONS[@]}" update

apt-get install -y --no-install-recommends \
    ca-certificates \
    curl \
    gnupg \
    libcurl3t64-gnutls \
    libuuid1

normalize_ubuntu_sources_to_scheme https

apt-get "${APT_RETRY_OPTIONS[@]}" update

mkdir -p /usr/share/keyrings
install_ondrej_php_keyring

. /etc/os-release
if [[ -z "${VERSION_CODENAME:-}" || ! "${VERSION_CODENAME}" =~ ^[A-Za-z0-9._-]+$ ]]; then
    echo "Ubuntu VERSION_CODENAME is missing or invalid." >&2
    exit 1
fi

printf 'deb [signed-by=/usr/share/keyrings/ondrej-php.gpg] https://ppa.launchpadcontent.net/ondrej/php/ubuntu %s main\n' "${VERSION_CODENAME}" \
    > /etc/apt/sources.list.d/ondrej-php.list

apt-get "${APT_RETRY_OPTIONS[@]}" update

apt-get install -y --no-install-recommends \
    "php${PHP_VERSION}-cli" \
    "php${PHP_VERSION}-curl" \
    "php${PHP_VERSION}-mbstring" \
    "php${PHP_VERSION}-sockets" \
    "php${PHP_VERSION}-sqlite3" \
    "php${PHP_VERSION}-xml"

ln -sf "/usr/bin/php${PHP_VERSION}" /usr/local/bin/php
rm -rf /var/lib/apt/lists/*
