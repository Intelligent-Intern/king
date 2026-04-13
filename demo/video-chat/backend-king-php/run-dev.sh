#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "${SCRIPT_DIR}/../../.." && pwd)"
HOST="${VIDEOCHAT_KING_HOST:-127.0.0.1}"
PORT="${VIDEOCHAT_KING_PORT:-18080}"
WS_PATH="${VIDEOCHAT_KING_WS_PATH:-/ws}"
DB_PATH="${VIDEOCHAT_KING_DB_PATH:-${REPO_ROOT}/demo/video-chat/backend-king-php/.local/video-chat.sqlite}"
PHP_BIN="${PHP_BIN:-php}"
DEFAULT_EXT="${REPO_ROOT}/extension/modules/king.so"
KING_EXTENSION_PATH="${KING_EXTENSION_PATH:-${DEFAULT_EXT}}"

php_args=()
ext_source=""

if "${PHP_BIN}" -m | grep -Eiq '^king$'; then
  ext_source="php.ini"
elif [[ -f "${KING_EXTENSION_PATH}" ]]; then
  php_args+=("-d" "extension=${KING_EXTENSION_PATH}")
  ext_source="${KING_EXTENSION_PATH}"
else
  echo "[video-chat][king-php-backend] King extension not found." >&2
  echo "Set KING_EXTENSION_PATH or build extension/modules/king.so first." >&2
  exit 1
fi

echo "[video-chat][king-php-backend] extension source: ${ext_source}"
echo "[video-chat][king-php-backend] starting http://$HOST:$PORT/"
echo "[video-chat][king-php-backend] websocket ws://$HOST:$PORT$WS_PATH"
mkdir -p "$(dirname "${DB_PATH}")"
touch "${DB_PATH}"
export VIDEOCHAT_KING_DB_PATH="${DB_PATH}"
echo "[video-chat][king-php-backend] sqlite path ${DB_PATH}"

"${PHP_BIN}" "${php_args[@]}" "${SCRIPT_DIR}/server.php" &
backend_pid=$!

cleanup() {
  local signal="${1:-SIGTERM}"
  echo "[video-chat][king-php-backend] forwarding ${signal} to pid ${backend_pid}" >&2
  kill -s "${signal}" "${backend_pid}" >/dev/null 2>&1 || true
  wait "${backend_pid}" 2>/dev/null || true
  echo "[video-chat][king-php-backend] stopped" >&2
}

trap 'cleanup SIGINT; exit 0' INT
trap 'cleanup SIGTERM; exit 0' TERM

wait "${backend_pid}"
