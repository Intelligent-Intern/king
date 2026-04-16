ARG VIDEOCHAT_BACKEND_PHP_IMAGE=php:8.4-cli-trixie
FROM ${VIDEOCHAT_BACKEND_PHP_IMAGE}

WORKDIR /app

RUN apt-get update \
    && apt-get install -y --no-install-recommends libsqlite3-dev \
    && docker-php-ext-install pdo_sqlite \
    && rm -rf /var/lib/apt/lists/*

COPY demo/video-chat/backend-king-php/ /app/
COPY extension/modules/king.so /opt/king/king.so

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

CMD ["./run-dev.sh"]
