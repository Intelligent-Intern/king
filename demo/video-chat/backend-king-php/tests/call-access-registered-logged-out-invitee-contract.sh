#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PHP_BIN="${PHP_BIN:-php}"

if ! "${PHP_BIN}" -m 2>/dev/null | grep -qi '^pdo_sqlite$'; then
  echo "[call-access-registered-logged-out-invitee-contract] SKIP: pdo_sqlite is not available for ${PHP_BIN}" >&2
  exit 0
fi

"${PHP_BIN}" "${SCRIPT_DIR}/call-access-registered-logged-out-invitee-contract.php"
