# Rebuild the existing 1.0.15 runtime with the fixed APT packages.
ARG BASE_IMAGE=intelligentintern/king:v1.0.15-beta
FROM ubuntu:24.04@sha256:fe81560990fae86dc4743202b2cb4d3df98d0bcf949173cecef15ce1127ef852 AS utilities
RUN mkdir -p /security-libs && find /usr/lib -name libgcrypt.so.20 -exec cp -L '{}' /security-libs/libgcrypt.so.20 \; && test -s /security-libs/libgcrypt.so.20
FROM ${BASE_IMAGE}
USER root
COPY --from=utilities /usr/bin/tar /usr/local/bin/tar
COPY --from=utilities /security-libs /tmp/security-libs
RUN export LD_LIBRARY_PATH="/tmp/security-libs${LD_LIBRARY_PATH:+:$LD_LIBRARY_PATH}" \
 && apt-get -o Acquire::Retries=0 update \
 && mkdir -p /tmp/security-debs && cd /tmp/security-debs \
 && apt-get -o Acquire::Retries=0 download apt libapt-pkg6.0t64 \
 && dpkg --install --force-depends ./*.deb \
 && for p in apt libapt-pkg6.0t64; do dpkg --compare-versions "$(dpkg-query -W -f='${Version}' "$p")" ge 2.8.4ubuntu0.1; done \
 && cd / && rm -rf /var/lib/apt/lists/* /usr/local/bin/tar /tmp/security-libs /tmp/security-debs \
 && php -r 'if (PHP_VERSION !== "8.5.12" || phpversion("king") !== "1.0.15-beta") exit(1);' \
 && /opt/king/package/bin/smoke.sh
