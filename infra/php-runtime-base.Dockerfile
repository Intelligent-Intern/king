# syntax=docker/dockerfile:1.7

ARG PHP_VERSION=8.5

FROM ubuntu:24.04

ARG PHP_VERSION
ENV DEBIAN_FRONTEND=noninteractive

# Provision only the shared Ubuntu/PHP runtime dependencies so downstream
# runtime/demo images can reuse this layer without repeating flaky live PPA ops.
RUN set -eux; \
    for source_file in /etc/apt/sources.list /etc/apt/sources.list.d/ubuntu.sources; do \
      if [ -f "${source_file}" ]; then \
        sed -i \
          -e 's|http://archive.ubuntu.com/ubuntu|https://archive.ubuntu.com/ubuntu|g' \
          -e 's|http://security.ubuntu.com/ubuntu|https://security.ubuntu.com/ubuntu|g' \
          "${source_file}"; \
      fi; \
    done; \
    apt-get \
      -o Acquire::Retries=5 \
      -o Acquire::http::Timeout=30 \
      -o Acquire::https::Timeout=30 \
      -o Acquire::ForceIPv4=true \
      update; \
    apt-get install -y --no-install-recommends \
      ca-certificates \
      curl \
      gnupg \
      libcurl3t64-gnutls \
      libuuid1; \
    rm -rf /var/lib/apt/lists/*

RUN set -eux; \
    mkdir -p /usr/share/keyrings; \
    curl --retry 5 --retry-delay 2 --retry-connrefused --retry-all-errors -fsSL \
      'https://keyserver.ubuntu.com/pks/lookup?op=get&search=0xB8DC7E53946656EFBCE4C1DD71DAEAAB4AD4CAB6' \
      | gpg --dearmor -o /usr/share/keyrings/ondrej-php.gpg; \
    . /etc/os-release; \
    printf 'deb [signed-by=/usr/share/keyrings/ondrej-php.gpg] https://ppa.launchpadcontent.net/ondrej/php/ubuntu %s main\n' "${VERSION_CODENAME}" \
      > /etc/apt/sources.list.d/ondrej-php.list; \
    apt-get \
      -o Acquire::Retries=5 \
      -o Acquire::http::Timeout=30 \
      -o Acquire::https::Timeout=30 \
      -o Acquire::ForceIPv4=true \
      update; \
    apt-get install -y --no-install-recommends \
      "php${PHP_VERSION}-cli" \
      "php${PHP_VERSION}-curl" \
      "php${PHP_VERSION}-mbstring" \
      "php${PHP_VERSION}-sockets" \
      "php${PHP_VERSION}-sqlite3" \
      "php${PHP_VERSION}-xml"; \
    ln -sf "/usr/bin/php${PHP_VERSION}" /usr/local/bin/php; \
    rm -rf /var/lib/apt/lists/*
