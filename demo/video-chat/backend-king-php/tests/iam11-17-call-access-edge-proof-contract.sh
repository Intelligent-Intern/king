#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
PHP_BIN="${PHP_BIN:-php}"

if ! "${PHP_BIN}" -m 2>/dev/null | grep -qi '^pdo_sqlite$'; then
  echo "[iam11-17-call-access-edge-proof-contract] SKIP: pdo_sqlite is not available for ${PHP_BIN}" >&2
  exit 0
fi

"${PHP_BIN}" "${SCRIPT_DIR}/iam11-17-call-access-edge-proof-contract.php"
