# syntax=docker/dockerfile:1.7
# Minimal arm64-friendly image for building the King PHP extension locally.
# Omits Node.js (CI-only) to avoid x86_64 binaries polluting PATH.

ARG PHP_VERSION=8.3

FROM ubuntu:24.04

ARG PHP_VERSION

ENV DEBIAN_FRONTEND=noninteractive \
    CARGO_HOME=/root/.cargo \
    PATH=/root/.cargo/bin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin

RUN apt-get update && apt-get install -y --no-install-recommends \
        ca-certificates \
        curl \
        gnupg \
    && mkdir -p /usr/share/keyrings \
    && curl --retry 5 --retry-delay 2 --retry-connrefused --retry-all-errors -fsSL \
        'https://keyserver.ubuntu.com/pks/lookup?op=get&search=0xB8DC7E53946656EFBCE4C1DD71DAEAAB4AD4CAB6' \
        | gpg --dearmor -o /usr/share/keyrings/ondrej-php.gpg \
    && . /etc/os-release \
    && printf 'deb [signed-by=/usr/share/keyrings/ondrej-php.gpg] https://ppa.launchpadcontent.net/ondrej/php/ubuntu %s main\n' "${VERSION_CODENAME}" \
        > /etc/apt/sources.list.d/ondrej-php.list \
    && apt-get -o Acquire::Retries=5 update \
    && apt-get install -y --no-install-recommends \
        autoconf \
        automake \
        bison \
        build-essential \
        clang \
        cmake \
        git \
        libcurl4-openssl-dev \
        libssl-dev \
        libtool \
        ninja-build \
        pkg-config \
        re2c \
        uuid-dev \
        "php${PHP_VERSION}-cli" \
        "php${PHP_VERSION}-curl" \
        "php${PHP_VERSION}-dev" \
        "php${PHP_VERSION}-mbstring" \
        "php${PHP_VERSION}-readline" \
        "php${PHP_VERSION}-sockets" \
        "php${PHP_VERSION}-sqlite3" \
        "php${PHP_VERSION}-xml" \
        "php${PHP_VERSION}-opcache" \
    && curl -fsSL https://sh.rustup.rs | sh -s -- -y --profile minimal --default-toolchain stable \
    && ln -sf "/usr/bin/php${PHP_VERSION}" /usr/local/bin/php \
    && ln -sf "/usr/bin/phpize${PHP_VERSION}" /usr/local/bin/phpize \
    && ln -sf "/usr/bin/php-config${PHP_VERSION}" /usr/local/bin/php-config \
    && php --version \
    && phpize --version \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /workspace
