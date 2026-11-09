# syntax=docker/dockerfile:1.7
# Build the actual working-tree sources for each target architecture.
ARG PHP_VERSION=8.5
FROM ubuntu:24.04 AS php-runtime
ARG PHP_VERSION
ENV DEBIAN_FRONTEND=noninteractive
SHELL ["/bin/bash", "-o", "pipefail", "-c"]
COPY infra/scripts/install-ubuntu-php-runtime.sh /tmp/install-ubuntu-php-runtime.sh
RUN /tmp/install-ubuntu-php-runtime.sh "${PHP_VERSION}" \
    && rm /tmp/install-ubuntu-php-runtime.sh

FROM php-runtime AS build
ARG PHP_VERSION
ARG BUILD_JOBS=4
ARG SOURCE_SHA256
RUN apt-get -o Acquire::Retries=5 update \
    && apt-get install -y --no-install-recommends \
       autoconf automake bison build-essential cmake git golang-go \
       libcurl4-openssl-dev libssl-dev libtool ninja-build pkg-config \
       python3 re2c zlib1g-dev "php${PHP_VERSION}-dev" \
    && rm -rf /var/lib/apt/lists/*
COPY dist/docker-source/king-source.tar.gz /tmp/king-source.tar.gz
RUN echo "${SOURCE_SHA256}  /tmp/king-source.tar.gz" | sha256sum -c - \
    && mkdir -p /src/king \
    && tar -xzf /tmp/king-source.tar.gz -C /src/king \
    && rm /tmp/king-source.tar.gz
WORKDIR /src/king
RUN CI=true JOBS="${BUILD_JOBS}" KING_LSQUIC_RUNTIME_BUILD=1 \
      ./infra/scripts/build-profile.sh release
RUN cd extension \
    && php -n run-tests.php -n -d extension="${PWD}/modules/king.so" \
       tests/001-load-and-core.phpt tests/006-health-shape.phpt \
       tests/*proto-*.phpt tests/*iibin-*.phpt
RUN CI=true ./infra/scripts/package-release.sh --verify-reproducible --output-dir /out \
    && cd /out && sha256sum -c ./*.sha256 \
    && mkdir -p /opt/king/package \
    && archive="$(find /out -maxdepth 1 -name '*.tar.gz' -print -quit)" \
    && tar -xzf "${archive}" -C /opt/king/package --strip-components=1 \
    && cp /src/king/docs/iibin.md /opt/king/package/docs/IIBIN-MIGRATION.md

FROM php-runtime AS runtime
ARG PHP_VERSION
ARG BUILD_DATE
ARG VCS_REF
ARG SOURCE_SHA256
LABEL org.opencontainers.image.title="king" \
      org.opencontainers.image.version="1.0.13-beta" \
      org.opencontainers.image.description="King PHP runtime with the IIBIN 2 schema contract and full unsigned 64-bit values" \
      org.opencontainers.image.source="https://github.com/Intelligent-Intern/king" \
      org.opencontainers.image.created="${BUILD_DATE}" \
      org.opencontainers.image.revision="${VCS_REF}" \
      io.intelligentintern.king.source-sha256="${SOURCE_SHA256}" \
      io.intelligentintern.king.iibin-contract="2"
COPY --from=build /opt/king/package /opt/king/package
ENV KING_LSQUIC_LIBRARY=/opt/king/package/runtime/liblsquic.so \
    LD_LIBRARY_PATH=/opt/king/package/runtime
RUN mkdir -p /workspace \
    && printf '%s\n' 'extension=/opt/king/package/modules/king.so' \
       > "/etc/php/${PHP_VERSION}/cli/conf.d/zz-king.ini" \
    && if command -v phpdismod >/dev/null 2>&1; then phpdismod -v "${PHP_VERSION}" -s cli xsl || true; fi \
    && rm -f /etc/php/${PHP_VERSION}/cli/conf.d/*xsl.ini /etc/php/${PHP_VERSION}/mods-available/xsl.ini \
    && php -r 'if (phpversion("king") !== "1.0.13-beta") exit(1);' \
    && PHP_BIN=php /opt/king/package/bin/smoke.sh \
    && dpkg --remove --force-remove-essential --force-depends libgcrypt20 login passwd tar
WORKDIR /workspace
CMD ["php", "-v"]
