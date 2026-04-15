ARG MODEL_INFERENCE_BACKEND_PHP_IMAGE=php:8.4-cli-trixie
FROM ${MODEL_INFERENCE_BACKEND_PHP_IMAGE}

WORKDIR /app

RUN apt-get update \
    && apt-get install -y --no-install-recommends libsqlite3-dev \
    && docker-php-ext-install pdo_sqlite \
    && rm -rf /var/lib/apt/lists/*

# NOTE: The llama.cpp server binary is copied in by the M-7 leaf. Until then
# the image still boots the HTTP/WS surfaces defined by M-1 and later module
# leaves; inference endpoints return not_implemented rather than falling back
# to a mock model.

COPY demo/model-inference/backend-king-php/ /app/
COPY extension/modules/king.so /opt/king/king.so

ENV KING_EXTENSION_PATH=/opt/king/king.so
ENV MODEL_INFERENCE_KING_HOST=0.0.0.0
ENV MODEL_INFERENCE_KING_PORT=18090
ENV MODEL_INFERENCE_KING_WS_PORT=18091
ENV MODEL_INFERENCE_KING_WS_PATH=/ws
ENV MODEL_INFERENCE_KING_DB_PATH=/data/model-inference.sqlite
ENV MODEL_INFERENCE_KING_OBJECT_STORE_ROOT=/data/object-store

# Enable userland object-store initialisation (M-5 onward). Without this,
# king_object_store_init() is refused by the security policy.
RUN echo "king.security_allow_config_override=1" > /usr/local/etc/php/conf.d/king-demo.ini

RUN mkdir -p /data /data/object-store

VOLUME ["/data"]
EXPOSE 18090
EXPOSE 18091

CMD ["./run-dev.sh"]
