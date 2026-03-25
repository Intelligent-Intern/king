# syntax=docker/dockerfile:1.7

# Compatibility wrapper for legacy scripts that still reference infra/php8.3/Dockerfile.
# Kept as a compatibility shim; runtime builds should now target infra/php-runtime.Dockerfile.

FROM php:8.3-cli-bookworm AS build

ARG BUILD_JOBS=4
ENV DEBIAN_FRONTEND=noninteractive \
    CARGO_HOME=/root/.cargo \
    CARGO_BUILD_JOBS=${BUILD_JOBS} \
    CARGO_INCREMENTAL=0 \
    CARGO_TERM_COLOR=always \
    CMAKE_BUILD_PARALLEL_LEVEL=${BUILD_JOBS} \
    JOBS=${BUILD_JOBS} \
    PATH=/root/.cargo/bin:${PATH}

RUN apt-get update && apt-get install -y --no-install-recommends \
    autoconf \
    automake \
    bison \
    build-essential \
    ca-certificates \
    clang \
    cmake \
    curl \
    git \
    libcurl4-openssl-dev \
    libssl-dev \
    libtool \
    ninja-build \
    pkg-config \
    re2c \
    uuid-dev \
    && rm -rf /var/lib/apt/lists/*

RUN curl -fsSL https://sh.rustup.rs | sh -s -- -y --profile minimal --default-toolchain stable

WORKDIR /src
COPY . .

WORKDIR /src/extension
RUN ./scripts/build-profile.sh release \
    && ./scripts/smoke-profile.sh release

FROM php:8.3-cli-bookworm AS runtime

RUN apt-get update && apt-get install -y --no-install-recommends \
    ca-certificates \
    libcurl4 \
    libuuid1 \
    && rm -rf /var/lib/apt/lists/*

RUN mkdir -p /opt/king/runtime /workspace

COPY --from=build /src/extension/build/profiles/release/king.so /opt/king/runtime/king.so
COPY --from=build /src/extension/build/profiles/release/libquiche.so /opt/king/runtime/libquiche.so
COPY --from=build /src/extension/build/profiles/release/quiche-server /opt/king/runtime/quiche-server

RUN printf '%s\n' \
    'extension=/opt/king/runtime/king.so' \
    > /usr/local/etc/php/conf.d/zz-king.ini \
    && php -m | grep -qx 'king'

ENV KING_QUICHE_LIBRARY=/opt/king/runtime/libquiche.so \
    KING_QUICHE_SERVER=/opt/king/runtime/quiche-server \
    LD_LIBRARY_PATH=/opt/king/runtime

WORKDIR /workspace

CMD ["php", "-v"]
