#!/usr/bin/env bash
set -euo pipefail
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PHP_BIN="${PHP_BIN:-php}"
if ! "${PHP_BIN}" -m | grep -qi '^pdo_sqlite$'; then
  echo "[localization-schema-contract] SKIP: pdo_sqlite is not available for ${PHP_BIN}" >&2
  exit 0
fi

"${PHP_BIN}" "${SCRIPT_DIR}/localization-schema-contract.php"
