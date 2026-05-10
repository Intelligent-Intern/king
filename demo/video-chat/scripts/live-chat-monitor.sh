#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
VIDEOCHAT_DIR="$(cd -- "${SCRIPT_DIR}/.." && pwd)"
LOCAL_ENV_FILE="${VIDEOCHAT_DIR}/.env.local"
STATE_DIR="${VIDEOCHAT_LIVE_CHAT_MONITOR_STATE_DIR:-${VIDEOCHAT_DIR}/.local/live-chat-monitor}"
PID_FILE="${STATE_DIR}/monitor.pid"
LOG_FILE="${STATE_DIR}/monitor.log"
HEARTBEAT_FILE="${STATE_DIR}/heartbeat"
SEQ_FILE="${STATE_DIR}/last-seq"
POLL_SECONDS="${VIDEOCHAT_LIVE_CHAT_POLL_SECONDS:-60}"
WATCHDOG_STALE_SECONDS="${VIDEOCHAT_LIVE_CHAT_WATCHDOG_STALE_SECONDS:-900}"
CALL_ID="${VIDEOCHAT_LIVE_CHAT_CALL_ID:-39c5b3ea-855b-40fd-b030-c8af1d512605}"
ROOM_ID="${VIDEOCHAT_LIVE_CHAT_ROOM_ID:-${CALL_ID}}"
REPORTER_USER_ID="${VIDEOCHAT_LIVE_CHAT_REPORTER_USER_ID:-80}"
READER_USER_ID="${VIDEOCHAT_LIVE_CHAT_READER_USER_ID:-0}"
TAIL_LIMIT="${VIDEOCHAT_LIVE_CHAT_TAIL_LIMIT:-50}"
TIMEOUT="${VIDEOCHAT_LIVE_CHAT_TIMEOUT:-12}"
DRY_RUN=0

log() {
  printf '[live-chat-monitor] %s\n' "$*"
}

fail() {
  printf '[live-chat-monitor] ERROR: %s\n' "$*" >&2
  exit 1
}

require_cmd() {
  command -v "$1" >/dev/null 2>&1 || fail "missing required command: $1"
}

shell_quote() {
  printf '%q' "$1"
}

redact_stream() {
  sed -E \
    -e 's/(authorization:[[:space:]]*bearer[[:space:]]+)[^[:space:]]+/\1[REDACTED]/Ig' \
    -e 's/(authorization:[[:space:]]*basic[[:space:]]+)[^[:space:]]+/\1[REDACTED]/Ig' \
    -e 's/(bearer[[:space:]]+)[A-Za-z0-9._~+\/=-]+/\1[REDACTED]/Ig' \
    -e 's/(set-cookie:[[:space:]]*)[^[:space:];]+=[^;[:space:]]+/\1[REDACTED]/Ig' \
    -e 's/([A-Za-z_][A-Za-z0-9_]*(TOKEN|SECRET|PASSWORD|PASS|KEY|CREDENTIAL|COOKIE|SESSION)[A-Za-z0-9_]*=)[^[:space:]]+/\1[REDACTED]/Ig' \
    -e 's/("(token|secret|password|pass|key|credential|cookie|session)[^"]*"[[:space:]]*:[[:space:]]*")[^"]+/\1[REDACTED]/Ig' \
    -e 's/(([?&][^=&[:space:]]*(token|secret|password|pass|key|credential|cookie|session)[^=&[:space:]]*=))[^&[:space:]]+/\1[REDACTED]/Ig' \
    -e 's/("(sdp|candidate|ice|media_)?(payload|frame|frame_data|image_data|audio_data|video_data|encoded|binary|bytes)"[[:space:]]*:[[:space:]]*")[^"]+/\1[REDACTED_MEDIA_PAYLOAD]/Ig' \
    -e 's/(data:(image|video|audio)\/[A-Za-z0-9.+-]+;base64,)[A-Za-z0-9+\/=._~-]+/\1[REDACTED_MEDIA_PAYLOAD]/Ig'
}

normalize_flag() {
  case "${1:-}" in
    1|true|TRUE|yes|YES|on|ON) return 0 ;;
    *) return 1 ;;
  esac
}

trim_env_value() {
  local value="$1"
  value="${value#"${value%%[![:space:]]*}"}"
  value="${value%"${value##*[![:space:]]}"}"
  printf '%s' "${value}"
}

parse_env_value() {
  local value
  value="$(trim_env_value "$1")"
  if [[ "${value}" == \"* ]]; then
    [[ "${#value}" -ge 2 && "${value}" == *\" ]] || return 1
    value="${value:1:${#value}-2}"
    value="${value//\\\"/\"}"
    value="${value//\\\\/\\}"
  elif [[ "${value}" == \'* ]]; then
    [[ "${#value}" -ge 2 && "${value}" == *\' ]] || return 1
    value="${value:1:${#value}-2}"
  fi
  printf '%s' "${value}"
}

load_local_env() {
  [[ -f "${LOCAL_ENV_FILE}" ]] || return 0
  local allowed_names=(
    VIDEOCHAT_DEPLOY_DOMAIN DEPLOY_DOMAIN
    VIDEOCHAT_DEPLOY_APP_DOMAIN DEPLOY_APP_DOMAIN
    VIDEOCHAT_DEPLOY_API_DOMAIN DEPLOY_API_DOMAIN
    VIDEOCHAT_DEPLOY_WS_DOMAIN DEPLOY_WS_DOMAIN
    VIDEOCHAT_DEPLOY_HOST DEPLOY_HOST
    VIDEOCHAT_DEPLOY_USER DEPLOY_USER
    VIDEOCHAT_DEPLOY_SSH_PORT DEPLOY_SSH_PORT
    VIDEOCHAT_DEPLOY_PATH DEPLOY_PATH
    VIDEOCHAT_DEPLOY_SSH_KEY
  )
  declare -A allowed=()
  local name line raw value
  for name in "${allowed_names[@]}"; do
    allowed["${name}"]=1
  done
  while IFS= read -r line || [[ -n "${line}" ]]; do
    line="${line%$'\r'}"
    [[ "${line}" =~ ^[[:space:]]*(#|$) ]] && continue
    [[ "${line}" =~ ^[[:space:]]*(export[[:space:]]+)?([A-Za-z_][A-Za-z0-9_]*)[[:space:]]*=(.*)$ ]] || continue
    name="${BASH_REMATCH[2]}"
    raw="${BASH_REMATCH[3]}"
    [[ -n "${allowed[${name}]+x}" ]] || continue
    [[ -z "${!name+x}" ]] || continue
    if value="$(parse_env_value "${raw}")"; then
      printf -v "${name}" '%s' "${value}"
      export "${name}"
    fi
  done < "${LOCAL_ENV_FILE}"
}

normalize_env() {
  DEPLOY_DOMAIN="${VIDEOCHAT_DEPLOY_DOMAIN:-${DEPLOY_DOMAIN:-kingrt.com}}"
  DEPLOY_API_DOMAIN="${VIDEOCHAT_DEPLOY_API_DOMAIN:-${DEPLOY_API_DOMAIN:-api.${DEPLOY_DOMAIN}}}"
  DEPLOY_WS_DOMAIN="${VIDEOCHAT_DEPLOY_WS_DOMAIN:-${DEPLOY_WS_DOMAIN:-ws.${DEPLOY_DOMAIN}}}"
  DEPLOY_HOST="${VIDEOCHAT_DEPLOY_HOST:-${DEPLOY_HOST:-}}"
  DEPLOY_USER="${VIDEOCHAT_DEPLOY_USER:-${DEPLOY_USER:-root}}"
  DEPLOY_SSH_PORT="${VIDEOCHAT_DEPLOY_SSH_PORT:-${DEPLOY_SSH_PORT:-22}}"
  DEPLOY_PATH="${VIDEOCHAT_DEPLOY_PATH:-${DEPLOY_PATH:-/opt/king-videochat}}"
}

validate_ids() {
  [[ "${CALL_ID}" =~ ^[A-Za-z0-9._:-]{1,200}$ ]] || fail "invalid call id"
  [[ "${ROOM_ID}" =~ ^[A-Za-z0-9._:-]{1,200}$ ]] || fail "invalid room id"
  [[ "${REPORTER_USER_ID}" =~ ^[0-9]+$ && "${REPORTER_USER_ID}" -gt 0 ]] || fail "invalid reporter user id"
  [[ "${READER_USER_ID}" =~ ^[0-9]+$ ]] || fail "invalid reader user id"
  [[ "${TAIL_LIMIT}" =~ ^[0-9]+$ && "${TAIL_LIMIT}" -gt 0 && "${TAIL_LIMIT}" -le 200 ]] || fail "invalid tail limit"
}

ssh_args() {
  local args=(-p "${DEPLOY_SSH_PORT}" -o BatchMode=yes -o ConnectTimeout="${TIMEOUT}")
  if [[ -n "${VIDEOCHAT_DEPLOY_SSH_KEY:-}" ]]; then
    args+=(-i "${VIDEOCHAT_DEPLOY_SSH_KEY}")
  fi
  printf '%s\n' "${args[@]}"
}

remote_php_sql() {
  local sql="$1"
  [[ -n "${DEPLOY_HOST}" ]] || fail "VIDEOCHAT_DEPLOY_HOST is required"
  local sql_b64 deploy_path_q ssh_dest
  sql_b64="$(printf '%s' "${sql}" | base64 -w 0)"
  deploy_path_q="$(shell_quote "${DEPLOY_PATH}")"
  ssh_dest="${DEPLOY_USER}@${DEPLOY_HOST}"
  mapfile -t SSH_ARGS < <(ssh_args)
  ssh "${SSH_ARGS[@]}" "${ssh_dest}" "SQL_B64=$(shell_quote "${sql_b64}") DEPLOY_PATH=${deploy_path_q} bash -s" <<'REMOTE' | redact_stream
set -euo pipefail
VIDEOCHAT_DIR="${DEPLOY_PATH}/demo/video-chat"
if [ ! -d "${VIDEOCHAT_DIR}" ]; then
  echo "[]"
  exit 0
fi
cd "${VIDEOCHAT_DIR}"
SANITIZED_ENV_FILE="$(mktemp)"
trap 'rm -f "${SANITIZED_ENV_FILE}"' EXIT
if [ -f .env.local ]; then
  while IFS= read -r env_line || [ -n "${env_line}" ]; do
    case "${env_line}" in
      ''|\#*) continue ;;
    esac
    env_key="${env_line%%=*}"
    env_key="${env_key#export }"
    env_key="${env_key%%[[:space:]]*}"
    case "${env_key}" in
      *TOKEN*|*SECRET*|*PASSWORD*|*PASS*|*KEY*|*CREDENTIAL*|*COOKIE*|*SESSION*|*HCLOUD*) continue ;;
      VIDEOCHAT_*|VITE_VIDEOCHAT_*|DEPLOY_*|COMPOSE_*|OTEL_*) printf '%s\n' "${env_line}" >> "${SANITIZED_ENV_FILE}" ;;
    esac
  done < .env.local
fi
if [ -f docker-compose.deploy.local.yml ]; then
  COMPOSE=(docker compose --env-file .env --env-file "${SANITIZED_ENV_FILE}" -f docker-compose.v1.yml -f docker-compose.deploy.local.yml --profile edge --profile turn)
else
  COMPOSE=(docker compose --env-file .env -f docker-compose.v1.yml --profile edge --profile turn)
fi
"${COMPOSE[@]}" exec -T -e SQL_B64="${SQL_B64}" videochat-backend-v1 php <<'PHP'
<?php
declare(strict_types=1);

$sql = base64_decode((string) getenv('SQL_B64'), true);
if (!is_string($sql) || trim($sql) === '') {
    fwrite(STDERR, "missing sql\n");
    exit(64);
}

$dbPath = getenv('VIDEOCHAT_KING_DB_PATH') ?: '/data/video-chat.sqlite';
$pdo = new PDO('sqlite:' . $dbPath);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$statement = $pdo->query($sql);
if ($statement === false) {
    fwrite(STDERR, "query failed\n");
    exit(65);
}

echo json_encode($statement->fetchAll(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), PHP_EOL;
PHP
REMOTE
}

reporter_session_sql() {
  cat <<SQL
SELECT id
FROM sessions
WHERE user_id = ${REPORTER_USER_ID}
  AND (revoked_at IS NULL OR revoked_at = '')
  AND strftime('%s', expires_at) > strftime('%s', 'now')
ORDER BY expires_at DESC, issued_at DESC
LIMIT 1
SQL
}

reader_session_sql() {
  if [[ "${READER_USER_ID}" -gt 0 ]]; then
    cat <<SQL
SELECT id
FROM sessions
WHERE user_id = ${READER_USER_ID}
  AND (revoked_at IS NULL OR revoked_at = '')
  AND strftime('%s', expires_at) > strftime('%s', 'now')
ORDER BY expires_at DESC, issued_at DESC
LIMIT 1
SQL
    return 0
  fi

  cat <<'SQL'
SELECT sessions.id
FROM sessions
INNER JOIN users ON users.id = sessions.user_id
INNER JOIN roles ON roles.id = users.role_id
WHERE roles.slug = 'admin'
  AND users.status = 'active'
  AND (sessions.revoked_at IS NULL OR sessions.revoked_at = '')
  AND strftime('%s', sessions.expires_at) > strftime('%s', 'now')
ORDER BY sessions.expires_at DESC, sessions.issued_at DESC
LIMIT 1
SQL
}

session_token_from_sql() {
  local sql="$1" rows token
  rows="$(remote_php_sql "${sql}")"
  token="$(ROWS_JSON="${rows}" python3 - <<'PY'
import json
import os
rows = json.loads(os.environ.get("ROWS_JSON") or "[]")
token = ""
if rows and isinstance(rows[0], dict):
    token = str(rows[0].get("id") or "")
print(token)
PY
)"
  [[ -n "${token}" ]] || return 1
  printf '%s' "${token}"
}

reporter_session_token() {
  session_token_from_sql "$(reporter_session_sql)" || fail "no active reporter session for user ${REPORTER_USER_ID}"
}

reader_session_token() {
  if [[ -n "${VIDEOCHAT_LIVE_CHAT_READER_SESSION_TOKEN:-}" ]]; then
    printf '%s' "${VIDEOCHAT_LIVE_CHAT_READER_SESSION_TOKEN}"
    return 0
  fi
  session_token_from_sql "$(reader_session_sql)" || fail "no active reader session for chat archive HTTP tail"
}

tail_json() {
  require_cmd python3
  local token
  token="$(reader_session_token)"
  DEPLOY_API_DOMAIN="${DEPLOY_API_DOMAIN}" CALL_ID="${CALL_ID}" ROOM_ID="${ROOM_ID}" TAIL_LIMIT="${TAIL_LIMIT}" SESSION_TOKEN="${token}" python3 - <<'PY' | redact_stream
import json
import os
import urllib.error
import urllib.parse
import urllib.request

api_domain = os.environ["DEPLOY_API_DOMAIN"]
call_id = os.environ["CALL_ID"]
room_id = os.environ["ROOM_ID"]
limit = int(os.environ.get("TAIL_LIMIT") or "50")
session = os.environ["SESSION_TOKEN"]
query = urllib.parse.urlencode({
    "room_id": room_id,
    "tail": "1",
    "limit": str(limit),
})
url = f"https://{api_domain}/api/calls/{urllib.parse.quote(call_id, safe='')}/chat-archive?{query}"
request = urllib.request.Request(
    url,
    headers={
        "Authorization": f"Bearer {session}",
        "Accept": "application/json",
    },
    method="GET",
)
try:
    with urllib.request.urlopen(request, timeout=12) as response:
        payload = json.loads(response.read().decode("utf-8"))
except urllib.error.HTTPError as error:
    reason = ""
    try:
        body = error.read().decode("utf-8")
        decoded = json.loads(body)
        reason = str(decoded.get("error", {}).get("code") or decoded.get("status") or "")
    except Exception:
        reason = ""
    raise SystemExit(f"chat archive HTTP {error.code}{(': ' + reason) if reason else ''}")

archive = payload.get("result", {}).get("archive", {})
messages = archive.get("messages", [])
rows = []
for message in messages if isinstance(messages, list) else []:
    sender = message.get("sender", {}) if isinstance(message, dict) else {}
    rows.append({
        "seq": int(message.get("seq") or 0),
        "server_time": str(message.get("server_time") or ""),
        "sender_display_name": str(sender.get("display_name") or ""),
        "sender_role": str(sender.get("role") or "user"),
        "text": str(message.get("text") or ""),
    })
print(json.dumps(rows, ensure_ascii=False, separators=(",", ":")))
PY
}

pretty_tail() {
  local rows="$1"
  ROWS_JSON="${rows}" python3 - <<'PY' | redact_stream
import json
import os
rows = json.loads(os.environ.get("ROWS_JSON") or "[]")
rows = sorted(rows, key=lambda row: int(row.get("seq") or 0))
for row in rows:
    seq = row.get("seq", "")
    ts = row.get("server_time", "")
    sender = row.get("sender_display_name", "")
    role = row.get("sender_role", "")
    text = str(row.get("text", "")).replace("\n", " ")
    print(f"{seq}\t{ts}\t{sender} ({role})\t{text}")
PY
}

max_seq_from_tail() {
  local rows="$1"
  ROWS_JSON="${rows}" python3 - <<'PY'
import json
import os
rows = json.loads(os.environ.get("ROWS_JSON") or "[]")
max_seq = 0
for row in rows:
    try:
        max_seq = max(max_seq, int(row.get("seq") or 0))
    except Exception:
        pass
print(max_seq)
PY
}

new_rows_from_tail() {
  local rows="$1" after_seq="$2"
  ROWS_JSON="${rows}" AFTER_SEQ="${after_seq}" python3 - <<'PY' | redact_stream
import json
import os
rows = json.loads(os.environ.get("ROWS_JSON") or "[]")
after = int(os.environ.get("AFTER_SEQ") or "0")
fresh = []
for row in rows:
    try:
        seq = int(row.get("seq") or 0)
    except Exception:
        seq = 0
    if seq > after:
        fresh.append(row)
fresh.sort(key=lambda row: int(row.get("seq") or 0))
for row in fresh:
    seq = row.get("seq", "")
    ts = row.get("server_time", "")
    sender = row.get("sender_display_name", "")
    role = row.get("sender_role", "")
    text = str(row.get("text", "")).replace("\n", " ")
    print(f"new seq={seq} at={ts} from={sender} role={role}: {text}")
PY
}

post_message() {
  local message="$1"
  [[ -n "${message}" ]] || fail "message is required"
  if [[ "${DRY_RUN}" == "1" ]]; then
    log "DRY-RUN would post to call ${CALL_ID} room ${ROOM_ID}: ${message}" | redact_stream
    return 0
  fi

  require_cmd python3
  local token
  token="$(reporter_session_token)"
  DEPLOY_WS_DOMAIN="${DEPLOY_WS_DOMAIN}" CALL_ID="${CALL_ID}" ROOM_ID="${ROOM_ID}" SESSION_TOKEN="${token}" CHAT_MESSAGE="${message}" python3 - <<'PY' | redact_stream
import asyncio
import json
import os
import time
import uuid
from urllib.parse import quote

import websockets

ws_domain = os.environ["DEPLOY_WS_DOMAIN"]
call_id = os.environ["CALL_ID"]
room_id = os.environ["ROOM_ID"]
session = os.environ["SESSION_TOKEN"]
message = os.environ["CHAT_MESSAGE"]
client_message_id = "live-monitor-" + uuid.uuid4().hex
url = (
    f"wss://{ws_domain}/ws"
    f"?room={quote(room_id, safe='')}"
    f"&call_id={quote(call_id, safe='')}"
    f"&session={quote(session, safe='')}"
)

async def main() -> None:
    async with websockets.connect(url, open_timeout=10, close_timeout=5, max_size=2_000_000) as socket:
        await socket.send(json.dumps({
            "type": "chat/send",
            "message": message,
            "client_message_id": client_message_id,
        }, ensure_ascii=False))
        deadline = time.monotonic() + 12
        last_type = ""
        while time.monotonic() < deadline:
            try:
                raw = await asyncio.wait_for(socket.recv(), timeout=1.5)
            except asyncio.TimeoutError:
                continue
            try:
                frame = json.loads(raw)
            except Exception:
                continue
            last_type = str(frame.get("type") or "")
            if last_type == "chat/ack" and str(frame.get("client_message_id") or "") == client_message_id:
                print(json.dumps({
                    "ok": True,
                    "type": "chat/ack",
                    "client_message_id": client_message_id,
                    "room_id": frame.get("room_id"),
                    "message_id": frame.get("message_id"),
                }, ensure_ascii=False))
                return
        raise SystemExit(f"chat ack timeout; last frame type={last_type or 'none'}")

asyncio.run(main())
PY
}

write_heartbeat() {
  mkdir -p "${STATE_DIR}"
  date -u +'%Y-%m-%dT%H:%M:%SZ' > "${HEARTBEAT_FILE}"
}

read_last_seq() {
  if [[ -f "${SEQ_FILE}" ]]; then
    tr -cd '0-9' < "${SEQ_FILE}"
  else
    printf '0'
  fi
}

write_last_seq() {
  printf '%s\n' "$1" > "${SEQ_FILE}"
}

run_once() {
  validate_ids
  if [[ "${DRY_RUN}" == "1" ]]; then
    log "DRY-RUN would read latest ${TAIL_LIMIT} chat rows for call=${CALL_ID} room=${ROOM_ID}"
    return 0
  fi
  local rows
  rows="$(tail_json)"
  pretty_tail "${rows}"
}

run_loop() {
  validate_ids
  mkdir -p "${STATE_DIR}"
  log "started call=${CALL_ID} room=${ROOM_ID} reporter_user=${REPORTER_USER_ID} reader_user=${READER_USER_ID:-0} poll=${POLL_SECONDS}s"
  while true; do
    write_heartbeat
    local previous rows current fresh
    previous="$(read_last_seq)"
    if rows="$(tail_json 2>&1)"; then
      current="$(max_seq_from_tail "${rows}")"
      fresh="$(new_rows_from_tail "${rows}" "${previous}")"
      if [[ -n "${fresh}" ]]; then
        printf '%s\n' "${fresh}" | redact_stream
      fi
      if [[ "${current}" =~ ^[0-9]+$ && "${current}" -gt "${previous:-0}" ]]; then
        write_last_seq "${current}"
      fi
    else
      log "tail failed: ${rows}" | redact_stream
    fi
    sleep "${POLL_SECONDS}"
  done
}

is_running() {
  [[ -f "${PID_FILE}" ]] || return 1
  local pid
  pid="$(tr -cd '0-9' < "${PID_FILE}")"
  [[ -n "${pid}" ]] || return 1
  kill -0 "${pid}" >/dev/null 2>&1
}

start_monitor() {
  mkdir -p "${STATE_DIR}"
  if is_running; then
    log "already running pid=$(cat "${PID_FILE}")"
    return 0
  fi
  local runner pid
  runner="${SCRIPT_DIR}/live-chat-monitor.sh"
  if command -v setsid >/dev/null 2>&1; then
    setsid "${runner}" run >> "${LOG_FILE}" 2>&1 < /dev/null &
  else
    nohup "${runner}" run >> "${LOG_FILE}" 2>&1 < /dev/null &
  fi
  pid="$!"
  printf '%s\n' "${pid}" > "${PID_FILE}"
  disown "${pid}" >/dev/null 2>&1 || true
  log "started pid=${pid} log=${LOG_FILE}"
}

stop_monitor() {
  if ! [[ -f "${PID_FILE}" ]]; then
    log "not running"
    return 0
  fi
  local pid
  pid="$(tr -cd '0-9' < "${PID_FILE}")"
  if [[ -n "${pid}" ]] && kill -0 "${pid}" >/dev/null 2>&1; then
    kill "${pid}" >/dev/null 2>&1 || true
    log "stopped pid=${pid}"
  else
    log "stale pid file removed"
  fi
  rm -f "${PID_FILE}"
}

status_monitor() {
  if is_running; then
    local heartbeat="missing"
    [[ -f "${HEARTBEAT_FILE}" ]] && heartbeat="$(cat "${HEARTBEAT_FILE}")"
    log "running pid=$(cat "${PID_FILE}") heartbeat=${heartbeat} log=${LOG_FILE}"
  else
    log "stopped"
    return 1
  fi
}

watchdog_monitor() {
  mkdir -p "${STATE_DIR}"
  local now heartbeat_epoch age reason
  now="$(date -u +%s)"
  if ! is_running; then
    reason="process_not_running"
    log "watchdog restarting monitor reason=${reason}" >> "${LOG_FILE}"
    start_monitor
    return 0
  fi
  if [[ ! -f "${HEARTBEAT_FILE}" ]]; then
    reason="heartbeat_missing"
    log "watchdog restarting monitor reason=${reason}" >> "${LOG_FILE}"
    stop_monitor
    start_monitor
    return 0
  fi
  heartbeat_epoch="$(date -u -d "$(cat "${HEARTBEAT_FILE}")" +%s 2>/dev/null || printf '0')"
  age=$((now - heartbeat_epoch))
  if [[ "${age}" -gt "${WATCHDOG_STALE_SECONDS}" ]]; then
    reason="heartbeat_stale_${age}s"
    log "watchdog restarting monitor reason=${reason}" >> "${LOG_FILE}"
    stop_monitor
    start_monitor
    return 0
  fi
  log "watchdog ok age=${age}s"
}

usage() {
  cat <<USAGE
Usage: $0 [--dry-run] start|stop|status|watchdog|once|post <message>|run|dry-run

Environment:
  VIDEOCHAT_LIVE_CHAT_CALL_ID           default ${CALL_ID}
  VIDEOCHAT_LIVE_CHAT_ROOM_ID           default call id
  VIDEOCHAT_LIVE_CHAT_REPORTER_USER_ID  default ${REPORTER_USER_ID}
  VIDEOCHAT_LIVE_CHAT_READER_USER_ID    default 0, meaning latest active admin session
  VIDEOCHAT_LIVE_CHAT_POLL_SECONDS      default ${POLL_SECONDS}
  VIDEOCHAT_LIVE_CHAT_WATCHDOG_STALE_SECONDS default ${WATCHDOG_STALE_SECONDS}
USAGE
}

main() {
  load_local_env
  normalize_env
  while [[ $# -gt 0 ]]; do
    case "$1" in
      --dry-run)
        DRY_RUN=1
        shift
        ;;
      dry-run)
        DRY_RUN=1
        shift
        set -- once "$@"
        break
        ;;
      *)
        break
        ;;
    esac
  done

  local command="${1:-}"
  shift || true
  case "${command}" in
    start) start_monitor ;;
    stop) stop_monitor ;;
    status) status_monitor ;;
    watchdog) watchdog_monitor ;;
    once) run_once ;;
    post) post_message "$*" ;;
    run) run_loop ;;
    -h|--help|help|'') usage ;;
    *) fail "unknown command: ${command}" ;;
  esac
}

main "$@"
