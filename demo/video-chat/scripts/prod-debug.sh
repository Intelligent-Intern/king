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

regex_escape() {
  printf '%s' "$1" | sed -E 's/[][(){}.^$?+*|\\]/\\&/g'
}

redact_stream() {
  sed -E \
    -e 's/(authorization:[[:space:]]*bearer[[:space:]]+)[^[:space:]]+/\1[REDACTED]/Ig' \
    -e 's/(bearer[[:space:]]+)[A-Za-z0-9._~+\/=-]+/\1[REDACTED]/Ig' \
    -e 's/([A-Za-z_][A-Za-z0-9_]*(TOKEN|SECRET|PASSWORD|PASS|KEY|CREDENTIAL|COOKIE|SESSION)[A-Za-z0-9_]*=)[^[:space:]]+/\1[REDACTED]/Ig' \
    -e 's/("(token|secret|password|pass|key|credential|cookie|session)[^"]*"[[:space:]]*:[[:space:]]*")[^"]+/\1[REDACTED]/Ig' \
    -e 's/(([?&][^=&[:space:]]*(token|secret|password|pass|key|credential|cookie|session)[^=&[:space:]]*=))[^&[:space:]]+/\1[REDACTED]/Ig' \
    -e 's/("(media_)?(payload|frame|frame_data|image_data|encoded|binary|bytes)"[[:space:]]*:[[:space:]]*")[^"]+/\1[REDACTED_MEDIA_PAYLOAD]/Ig' \
    -e 's/(data:(image|video|audio)\/[A-Za-z0-9.+-]+;base64,)[A-Za-z0-9+\/=._~-]+/\1[REDACTED_MEDIA_PAYLOAD]/Ig' \
    -e 's/([A-Za-z_][A-Za-z0-9_]*(MEDIA_PAYLOAD|FRAME_DATA|IMAGE_DATA|ENCODED_FRAME|BINARY_FRAME)[A-Za-z0-9_]*=)[^[:space:]]+/\1[REDACTED_MEDIA_PAYLOAD]/Ig'
}

load_local_env() {
  [[ -f "${LOCAL_ENV_FILE}" ]] || return 0
  local preserved_names=(
    VIDEOCHAT_DEPLOY_DOMAIN DEPLOY_DOMAIN
    VIDEOCHAT_DEPLOY_APP_DOMAIN DEPLOY_APP_DOMAIN
    VIDEOCHAT_DEPLOY_API_DOMAIN DEPLOY_API_DOMAIN
    VIDEOCHAT_DEPLOY_WS_DOMAIN DEPLOY_WS_DOMAIN
    VIDEOCHAT_DEPLOY_SFU_DOMAIN DEPLOY_SFU_DOMAIN
    VIDEOCHAT_DEPLOY_TURN_DOMAIN DEPLOY_TURN_DOMAIN
    VIDEOCHAT_DEPLOY_CDN_DOMAIN DEPLOY_CDN_DOMAIN
    VIDEOCHAT_DEPLOY_CALL_APP_DOMAIN DEPLOY_CALL_APP_DOMAIN
    VIDEOCHAT_DEPLOY_REGISTRY_DOMAIN DEPLOY_REGISTRY_DOMAIN
    VIDEOCHAT_DEPLOY_HOST DEPLOY_HOST
    VIDEOCHAT_DEPLOY_USER DEPLOY_USER
    VIDEOCHAT_DEPLOY_SSH_PORT DEPLOY_SSH_PORT
    VIDEOCHAT_DEPLOY_PATH DEPLOY_PATH
    VIDEOCHAT_PROD_DEBUG_DRY_RUN VIDEOCHAT_PROD_DEBUG_SKIP_REMOTE
  )
  local name
  declare -A preserved_values=()
  for name in "${preserved_names[@]}"; do
    if [[ -n "${!name+x}" ]]; then
      preserved_values["${name}"]="${!name}"
    fi
  done
  set -a
  # shellcheck source=/dev/null
  source "${LOCAL_ENV_FILE}"
  set +a
  if [[ -n "${preserved_values[VIDEOCHAT_DEPLOY_DOMAIN]+x}" || -n "${preserved_values[DEPLOY_DOMAIN]+x}" ]]; then
    local service_prefix
    for service_prefix in APP API WS SFU TURN CDN CALL_APP REGISTRY; do
      if [[ -z "${preserved_values[VIDEOCHAT_DEPLOY_${service_prefix}_DOMAIN]+x}" && -z "${preserved_values[DEPLOY_${service_prefix}_DOMAIN]+x}" ]]; then
        unset "VIDEOCHAT_DEPLOY_${service_prefix}_DOMAIN" "DEPLOY_${service_prefix}_DOMAIN"
      fi
    done
  fi
  for name in "${!preserved_values[@]}"; do
    printf -v "${name}" '%s' "${preserved_values[${name}]}"
    export "${name}"
  done
}

normalize_domains() {
  DEPLOY_DOMAIN="${VIDEOCHAT_DEPLOY_DOMAIN:-${DEPLOY_DOMAIN:-kingrt.com}}"
  DEPLOY_APP_DOMAIN="${VIDEOCHAT_DEPLOY_APP_DOMAIN:-${DEPLOY_APP_DOMAIN:-app.${DEPLOY_DOMAIN}}}"
  DEPLOY_API_DOMAIN="${VIDEOCHAT_DEPLOY_API_DOMAIN:-${DEPLOY_API_DOMAIN:-api.${DEPLOY_DOMAIN}}}"
  DEPLOY_WS_DOMAIN="${VIDEOCHAT_DEPLOY_WS_DOMAIN:-${DEPLOY_WS_DOMAIN:-ws.${DEPLOY_DOMAIN}}}"
  DEPLOY_SFU_DOMAIN="${VIDEOCHAT_DEPLOY_SFU_DOMAIN:-${DEPLOY_SFU_DOMAIN:-sfu.${DEPLOY_DOMAIN}}}"
  DEPLOY_TURN_DOMAIN="${VIDEOCHAT_DEPLOY_TURN_DOMAIN:-${DEPLOY_TURN_DOMAIN:-turn.${DEPLOY_DOMAIN}}}"
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

assert_domain_contract() {
  local nested=".${DEPLOY_APP_DOMAIN}" label var host
  for label in app:DEPLOY_APP_DOMAIN api:DEPLOY_API_DOMAIN ws:DEPLOY_WS_DOMAIN sfu:DEPLOY_SFU_DOMAIN turn:DEPLOY_TURN_DOMAIN cdn:DEPLOY_CDN_DOMAIN registry:DEPLOY_REGISTRY_DOMAIN whiteboard:DEPLOY_CALL_APP_DOMAIN; do
    var="${label#*:}"
    host="${!var}"
    [[ -n "${host}" ]] || fail "${var} must not be empty"
    case "${host}" in
      *"${nested}"|*.app.kingrt.com) fail "${var} must not be nested under app.kingrt.com: ${host}" ;;
    esac
  done
  if [[ "${DEPLOY_DOMAIN}" == "kingrt.com" ]]; then
    [[ "${DEPLOY_APP_DOMAIN}" == "app.kingrt.com" && "${DEPLOY_API_DOMAIN}" == "api.kingrt.com" && "${DEPLOY_WS_DOMAIN}" == "ws.kingrt.com" && "${DEPLOY_SFU_DOMAIN}" == "sfu.kingrt.com" && "${DEPLOY_TURN_DOMAIN}" == "turn.kingrt.com" && "${DEPLOY_CDN_DOMAIN}" == "cdn.kingrt.com" && "${DEPLOY_REGISTRY_DOMAIN}" == "registry.kingrt.com" && "${DEPLOY_CALL_APP_DOMAIN}" == "whiteboard.kingrt.com" ]] || fail "kingrt.com production domains must use app/api/ws/sfu/turn/cdn/registry/whiteboard kingrt.com hosts"
  fi
  log "domain contract: app/api/ws/sfu/cdn/turn/registry/whiteboard rooted at ${DEPLOY_DOMAIN}; no nested .app.kingrt.com domains"
}

http_probe() {
  local label="$1" url="$2" method="${3:-GET}" body code
  if [[ "${VIDEOCHAT_PROD_DEBUG_DRY_RUN:-0}" == "1" ]]; then
    printf '%-28s DRY-RUN %s %s\n' "${label}" "${method}" "${url}" | redact_stream
    return 0
  fi
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

header_value() {
  local header_name="$1" headers="$2"
  awk -v name="${header_name}" 'BEGIN { lower = tolower(name) ":" } tolower($0) ~ "^" lower { sub("^[^:]*:[[:space:]]*", "", $0); gsub("\r", "", $0); print $0; exit }' "${headers}"
}

call_app_frame_header_probe() {
  local label="$1" url="$2" headers body code csp allow_csp_from nested_pattern escaped_app_domain
  local wildcard_frame_ancestors_pattern wildcard_frame_src_pattern wildcard_script_src_pattern wildcard_connect_src_pattern
  if [[ "${VIDEOCHAT_PROD_DEBUG_DRY_RUN:-0}" == "1" ]]; then
    log "${label}: DRY-RUN ${url}; CSP frame-ancestors https://${DEPLOY_APP_DOMAIN}; Allow-CSP-From https://${DEPLOY_APP_DOMAIN}; X-Frame-Options absent; no nested *.${DEPLOY_APP_DOMAIN} origins"
    return 0
  fi
  headers="$(mktemp)"
  body="$(mktemp)"
  code="$(curl -sS --max-time "${TIMEOUT}" -D "${headers}" -o "${body}" -w '%{http_code}' "${url}" || true)"
  if [[ "${code}" != "200" ]]; then
    printf '[videochat-prod-debug] %s headers:\n' "${label}" >&2
    cat "${headers}" >&2 || true
    printf '[videochat-prod-debug] %s body:\n' "${label}" >&2
    head -c 2000 "${body}" >&2 || true
    printf '\n' >&2
    rm -f "${headers}" "${body}"
    fail "${label}: expected HTTP 200, got ${code:-none}"
  fi

  csp="$(header_value Content-Security-Policy "${headers}")"
  allow_csp_from="$(header_value Allow-CSP-From "${headers}")"
  if [[ "${csp}" != *"frame-ancestors https://${DEPLOY_APP_DOMAIN}"* ]]; then
    cat "${headers}" >&2 || true
    rm -f "${headers}" "${body}"
    fail "${label}: Content-Security-Policy must allow frame ancestor https://${DEPLOY_APP_DOMAIN}"
  fi
  if [[ "${csp}" != *"script-src 'self'"* ]]; then
    cat "${headers}" >&2 || true
    rm -f "${headers}" "${body}"
    fail "${label}: Content-Security-Policy must keep script-src self-scoped"
  fi
  if [[ "${csp}" != *"connect-src 'self'"* ]]; then
    cat "${headers}" >&2 || true
    rm -f "${headers}" "${body}"
    fail "${label}: Content-Security-Policy must keep connect-src self-scoped"
  fi
  wildcard_frame_ancestors_pattern='(^|[[:space:];])frame-ancestors[^;]*\*'
  wildcard_frame_src_pattern='(^|[[:space:];])frame-src[^;]*\*'
  wildcard_script_src_pattern='(^|[[:space:];])script-src[^;]*\*'
  wildcard_connect_src_pattern='(^|[[:space:];])connect-src[^;]*\*'
  if [[ "${csp}" =~ ${wildcard_frame_ancestors_pattern} || "${csp}" =~ ${wildcard_frame_src_pattern} || "${csp}" =~ ${wildcard_script_src_pattern} || "${csp}" =~ ${wildcard_connect_src_pattern} ]]; then
    cat "${headers}" >&2 || true
    rm -f "${headers}" "${body}"
    fail "${label}: Content-Security-Policy must not use wildcard frame/script/connect directives"
  fi
  if [[ "${allow_csp_from}" != "https://${DEPLOY_APP_DOMAIN}" ]]; then
    cat "${headers}" >&2 || true
    rm -f "${headers}" "${body}"
    fail "${label}: Allow-CSP-From must equal https://${DEPLOY_APP_DOMAIN}"
  fi
  if [[ -n "$(header_value X-Frame-Options "${headers}")" ]]; then
    cat "${headers}" >&2 || true
    rm -f "${headers}" "${body}"
    fail "${label}: X-Frame-Options must be absent for Call App iframe responses"
  fi

  escaped_app_domain="$(regex_escape "${DEPLOY_APP_DOMAIN}")"
  nested_pattern="https?://[A-Za-z0-9.-]+\\.${escaped_app_domain}"
  if grep -Eia "${nested_pattern}" "${headers}" "${body}" >/dev/null; then
    printf '[videochat-prod-debug] %s nested app-domain origin matches:\n' "${label}" >&2
    grep -Eia "${nested_pattern}" "${headers}" "${body}" >&2 || true
    rm -f "${headers}" "${body}"
    fail "${label}: must not reference nested *.${DEPLOY_APP_DOMAIN} service origins"
  fi

  rm -f "${headers}" "${body}"
  log "${label}: HTTP ${code}; CSP frame-ancestors https://${DEPLOY_APP_DOMAIN}; Allow-CSP-From https://${DEPLOY_APP_DOMAIN}; X-Frame-Options absent; no nested *.${DEPLOY_APP_DOMAIN} origins"
}

call_app_csp_header_proof() {
  section "Call-App CSP Header Proof"
  call_app_frame_header_probe "call-app whiteboard host CSP" "https://${DEPLOY_CALL_APP_DOMAIN}/public/index.html"
  call_app_frame_header_probe "call-app whiteboard path CSP" "https://${DEPLOY_CALL_APP_DOMAIN}/call-app/whiteboard/public/index.html"
}

websocket_probe() {
  local label="$1" url="$2" headers body code upgrade curl_url
  if [[ "${VIDEOCHAT_PROD_DEBUG_DRY_RUN:-0}" == "1" ]]; then
    printf '%-28s DRY-RUN websocket %s\n' "${label}" "${url}" | redact_stream
    return 0
  fi
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
  if [[ "${VIDEOCHAT_PROD_DEBUG_DRY_RUN:-0}" == "1" ]]; then
    section "Remote Containers And Recent Diagnostics"
    log "DRY-RUN: remote SSH probes skipped; read-only compose ps/logs would execute when enabled"
    return 0
  fi

  case "${VIDEOCHAT_PROD_DEBUG_SKIP_REMOTE:-0}" in
    1|true|TRUE|yes|YES)
      log "remote SSH probes skipped; VIDEOCHAT_PROD_DEBUG_SKIP_REMOTE=1"
      return 0
      ;;
  esac

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
    -e 's/(([?&][^=&[:space:]]*(token|secret|password|pass|key|credential|cookie|session)[^=&[:space:]]*=))[^&[:space:]]+/\1[REDACTED]/Ig' \
    -e 's/("(media_)?(payload|frame|frame_data|image_data|encoded|binary|bytes)"[[:space:]]*:[[:space:]]*")[^"]+/\1[REDACTED_MEDIA_PAYLOAD]/Ig' \
    -e 's/(data:(image|video|audio)\/[A-Za-z0-9.+-]+;base64,)[A-Za-z0-9+\/=._~-]+/\1[REDACTED_MEDIA_PAYLOAD]/Ig' \
    -e 's/([A-Za-z_][A-Za-z0-9_]*(MEDIA_PAYLOAD|FRAME_DATA|IMAGE_DATA|ENCODED_FRAME|BINARY_FRAME)[A-Za-z0-9_]*=)[^[:space:]]+/\1[REDACTED_MEDIA_PAYLOAD]/Ig'
}
filter_recent_logs() {
  label="\$1"
  pattern="\$2"
  echo
  echo "# filtered recent logs: \${label}"
  "\${COMPOSE[@]}" logs --no-color --tail "\${LOG_LINES}" 2>&1 \
    | grep -Eai "\${pattern}" \
    | tail -n "\${LOG_LINES}" \
    | redact_stream || true
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
filter_recent_logs "call health and runtime status" 'call|lobby|diagnostic|health|runtime|room|presence|error|warn|fail'
filter_recent_logs "media reconnect" 'media[_ -]?reconnect|reconnect.*media|foreground[_ -]?reconnect|local[_ -]?media.*reconnect|stale_local_media_capture_discarded'
filter_recent_logs "screen-share reconnect exhaustion" 'local_screen_share_sfu_reconnect_exhausted|screen[_ -]?share.*(reconnect|exhaust|stopped|disconnect)|screen_share.*reconnect'
filter_recent_logs "stale local media capture discard" 'stale_local_media_capture_discarded|local_media_cleanup_preserved_active_track|stale.*local.*media.*discard'
filter_recent_logs "audio/video track loss" '(audio|video).*(track|capture).*(ended|lost|stop|stopped|mute|failed|error)|track.*(lost|ended|stopped)|getUserMedia|devicechange|NotReadableError|NotAllowedError'
filter_recent_logs "SFU reconnect and websocket transport" 'sfu.*(reconnect|disconnect|websocket|ws|close|closed|error|fail)|local_screen_share_sfu_reconnect|sfu_reconnect|websocket.*sfu'
filter_recent_logs "Call App frame and CSP errors" 'call[_ -]?app.*(frame|iframe|csp|postmessage|postMessage|sandbox|origin|launch|error|fail)|Content-Security-Policy|Allow-CSP-From|frame-ancestors|postMessage'
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
  assert_domain_contract
  printf 'root=%s\napp=%s\napi=%s\nws=%s\nsfu=%s\nturn=%s\ncdn=%s\nwhiteboard=%s\nregistry=%s\n' \
    "${DEPLOY_DOMAIN}" "${DEPLOY_APP_DOMAIN}" "${DEPLOY_API_DOMAIN}" "${DEPLOY_WS_DOMAIN}" \
    "${DEPLOY_SFU_DOMAIN}" "${DEPLOY_TURN_DOMAIN}" "${DEPLOY_CDN_DOMAIN}" "${DEPLOY_CALL_APP_DOMAIN}" "${DEPLOY_REGISTRY_DOMAIN}" \
    | redact_stream

  section "Public Runtime And Asset Version"
  http_probe "api runtime" "https://${DEPLOY_API_DOMAIN}/api/runtime"
  http_probe "api version" "https://${DEPLOY_API_DOMAIN}/api/version"
  http_probe "app shell" "https://${DEPLOY_APP_DOMAIN}/" HEAD
  http_probe "cdn asset root" "https://${DEPLOY_CDN_DOMAIN}/" HEAD
  http_probe "cdn mediapipe model" "https://${DEPLOY_CDN_DOMAIN}/cdn/vendor/mediapipe/models/selfie_multiclass_256x256.tflite" HEAD
  http_probe "cdn tasks vision" "https://${DEPLOY_CDN_DOMAIN}/cdn/vendor/mediapipe/tasks-vision/vision_bundle.mjs" HEAD
  http_probe "cdn tasks wasm" "https://${DEPLOY_CDN_DOMAIN}/wasm/vision_wasm_internal.js" HEAD
  http_probe "background modal icon" "https://${DEPLOY_APP_DOMAIN}/assets/orgas/kingrt/icons/solid.png" HEAD
  http_probe "background avatar asset" "https://${DEPLOY_APP_DOMAIN}/assets/orgas/kingrt/avatar-placeholder.svg" HEAD

  section "API, WS, SFU, Marketplace, Call-App Reachability"
  http_probe "marketplace apps" "https://${DEPLOY_API_DOMAIN}/api/marketplace/call-apps"
  http_probe "call-app host" "https://${DEPLOY_CALL_APP_DOMAIN}/" HEAD
  http_probe "registry host" "https://${DEPLOY_REGISTRY_DOMAIN}/" HEAD
  websocket_probe "lobby websocket" "wss://${DEPLOY_WS_DOMAIN}/ws"
  websocket_probe "sfu websocket" "wss://${DEPLOY_SFU_DOMAIN}/sfu"

  call_app_csp_header_proof

  remote_readonly_probe
}

main "$@"
