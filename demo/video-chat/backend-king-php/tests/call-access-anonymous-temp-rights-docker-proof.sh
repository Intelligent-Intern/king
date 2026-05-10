#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
BACKEND_ROOT="$(cd "${SCRIPT_DIR}/.." && pwd)"
REPO_ROOT="$(cd "${BACKEND_ROOT}/../../.." && pwd)"
PHP_BIN="${PHP_BIN:-php}"
DOCKER_BIN="${DOCKER_BIN:-docker}"
DOCKER_IMAGE="${IAM_ANONYMOUS_TEMP_RIGHTS_PHP_IMAGE:-php:8.4-cli-trixie}"
CONTRACT="call-access-anonymous-temp-rights-contract.php"
LOG_PREFIX="[call-access-anonymous-temp-rights-docker-proof]"

php_has_pdo_sqlite() {
  "${PHP_BIN}" -m 2>/dev/null | grep -qi '^pdo_sqlite$'
}

run_contract_with_php_bin() {
  echo "${LOG_PREFIX} PHP runtime: $("${PHP_BIN}" -r 'echo PHP_VERSION;' 2>/dev/null)"
  "${PHP_BIN}" -m | grep -i '^pdo_sqlite$'
  "${PHP_BIN}" "${SCRIPT_DIR}/${CONTRACT}"
}

run_contract_with_docker() {
  if ! command -v "${DOCKER_BIN}" >/dev/null 2>&1; then
    echo "${LOG_PREFIX} FAIL: ${PHP_BIN} lacks pdo_sqlite and ${DOCKER_BIN} is unavailable" >&2
    exit 1
  fi

  echo "${LOG_PREFIX} Host PHP lacks pdo_sqlite; using container fallback"
  echo "${LOG_PREFIX} Container runtime: ${DOCKER_IMAGE}"
  "${DOCKER_BIN}" run --rm \
    -v "${REPO_ROOT}:/workspace" \
    -w /workspace/demo/video-chat/backend-king-php \
    "${DOCKER_IMAGE}" \
    bash -lc '
      set -euo pipefail
      if ! php -m | grep -qi "^pdo_sqlite$"; then
        if command -v docker-php-ext-install >/dev/null 2>&1; then
          export DEBIAN_FRONTEND=noninteractive
          apt-get update >/tmp/iam-anonymous-temp-rights-apt-update.log
          apt-get install -y --no-install-recommends libsqlite3-dev >/tmp/iam-anonymous-temp-rights-apt-install.log
          docker-php-ext-install pdo_sqlite >/tmp/iam-anonymous-temp-rights-pdo-build.log
        fi
      fi
      php -m | grep -i "^pdo_sqlite$"
      php tests/call-access-anonymous-temp-rights-contract.php
    '
}

if php_has_pdo_sqlite; then
  run_contract_with_php_bin
else
  run_contract_with_docker
fi

echo "${LOG_PREFIX} PASS"
