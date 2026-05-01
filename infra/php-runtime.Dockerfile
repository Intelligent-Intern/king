# syntax=docker/dockerfile:1.7

ARG PHP_VERSION=8.5
ARG PHP_BASE_IMAGE=ubuntu:24.04

FROM debian:bookworm-slim AS package

ARG PHP_VERSION
ARG TARGETARCH

RUN --mount=type=bind,source=dist/docker-packages,target=/mnt/packages,readonly \
    set -eux; \
    package_dir="/mnt/packages/php${PHP_VERSION}/linux-${TARGETARCH}"; \
    test -d "${package_dir}"; \
    mkdir -p /tmp/king-package-input /opt/king/package; \
    cp -a "${package_dir}/." /tmp/king-package-input/; \
    cd /tmp/king-package-input; \
    sha256sum -c ./*.sha256; \
    archive="$(find . -maxdepth 1 -type f -name '*.tar.gz' | head -n 1)"; \
    test -n "${archive}"; \
    tar -xzf "${archive}" -C /opt/king/package --strip-components=1

FROM ${PHP_BASE_IMAGE} AS runtime

ARG PHP_VERSION
ARG PHP_BASE_IMAGE
ARG BUILD_DATE
ARG VCS_REF
ENV DEBIAN_FRONTEND=noninteractive

LABEL org.opencontainers.image.title="king" \
      org.opencontainers.image.description="King PHP extension runtime image" \
      org.opencontainers.image.created="${BUILD_DATE}" \
      org.opencontainers.image.revision="${VCS_REF}"

RUN --mount=type=bind,source=infra/scripts/install-ubuntu-php-runtime.sh,target=/tmp/install-ubuntu-php-runtime.sh,readonly \
    set -eux; \
    if command -v "php${PHP_VERSION}" >/dev/null 2>&1; then \
      ln -sf "/usr/bin/php${PHP_VERSION}" /usr/local/bin/php; \
    else \
      /tmp/install-ubuntu-php-runtime.sh "${PHP_VERSION}"; \
    fi

RUN mkdir -p /opt/king /workspace

COPY --from=package /opt/king/package /opt/king/package

ENV KING_LSQUIC_LIBRARY=/opt/king/package/runtime/liblsquic.so \
    LD_LIBRARY_PATH=/opt/king/package/runtime

RUN printf '%s\n' \
    'extension=/opt/king/package/modules/king.so' \
    > "/etc/php/${PHP_VERSION}/cli/conf.d/zz-king.ini" \
    # xsl depends on libgcrypt through libxslt; disable it in this runtime image
    # so we can remove libgcrypt20 without startup warnings.
    && if command -v phpdismod >/dev/null 2>&1; then phpdismod -v "${PHP_VERSION}" -s cli xsl || true; fi \
    && rm -f /etc/php/${PHP_VERSION}/cli/conf.d/*xsl.ini /etc/php/${PHP_VERSION}/mods-available/xsl.ini \
    && php -m | grep -qx 'king' \
    && PHP_BIN=php /opt/king/package/bin/smoke.sh \
    # Keep the runtime image immutable and drop packages carrying known CVEs
    # that are not required after build-time provisioning.
    && dpkg --remove --force-remove-essential --force-depends \
        libgcrypt20 \
        login \
        passwd \
        tar

WORKDIR /workspace

CMD ["php", "-v"]
