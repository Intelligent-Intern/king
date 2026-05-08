#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
VIDEOCHAT_DIR="$(cd -- "${SCRIPT_DIR}/.." && pwd)"
LOCAL_ENV_FILE="${VIDEOCHAT_PROD_DEBUG_ENV_FILE:-${VIDEOCHAT_DIR}/.env.local}"
TIMEOUT="${VIDEOCHAT_PROD_DEBUG_TIMEOUT:-12}"
LOG_TAIL="${VIDEOCHAT_PROD_DEBUG_LOG_TAIL:-120}"

log() {
  printf '[videochat-prod-debug] %s\n' "$*"
}

warn() {
  printf '[videochat-prod-debug] WARN: %s\n' "$*" >&2
}

fail() {
  printf '[videochat-prod-debug] ERROR: %s\n' "$*" >&2
  exit 1
}

usage() {
  cat <<'USAGE'
Usage:
  demo/video-chat/scripts/prod-debug.sh

Optional environment:
  VIDEOCHAT_PROD_DEBUG_TIMEOUT   Public probe timeout in seconds, default: 12.
  VIDEOCHAT_PROD_DEBUG_LOG_TAIL  Recent remote log lines per service, default: 120.
  VIDEOCHAT_PROD_DEBUG_SKIP_SSH  Skip remote SSH diagnostics, default: 0.
  VIDEOCHAT_PROD_DEBUG_ENV_FILE  Env file to read, default: demo/video-chat/.env.local.

The command reads the configured env file for deploy host/domain settings.
USAGE
}

require_cmd() {
  command -v "$1" >/dev/null 2>&1 || fail "missing required command: $1"
}

shell_quote() {
  printf '%q' "$1"
}

load_local_env() {
  [[ -f "${LOCAL_ENV_FILE}" ]] || return 0
  set -a
  # shellcheck source=/dev/null
  source "${LOCAL_ENV_FILE}"
  set +a
}

redact_stream() {
  sed -E \
    -e 's/([A-Za-z0-9_]*(TOKEN|SECRET|PASSWORD|PASS|KEY|AUTH|COOKIE|SESSION)[A-Za-z0-9_]*=)[^[:space:]]+/\1[REDACTED]/Ig' \
    -e 's/(Authorization:[[:space:]]*Bearer[[:space:]]+)[A-Za-z0-9._~+\/=-]+/\1[REDACTED]/Ig' \
    -e 's/((token|secret|password|pass|key|auth|cookie|session)["'\'']?[[:space:]]*[:=][[:space:]]*["'\'']?)[^"'\''[:space:],}]+/\1[REDACTED]/Ig'
}

curl_code() {
  local label="$1" url="$2" output code
  output="$(mktemp)"
  code="$(curl -sS --max-time "${TIMEOUT}" -o "${output}" -w '%{http_code}' "${url}" || true)"
  printf '[videochat-prod-debug] %-32s HTTP %s %s\n' "${label}" "${code:-000}" "${url}"
  if [[ -s "${output}" && "${label}" == "api health" ]]; then
    redact_stream <"${output}" | head -c 1200
    printf '\n'
  fi
  rm -f "${output}"
}

curl_head_code() {
  local label="$1" url="$2" code
  code="$(curl -sS -I --max-time "${TIMEOUT}" -o /dev/null -w '%{http_code}' "${url}" || true)"
  printf '[videochat-prod-debug] %-32s HTTP %s %s\n' "${label}" "${code:-000}" "${url}"
}

websocket_handshake_probe() {
  local label="$1" url="$2" headers body code header_code
  headers="$(mktemp)"
  body="$(mktemp)"
  code="$(
    curl -sS --http1.1 --max-time "${TIMEOUT}" \
      -D "${headers}" \
      -o "${body}" \
      -w '%{http_code}' \
      -H 'Connection: Upgrade' \
      -H 'Upgrade: websocket' \
      -H 'Sec-WebSocket-Key: dGhlIHNhbXBsZSBub25jZQ==' \
      -H 'Sec-WebSocket-Version: 13' \
      "${url}" || true
  )"
  if [[ "${code}" == "000" ]]; then
    header_code="$(awk '/^HTTP\// {code=$2} END {print code}' "${headers}" | tr -d '\r')"
    [[ -n "${header_code}" ]] && code="${header_code}"
  fi
  printf '[videochat-prod-debug] %-32s HTTP %s %s\n' "${label}" "${code:-000}" "${url}"
  if [[ -s "${headers}" ]]; then
    awk 'BEGIN{IGNORECASE=1} /^(HTTP\/|upgrade:|connection:|sec-websocket-accept:)/ {print}' "${headers}" | redact_stream
  fi
  rm -f "${headers}" "${body}"
}

remote_read_only_diagnostics() {
  local deploy_path_q log_tail_q locale_q
  deploy_path_q="$(shell_quote "${DEPLOY_PATH}")"
  log_tail_q="$(shell_quote "${LOG_TAIL}")"
  locale_q="$(shell_quote "${DEPLOY_REMOTE_LOCALE}")"
  ssh "${SSH_ARGS[@]}" "${SSH_DEST}" "LC_ALL=${locale_q} LANG=${locale_q} LANGUAGE= DEPLOY_PATH=${deploy_path_q} LOG_TAIL=${log_tail_q} bash -s" <<'REMOTE' | redact_stream
set -euo pipefail
VIDEOCHAT_DIR="${DEPLOY_PATH}/demo/video-chat"

echo "== remote identity =="
hostname || true
date -u '+%Y-%m-%dT%H:%M:%SZ' || true

if [ ! -d "${VIDEOCHAT_DIR}" ]; then
  echo "remote video-chat directory missing: ${VIDEOCHAT_DIR}"
  exit 0
fi

cd "${VIDEOCHAT_DIR}"
if [ -f docker-compose.deploy.local.yml ]; then
  COMPOSE=(docker compose --env-file .env --env-file .env.local -f docker-compose.v1.yml -f docker-compose.deploy.local.yml --profile edge --profile turn)
elif [ -f docker-compose.v1.yml ]; then
  COMPOSE=(docker compose --env-file .env -f docker-compose.v1.yml --profile edge --profile turn)
else
  echo "compose file missing"
  exit 0
fi

echo "== compose ps =="
"${COMPOSE[@]}" ps || true

echo "== compose service names =="
"${COMPOSE[@]}" config --services || true

echo "== call app marketplace read-only db summary =="
"${COMPOSE[@]}" exec -T videochat-backend-v1 php -r '
function emit_count(SQLite3 $db, string $label, string $sql): void {
    $result = @$db->querySingle($sql);
    if ($result === false || $result === null) {
        echo $label . ": unavailable\n";
        return;
    }
    echo $label . ": " . $result . "\n";
}
if (!class_exists("SQLite3")) {
    echo "sqlite3 extension unavailable\n";
    exit(0);
}
$path = getenv("VIDEOCHAT_KING_DB_PATH") ?: "/data/video-chat.sqlite";
try {
    $db = new SQLite3($path, SQLITE3_OPEN_READONLY);
} catch (Throwable $exception) {
    echo "database unavailable for readonly open\n";
    exit(0);
}
emit_count($db, "catalog_entries", "SELECT count(*) FROM call_app_catalog_entries");
emit_count($db, "healthy_catalog_entries", "SELECT count(*) FROM call_app_catalog_entries WHERE health_status = '\''healthy'\''");
emit_count($db, "enabled_installations", "SELECT count(*) FROM organization_call_app_installations WHERE status = '\''enabled'\''");
emit_count($db, "active_entitlements", "SELECT count(*) FROM organization_call_app_entitlements WHERE status = '\''active'\''");
' || true

for service in videochat-edge-v1 videochat-backend-v1 videochat-backend-ws-v1 videochat-backend-sfu-v1 videochat-frontend-v1 videochat-turn-v1; do
  if "${COMPOSE[@]}" ps -q "${service}" 2>/dev/null | grep -q .; then
    echo "== recent logs: ${service} =="
    "${COMPOSE[@]}" logs --no-color --tail "${LOG_TAIL}" "${service}" || true
  fi
done
REMOTE
}

case "${1:-}" in
  help|-h|--help)
    usage
    exit 0
    ;;
  "")
    ;;
  *)
    usage >&2
    fail "unknown argument: $1"
    ;;
esac

load_local_env
require_cmd curl

DEPLOY_DOMAIN="${VIDEOCHAT_DEPLOY_DOMAIN:-${VIDEOCHAT_V1_PUBLIC_HOST:-}}"
[[ -n "${DEPLOY_DOMAIN}" ]] || fail "VIDEOCHAT_DEPLOY_DOMAIN or VIDEOCHAT_V1_PUBLIC_HOST is required"
DEPLOY_HOST="${VIDEOCHAT_DEPLOY_HOST:-}"
DEPLOY_USER="${VIDEOCHAT_DEPLOY_USER:-root}"
DEPLOY_SSH_PORT="${VIDEOCHAT_DEPLOY_SSH_PORT:-22}"
DEPLOY_PATH="${VIDEOCHAT_DEPLOY_PATH:-/opt/king-videochat}"
DEPLOY_REMOTE_LOCALE="${VIDEOCHAT_DEPLOY_REMOTE_LOCALE:-C.UTF-8}"
DEPLOY_API_DOMAIN="${VIDEOCHAT_DEPLOY_API_DOMAIN:-api.${DEPLOY_DOMAIN}}"
DEPLOY_WS_DOMAIN="${VIDEOCHAT_DEPLOY_WS_DOMAIN:-ws.${DEPLOY_DOMAIN}}"
DEPLOY_SFU_DOMAIN="${VIDEOCHAT_DEPLOY_SFU_DOMAIN:-sfu.${DEPLOY_DOMAIN}}"
DEPLOY_TURN_DOMAIN="${VIDEOCHAT_DEPLOY_TURN_DOMAIN:-turn.${DEPLOY_DOMAIN}}"
DEPLOY_CDN_DOMAIN="${VIDEOCHAT_DEPLOY_CDN_DOMAIN:-cdn.${DEPLOY_DOMAIN}}"
DEPLOY_CALL_APP_DOMAIN="${VIDEOCHAT_DEPLOY_CALL_APP_DOMAIN:-apps.${DEPLOY_DOMAIN}}"
DEPLOY_MOTHERNODE_DOMAIN="${VIDEOCHAT_DEPLOY_MOTHERNODE_DOMAIN:-mother.${DEPLOY_DOMAIN}}"

log "read-only production debug for ${DEPLOY_DOMAIN}"
log "domains: api=${DEPLOY_API_DOMAIN} ws=${DEPLOY_WS_DOMAIN} sfu=${DEPLOY_SFU_DOMAIN} turn=${DEPLOY_TURN_DOMAIN} cdn=${DEPLOY_CDN_DOMAIN} call_app=${DEPLOY_CALL_APP_DOMAIN} mothernode=${DEPLOY_MOTHERNODE_DOMAIN}"

log "public HTTP health and asset probes"
curl_head_code "frontend" "https://${DEPLOY_DOMAIN}/"
curl_code "api health" "https://${DEPLOY_API_DOMAIN}/health"
curl_code "api version" "https://${DEPLOY_API_DOMAIN}/api/version"
curl_head_code "cdn root" "https://${DEPLOY_CDN_DOMAIN}/"
curl_head_code "call app whiteboard" "https://${DEPLOY_CALL_APP_DOMAIN}/call-app/whiteboard/public/index.html"
curl_head_code "mothernode host" "https://${DEPLOY_MOTHERNODE_DOMAIN}/"

log "public websocket reachability probes"
websocket_handshake_probe "lobby websocket host" "https://${DEPLOY_WS_DOMAIN}/ws?room=prod-debug"
websocket_handshake_probe "lobby websocket api fallback" "https://${DEPLOY_API_DOMAIN}/ws?room=prod-debug"
websocket_handshake_probe "sfu websocket host" "https://${DEPLOY_SFU_DOMAIN}/sfu?room_id=prod-debug"
websocket_handshake_probe "sfu websocket api fallback" "https://${DEPLOY_API_DOMAIN}/sfu?room_id=prod-debug"

case "${VIDEOCHAT_PROD_DEBUG_SKIP_SSH:-0}" in
  1|true|TRUE|yes|YES)
    log "remote SSH diagnostics skipped"
    exit 0
    ;;
esac

if [[ -z "${DEPLOY_HOST}" ]]; then
  warn "VIDEOCHAT_DEPLOY_HOST is not set; skipping remote compose/log diagnostics"
  exit 0
fi

require_cmd ssh
SSH_DEST="${DEPLOY_USER}@${DEPLOY_HOST}"
SSH_ARGS=(-p "${DEPLOY_SSH_PORT}" -o BatchMode=yes -o StrictHostKeyChecking=accept-new)
if [[ -n "${VIDEOCHAT_DEPLOY_SSH_KEY:-}" ]]; then
  SSH_ARGS+=(-i "${VIDEOCHAT_DEPLOY_SSH_KEY}")
fi

log "remote read-only diagnostics from ${SSH_DEST}"
remote_read_only_diagnostics
