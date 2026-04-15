#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "${SCRIPT_DIR}/../../.." && pwd)"
HOST="${MODEL_INFERENCE_KING_HOST:-127.0.0.1}"
PORT="${MODEL_INFERENCE_KING_PORT:-18090}"
WS_PORT="${MODEL_INFERENCE_KING_WS_PORT:-18091}"
WS_PATH="${MODEL_INFERENCE_KING_WS_PATH:-/ws}"
DB_PATH="${MODEL_INFERENCE_KING_DB_PATH:-${REPO_ROOT}/demo/model-inference/backend-king-php/.local/model-inference.sqlite}"
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
  echo "[model-inference][king-php-backend] King extension not found." >&2
  echo "Set KING_EXTENSION_PATH or build extension/modules/king.so first." >&2
  exit 1
fi

if ! "${PHP_BIN}" "${php_args[@]}" -m | grep -Eiq '^pdo_sqlite$'; then
  echo "[model-inference][king-php-backend] Missing required PHP extension: pdo_sqlite." >&2
  echo "Install/enable pdo_sqlite (or use docker-compose.v1.yml backend image)." >&2
  exit 1
fi

echo "[model-inference][king-php-backend] extension source: ${ext_source}"
echo "[model-inference][king-php-backend] starting http://$HOST:$PORT/"
if [[ "$WS_PORT" == "$PORT" ]]; then
  echo "[model-inference][king-php-backend] websocket ws://$HOST:$PORT$WS_PATH (shared listener, handshake lands at #M-11)"
else
  echo "[model-inference][king-php-backend] websocket ws://$HOST:$WS_PORT$WS_PATH (dedicated listener, handshake lands at #M-11)"
fi
mkdir -p "$(dirname "${DB_PATH}")"
touch "${DB_PATH}"
export MODEL_INFERENCE_KING_DB_PATH="${DB_PATH}"
echo "[model-inference][king-php-backend] sqlite path ${DB_PATH}"

backend_pids=()

start_backend() {
  local mode="$1"
  local bind_port="$2"
  MODEL_INFERENCE_KING_PORT="${bind_port}" \
  MODEL_INFERENCE_KING_SERVER_MODE="${mode}" \
  "${PHP_BIN}" "${php_args[@]}" "${SCRIPT_DIR}/server.php" &
  backend_pids+=("$!")
}

if [[ "$WS_PORT" == "$PORT" ]]; then
  start_backend "all" "${PORT}"
else
  start_backend "http" "${PORT}"
  start_backend "ws" "${WS_PORT}"
fi

cleanup() {
  local signal="${1:-SIGTERM}"
  for pid in "${backend_pids[@]}"; do
    echo "[model-inference][king-php-backend] forwarding ${signal} to pid ${pid}" >&2
    kill -s "${signal}" "${pid}" >/dev/null 2>&1 || true
  done
  for pid in "${backend_pids[@]}"; do
    wait "${pid}" 2>/dev/null || true
  done
  echo "[model-inference][king-php-backend] stopped" >&2
}

trap 'cleanup SIGINT; exit 0' INT
trap 'cleanup SIGTERM; exit 0' TERM

if [[ "${#backend_pids[@]}" -eq 1 ]]; then
  wait "${backend_pids[0]}"
  exit $?
fi

wait -n "${backend_pids[@]}"
exit_code=$?
cleanup SIGTERM
exit "${exit_code}"
