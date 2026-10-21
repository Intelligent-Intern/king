# syntax=docker/dockerfile:1.7
# Rebuild PHP from an official security release while retaining King's bundle.
ARG KING_RUNTIME_IMAGE=ghcr.io/intelligent-intern/king:php8.4
FROM ubuntu:24.04 AS php-build
ARG PHP_PATCH_VERSION=8.4.27
ARG PHP_MINOR_VERSION=8.4
ARG BUILD_JOBS=8
ENV DEBIAN_FRONTEND=noninteractive
RUN apt-get update && apt-get install -y --no-install-recommends \
    build-essential autoconf bison re2c pkg-config ca-certificates curl xz-utils \
    libargon2-dev libcurl4-gnutls-dev libedit-dev libffi-dev libonig-dev \
    libsodium-dev libsqlite3-dev libssl-dev libxml2-dev zlib1g-dev \
    && rm -rf /var/lib/apt/lists/*
RUN curl --fail --location --retry 3 \
      "https://www.php.net/distributions/php-${PHP_PATCH_VERSION}.tar.xz" -o /tmp/php.tar.xz \
    && mkdir /src && tar -xJf /tmp/php.tar.xz -C /src --strip-components=1
WORKDIR /src
RUN ./configure --prefix=/opt/php --with-config-file-path=/etc/php/${PHP_MINOR_VERSION}/cli \
      --with-config-file-scan-dir=/opt/php/etc/conf.d --with-pic \
      --disable-cgi --disable-phpdbg --enable-option-checking=fatal \
      --with-openssl --with-zlib --with-password-argon2 --enable-pcntl \
      --enable-calendar=shared --enable-ctype=shared --with-curl=shared \
      --enable-dom=shared --enable-exif=shared --with-ffi=shared --enable-fileinfo=shared \
      --enable-ftp=shared --with-gettext=shared --with-iconv=shared \
      --enable-mbstring=shared --enable-pdo=shared --with-pdo-sqlite=shared,/usr \
      --enable-phar=shared --enable-posix=shared --with-libedit=shared \
      --enable-shmop=shared --enable-simplexml=shared --enable-sockets=shared \
      --with-sodium --with-sqlite3=shared,/usr --enable-sysvmsg=shared \
      --enable-sysvsem=shared --enable-sysvshm=shared --enable-tokenizer=shared \
      --enable-xml=shared --enable-xmlreader=shared --enable-xmlwriter=shared \
    && make -j"${BUILD_JOBS}" && make install \
    && strip /opt/php/bin/php /opt/php/lib/php/extensions/*/*.so \
    && /opt/php/bin/php -n -r 'echo PHP_VERSION, PHP_EOL;'

FROM ${KING_RUNTIME_IMAGE}
ARG PHP_PATCH_VERSION=8.4.27
ARG PHP_MINOR_VERSION=8.4
USER root
# The published bundle removes tar, but dpkg needs it even for package removal.
COPY --from=php-build /usr/bin/tar /usr/local/bin/tar
# Keep configuration before removing the replaced distro PHP packages. This
# prevents retaining vulnerable, unused PHP modules or misleading package state.
RUN if [ ! -f /opt/php/etc/conf.d/00-extension-directory.ini ]; then \
      mkdir -p /opt/php/etc/conf.d; \
      cp -L /etc/php/${PHP_MINOR_VERSION}/cli/conf.d/*.ini /opt/php/etc/conf.d/; fi \
    && dpkg-query -W -f='${binary:Package} ${db:Status-Status}\n' "php${PHP_MINOR_VERSION}-*" php-common \
      | awk '$2 == "installed" { print $1 }' \
      | xargs -r dpkg --remove --force-depends
RUN rm /usr/local/bin/tar
COPY --from=php-build /opt/php/ /opt/php/
# Preserve the existing PHP settings and module order, including King's config.
RUN mkdir -p /opt/php/etc/conf.d \
    && extension_dir="$(find /opt/php/lib/php/extensions -mindepth 1 -maxdepth 1 -type d)" \
    && printf 'extension_dir="%s"\n' "$extension_dir" > /opt/php/etc/conf.d/00-extension-directory.ini \
    && for tool in php php-config phpize phar phar.phar; do \
      ln -sf "/opt/php/bin/$tool" "/usr/local/bin/$tool"; done \
    && ln -sf /opt/php/bin/php /usr/bin/php \
    && ln -sf /opt/php/bin/php /usr/bin/php${PHP_MINOR_VERSION} \
    && php -r 'if (PHP_VERSION !== $argv[1] || !extension_loaded("king")) { exit(1); } echo "PHP ", PHP_VERSION, " King ", phpversion("king"), PHP_EOL;' "${PHP_PATCH_VERSION}"
LABEL org.opencontainers.image.version="php${PHP_PATCH_VERSION}" \
    org.opencontainers.image.description="King bundled runtime with PHP security update from official source"
CMD ["php", "-v"]
