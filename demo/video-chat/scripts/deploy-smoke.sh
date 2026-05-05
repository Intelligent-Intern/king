#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
VIDEOCHAT_DIR="$(cd -- "${SCRIPT_DIR}/.." && pwd)"
LOCAL_ENV_FILE="${VIDEOCHAT_DIR}/.env.local"
TIMEOUT="${VIDEOCHAT_DEPLOY_SMOKE_TIMEOUT:-12}"
ADMIN_SMOKE_SESSION_TOKEN=""

log() {
  printf '[videochat-deploy-smoke] %s\n' "$*"
}

fail() {
  printf '[videochat-deploy-smoke] ERROR: %s\n' "$*" >&2
  exit 1
}

admin_logout_session() {
  local token="$1" output code
  [[ -n "${token}" ]] || return 0

  output="$(mktemp)"
  code="$(
    curl -sS --max-time "${TIMEOUT}" \
      -o "${output}" \
      -w '%{http_code}' \
      -X POST \
      -H "authorization: Bearer ${token}" \
      "https://${DEPLOY_API_DOMAIN}/api/auth/logout" || true
  )"
  if [[ "${code}" != "200" ]]; then
    printf '[videochat-deploy-smoke] admin session cleanup response body:\n' >&2
    cat "${output}" >&2 || true
    rm -f "${output}"
    return 1
  fi

  rm -f "${output}"
  log "admin session cleanup: HTTP ${code}"
}

cleanup_admin_session() {
  local token="${ADMIN_SMOKE_SESSION_TOKEN:-}"
  [[ -n "${token}" ]] || return 0

  ADMIN_SMOKE_SESSION_TOKEN=""
  admin_logout_session "${token}" || log "admin session cleanup failed; smoke session may expire by TTL"
}

trap cleanup_admin_session EXIT

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
    if ($keys !== ["asset_version", "service", "status", "time"]) {
        fwrite(STDERR, "unexpected public health keys: " . implode(",", $keys) . "\n");
        exit(1);
    }
    if (($payload["status"] ?? "") !== "ok") {
        fwrite(STDERR, "health status is not ok\n");
        exit(1);
    }
    if (!is_string($payload["asset_version"] ?? null) || $payload["asset_version"] === "") {
        fwrite(STDERR, "health asset_version is missing\n");
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
    header_code="$(awk '/^HTTP\// {code=$2} END {print code}' "${headers}" | tr -d '\r')"
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

  local ssh_dest sudo_value domain_q api_q ws_q sfu_q turn_q cdn_q
  ssh_dest="${DEPLOY_USER}@${DEPLOY_HOST}"
  sudo_value=""
  [[ "${DEPLOY_USER}" != "root" ]] && sudo_value="sudo"
  domain_q="$(shell_quote "${DEPLOY_DOMAIN}")"
  api_q="$(shell_quote "${DEPLOY_API_DOMAIN}")"
  ws_q="$(shell_quote "${DEPLOY_WS_DOMAIN}")"
  sfu_q="$(shell_quote "${DEPLOY_SFU_DOMAIN}")"
  turn_q="$(shell_quote "${DEPLOY_TURN_DOMAIN}")"
  cdn_q="$(shell_quote "${DEPLOY_CDN_DOMAIN}")"

  local ssh_args=(-p "${DEPLOY_SSH_PORT}" -o BatchMode=yes -o StrictHostKeyChecking=accept-new)
  if [[ -n "${VIDEOCHAT_DEPLOY_SSH_KEY:-}" ]]; then
    ssh_args+=(-i "${VIDEOCHAT_DEPLOY_SSH_KEY}")
  fi

  ssh "${ssh_args[@]}" "${ssh_dest}" "SUDO=$(shell_quote "${sudo_value}") DOMAIN=${domain_q} API_DOMAIN=${api_q} WS_DOMAIN=${ws_q} SFU_DOMAIN=${sfu_q} TURN_DOMAIN=${turn_q} CDN_DOMAIN=${cdn_q} bash -s" <<'REMOTE'
set -euo pipefail
HOOK=/etc/letsencrypt/renewal-hooks/deploy/king-videochat-restart.sh
${SUDO} test -x "${HOOK}"
${SUDO} grep -Fq 'docker compose' "${HOOK}"
${SUDO} grep -Fq 'restart || true' "${HOOK}"
cert_output="$(${SUDO} certbot certificates -d "${DOMAIN}" 2>&1)"
printf '%s\n' "${cert_output}" | grep -Fq "Certificate Name: ${DOMAIN}"
for expected in "${DOMAIN}" "${API_DOMAIN}" "${WS_DOMAIN}" "${SFU_DOMAIN}" "${TURN_DOMAIN}" "${CDN_DOMAIN}"; do
  printf '%s\n' "${cert_output}" | grep -Fq "${expected}"
done
REMOTE

  log "certbot renewal hook and certificate SANs verified on ${ssh_dest}"
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

admin_session_token() {
  local password payload response_file code token attempt
  password="$(admin_password)" || fail "VIDEOCHAT_DEPLOY_ADMIN_PASSWORD, VIDEOCHAT_DEPLOY_ADMIN_PASSWORD_FILE, or secrets/admin-password is required for admin operations smoke"
  payload="$(
    ADMIN_PASSWORD="${password}" php -r '
      echo json_encode([
          "email" => "admin@intelligent-intern.com",
          "password" => getenv("ADMIN_PASSWORD"),
      ], JSON_UNESCAPED_SLASHES);
    '
  )"
  response_file="$(mktemp)"
  for attempt in $(seq 1 30); do
    code="$(
      curl -sS --max-time "${TIMEOUT}" \
        -o "${response_file}" \
        -w '%{http_code}' \
        -X POST \
        -H 'content-type: application/json' \
        --data "${payload}" \
        "https://${DEPLOY_API_DOMAIN}/api/auth/login" || true
    )"
    if [[ "${code}" == "200" ]]; then
      token="$(
        php -r '
          $raw = stream_get_contents(STDIN);
          $payload = json_decode($raw, true);
          if (!is_array($payload)) {
              exit(1);
          }
          $candidates = [
              $payload["session"]["token"] ?? null,
              $payload["result"]["session_token"] ?? null,
              $payload["result"]["token"] ?? null,
              $payload["token"] ?? null,
          ];
          foreach ($candidates as $candidate) {
              if (is_string($candidate) && trim($candidate) !== "") {
                  echo trim($candidate);
                  exit(0);
              }
          }
          exit(1);
        ' < "${response_file}" 2>/dev/null || true
      )"
      if [[ -n "${token}" ]]; then
        rm -f "${response_file}"
        printf '%s' "${token}"
        return 0
      fi
    fi
    sleep 1
  done

  rm -f "${response_file}"
  fail "admin login did not return a session token after deploy readiness retries"
}

assert_admin_infrastructure_payload() {
  php -r '
    function fail(string $message): void {
        fwrite(STDERR, $message . "\n");
        exit(1);
    }
    function deny_sensitive(mixed $value, string $path = "$"): void {
        if (is_array($value)) {
            foreach ($value as $key => $item) {
                $segment = is_string($key) ? $key : (string) $key;
                if (preg_match("/(password|secret|token)/i", $segment) === 1) {
                    fail("sensitive key leaked in infrastructure payload: " . $path . "." . $segment);
                }
                deny_sensitive($item, $path . "." . $segment);
            }
            return;
        }
        if (is_string($value) && preg_match("/(sess_[a-f0-9]{20,}|Bearer\\s+)/i", $value) === 1) {
            fail("sensitive value leaked in infrastructure payload: " . $path);
        }
    }
    $raw = stream_get_contents(STDIN);
    $payload = json_decode($raw, true);
    if (!is_array($payload) || ($payload["status"] ?? "") !== "ok") {
        fail("infrastructure payload status is not ok");
    }
    foreach (["deployment", "telemetry", "scaling"] as $key) {
        if (!is_array($payload[$key] ?? null)) {
            fail("infrastructure payload missing object: " . $key);
        }
    }
    foreach (["providers", "nodes", "services"] as $key) {
        if (!is_array($payload[$key] ?? null) || count($payload[$key]) < 1) {
            fail("infrastructure payload missing non-empty list: " . $key);
        }
    }
    $providerIds = [];
    foreach ($payload["providers"] as $provider) {
        if (is_array($provider) && is_string($provider["id"] ?? null)) {
            $providerIds[] = $provider["id"];
        }
    }
    if ($providerIds === []) {
        fail("infrastructure payload has no provider ids");
    }
    $otel = $payload["telemetry"]["open_telemetry"] ?? [];
    if (is_array($otel) && isset($otel["exporter_endpoint"]) && !in_array($otel["exporter_endpoint"], ["", "[configured]"], true)) {
        fail("infrastructure payload exposes raw OpenTelemetry endpoint");
    }
    if (($payload["scaling"]["write_actions_enabled"] ?? null) !== false) {
        fail("infrastructure scaling writes must stay disabled in smoke");
    }
    deny_sensitive($payload);
  '
}

assert_admin_session_payload() {
  VIDEOCHAT_DEPLOY_SMOKE_EXPECT_USER_LOCALE="${VIDEOCHAT_DEPLOY_SMOKE_EXPECT_USER_LOCALE:-en}" php -r '
    function fail(string $message): void {
        fwrite(STDERR, $message . "\n");
        exit(1);
    }
    $expectedLocale = trim((string) getenv("VIDEOCHAT_DEPLOY_SMOKE_EXPECT_USER_LOCALE"));
    $raw = stream_get_contents(STDIN);
    $payload = json_decode($raw, true);
    if (!is_array($payload) || ($payload["status"] ?? "") !== "ok") {
        fail("admin session payload status is not ok");
    }
    $user = $payload["user"] ?? null;
    if (!is_array($user)) {
        fail("admin session payload missing user");
    }
    $errors = [];
    $localeValue = array_key_exists("locale", $user) ? (string) $user["locale"] : "<missing>";
    if ($expectedLocale !== "" && $localeValue !== $expectedLocale) {
        $errors[] = "locale expected " . $expectedLocale . ", got " . $localeValue;
    }

    $directionValue = array_key_exists("direction", $user) ? (string) $user["direction"] : "<missing>";
    if ($expectedLocale === "en" && $directionValue !== "ltr") {
        $errors[] = "direction expected ltr, got " . $directionValue;
    }

    $supported = $user["supported_locales"] ?? [];
    if (!is_array($supported)) {
        $errors[] = "supported_locales missing";
        $supported = [];
    }
    $codes = [];
    foreach ($supported as $locale) {
        if (is_array($locale) && is_string($locale["code"] ?? null)) {
            $codes[] = $locale["code"];
        }
    }
    foreach (["en", "de", "ar", "sgd"] as $requiredLocale) {
        if (!in_array($requiredLocale, $codes, true)) {
            $errors[] = "supported locale missing " . $requiredLocale;
        }
    }
    if ($errors !== []) {
        fail("admin session payload localization mismatch: " . implode("; ", $errors));
    }
  '
}

assert_admin_localization_preview_payload() {
  php -r '
    function fail(string $message): void {
        fwrite(STDERR, $message . "\n");
        exit(1);
    }
    $raw = stream_get_contents(STDIN);
    $payload = json_decode($raw, true);
    if (!is_array($payload) || ($payload["status"] ?? "") !== "ok") {
        fail("localization CSV preview payload status is not ok");
    }
    $preview = $payload["result"]["preview"] ?? null;
    if (!is_array($preview)) {
        fail("localization CSV preview payload missing preview");
    }
    if (($preview["ok"] ?? null) !== true) {
        fail("localization CSV preview did not pass validation");
    }
    if ((int) ($preview["valid_rows"] ?? 0) < 1) {
        fail("localization CSV preview must include at least one valid row");
    }
    if ((int) ($preview["error_count"] ?? 0) !== 0) {
        fail("localization CSV preview must not include validation errors");
    }
    $summary = is_array($preview["summary"] ?? null) ? $preview["summary"] : [];
    $locales = is_array($summary["locales"] ?? null) ? $summary["locales"] : [];
    $namespaces = is_array($summary["namespaces"] ?? null) ? $summary["namespaces"] : [];
    if (!in_array("de", $locales, true)) {
        fail("localization CSV preview missing de locale summary");
    }
    if (!in_array("deploy_smoke", $namespaces, true)) {
        fail("localization CSV preview missing deploy_smoke namespace summary");
    }
  '
}

assert_admin_video_operations_payload() {
  php -r '
    function fail(string $message): void {
        fwrite(STDERR, $message . "\n");
        exit(1);
    }
    function deny_sensitive(mixed $value, string $path = "$"): void {
        if (is_array($value)) {
            foreach ($value as $key => $item) {
                $segment = is_string($key) ? $key : (string) $key;
                if (preg_match("/(password|secret|token)/i", $segment) === 1) {
                    fail("sensitive key leaked in operations payload: " . $path . "." . $segment);
                }
                deny_sensitive($item, $path . "." . $segment);
            }
            return;
        }
        if (is_string($value) && preg_match("/(sess_[a-f0-9]{20,}|Bearer\\s+)/i", $value) === 1) {
            fail("sensitive value leaked in operations payload: " . $path);
        }
    }
    $raw = stream_get_contents(STDIN);
    $payload = json_decode($raw, true);
    if (!is_array($payload) || ($payload["status"] ?? "") !== "ok") {
        fail("video operations payload status is not ok");
    }
    $metrics = $payload["metrics"] ?? null;
    if (!is_array($metrics)) {
        fail("video operations payload missing metrics");
    }
    foreach (["live_calls", "concurrent_participants"] as $key) {
        if (!is_int($metrics[$key] ?? null) || $metrics[$key] < 0) {
            fail("video operations metric is invalid: " . $key);
        }
    }
    if (!is_array($payload["running_calls"] ?? null)) {
        fail("video operations payload missing running_calls list");
    }
    $sampleTitles = ["Sales Standup", "Incident Bridge", "Quarterly Sync"];
    foreach ($payload["running_calls"] as $call) {
        if (!is_array($call)) {
            fail("video operations running call row is not an object");
        }
        if (in_array((string) ($call["title"] ?? ""), $sampleTitles, true)) {
            fail("video operations payload still exposes static sample call data");
        }
        if ((int) (($call["live_participants"] ?? [])["total"] ?? 0) <= 0) {
            fail("video operations running call without live participants");
        }
        if ((string) (($call["presence"] ?? [])["source"] ?? "") !== "realtime_presence_connections") {
            fail("video operations running call is not sourced from realtime presence");
        }
    }
    deny_sensitive($payload);
  '
}

verify_admin_operations() {
  case "${VIDEOCHAT_DEPLOY_SMOKE_SKIP_ADMIN:-0}" in
    1|true|TRUE|yes|YES)
      log "admin operations check skipped"
      return 0
      ;;
  esac

  local token session_payload csv_preview_request csv_preview_payload infrastructure_payload operations_payload
  token="$(admin_session_token)"
  ADMIN_SMOKE_SESSION_TOKEN="${token}"
  session_payload="$(admin_get_json "admin session" "https://${DEPLOY_API_DOMAIN}/api/auth/session" "${token}")"
  printf '%s' "${session_payload}" | assert_admin_session_payload
  log "admin session: authenticated default locale payload verified"

  csv_preview_request="$(
    php -r '
      echo json_encode([
          "file_name" => "deploy-smoke-preview.csv",
          "csv" => "locale,namespace,resource_key,value\n"
              . "de,deploy_smoke,ready,Bereit\n",
      ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    '
  )"
  csv_preview_payload="$(admin_post_json "admin localization CSV preview" "https://${DEPLOY_API_DOMAIN}/api/admin/localization/imports/preview" "${token}" "${csv_preview_request}")"
  printf '%s' "${csv_preview_payload}" | assert_admin_localization_preview_payload
  log "admin localization CSV preview: superadmin preview verified without commit"

  infrastructure_payload="$(admin_get_json "admin infrastructure" "https://${DEPLOY_API_DOMAIN}/api/admin/infrastructure" "${token}")"
  printf '%s' "${infrastructure_payload}" | assert_admin_infrastructure_payload
  log "admin infrastructure: provider-neutral safe payload verified"

  operations_payload="$(admin_get_json "admin video operations" "https://${DEPLOY_API_DOMAIN}/api/admin/video-operations" "${token}")"
  printf '%s' "${operations_payload}" | assert_admin_video_operations_payload
  log "admin video operations: realtime safe payload verified"

  admin_logout_session "${token}" || fail "admin session cleanup failed"
  ADMIN_SMOKE_SESSION_TOKEN=""
}

admin_get_json() {
  local label="$1" url="$2" token="$3" output code attempt
  output="$(mktemp)"
  for attempt in $(seq 1 30); do
    code="$(
      curl -sS --max-time "${TIMEOUT}" \
        -o "${output}" \
        -w '%{http_code}' \
        -H "authorization: Bearer ${token}" \
        "${url}" || true
    )"
    if [[ "${code}" == "200" ]]; then
      cat "${output}"
      rm -f "${output}"
      return 0
    fi
    sleep 1
  done

  rm -f "${output}"
  fail "${label}: expected HTTP 200 after deploy readiness retries, got ${code:-none}"
}

admin_post_json() {
  local label="$1" url="$2" token="$3" payload="$4" output code attempt
  output="$(mktemp)"
  for attempt in $(seq 1 30); do
    code="$(
      curl -sS --max-time "${TIMEOUT}" \
        -o "${output}" \
        -w '%{http_code}' \
        -X POST \
        -H "authorization: Bearer ${token}" \
        -H 'content-type: application/json' \
        --data "${payload}" \
        "${url}" || true
    )"
    if [[ "${code}" == "200" ]]; then
      cat "${output}"
      rm -f "${output}"
      return 0
    fi
    sleep 1
  done

  printf '[videochat-deploy-smoke] %s response body:\n' "${label}" >&2
  cat "${output}" >&2 || true
  rm -f "${output}"
  fail "${label}: expected HTTP 200 after deploy readiness retries, got ${code:-none}"
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
DEPLOY_CDN_DOMAIN="${VIDEOCHAT_DEPLOY_CDN_DOMAIN:-cdn.${DEPLOY_DOMAIN}}"

check_https_redirect
expect_http_code https-frontend 200 "https://${DEPLOY_DOMAIN}/"
expect_http_code cdn-mediapipe-wasm-loader 200 "https://${DEPLOY_CDN_DOMAIN}/cdn/vendor/mediapipe/selfie_segmentation/selfie_segmentation_solution_simd_wasm_bin.js"
expect_http_code cdn-tensorflow-fallback-loader 200 "https://${DEPLOY_CDN_DOMAIN}/cdn/vendor/tensorflow/tfjs-core/tf-core.min.js"

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
verify_admin_operations

log "Production deploy smoke checks passed."
