ARG MODEL_INFERENCE_BACKEND_PHP_IMAGE=php:8.4-cli-trixie
FROM ${MODEL_INFERENCE_BACKEND_PHP_IMAGE}

WORKDIR /app

RUN apt-get update \
    && apt-get install -y --no-install-recommends libsqlite3-dev curl ca-certificates \
    && docker-php-ext-install pdo_sqlite \
    && rm -rf /var/lib/apt/lists/*

COPY demo/model-inference/backend-king-php/ /app/
COPY extension/modules/king.so /opt/king/king.so

# M-7: install the pinned llama.cpp runtime. The install script is idempotent
# and verifies a committed SHA-256 against the binary archive. Set
# MODEL_INFERENCE_SKIP_LLAMA_INSTALL=1 at build time to defer runtime
# installation to a volume mount (useful for CI images that preload the
# runtime via a cached layer).
ARG MODEL_INFERENCE_SKIP_LLAMA_INSTALL=0
RUN if [ "${MODEL_INFERENCE_SKIP_LLAMA_INSTALL}" != "1" ]; then \
        /app/scripts/install-llama-runtime.sh binary ; \
    else \
        echo "[dockerfile] skipping llama.cpp install (MODEL_INFERENCE_SKIP_LLAMA_INSTALL=1)" ; \
    fi

ENV KING_EXTENSION_PATH=/opt/king/king.so
ENV LLAMA_CPP_HOME=/opt/llama-cpp/llama-b8802
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
