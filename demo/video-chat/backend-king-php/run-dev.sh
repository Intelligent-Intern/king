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
DEFAULT_HTTP_WORKERS="${VIDEOCHAT_KING_DEFAULT_HTTP_WORKERS:-24}"
DEFAULT_WS_WORKERS="${VIDEOCHAT_KING_DEFAULT_WS_WORKERS:-8}"
HTTP_WORKERS="${VIDEOCHAT_KING_HTTP_WORKERS:-${VIDEOCHAT_KING_WORKERS:-${DEFAULT_HTTP_WORKERS}}}"
WS_WORKERS="${VIDEOCHAT_KING_WS_WORKERS:-${VIDEOCHAT_KING_WORKERS:-${DEFAULT_WS_WORKERS}}}"

php_args=("-d" "king.security_allow_config_override=1")
ext_source=""

append_php_ini_if_set() {
  local env_name="$1"
  local ini_name="$2"
  local value="${!env_name:-}"
  if [[ -n "${value}" ]]; then
    php_args+=("-d" "${ini_name}=${value}")
  fi
}

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

if [[ -n "${VIDEOCHAT_OTEL_EXPORTER_ENDPOINT:-}" && -z "${VIDEOCHAT_OTEL_ENABLE:-}" ]]; then
  VIDEOCHAT_OTEL_ENABLE=1
fi

append_php_ini_if_set VIDEOCHAT_OTEL_ENABLE king.otel_enable
append_php_ini_if_set VIDEOCHAT_OTEL_SERVICE_NAME king.otel_service_name
append_php_ini_if_set VIDEOCHAT_OTEL_EXPORTER_ENDPOINT king.otel_exporter_endpoint
append_php_ini_if_set VIDEOCHAT_OTEL_EXPORTER_PROTOCOL king.otel_exporter_protocol
append_php_ini_if_set VIDEOCHAT_OTEL_EXPORTER_TIMEOUT_MS king.otel_exporter_timeout_ms
append_php_ini_if_set VIDEOCHAT_OTEL_QUEUE_STATE_PATH king.otel_queue_state_path
append_php_ini_if_set VIDEOCHAT_OTEL_BATCH_MAX_QUEUE_SIZE king.otel_batch_processor_max_queue_size
append_php_ini_if_set VIDEOCHAT_OTEL_METRICS_ENABLE king.otel_metrics_enable
append_php_ini_if_set VIDEOCHAT_OTEL_METRICS_EXPORT_INTERVAL_MS king.otel_metrics_export_interval_ms
append_php_ini_if_set VIDEOCHAT_OTEL_LOGS_ENABLE king.otel_logs_enable
append_php_ini_if_set VIDEOCHAT_OTEL_LOGS_EXPORTER_BATCH_SIZE king.otel_logs_exporter_batch_size

# Fail fast when sqlite driver support is unavailable.
if ! "${PHP_BIN}" "${php_args[@]}" -m | grep -Eiq '^pdo_sqlite$'; then
  echo "[video-chat][king-php-backend] Missing required PHP extension: pdo_sqlite." >&2
  echo "Install/enable pdo_sqlite (or use docker-compose.v1.yml backend image)." >&2
  exit 1
fi

echo "[video-chat][king-php-backend] extension source: ${ext_source}"
if [[ -n "${VIDEOCHAT_OTEL_EXPORTER_ENDPOINT:-}" ]]; then
  echo "[video-chat][king-php-backend] otlp exporter endpoint: ${VIDEOCHAT_OTEL_EXPORTER_ENDPOINT}"
fi
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
  local reuseport_value="${KING_HTTP1_ENABLE_REUSEPORT:-}"
  if [[ -z "${reuseport_value}" && "${worker_count}" -gt 1 ]]; then
    reuseport_value="1"
  fi

  local worker_index=1
  while (( worker_index <= worker_count )); do
    KING_HTTP1_ENABLE_REUSEPORT="${reuseport_value}" \
    VIDEOCHAT_KING_SKIP_BOOTSTRAP=1 \
    VIDEOCHAT_KING_PORT="${bind_port}" \
    VIDEOCHAT_KING_SERVER_MODE="${mode}" \
    VIDEOCHAT_KING_WORKER_INDEX="${worker_index}" \
    VIDEOCHAT_KING_WORKER_COUNT="${worker_count}" \
    "${PHP_BIN}" "${php_args[@]}" "${SCRIPT_DIR}/server.php" &
    backend_pids+=("$!")
    worker_index=$((worker_index + 1))
  done

  echo "[video-chat][king-php-backend] started ${worker_count} worker(s) in ${mode} mode on port ${bind_port}"
  if [[ "${reuseport_value}" == "1" && "${worker_count}" -gt 1 ]]; then
    echo "[video-chat][king-php-backend] enabled SO_REUSEPORT for trusted ${mode} worker group"
  fi
}

run_parent_bootstrap() {
  local mode="$1"
  if [[ "${VIDEOCHAT_KING_SKIP_PARENT_BOOTSTRAP:-0}" =~ ^(1|true|TRUE|yes|YES|on|ON)$ ]]; then
    echo "[video-chat][king-php-backend] parent sqlite bootstrap skipped by VIDEOCHAT_KING_SKIP_PARENT_BOOTSTRAP"
    return
  fi

  echo "[video-chat][king-php-backend] running parent sqlite bootstrap before workers"
  VIDEOCHAT_KING_BOOTSTRAP_ONLY=1 \
  VIDEOCHAT_KING_SKIP_BOOTSTRAP=0 \
  VIDEOCHAT_KING_SERVER_MODE="${mode}" \
  VIDEOCHAT_KING_WORKER_INDEX=0 \
  VIDEOCHAT_KING_WORKER_COUNT=0 \
  "${PHP_BIN}" "${php_args[@]}" "${SCRIPT_DIR}/server.php"
}

normalized_mode_override="$(echo "${SERVER_MODE_OVERRIDE}" | tr '[:upper:]' '[:lower:]' | xargs || true)"
bootstrap_mode="${normalized_mode_override}"
if [[ "${bootstrap_mode}" != "all" && "${bootstrap_mode}" != "http" && "${bootstrap_mode}" != "ws" ]]; then
  bootstrap_mode="all"
fi
run_parent_bootstrap "${bootstrap_mode}"
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
