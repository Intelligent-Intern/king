#!/usr/bin/env bash
set -euo pipefail

PHP_BIN="${PHP_BIN:-php}"
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

if ! "${PHP_BIN}" -m | grep -qi "pdo_sqlite"; then
  echo "[call-access-cross-org-contract] Host PHP lacks pdo_sqlite; using IAM3-15 Docker runtime proof" >&2
  IAM3_15_CONTRACTS="call-access-cross-org-contract.php" "${SCRIPT_DIR}/call-access-cross-org-stale-role-docker-proof.sh"
  exit $?
fi

"${PHP_BIN}" "${SCRIPT_DIR}/call-access-cross-org-contract.php"
