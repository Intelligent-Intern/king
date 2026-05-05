ARG VIDEOCHAT_BACKEND_PHP_IMAGE=php:8.4-cli-trixie
ARG VIDEOCHAT_BACKEND_PHP_VERSION=8.4
ARG VIDEOCHAT_BACKEND_PREFER_WORKTREE_EXTENSION=0
FROM ${VIDEOCHAT_BACKEND_PHP_IMAGE} AS king-extension-build

WORKDIR /src

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        ${PHPIZE_DEPS} \
        libcurl4-openssl-dev \
        libssl-dev \
    && rm -rf /var/lib/apt/lists/*

COPY extension/ /src/extension/

RUN set -eux; \
    cd /src/extension; \
    rm -f Makefile Makefile.fragments Makefile.objects config.h config.h.in config.log config.nice config.status configure configure.ac king.la libtool run-tests.php; \
    rm -rf .libs autom4te.cache build modules; \
    find . -type f \( -name '*.dep' -o -name '*.lo' -o -name '*.o' -o -name '*.la' -o -name '.dirstamp' \) -delete; \
    phpize --clean >/dev/null 2>&1 || true; \
    phpize; \
    ./configure --enable-king; \
    find . -type f -exec sed -i 's#/Users/sasha/king/extension#/src/extension#g' {} +; \
    make -j"$(nproc)"; \
    mkdir -p /opt/king; \
    cp modules/king.so /opt/king/king.so; \
    test -f /opt/king/king.so

FROM ${VIDEOCHAT_BACKEND_PHP_IMAGE}

WORKDIR /app

ARG VIDEOCHAT_BACKEND_PHP_VERSION
ARG VIDEOCHAT_BACKEND_PREFER_WORKTREE_EXTENSION
ARG TARGETARCH

RUN apt-get update \
    && apt-get install -y --no-install-recommends libcurl4 libsqlite3-dev \
    && docker-php-ext-install pdo_sqlite \
    && rm -rf /var/lib/apt/lists/*

COPY --from=king-extension-build /opt/king/king.so /opt/king/king.so

COPY demo/video-chat/backend-king-php/ /app/

ENV KING_EXTENSION_PATH=/opt/king/king.so
ENV VIDEOCHAT_KING_HOST=0.0.0.0
ENV VIDEOCHAT_KING_PORT=18080
ENV VIDEOCHAT_KING_WS_PORT=18080
ENV VIDEOCHAT_KING_WS_PATH=/ws
ENV VIDEOCHAT_KING_DB_PATH=/data/video-chat.sqlite
ENV VIDEOCHAT_KING_SFU_BROKER_DB_PATH=/data/video-chat-sfu-broker.sqlite

RUN mkdir -p /data

VOLUME ["/data"]
# The image listens on 18080 only. docker-compose.v1.yml maps dedicated
# HTTP/WS/SFU service instances to host ports 18080/18081/18082.
EXPOSE 18080

CMD ["./run-dev.sh"]
