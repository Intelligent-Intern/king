#!/usr/bin/env bash
set -euo pipefail

PHP_BIN="${PHP_BIN:-php}"
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

if ! "${PHP_BIN}" -m | grep -qi "pdo_sqlite"; then
  echo "[realtime-activity-layout-contract] SKIP: pdo_sqlite is not available for ${PHP_BIN}" >&2
  exit 0
fi

"${PHP_BIN}" "${SCRIPT_DIR}/realtime-activity-layout-contract.php"
