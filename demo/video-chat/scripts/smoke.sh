#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
FRONTEND_DIR="${ROOT_DIR}/frontend-vue"
BACKEND_DIR="${ROOT_DIR}/backend-king-php"
COMPOSE_FILE="${ROOT_DIR}/docker-compose.v1.yml"

log() {
  printf '[video-chat-smoke] %s\n' "$*"
}

run_step() {
  local step="$1"
  log "START: ${step}"
  shift
  "$@"
  log "OK: ${step}"
}

compose_smoke() {
  if [[ "${VIDEOCHAT_SMOKE_SKIP_COMPOSE:-0}" == "1" ]]; then
    log "SKIP: compose smoke gate disabled via VIDEOCHAT_SMOKE_SKIP_COMPOSE=1"
    return 0
  fi

  if ! command -v docker >/dev/null 2>&1; then
    if [[ "${VIDEOCHAT_SMOKE_REQUIRE_COMPOSE:-0}" == "1" ]]; then
      log "ERROR: docker CLI missing while VIDEOCHAT_SMOKE_REQUIRE_COMPOSE=1"
      return 1
    fi
    log "SKIP: docker CLI not available; compose smoke gate not executed"
    return 0
  fi

  if ! docker compose version >/dev/null 2>&1; then
    if [[ "${VIDEOCHAT_SMOKE_REQUIRE_COMPOSE:-0}" == "1" ]]; then
      log "ERROR: docker compose plugin unavailable while VIDEOCHAT_SMOKE_REQUIRE_COMPOSE=1"
      return 1
    fi
    log "SKIP: docker compose plugin unavailable; compose smoke gate not executed"
    return 0
  fi

  if ! docker info >/dev/null 2>&1; then
    if [[ "${VIDEOCHAT_SMOKE_REQUIRE_COMPOSE:-0}" == "1" ]]; then
      log "ERROR: docker daemon unavailable while VIDEOCHAT_SMOKE_REQUIRE_COMPOSE=1"
      return 1
    fi
    log "SKIP: docker daemon unavailable; compose smoke gate not executed"
    return 0
  fi

  local compose_backend_port="${VIDEOCHAT_SMOKE_COMPOSE_BACKEND_PORT:-38080}"
  local compose_backend_ws_port="${VIDEOCHAT_SMOKE_COMPOSE_BACKEND_WS_PORT:-$((compose_backend_port + 1))}"
  local compose_frontend_port="${VIDEOCHAT_SMOKE_COMPOSE_FRONTEND_PORT:-35174}"
  local compose_project="${VIDEOCHAT_SMOKE_COMPOSE_PROJECT:-king-videochat-smoke}"
  local compose_backend_php_image="${VIDEOCHAT_SMOKE_COMPOSE_BACKEND_PHP_IMAGE:-}"
  local king_extension_path="${VIDEOCHAT_SMOKE_KING_EXTENSION_PATH:-${ROOT_DIR}/../../extension/modules/king.so}"
  local king_extension_api=""

  if [[ -z "${compose_backend_php_image}" ]]; then
    if [[ -f "${king_extension_path}" ]] && command -v strings >/dev/null 2>&1; then
      king_extension_api="$(strings "${king_extension_path}" 2>/dev/null | sed -n 's/.*API\([0-9]\{8\}\).*/\1/p' | head -n1 || true)"
    fi

    if [[ "${king_extension_api}" == "20250925" ]]; then
      compose_backend_php_image="php:8.5-cli-trixie"
    elif [[ "${king_extension_api}" == "20240924" ]]; then
      compose_backend_php_image="php:8.4-cli-trixie"
    else
      local host_php_api=""
      host_php_api="$(php -i 2>/dev/null | awk -F'=> ' '/^PHP API =>/{gsub(/ /, "", $2); print $2; exit}' || true)"
      if [[ "${host_php_api}" =~ ^[0-9]+$ ]] && (( host_php_api >= 20250925 )); then
        compose_backend_php_image="php:8.5-cli-trixie"
      else
        compose_backend_php_image="php:8.4-cli-trixie"
      fi
    fi
  fi

  log "compose smoke project=${compose_project} backend=${compose_backend_port} backend_ws=${compose_backend_ws_port} frontend=${compose_frontend_port} backend_php_image=${compose_backend_php_image} king_extension_api=${king_extension_api:-unknown}"

  local compose_cmd=(
    docker compose
    -p "${compose_project}"
    -f "${COMPOSE_FILE}"
  )

  local compose_up_log
  compose_up_log="$(mktemp)"
  if VIDEOCHAT_V1_BACKEND_PORT="${compose_backend_port}" \
    VIDEOCHAT_V1_BACKEND_WS_PORT="${compose_backend_ws_port}" \
    VIDEOCHAT_V1_FRONTEND_PORT="${compose_frontend_port}" \
    VIDEOCHAT_V1_BACKEND_ORIGIN="http://127.0.0.1:${compose_backend_port}" \
    VIDEOCHAT_V1_BACKEND_PHP_IMAGE="${compose_backend_php_image}" \
    "${compose_cmd[@]}" up -d --build >"${compose_up_log}" 2>&1; then
    rm -f "${compose_up_log}"
  else
    local compose_up_exit=$?
    log "ERROR: docker compose up failed (exit=${compose_up_exit}); dumping output"
    cat "${compose_up_log}" >&2 || true
    rm -f "${compose_up_log}"
    return 1
  fi

  local cleanup_compose_enabled=0
  cleanup_compose() {
    if [[ "${cleanup_compose_enabled:-0}" == "1" ]]; then
      VIDEOCHAT_V1_BACKEND_PORT="${compose_backend_port}" \
      VIDEOCHAT_V1_BACKEND_WS_PORT="${compose_backend_ws_port}" \
      VIDEOCHAT_V1_FRONTEND_PORT="${compose_frontend_port}" \
      VIDEOCHAT_V1_BACKEND_ORIGIN="http://127.0.0.1:${compose_backend_port}" \
      VIDEOCHAT_V1_BACKEND_PHP_IMAGE="${compose_backend_php_image}" \
      "${compose_cmd[@]}" down -v >/dev/null 2>&1 || true
    fi
  }
  cleanup_compose_enabled=1
  trap cleanup_compose RETURN

  local health_url="http://127.0.0.1:${compose_backend_port}/health"
  local runtime_url="http://127.0.0.1:${compose_backend_port}/api/runtime"
  local login_url="http://127.0.0.1:${compose_backend_port}/api/auth/login"
  local session_url="http://127.0.0.1:${compose_backend_port}/api/auth/session"
  local frontend_url="http://127.0.0.1:${compose_frontend_port}/"

  for _ in {1..180}; do
    if curl -fsS "${health_url}" >/dev/null; then
      break
    fi
    sleep 0.5
  done

  if ! curl -fsS "${health_url}" >/dev/null; then
    log "ERROR: backend health did not become ready; dumping compose status/logs"
    VIDEOCHAT_V1_BACKEND_PORT="${compose_backend_port}" \
    VIDEOCHAT_V1_BACKEND_WS_PORT="${compose_backend_ws_port}" \
    VIDEOCHAT_V1_FRONTEND_PORT="${compose_frontend_port}" \
    VIDEOCHAT_V1_BACKEND_ORIGIN="http://127.0.0.1:${compose_backend_port}" \
    VIDEOCHAT_V1_BACKEND_PHP_IMAGE="${compose_backend_php_image}" \
    "${compose_cmd[@]}" ps || true
    VIDEOCHAT_V1_BACKEND_PORT="${compose_backend_port}" \
    VIDEOCHAT_V1_BACKEND_WS_PORT="${compose_backend_ws_port}" \
    VIDEOCHAT_V1_FRONTEND_PORT="${compose_frontend_port}" \
    VIDEOCHAT_V1_BACKEND_ORIGIN="http://127.0.0.1:${compose_backend_port}" \
    VIDEOCHAT_V1_BACKEND_PHP_IMAGE="${compose_backend_php_image}" \
    "${compose_cmd[@]}" logs --tail 200 videochat-backend-v1 || true
    return 1
  fi
  curl -fsS "${frontend_url}" >/dev/null

  local runtime_response
  runtime_response="$(curl -fsS "${runtime_url}")"
  printf '%s' "${runtime_response}" | php -r '
    $raw = stream_get_contents(STDIN);
    $data = json_decode($raw, true);
    if (!is_array($data) || ($data["status"] ?? "") !== "ok") {
        fwrite(STDERR, "runtime status not ok\n");
        exit(1);
    }
    $db = $data["database"] ?? null;
    if (!is_array($db)) {
        fwrite(STDERR, "runtime missing database snapshot\n");
        exit(1);
    }

    $applied = -1;
    if (is_array($db["migrations"] ?? null)) {
        $applied = (int) ($db["migrations"]["applied_count"] ?? -1);
    } elseif (array_key_exists("migrations_applied", $db)) {
        $applied = (int) ($db["migrations_applied"] ?? -1);
    } elseif (array_key_exists("schema_version", $db)) {
        $applied = (int) ($db["schema_version"] ?? -1);
    }

    if ($applied < 1) {
        fwrite(STDERR, "runtime migration snapshot invalid\n");
        exit(1);
    }
  '

  local login_response
  login_response="$(curl -fsS -X POST \
    -H 'content-type: application/json' \
    --data '{"email":"admin@intelligent-intern.com","password":"admin123"}' \
    "${login_url}")"

  local session_token
  session_token="$(printf '%s' "${login_response}" | php -r '
    $raw = stream_get_contents(STDIN);
    $data = json_decode($raw, true);
    if (!is_array($data) || ($data["status"] ?? "") !== "ok") {
        fwrite(STDERR, "compose login status not ok\n");
        exit(1);
    }
    $token = $data["session"]["token"] ?? null;
    if (!is_string($token) || trim($token) === "") {
        fwrite(STDERR, "compose login missing session token\n");
        exit(1);
    }
    echo trim($token);
  ')"

  local session_response
  session_response="$(curl -fsS \
    -H "authorization: Bearer ${session_token}" \
    "${session_url}")"

  printf '%s' "${session_response}" | php -r '
    $raw = stream_get_contents(STDIN);
    $data = json_decode($raw, true);
    if (!is_array($data) || ($data["status"] ?? "") !== "ok") {
        fwrite(STDERR, "compose session status not ok\n");
        exit(1);
    }
    $email = $data["user"]["email"] ?? null;
    if (!is_string($email) || strtolower(trim($email)) !== "admin@intelligent-intern.com") {
        fwrite(STDERR, "compose session user mismatch\n");
        exit(1);
    }
  '
}

log "Root: ${ROOT_DIR}"

run_step "backend launcher syntax" bash -lc "bash -n '${BACKEND_DIR}/run-dev.sh'"
run_step "backend php syntax" bash -lc "php -l '${BACKEND_DIR}/public/index.php'"
run_step "backend server php syntax" bash -lc "php -l '${BACKEND_DIR}/server.php'"
run_step "frontend launcher syntax" bash -lc "node --check '${FRONTEND_DIR}/scripts/dev-server.mjs'"
run_step "compose stack boot + migration/auth sanity" compose_smoke

if [[ "${VIDEOCHAT_SMOKE_COMPOSE_ONLY:-0}" == "1" ]]; then
  log "Compose-only smoke checks passed."
  exit 0
fi

BACKEND_PORT="${VIDEOCHAT_KING_PORT:-18080}"
FRONTEND_PORT="${VIDEOCHAT_VUE_PORT:-5174}"

run_step "backend scaffold boot + auth/login check" bash -lc "
  set -euo pipefail
  tmp_log=\$(mktemp)
  VIDEOCHAT_KING_PORT='${BACKEND_PORT}' '${BACKEND_DIR}/run-dev.sh' >\"\${tmp_log}\" 2>&1 &
  pid=\$!
  trap 'kill \"\${pid}\" >/dev/null 2>&1 || true; rm -f \"\${tmp_log}\"' EXIT
  for _ in {1..60}; do
    if curl -fsS \"http://127.0.0.1:${BACKEND_PORT}/health\" >/dev/null; then
      break
    fi
    sleep 0.2
  done
  if ! curl -fsS \"http://127.0.0.1:${BACKEND_PORT}/health\" >/dev/null; then
    cat \"\${tmp_log}\" >&2 || true
    exit 1
  fi

  login_response=\$(curl -fsS -X POST \
    -H 'content-type: application/json' \
    --data '{\"email\":\"admin@intelligent-intern.com\",\"password\":\"admin123\"}' \
    \"http://127.0.0.1:${BACKEND_PORT}/api/auth/login\")

  session_token=\$(printf '%s' \"\${login_response}\" | php -r '
    \$raw = stream_get_contents(STDIN);
    \$data = json_decode(\$raw, true);
    if (!is_array(\$data) || (\$data[\"status\"] ?? \"\") !== \"ok\") {
        fwrite(STDERR, \"login status not ok\\n\");
        exit(1);
    }
    \$token = \$data[\"session\"][\"token\"] ?? null;
    if (!is_string(\$token) || trim(\$token) === \"\") {
        fwrite(STDERR, \"missing login session token\\n\");
        exit(1);
    }
    echo trim(\$token);
  ')

  session_response=\$(curl -fsS \
    -H \"authorization: Bearer \${session_token}\" \
    \"http://127.0.0.1:${BACKEND_PORT}/api/auth/session\")

  printf '%s' \"\${session_response}\" | php -r '
    \$raw = stream_get_contents(STDIN);
    \$data = json_decode(\$raw, true);
    if (!is_array(\$data) || (\$data[\"status\"] ?? \"\") !== \"ok\") {
        fwrite(STDERR, \"session status not ok\\n\");
        exit(1);
    }
    \$email = \$data[\"user\"][\"email\"] ?? null;
    if (!is_string(\$email) || strtolower(trim(\$email)) !== \"admin@intelligent-intern.com\") {
        fwrite(STDERR, \"unexpected session user email\\n\");
        exit(1);
    }
  '

  logout_response=\$(curl -fsS -X POST \
    -H \"authorization: Bearer \${session_token}\" \
    \"http://127.0.0.1:${BACKEND_PORT}/api/auth/logout\")

  printf '%s' \"\${logout_response}\" | php -r '
    \$raw = stream_get_contents(STDIN);
    \$data = json_decode(\$raw, true);
    if (!is_array(\$data) || (\$data[\"status\"] ?? \"\") !== \"ok\") {
        fwrite(STDERR, \"logout status not ok\\n\");
        exit(1);
    }
  '

  kill \"\${pid}\" >/dev/null 2>&1 || true
  wait \"\${pid}\" 2>/dev/null || true
  rm -f \"\${tmp_log}\"
  trap - EXIT
  exit 0
"

run_step "backend contract: auth/session" bash -lc "'${BACKEND_DIR}/tests/session-auth-contract.sh'"
run_step "backend contract: auth/session refresh-rotation" bash -lc "'${BACKEND_DIR}/tests/session-refresh-contract.sh'"
run_step "backend contract: auth/session logout-revoke" bash -lc "'${BACKEND_DIR}/tests/session-logout-contract.sh'"
run_step "backend contract: RBAC middleware matrix" bash -lc "'${BACKEND_DIR}/tests/rbac-middleware-contract.sh'"
run_step "backend contract: realtime session revocation propagation" bash -lc "'${BACKEND_DIR}/tests/realtime-session-revocation-contract.sh'"
run_step "backend contract: API/WS catalog parity" bash -lc "'${BACKEND_DIR}/tests/contract-catalog-parity-contract.sh'"
run_step "backend contract: WLVC wire envelope" bash -lc "'${BACKEND_DIR}/tests/wlvc-wire-contract.sh'"
run_step "backend contract: room join/presence" bash -lc "'${BACKEND_DIR}/tests/realtime-presence-contract.sh'"
run_step "backend contract: chat fanout" bash -lc "'${BACKEND_DIR}/tests/realtime-chat-contract.sh'"
run_step "backend contract: reaction stream throttle" bash -lc "'${BACKEND_DIR}/tests/realtime-reaction-contract.sh'"
run_step "backend contract: invite redeem" bash -lc "'${BACKEND_DIR}/tests/invite-code-redeem-contract.sh'"
run_step "backend contract: call signaling bootstrap" bash -lc "'${BACKEND_DIR}/tests/realtime-signaling-contract.sh'"

run_step "frontend contract: WLVC wire envelope" bash -lc "
  set -euo pipefail
  cd '${FRONTEND_DIR}'
  npm run test:contract:wlvc
"

run_step "frontend scaffold boot check" bash -lc "
  set -euo pipefail
  tmp_log=\$(mktemp)
  cd '${FRONTEND_DIR}'
  VIDEOCHAT_VUE_PORT='${FRONTEND_PORT}' npm run dev >\"\${tmp_log}\" 2>&1 &
  pid=\$!
  trap 'kill \"\${pid}\" >/dev/null 2>&1 || true; rm -f \"\${tmp_log}\"' EXIT
  for _ in {1..30}; do
    if curl -fsS \"http://127.0.0.1:${FRONTEND_PORT}/\" >/dev/null; then
      kill \"\${pid}\" >/dev/null 2>&1 || true
      wait \"\${pid}\" 2>/dev/null || true
      rm -f \"\${tmp_log}\"
      trap - EXIT
      exit 0
    fi
    sleep 0.2
  done
  cat \"\${tmp_log}\" >&2 || true
  exit 1
"

log "All new-stack smoke checks passed."
