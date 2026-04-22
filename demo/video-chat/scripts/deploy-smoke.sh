#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
VIDEOCHAT_DIR="$(cd -- "${SCRIPT_DIR}/.." && pwd)"
LOCAL_ENV_FILE="${VIDEOCHAT_DIR}/.env.local"
TIMEOUT="${VIDEOCHAT_DEPLOY_SMOKE_TIMEOUT:-12}"

log() {
  printf '[videochat-deploy-smoke] %s\n' "$*"
}

fail() {
  printf '[videochat-deploy-smoke] ERROR: %s\n' "$*" >&2
  exit 1
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

expect_http_code() {
  local label="$1" expected="$2"
  shift 2
  local output code
  output="$(mktemp)"
  code="$(curl -sS --max-time "${TIMEOUT}" -o "${output}" -w '%{http_code}' "$@" || true)"
  if [[ "${code}" != "${expected}" ]]; then
    printf '[videochat-deploy-smoke] %s response body:\n' "${label}" >&2
    cat "${output}" >&2 || true
    rm -f "${output}"
    fail "${label}: expected HTTP ${expected}, got ${code:-none}"
  fi
  rm -f "${output}"
  log "${label}: HTTP ${code}"
}

assert_public_health_payload() {
  php -r '
    $raw = stream_get_contents(STDIN);
    $payload = json_decode($raw, true);
    if (!is_array($payload)) {
        fwrite(STDERR, "health response is not JSON\n");
        exit(1);
    }
    $keys = array_keys($payload);
    sort($keys);
    if ($keys !== ["service", "status", "time"]) {
        fwrite(STDERR, "unexpected public health keys: " . implode(",", $keys) . "\n");
        exit(1);
    }
    if (($payload["status"] ?? "") !== "ok") {
        fwrite(STDERR, "health status is not ok\n");
        exit(1);
    }
  '
}

check_https_redirect() {
  local headers code location
  headers="$(mktemp)"
  code="$(curl -sS --max-time "${TIMEOUT}" -D "${headers}" -o /dev/null -w '%{http_code}' "http://${DEPLOY_DOMAIN}/" || true)"
  case "${code}" in
    301|308) ;;
    *)
      cat "${headers}" >&2 || true
      rm -f "${headers}"
      fail "http redirect: expected 301/308, got ${code:-none}"
      ;;
  esac

  location="$(awk 'tolower($1) == "location:" {print $2; exit}' "${headers}" | tr -d '\r')"
  rm -f "${headers}"
  [[ "${location}" == "https://${DEPLOY_DOMAIN}/"* ]] || fail "http redirect location mismatch: ${location:-missing}"
  log "http redirect: ${code} -> ${location}"
}

websocket_upgrade_smoke() {
  local label="$1" url="$2" headers body code
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
    local header_code
    header_code="$(awk '/^HTTP\\// {code=$2} END {print code}' "${headers}" | tr -d '\r')"
    [[ -n "${header_code}" ]] && code="${header_code}"
  fi

  case "${code}" in
    101)
      grep -Eiq '^upgrade:[[:space:]]*websocket' "${headers}" || {
        cat "${headers}" >&2 || true
        rm -f "${headers}" "${body}"
        fail "${label}: HTTP 101 without websocket upgrade header"
      }
      ;;
    400|401|403|426)
      ;;
    *)
      printf '[videochat-deploy-smoke] %s headers:\n' "${label}" >&2
      cat "${headers}" >&2 || true
      printf '[videochat-deploy-smoke] %s body:\n' "${label}" >&2
      cat "${body}" >&2 || true
      rm -f "${headers}" "${body}"
      fail "${label}: expected routed websocket HTTP 101/400/401/403/426, got ${code:-none}"
      ;;
  esac

  rm -f "${headers}" "${body}"
  log "${label}: routed websocket handshake HTTP ${code}"
}

verify_remote_certbot_hook() {
  case "${VIDEOCHAT_DEPLOY_SMOKE_SKIP_REMOTE:-0}" in
    1|true|TRUE|yes|YES)
      log "remote certbot hook check skipped"
      return 0
      ;;
  esac

  [[ -n "${DEPLOY_HOST}" ]] || fail "VIDEOCHAT_DEPLOY_HOST is required for certbot renewal-hook smoke"
  require_cmd ssh

  local ssh_dest sudo_value domain_q api_q ws_q sfu_q turn_q
  ssh_dest="${DEPLOY_USER}@${DEPLOY_HOST}"
  sudo_value=""
  [[ "${DEPLOY_USER}" != "root" ]] && sudo_value="sudo"
  domain_q="$(shell_quote "${DEPLOY_DOMAIN}")"
  api_q="$(shell_quote "${DEPLOY_API_DOMAIN}")"
  ws_q="$(shell_quote "${DEPLOY_WS_DOMAIN}")"
  sfu_q="$(shell_quote "${DEPLOY_SFU_DOMAIN}")"
  turn_q="$(shell_quote "${DEPLOY_TURN_DOMAIN}")"

  local ssh_args=(-p "${DEPLOY_SSH_PORT}" -o BatchMode=yes -o StrictHostKeyChecking=accept-new)
  if [[ -n "${VIDEOCHAT_DEPLOY_SSH_KEY:-}" ]]; then
    ssh_args+=(-i "${VIDEOCHAT_DEPLOY_SSH_KEY}")
  fi

  ssh "${ssh_args[@]}" "${ssh_dest}" "SUDO=$(shell_quote "${sudo_value}") DOMAIN=${domain_q} API_DOMAIN=${api_q} WS_DOMAIN=${ws_q} SFU_DOMAIN=${sfu_q} TURN_DOMAIN=${turn_q} bash -s" <<'REMOTE'
set -euo pipefail
HOOK=/etc/letsencrypt/renewal-hooks/deploy/king-videochat-restart.sh
${SUDO} test -x "${HOOK}"
${SUDO} grep -Fq 'docker compose' "${HOOK}"
${SUDO} grep -Fq 'restart || true' "${HOOK}"
cert_output="$(${SUDO} certbot certificates -d "${DOMAIN}" 2>&1)"
printf '%s\n' "${cert_output}" | grep -Fq "Certificate Name: ${DOMAIN}"
for expected in "${DOMAIN}" "${API_DOMAIN}" "${WS_DOMAIN}" "${SFU_DOMAIN}" "${TURN_DOMAIN}"; do
  printf '%s\n' "${cert_output}" | grep -Fq "${expected}"
done
REMOTE

  log "certbot renewal hook and certificate SANs verified on ${ssh_dest}"
}

load_local_env
require_cmd curl
require_cmd php

DEPLOY_DOMAIN="${VIDEOCHAT_DEPLOY_DOMAIN:-${VIDEOCHAT_V1_PUBLIC_HOST:-}}"
[[ -n "${DEPLOY_DOMAIN}" ]] || fail "VIDEOCHAT_DEPLOY_DOMAIN or VIDEOCHAT_V1_PUBLIC_HOST is required"
DEPLOY_USER="${VIDEOCHAT_DEPLOY_USER:-root}"
DEPLOY_SSH_PORT="${VIDEOCHAT_DEPLOY_SSH_PORT:-22}"
DEPLOY_HOST="${VIDEOCHAT_DEPLOY_HOST:-}"
DEPLOY_API_DOMAIN="${VIDEOCHAT_DEPLOY_API_DOMAIN:-api.${DEPLOY_DOMAIN}}"
DEPLOY_WS_DOMAIN="${VIDEOCHAT_DEPLOY_WS_DOMAIN:-ws.${DEPLOY_DOMAIN}}"
DEPLOY_SFU_DOMAIN="${VIDEOCHAT_DEPLOY_SFU_DOMAIN:-sfu.${DEPLOY_DOMAIN}}"
DEPLOY_TURN_DOMAIN="${VIDEOCHAT_DEPLOY_TURN_DOMAIN:-turn.${DEPLOY_DOMAIN}}"

check_https_redirect
expect_http_code https-frontend 200 "https://${DEPLOY_DOMAIN}/"

health_payload="$(curl -fsS --max-time "${TIMEOUT}" "https://${DEPLOY_API_DOMAIN}/health")"
printf '%s' "${health_payload}" | assert_public_health_payload
log "api health: public allow-list payload verified"

expect_http_code admin-runtime-auth-boundary 401 "https://${DEPLOY_API_DOMAIN}/api/admin/runtime"
expect_http_code api-version 200 "https://${DEPLOY_API_DOMAIN}/api/version"

websocket_upgrade_smoke "lobby websocket host" "https://${DEPLOY_WS_DOMAIN}/ws?room=lobby"
websocket_upgrade_smoke "lobby websocket api fallback" "https://${DEPLOY_API_DOMAIN}/ws?room=lobby"
websocket_upgrade_smoke "sfu websocket host" "https://${DEPLOY_SFU_DOMAIN}/sfu?room_id=smoke"
websocket_upgrade_smoke "sfu websocket api fallback" "https://${DEPLOY_API_DOMAIN}/sfu?room_id=smoke"

verify_remote_certbot_hook

log "Production deploy smoke checks passed."
