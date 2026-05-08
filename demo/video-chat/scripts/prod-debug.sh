#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
VIDEOCHAT_DIR="$(cd -- "${SCRIPT_DIR}/.." && pwd)"
LOCAL_ENV_FILE="${VIDEOCHAT_DIR}/.env.local"
TIMEOUT="${VIDEOCHAT_PROD_DEBUG_TIMEOUT:-10}"
LOG_LINES="${VIDEOCHAT_PROD_DEBUG_LOG_LINES:-240}"

log() {
  printf '[videochat-prod-debug] %s\n' "$*"
}

fail() {
  printf '[videochat-prod-debug] ERROR: %s\n' "$*" >&2
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
    -e 's/(bearer[[:space:]]+)[A-Za-z0-9._~+\/=-]+/\1[REDACTED]/Ig' \
    -e 's/([A-Za-z_][A-Za-z0-9_]*(TOKEN|SECRET|PASSWORD|PASS|KEY|CREDENTIAL|COOKIE|SESSION)[A-Za-z0-9_]*=)[^[:space:]]+/\1[REDACTED]/Ig' \
    -e 's/("(token|secret|password|pass|key|credential|cookie|session)[^"]*"[[:space:]]*:[[:space:]]*")[^"]+/\1[REDACTED]/Ig' \
    -e 's/(([?&][^=&[:space:]]*(token|secret|password|pass|key|credential|cookie|session)[^=&[:space:]]*=))[^&[:space:]]+/\1[REDACTED]/Ig'
}

load_local_env() {
  [[ -f "${LOCAL_ENV_FILE}" ]] || return 0
  set -a
  # shellcheck source=/dev/null
  source "${LOCAL_ENV_FILE}"
  set +a
}

normalize_domains() {
  DEPLOY_DOMAIN="${VIDEOCHAT_DEPLOY_DOMAIN:-${DEPLOY_DOMAIN:-kingrt.com}}"
  DEPLOY_APP_DOMAIN="${VIDEOCHAT_DEPLOY_APP_DOMAIN:-${DEPLOY_APP_DOMAIN:-app.${DEPLOY_DOMAIN}}}"
  DEPLOY_API_DOMAIN="${VIDEOCHAT_DEPLOY_API_DOMAIN:-${DEPLOY_API_DOMAIN:-api.${DEPLOY_DOMAIN}}}"
  DEPLOY_WS_DOMAIN="${VIDEOCHAT_DEPLOY_WS_DOMAIN:-${DEPLOY_WS_DOMAIN:-ws.${DEPLOY_DOMAIN}}}"
  DEPLOY_SFU_DOMAIN="${VIDEOCHAT_DEPLOY_SFU_DOMAIN:-${DEPLOY_SFU_DOMAIN:-sfu.${DEPLOY_DOMAIN}}}"
  DEPLOY_CDN_DOMAIN="${VIDEOCHAT_DEPLOY_CDN_DOMAIN:-${DEPLOY_CDN_DOMAIN:-cdn.${DEPLOY_DOMAIN}}}"
  DEPLOY_CALL_APP_DOMAIN="${VIDEOCHAT_DEPLOY_CALL_APP_DOMAIN:-${DEPLOY_CALL_APP_DOMAIN:-whiteboard.${DEPLOY_DOMAIN}}}"
  DEPLOY_REGISTRY_DOMAIN="${VIDEOCHAT_DEPLOY_REGISTRY_DOMAIN:-${DEPLOY_REGISTRY_DOMAIN:-registry.${DEPLOY_DOMAIN}}}"
  DEPLOY_HOST="${VIDEOCHAT_DEPLOY_HOST:-${DEPLOY_HOST:-}}"
  DEPLOY_USER="${VIDEOCHAT_DEPLOY_USER:-${DEPLOY_USER:-root}}"
  DEPLOY_SSH_PORT="${VIDEOCHAT_DEPLOY_SSH_PORT:-${DEPLOY_SSH_PORT:-22}}"
  DEPLOY_PATH="${VIDEOCHAT_DEPLOY_PATH:-${DEPLOY_PATH:-/opt/king-videochat}}"
}

section() {
  printf '\n## %s\n' "$1"
}

http_probe() {
  local label="$1" url="$2" method="${3:-GET}" body code
  body="$(mktemp)"
  if [[ "${method}" == "HEAD" ]]; then
    code="$(curl -sS -I --max-time "${TIMEOUT}" -o "${body}" -w '%{http_code}' "${url}" || true)"
  else
    code="$(curl -sS --max-time "${TIMEOUT}" -o "${body}" -w '%{http_code}' -X "${method}" "${url}" || true)"
  fi
  printf '%-28s %s %s\n' "${label}" "${code:-000}" "${url}" | redact_stream
  if [[ "${method}" == "GET" && -s "${body}" ]]; then
    head -c 2000 "${body}" | redact_stream
    printf '\n'
  fi
  rm -f "${body}"
}

websocket_probe() {
  local label="$1" url="$2" headers body code upgrade curl_url
  curl_url="${url/wss:\/\//https://}"
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
      "${curl_url}" || true
  )"
  upgrade="$(awk 'tolower($1) == "upgrade:" {print $2; exit}' "${headers}" | tr -d '\r')"
  printf '%-28s %s upgrade=%s %s\n' "${label}" "${code:-000}" "${upgrade:-none}" "${url}" | redact_stream
  rm -f "${headers}" "${body}"
}

ssh_args() {
  local args=(-p "${DEPLOY_SSH_PORT}" -o BatchMode=yes -o ConnectTimeout="${TIMEOUT}")
  if [[ -n "${VIDEOCHAT_DEPLOY_SSH_KEY:-}" ]]; then
    args+=(-i "${VIDEOCHAT_DEPLOY_SSH_KEY}")
  fi
  printf '%s\n' "${args[@]}"
}

remote_readonly_probe() {
  [[ -n "${DEPLOY_HOST}" ]] || {
    log "remote SSH probes skipped; VIDEOCHAT_DEPLOY_HOST is not set"
    return 0
  }

  local deploy_path_q log_lines_q ssh_dest
  deploy_path_q="$(shell_quote "${DEPLOY_PATH}")"
  log_lines_q="$(shell_quote "${LOG_LINES}")"
  ssh_dest="${DEPLOY_USER}@${DEPLOY_HOST}"

  section "Remote Containers And Recent Diagnostics"
  log "SSH target: ${DEPLOY_USER}@${DEPLOY_HOST}:${DEPLOY_SSH_PORT}, deploy path: ${DEPLOY_PATH}"
  mapfile -t SSH_ARGS < <(ssh_args)
  ssh "${SSH_ARGS[@]}" "${ssh_dest}" "bash -s" <<REMOTE | redact_stream
set -euo pipefail
DEPLOY_PATH=${deploy_path_q}
LOG_LINES=${log_lines_q}
VIDEOCHAT_DIR="\${DEPLOY_PATH}/demo/video-chat"
redact_stream() {
  sed -E \
    -e 's/(authorization:[[:space:]]*bearer[[:space:]]+)[^[:space:]]+/\1[REDACTED]/Ig' \
    -e 's/(bearer[[:space:]]+)[A-Za-z0-9._~+\/=-]+/\1[REDACTED]/Ig' \
    -e 's/([A-Za-z_][A-Za-z0-9_]*(TOKEN|SECRET|PASSWORD|PASS|KEY|CREDENTIAL|COOKIE|SESSION)[A-Za-z0-9_]*=)[^[:space:]]+/\1[REDACTED]/Ig' \
    -e 's/("(token|secret|password|pass|key|credential|cookie|session)[^"]*"[[:space:]]*:[[:space:]]*")[^"]+/\1[REDACTED]/Ig' \
    -e 's/(([?&][^=&[:space:]]*(token|secret|password|pass|key|credential|cookie|session)[^=&[:space:]]*=))[^&[:space:]]+/\1[REDACTED]/Ig'
}
if [ ! -d "\${VIDEOCHAT_DIR}" ]; then
  echo "remote checkout missing: \${VIDEOCHAT_DIR}"
  exit 0
fi
cd "\${VIDEOCHAT_DIR}"
if [ -f docker-compose.deploy.local.yml ]; then
  COMPOSE=(docker compose --env-file .env --env-file .env.local -f docker-compose.v1.yml -f docker-compose.deploy.local.yml --profile edge --profile turn)
elif [ -f docker-compose.v1.yml ]; then
  COMPOSE=(docker compose --env-file .env -f docker-compose.v1.yml --profile edge --profile turn)
else
  echo "docker compose files not found"
  exit 0
fi
echo "# docker compose ps"
"\${COMPOSE[@]}" ps 2>&1 | redact_stream || true
echo
echo "# filtered recent logs: call health, reconnect, media, SFU, call-app diagnostics"
"\${COMPOSE[@]}" logs --no-color --tail "\${LOG_LINES}" 2>&1 \
  | grep -Eai 'call|reconnect|media|sfu|websocket|ws|lobby|diagnostic|health|runtime|call[_ -]?app|marketplace|whiteboard|error|warn|fail' \
  | tail -n "\${LOG_LINES}" \
  | redact_stream || true
REMOTE
}

main() {
  require_cmd curl
  require_cmd sed
  load_local_env
  normalize_domains

  section "Read Only Contract"
  log "mode: read-only production diagnostics; no deploy, restart, DB write, DNS change, or admin action"
  log "env source: ${LOCAL_ENV_FILE} if present; values are used only for domains and SSH target"

  section "Domains"
  printf 'root=%s\napp=%s\napi=%s\nws=%s\nsfu=%s\ncdn=%s\ncall_app=%s\nregistry=%s\n' \
    "${DEPLOY_DOMAIN}" "${DEPLOY_APP_DOMAIN}" "${DEPLOY_API_DOMAIN}" "${DEPLOY_WS_DOMAIN}" \
    "${DEPLOY_SFU_DOMAIN}" "${DEPLOY_CDN_DOMAIN}" "${DEPLOY_CALL_APP_DOMAIN}" "${DEPLOY_REGISTRY_DOMAIN}" \
    | redact_stream

  section "Public Runtime And Asset Version"
  http_probe "api runtime" "https://${DEPLOY_API_DOMAIN}/api/runtime"
  http_probe "api version" "https://${DEPLOY_API_DOMAIN}/api/version"
  http_probe "app shell" "https://${DEPLOY_APP_DOMAIN}/" HEAD
  http_probe "cdn asset root" "https://${DEPLOY_CDN_DOMAIN}/" HEAD

  section "API, WS, SFU, Marketplace, Call-App Reachability"
  http_probe "marketplace apps" "https://${DEPLOY_API_DOMAIN}/api/marketplace/call-apps"
  http_probe "call-app host" "https://${DEPLOY_CALL_APP_DOMAIN}/" HEAD
  http_probe "registry host" "https://${DEPLOY_REGISTRY_DOMAIN}/" HEAD
  websocket_probe "lobby websocket" "wss://${DEPLOY_WS_DOMAIN}/ws"
  websocket_probe "sfu websocket" "wss://${DEPLOY_SFU_DOMAIN}/sfu"

  remote_readonly_probe
}

main "$@"
