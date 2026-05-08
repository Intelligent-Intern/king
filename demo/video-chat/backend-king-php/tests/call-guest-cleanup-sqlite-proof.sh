#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
BACKEND_ROOT="$(cd "${SCRIPT_DIR}/.." && pwd)"
REPO_ROOT="$(cd "${BACKEND_ROOT}/../../.." && pwd)"
PHP_BIN="${PHP_BIN:-php}"
DOCKER_BIN="${DOCKER_BIN:-docker}"
DOCKER_IMAGE="${GUEST_CLEANUP_SQLITE_PHP_IMAGE:-php:8.4-cli-trixie}"
GUEST_CLEANUP_CONTRACTS=(
  call-guest-cleanup-explicit-contract.sh
  call-guest-cleanup-invitation-delete-contract.sh
  call-guest-cleanup-restart-contract.sh
  call-guest-cleanup-lifecycle-remaining-contract.sh
  call-guest-cleanup-call-end-contract.sh
  call-guest-cleanup-call-delete-contract.sh
)

php_has_pdo_sqlite() {
  "${PHP_BIN}" -m 2>/dev/null | grep -qi '^pdo_sqlite$'
}

run_with_php_bin() {
  echo "[call-guest-cleanup-sqlite-proof] PHP runtime: $("${PHP_BIN}" -r 'echo PHP_VERSION;' 2>/dev/null)"
  "${PHP_BIN}" -m | grep -i '^pdo_sqlite$'
  local contract
  for contract in "${GUEST_CLEANUP_CONTRACTS[@]}"; do
    "${SCRIPT_DIR}/${contract}"
  done
}

run_with_docker() {
  if ! command -v "${DOCKER_BIN}" >/dev/null 2>&1; then
    echo "[call-guest-cleanup-sqlite-proof] FAIL: ${PHP_BIN} lacks pdo_sqlite and ${DOCKER_BIN} is unavailable" >&2
    exit 1
  fi

  echo "[call-guest-cleanup-sqlite-proof] Container runtime: ${DOCKER_IMAGE}"
  "${DOCKER_BIN}" run --rm \
    -v "${REPO_ROOT}:/workspace" \
    -w /workspace/demo/video-chat/backend-king-php \
    -e PHP_BIN=php \
    "${DOCKER_IMAGE}" \
    bash -lc '
      set -euo pipefail
      php -m | grep -i "^pdo_sqlite$"
      tests/call-guest-cleanup-explicit-contract.sh
      tests/call-guest-cleanup-invitation-delete-contract.sh
      tests/call-guest-cleanup-restart-contract.sh
      tests/call-guest-cleanup-lifecycle-remaining-contract.sh
      tests/call-guest-cleanup-call-end-contract.sh
      tests/call-guest-cleanup-call-delete-contract.sh
    '
}

if php_has_pdo_sqlite; then
  run_with_php_bin
else
  echo "[call-guest-cleanup-sqlite-proof] Host PHP lacks pdo_sqlite; using container fallback"
  run_with_docker
fi

echo "[call-guest-cleanup-sqlite-proof] PASS"
