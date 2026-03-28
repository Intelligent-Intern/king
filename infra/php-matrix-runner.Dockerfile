# syntax=docker/dockerfile:1.7

ARG PHP_VERSION=8.5
ARG NODE_DIST_URL=https://nodejs.org/dist/latest-v22.x

FROM ubuntu:24.04

ARG PHP_VERSION
ARG NODE_DIST_URL

ENV DEBIAN_FRONTEND=noninteractive \
    CARGO_HOME=/root/.cargo \
    PATH=/root/.cargo/bin:/opt/node/bin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin

RUN apt-get update && apt-get install -y --no-install-recommends \
    ca-certificates \
    curl \
    gnupg \
    xz-utils \
    && mkdir -p /usr/share/keyrings \
    && curl --retry 5 --retry-delay 2 --retry-connrefused --retry-all-errors -fsSL \
        'https://keyserver.ubuntu.com/pks/lookup?op=get&search=0xB8DC7E53946656EFBCE4C1DD71DAEAAB4AD4CAB6' \
        | gpg --dearmor -o /usr/share/keyrings/ondrej-php.gpg \
    && . /etc/os-release \
    && printf 'deb [signed-by=/usr/share/keyrings/ondrej-php.gpg] https://ppa.launchpadcontent.net/ondrej/php/ubuntu %s main\n' "${VERSION_CODENAME}" \
        > /etc/apt/sources.list.d/ondrej-php.list \
    && apt-get -o Acquire::Retries=5 update \
    && php_packages="\
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
        php${PHP_VERSION}-cli \
        php${PHP_VERSION}-curl \
        php${PHP_VERSION}-dev \
        php${PHP_VERSION}-mbstring \
        php${PHP_VERSION}-readline \
        php${PHP_VERSION}-sockets \
        php${PHP_VERSION}-sqlite3 \
        php${PHP_VERSION}-xml" \
    && if apt-cache show "php${PHP_VERSION}-opcache" >/dev/null 2>&1; then \
        php_packages="${php_packages} php${PHP_VERSION}-opcache"; \
    fi \
    && apt-get install -y --no-install-recommends ${php_packages} \
    && curl -fsSL https://sh.rustup.rs | sh -s -- -y --profile minimal --default-toolchain stable \
    && node_dist="$(curl -fsSL "${NODE_DIST_URL}/SHASUMS256.txt" | awk '/linux-x64.tar.xz$/ {print $2; exit}')" \
    && curl -fsSL "${NODE_DIST_URL}/${node_dist}" -o /tmp/node.tar.xz \
    && mkdir -p /opt/node \
    && tar -xJf /tmp/node.tar.xz -C /opt/node --strip-components=1 \
    && ln -sf "/usr/bin/php${PHP_VERSION}" /usr/local/bin/php \
    && ln -sf "/usr/bin/phpize${PHP_VERSION}" /usr/local/bin/phpize \
    && ln -sf "/usr/bin/php-config${PHP_VERSION}" /usr/local/bin/php-config \
    && php --version \
    && phpize --version \
    && node --version \
    && rm -f /tmp/node.tar.xz \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /workspace
