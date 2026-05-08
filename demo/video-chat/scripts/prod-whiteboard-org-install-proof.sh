#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
VIDEOCHAT_DIR="$(cd -- "${SCRIPT_DIR}/.." && pwd)"
LOCAL_ENV_FILE="${VIDEOCHAT_DIR}/.env.local"
TIMEOUT="${VIDEOCHAT_PROD_WHITEBOARD_PROOF_TIMEOUT:-12}"
SESSION_TOKEN=""
PROOF_CALL_ID=""

log() {
  printf '[prod-whiteboard-org-install-proof] %s\n' "$*"
}

fail() {
  printf '[prod-whiteboard-org-install-proof] ERROR: %s\n' "$*" >&2
  exit 1
}

require_cmd() {
  command -v "$1" >/dev/null 2>&1 || fail "missing required command: $1"
}

load_local_env() {
  [[ -f "${LOCAL_ENV_FILE}" ]] || return 0
  set -a
  # shellcheck source=/dev/null
  source "${LOCAL_ENV_FILE}"
  set +a
}

redact() {
  sed -E \
    -e 's/(authorization: Bearer )[A-Za-z0-9._:-]+/\1[REDACTED]/Ig' \
    -e 's/("token"[[:space:]]*:[[:space:]]*")[^"]+/\1[REDACTED]/Ig' \
    -e 's/(PASSWORD|SECRET|TOKEN|SESSION)[A-Za-z0-9_]*=[^[:space:]]+/\1=[REDACTED]/Ig'
}

normalize_domains() {
  DEPLOY_DOMAIN="${VIDEOCHAT_DEPLOY_DOMAIN:-${VIDEOCHAT_V1_PUBLIC_HOST:-kingrt.com}}"
  DEPLOY_APP_DOMAIN="${VIDEOCHAT_DEPLOY_APP_DOMAIN:-app.${DEPLOY_DOMAIN}}"
  DEPLOY_API_DOMAIN="${VIDEOCHAT_DEPLOY_API_DOMAIN:-api.${DEPLOY_DOMAIN}}"
  DEPLOY_CALL_APP_DOMAIN="${VIDEOCHAT_DEPLOY_CALL_APP_DOMAIN:-whiteboard.${DEPLOY_DOMAIN}}"

  if [[ "${DEPLOY_DOMAIN}" == "kingrt.com" ]]; then
    [[ "${DEPLOY_APP_DOMAIN}" == "app.kingrt.com" ]] || fail "kingrt.com proof requires app.kingrt.com, got ${DEPLOY_APP_DOMAIN}"
    [[ "${DEPLOY_API_DOMAIN}" == "api.kingrt.com" ]] || fail "kingrt.com proof requires api.kingrt.com, got ${DEPLOY_API_DOMAIN}"
    [[ "${DEPLOY_CALL_APP_DOMAIN}" == "whiteboard.kingrt.com" ]] || fail "kingrt.com proof requires whiteboard.kingrt.com, got ${DEPLOY_CALL_APP_DOMAIN}"
  fi
}

admin_password() {
  if [[ -n "${VIDEOCHAT_DEPLOY_ADMIN_PASSWORD:-}" ]]; then
    printf '%s' "${VIDEOCHAT_DEPLOY_ADMIN_PASSWORD}"
    return 0
  fi
  if [[ -n "${VIDEOCHAT_DEPLOY_ADMIN_PASSWORD_FILE:-}" && -f "${VIDEOCHAT_DEPLOY_ADMIN_PASSWORD_FILE}" ]]; then
    cat "${VIDEOCHAT_DEPLOY_ADMIN_PASSWORD_FILE}"
    return 0
  fi
  if [[ -f "${VIDEOCHAT_DIR}/secrets/admin-password" ]]; then
    cat "${VIDEOCHAT_DIR}/secrets/admin-password"
    return 0
  fi

  return 1
}

json_get() {
  local path="$1"
  php -r '
    $path = explode(".", $argv[1] ?? "");
    $payload = json_decode(stream_get_contents(STDIN), true);
    foreach ($path as $segment) {
        if (!is_array($payload) || !array_key_exists($segment, $payload)) {
            exit(1);
        }
        $payload = $payload[$segment];
    }
    if (is_scalar($payload)) {
        echo (string) $payload;
        exit(0);
    }
    exit(1);
  ' "${path}"
}

http_json() {
  local label="$1" expected_code="$2" method="$3" url="$4" token="${5:-}" payload="${6:-}"
  local output code args=()
  output="$(mktemp)"
  args=(-sS --max-time "${TIMEOUT}" -o "${output}" -w '%{http_code}' -X "${method}" -H 'accept: application/json')
  if [[ -n "${token}" ]]; then
    args+=(-H "authorization: Bearer ${token}")
  fi
  if [[ -n "${payload}" ]]; then
    args+=(-H 'content-type: application/json' --data "${payload}")
  fi

  code="$(curl "${args[@]}" "${url}" || true)"
  if [[ "${code}" != "${expected_code}" ]]; then
    printf '[prod-whiteboard-org-install-proof] %s response body:\n' "${label}" >&2
    redact < "${output}" >&2 || true
    rm -f "${output}"
    fail "${label}: expected HTTP ${expected_code}, got ${code:-none}"
  fi
  cat "${output}"
  rm -f "${output}"
}

admin_login() {
  local password payload response token
  password="$(admin_password)" || fail "VIDEOCHAT_DEPLOY_ADMIN_PASSWORD, VIDEOCHAT_DEPLOY_ADMIN_PASSWORD_FILE, or secrets/admin-password is required"
  payload="$(
    ADMIN_PASSWORD="${password}" php -r '
      echo json_encode([
          "email" => "admin@intelligent-intern.com",
          "password" => getenv("ADMIN_PASSWORD"),
      ], JSON_UNESCAPED_SLASHES);
    '
  )"
  response="$(http_json "admin login" 200 POST "https://${DEPLOY_API_DOMAIN}/api/auth/login" "" "${payload}")"
  token="$(printf '%s' "${response}" | json_get 'session.token' || true)"
  [[ -n "${token}" ]] || token="$(printf '%s' "${response}" | json_get 'result.session_token' || true)"
  [[ -n "${token}" ]] || fail "admin login did not return a session token"
  SESSION_TOKEN="${token}"
}

cleanup() {
  if [[ -n "${PROOF_CALL_ID}" && -n "${SESSION_TOKEN}" ]]; then
    http_json "proof call cleanup" 200 DELETE "https://${DEPLOY_API_DOMAIN}/api/calls/${PROOF_CALL_ID}" "${SESSION_TOKEN}" >/dev/null || true
  fi
  if [[ -n "${SESSION_TOKEN}" ]]; then
    http_json "admin logout" 200 POST "https://${DEPLOY_API_DOMAIN}/api/auth/logout" "${SESSION_TOKEN}" >/dev/null || true
  fi
}

assert_marketplace_catalog() {
  php -r '
    function fail(string $message): void { fwrite(STDERR, $message . "\n"); exit(1); }
    $payload = json_decode(stream_get_contents(STDIN), true);
    if (!is_array($payload) || ($payload["status"] ?? "") !== "ok") {
        fail("marketplace catalog status is not ok");
    }
    if (($payload["discovery"]["source"] ?? "") !== "semantic_dns_mcp") {
        fail("marketplace catalog must use Semantic-DNS/MCP discovery");
    }
    $matches = array_values(array_filter($payload["apps"] ?? [], static fn ($app): bool => is_array($app) && ($app["app_key"] ?? "") === "whiteboard"));
    if (count($matches) !== 1) {
        fail("marketplace catalog must include whiteboard exactly once");
    }
    $whiteboard = $matches[0];
    if (($whiteboard["health_status"] ?? "") !== "healthy") {
        fail("whiteboard catalog entry must be healthy");
    }
    if (empty($whiteboard["organization_actions"]["add_to_organization"]["available"])
        && empty($whiteboard["organization_actions"]["verify_installation"]["available"])) {
        fail("whiteboard must expose add-to-organization or verify-installation action");
    }
  '
}

assert_available_whiteboard() {
  php -r '
    function fail(string $message): void { fwrite(STDERR, $message . "\n"); exit(1); }
    $payload = json_decode(stream_get_contents(STDIN), true);
    if (!is_array($payload) || ($payload["status"] ?? "") !== "ok") {
        fail("call app availability status is not ok");
    }
    $apps = $payload["result"]["apps"] ?? [];
    $matches = array_values(array_filter($apps, static fn ($app): bool => is_array($app) && ($app["app_key"] ?? "") === "whiteboard"));
    if (count($matches) !== 1) {
        fail("installed whiteboard must appear in Call Apps availability");
    }
    $whiteboard = $matches[0];
    if (($whiteboard["installation"]["status"] ?? "") !== "enabled") {
        fail("whiteboard installation must be enabled");
    }
    if (empty($whiteboard["availability"]["installed"]) || empty($whiteboard["availability"]["healthy"])) {
        fail("whiteboard availability must be installed and healthy");
    }
  '
}

assert_call_app_session() {
  php -r '
    function fail(string $message): void { fwrite(STDERR, $message . "\n"); exit(1); }
    $payload = json_decode(stream_get_contents(STDIN), true);
    if (!is_array($payload) || ($payload["status"] ?? "") !== "ok") {
        fail("call app session status is not ok");
    }
    $session = $payload["result"]["session"] ?? [];
    if (($session["app_key"] ?? "") !== "whiteboard") {
        fail("created Call App session must be for whiteboard");
    }
    if (($session["status"] ?? "") !== "active") {
        fail("created Call App session must be active");
    }
    if (($session["default_app_policy"] ?? "") !== "allowed_by_default") {
        fail("created Call App session must preserve default allowed policy");
    }
  '
}

create_proof_call_payload() {
  php -r '
    $start = gmdate("c", time() - 60);
    $end = gmdate("c", time() + 1800);
    echo json_encode([
        "title" => "Whiteboard Marketplace Org Install Proof " . gmdate("YmdHis"),
        "room_id" => "whiteboard-marketplace-org-install-proof",
        "access_mode" => "invite_only",
        "starts_at" => $start,
        "ends_at" => $end,
        "schedule_timezone" => "UTC",
        "schedule_all_day" => false,
        "internal_participant_user_ids" => [],
        "external_participants" => [],
    ], JSON_UNESCAPED_SLASHES);
  '
}

main() {
  require_cmd curl
  require_cmd php
  load_local_env
  normalize_domains
  trap cleanup EXIT

  log "domain root=${DEPLOY_DOMAIN} app=${DEPLOY_APP_DOMAIN} api=${DEPLOY_API_DOMAIN} whiteboard=${DEPLOY_CALL_APP_DOMAIN}"
  admin_login
  log "admin session established"

  marketplace_payload="$(http_json "marketplace catalog" 200 GET "https://${DEPLOY_API_DOMAIN}/api/marketplace/call-apps?query=whiteboard" "${SESSION_TOKEN}")"
  printf '%s' "${marketplace_payload}" | assert_marketplace_catalog
  log "marketplace catalog: Whiteboard visible, healthy, and organization-installable"

  order_payload="$(http_json "whiteboard marketplace order" 201 POST "https://${DEPLOY_API_DOMAIN}/api/marketplace/call-apps/whiteboard/orders" "${SESSION_TOKEN}" '{}')"
  order_state="$(printf '%s' "${order_payload}" | json_get 'result.state' || true)"
  entitlement_status="$(printf '%s' "${order_payload}" | json_get 'result.entitlement.status' || true)"
  [[ "${order_state}" == "created" || "${order_state}" == "existing" ]] || fail "unexpected order state: ${order_state:-missing}"
  [[ "${entitlement_status}" == "active" ]] || fail "whiteboard entitlement must be active, got ${entitlement_status:-missing}"
  log "marketplace order: idempotent state=${order_state}, entitlement=${entitlement_status}"

  install_payload="$(
    php -r 'echo json_encode(["default_app_policy" => "allowed_by_default", "config" => ["proof" => "bgf-10-bgf-11"]], JSON_UNESCAPED_SLASHES);'
  )"
  install_response="$(http_json "whiteboard organization install" 201 POST "https://${DEPLOY_API_DOMAIN}/api/marketplace/call-apps/whiteboard/installations" "${SESSION_TOKEN}" "${install_payload}")"
  install_status="$(printf '%s' "${install_response}" | json_get 'result.installation.status' || true)"
  [[ "${install_status}" == "enabled" ]] || fail "whiteboard installation status must be enabled, got ${install_status:-missing}"
  log "organization installation: Whiteboard enabled"

  call_payload="$(create_proof_call_payload)"
  call_response="$(http_json "proof call create" 201 POST "https://${DEPLOY_API_DOMAIN}/api/calls" "${SESSION_TOKEN}" "${call_payload}")"
  PROOF_CALL_ID="$(printf '%s' "${call_response}" | json_get 'result.call.id' || true)"
  [[ -n "${PROOF_CALL_ID}" ]] || fail "proof call id missing"
  log "proof call created: ${PROOF_CALL_ID}"

  availability_payload="$(http_json "call app availability" 200 GET "https://${DEPLOY_API_DOMAIN}/api/calls/${PROOF_CALL_ID}/call-apps/available?query=whiteboard&page=1&page_size=8" "${SESSION_TOKEN}")"
  printf '%s' "${availability_payload}" | assert_available_whiteboard
  log "Call Apps tab availability: installed Whiteboard available"

  session_payload="$(
    php -r 'echo json_encode(["app_key" => "whiteboard", "default_app_policy" => "allowed_by_default"], JSON_UNESCAPED_SLASHES);'
  )"
  session_response="$(http_json "whiteboard call app session" 201 POST "https://${DEPLOY_API_DOMAIN}/api/calls/${PROOF_CALL_ID}/call-app-sessions" "${SESSION_TOKEN}" "${session_payload}")"
  printf '%s' "${session_response}" | assert_call_app_session
  log "Call Apps tab attach: Whiteboard session active with allowed default policy"

  curl -sS --max-time "${TIMEOUT}" -o /dev/null -f "https://${DEPLOY_CALL_APP_DOMAIN}/public/index.html" \
    || fail "whiteboard.kingrt.com iframe entry is not reachable"
  log "Whiteboard iframe host: https://${DEPLOY_CALL_APP_DOMAIN}/public/index.html reachable"
  log "PASS"
}

main "$@"
