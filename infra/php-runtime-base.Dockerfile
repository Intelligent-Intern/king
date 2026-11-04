# syntax=docker/dockerfile:1.7@sha256:a57df69d0ea827fb7266491f2813635de6f17269be881f696fbfdf2d83dda33e

ARG PHP_VERSION=8.5

FROM ubuntu:24.04@sha256:fe81560990fae86dc4743202b2cb4d3df98d0bcf949173cecef15ce1127ef852

ARG PHP_VERSION
ENV DEBIAN_FRONTEND=noninteractive

# Provision only the shared Ubuntu/PHP runtime dependencies so downstream
# runtime images can reuse this layer without repeating flaky live PPA ops.
RUN --mount=type=bind,source=infra/scripts/install-ubuntu-php-runtime.sh,target=/tmp/install-ubuntu-php-runtime.sh,readonly \
    /tmp/install-ubuntu-php-runtime.sh "${PHP_VERSION}"
