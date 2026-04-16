#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "${SCRIPT_DIR}/../../../.." && pwd)"
PHP_BIN="${PHP_BIN:-php}"
DEFAULT_EXT="${REPO_ROOT}/extension/modules/king.so"
KING_EXTENSION_PATH="${KING_EXTENSION_PATH:-${DEFAULT_EXT}}"

php_args=()
if "${PHP_BIN}" -m | grep -Eiq '^king$'; then
  :
elif [[ -f "${KING_EXTENSION_PATH}" ]]; then
  php_args+=("-d" "extension=${KING_EXTENSION_PATH}")
else
  echo "[model-registry-contract] SKIP: King extension not found (set KING_EXTENSION_PATH or build extension/modules/king.so first)." >&2
  exit 0
fi
php_args+=("-d" "king.security_allow_config_override=1")

# Refuse to skip if the provided extension refuses to load — that is a real
# failure, not a "not available" case.
if ! "${PHP_BIN}" "${php_args[@]}" -r 'exit(extension_loaded("king") ? 0 : 1);' 2>/dev/null; then
  echo "[model-registry-contract] FAIL: King extension path present but failed to load. Run inside the dev container or rebuild." >&2
  exit 1
fi

"${PHP_BIN}" "${php_args[@]}" "${SCRIPT_DIR}/model-registry-contract.php"
