ARG VIDEOCHAT_BACKEND_PHP_IMAGE=php:8.4-cli-trixie
ARG VIDEOCHAT_BACKEND_PHP_VERSION=8.4
FROM ${VIDEOCHAT_BACKEND_PHP_IMAGE}

WORKDIR /app

ARG VIDEOCHAT_BACKEND_PHP_VERSION
ARG TARGETARCH

RUN apt-get update \
    && apt-get install -y --no-install-recommends libsqlite3-dev \
    && docker-php-ext-install pdo_sqlite \
    && rm -rf /var/lib/apt/lists/*

RUN --mount=type=bind,source=.,target=/src,readonly \
    set -eux; \
    mkdir -p /opt/king; \
    package_dir="/src/dist/docker-packages/php${VIDEOCHAT_BACKEND_PHP_VERSION}/linux-${TARGETARCH}"; \
    archive=""; \
    if [ -d "${package_dir}" ]; then \
        archive="$(find "${package_dir}" -maxdepth 1 -type f -name '*.tar.gz' | head -n 1 || true)"; \
    fi; \
    if [ -n "${archive}" ] && [ -f "${archive}" ]; then \
        tar -xzf "${archive}" -C /opt/king --strip-components=2 --wildcards '*/modules/king.so'; \
    elif [ -f /src/extension/modules/king.so ]; then \
        cp /src/extension/modules/king.so /opt/king/king.so; \
    else \
        echo "Missing king extension artifact: expected ${package_dir}/*.tar.gz or /src/extension/modules/king.so" >&2; \
        exit 1; \
    fi; \
    test -f /opt/king/king.so

COPY demo/video-chat/backend-king-php/ /app/

ENV KING_EXTENSION_PATH=/opt/king/king.so
ENV VIDEOCHAT_KING_HOST=0.0.0.0
ENV VIDEOCHAT_KING_PORT=18080
ENV VIDEOCHAT_KING_WS_PORT=18080
ENV VIDEOCHAT_KING_WS_PATH=/ws
ENV VIDEOCHAT_KING_DB_PATH=/data/video-chat.sqlite

RUN mkdir -p /data

VOLUME ["/data"]
EXPOSE 18080
EXPOSE 18081
EXPOSE 18082

CMD ["./run-dev.sh"]
