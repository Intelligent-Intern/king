#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "${SCRIPT_DIR}/../../.." && pwd)"
HOST="${VIDEOCHAT_KING_HOST:-127.0.0.1}"
PORT="${VIDEOCHAT_KING_PORT:-18080}"
WS_PORT="${VIDEOCHAT_KING_WS_PORT:-${PORT}}"
WS_PATH="${VIDEOCHAT_KING_WS_PATH:-/ws}"
DB_PATH="${VIDEOCHAT_KING_DB_PATH:-${REPO_ROOT}/demo/video-chat/backend-king-php/.local/video-chat.sqlite}"
PHP_BIN="${PHP_BIN:-php}"
DEFAULT_EXT="${REPO_ROOT}/extension/modules/king.so"
KING_EXTENSION_PATH="${KING_EXTENSION_PATH:-${DEFAULT_EXT}}"
SERVER_MODE_OVERRIDE="${VIDEOCHAT_KING_SERVER_MODE:-}"
DEFAULT_WORKERS="${VIDEOCHAT_KING_WORKERS:-1}"
HTTP_WORKERS="${VIDEOCHAT_KING_HTTP_WORKERS:-${DEFAULT_WORKERS}}"
WS_WORKERS="${VIDEOCHAT_KING_WS_WORKERS:-${DEFAULT_WORKERS}}"

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

# Fail fast when sqlite driver support is unavailable.
if ! "${PHP_BIN}" "${php_args[@]}" -m | grep -Eiq '^pdo_sqlite$'; then
  echo "[video-chat][king-php-backend] Missing required PHP extension: pdo_sqlite." >&2
  echo "Install/enable pdo_sqlite (or use docker-compose.v1.yml backend image)." >&2
  exit 1
fi

echo "[video-chat][king-php-backend] extension source: ${ext_source}"
echo "[video-chat][king-php-backend] starting http://$HOST:$PORT/"
if [[ "$WS_PORT" == "$PORT" ]]; then
  echo "[video-chat][king-php-backend] websocket ws://$HOST:$PORT$WS_PATH (shared listener)"
else
  echo "[video-chat][king-php-backend] websocket ws://$HOST:$WS_PORT$WS_PATH (dedicated listener)"
fi
mkdir -p "$(dirname "${DB_PATH}")"
touch "${DB_PATH}"
export VIDEOCHAT_KING_DB_PATH="${DB_PATH}"
export VIDEOCHAT_DEMO_SEED_CALLS="${VIDEOCHAT_DEMO_SEED_CALLS:-1}"
echo "[video-chat][king-php-backend] sqlite path ${DB_PATH}"

backend_pids=()

normalize_worker_count() {
  local raw="$1"
  if [[ ! "${raw}" =~ ^[0-9]+$ ]]; then
    echo "1"
    return
  fi
  if (( raw < 1 )); then
    echo "1"
    return
  fi
  if (( raw > 64 )); then
    echo "64"
    return
  fi
  echo "${raw}"
}

worker_count_for_mode() {
  local mode="$1"
  case "${mode}" in
    ws)
      normalize_worker_count "${WS_WORKERS}"
      ;;
    http|all)
      normalize_worker_count "${HTTP_WORKERS}"
      ;;
    *)
      echo "1"
      ;;
  esac
}

start_backend() {
  local mode="$1"
  local bind_port="$2"
  local worker_count
  worker_count="$(worker_count_for_mode "${mode}")"

  local worker_index=1
  while (( worker_index <= worker_count )); do
    VIDEOCHAT_KING_PORT="${bind_port}" \
    VIDEOCHAT_KING_SERVER_MODE="${mode}" \
    VIDEOCHAT_KING_WORKER_INDEX="${worker_index}" \
    VIDEOCHAT_KING_WORKER_COUNT="${worker_count}" \
    "${PHP_BIN}" "${php_args[@]}" "${SCRIPT_DIR}/server.php" &
    backend_pids+=("$!")
    worker_index=$((worker_index + 1))
  done

  echo "[video-chat][king-php-backend] started ${worker_count} worker(s) in ${mode} mode on port ${bind_port}"
}

normalized_mode_override="$(echo "${SERVER_MODE_OVERRIDE}" | tr '[:upper:]' '[:lower:]' | xargs || true)"
if [[ "${normalized_mode_override}" == "all" || "${normalized_mode_override}" == "http" || "${normalized_mode_override}" == "ws" ]]; then
  echo "[video-chat][king-php-backend] server mode override: ${normalized_mode_override}"
  start_backend "${normalized_mode_override}" "${PORT}"
elif [[ "$WS_PORT" == "$PORT" ]]; then
  start_backend "all" "${PORT}"
else
  start_backend "http" "${PORT}"
  start_backend "ws" "${WS_PORT}"
fi

cleanup() {
  local signal="${1:-SIGTERM}"
  for pid in "${backend_pids[@]}"; do
    echo "[video-chat][king-php-backend] forwarding ${signal} to pid ${pid}" >&2
    kill -s "${signal}" "${pid}" >/dev/null 2>&1 || true
  done
  for pid in "${backend_pids[@]}"; do
    wait "${pid}" 2>/dev/null || true
  done
  echo "[video-chat][king-php-backend] stopped" >&2
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
