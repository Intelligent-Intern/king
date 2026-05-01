# syntax=docker/dockerfile:1.7

ARG PHP_VERSION=8.5

FROM ubuntu:24.04

ARG PHP_VERSION
ENV DEBIAN_FRONTEND=noninteractive

# Provision only the shared Ubuntu/PHP runtime dependencies so downstream
# runtime/demo images can reuse this layer without repeating flaky live PPA ops.
RUN --mount=type=bind,source=infra/scripts/install-ubuntu-php-runtime.sh,target=/tmp/install-ubuntu-php-runtime.sh,readonly \
    /tmp/install-ubuntu-php-runtime.sh "${PHP_VERSION}"
