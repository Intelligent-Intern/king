#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
BACKEND_ROOT="$(cd "${SCRIPT_DIR}/.." && pwd)"
REPO_ROOT="$(cd "${BACKEND_ROOT}/../../.." && pwd)"
PHP_BIN="${PHP_BIN:-php}"
DOCKER_BIN="${DOCKER_BIN:-docker}"
DOCKER_IMAGE="${IAM_SQLITE_PHP_IMAGE:-php:8.4-cli-trixie}"

DEFAULT_CONTRACTS=(
  "call-access-admin-prevention-contract.sh"
  "call-access-cross-org-contract.sh"
  "call-access-membership-removal-contract.sh"
  "call-access-session-route-guard-contract.sh"
  "call-access-stale-organization-role-contract.sh"
  "call-access-strong-mismatch-privacy-contract.sh"
  "call-access-terminal-join-contract.sh"
  "call-calendar-invitation-flow-contract.sh"
  "call-guest-list-direct-join-contract.sh"
)

if [[ -n "${IAM_SQLITE_CONTRACTS:-}" ]]; then
  # Optional space-separated override for broader/manual backend sweeps.
  read -r -a CONTRACTS <<<"${IAM_SQLITE_CONTRACTS}"
else
  CONTRACTS=("${DEFAULT_CONTRACTS[@]}")
fi

php_has_pdo_sqlite() {
  "${PHP_BIN}" -m 2>/dev/null | grep -qi '^pdo_sqlite$'
}

run_contracts_with_php_bin() {
  echo "[iam-call-access-sqlite-runtime-proof] PHP runtime: $("${PHP_BIN}" -r 'echo PHP_VERSION;' 2>/dev/null)"
  "${PHP_BIN}" -m | grep -i '^pdo_sqlite$'
  for contract in "${CONTRACTS[@]}"; do
    echo "[iam-call-access-sqlite-runtime-proof] ${contract}"
    PHP_BIN="${PHP_BIN}" "${SCRIPT_DIR}/${contract}"
  done
}

run_contracts_with_docker() {
  if ! command -v "${DOCKER_BIN}" >/dev/null 2>&1; then
    echo "[iam-call-access-sqlite-runtime-proof] FAIL: ${PHP_BIN} lacks pdo_sqlite and ${DOCKER_BIN} is unavailable" >&2
    exit 1
  fi

  echo "[iam-call-access-sqlite-runtime-proof] Host PHP lacks pdo_sqlite; using container fallback"
  echo "[iam-call-access-sqlite-runtime-proof] Container runtime: ${DOCKER_IMAGE}"
  "${DOCKER_BIN}" run --rm \
    -v "${REPO_ROOT}:/workspace" \
    -w /workspace/demo/video-chat/backend-king-php \
    -e PHP_BIN=php \
    -e IAM_SQLITE_CONTRACTS="${CONTRACTS[*]}" \
    "${DOCKER_IMAGE}" \
    bash -lc '
      set -euo pipefail
      if ! php -m | grep -qi "^pdo_sqlite$"; then
        if command -v docker-php-ext-install >/dev/null 2>&1; then
          export DEBIAN_FRONTEND=noninteractive
          apt-get update >/tmp/iam-sqlite-apt-update.log
          apt-get install -y --no-install-recommends libsqlite3-dev >/tmp/iam-sqlite-apt-install.log
          docker-php-ext-install pdo_sqlite >/tmp/iam-sqlite-pdo-build.log
        fi
      fi
      php -m | grep -i "^pdo_sqlite$"
      for contract in ${IAM_SQLITE_CONTRACTS}; do
        echo "[iam-call-access-sqlite-runtime-proof] ${contract}"
        tests/"${contract}"
      done
    '
}

if php_has_pdo_sqlite; then
  run_contracts_with_php_bin
else
  run_contracts_with_docker
fi

echo "[iam-call-access-sqlite-runtime-proof] PASS"
