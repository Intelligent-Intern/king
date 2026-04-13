#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
FRONTEND_DIR="${ROOT_DIR}/frontend-vue"
BACKEND_DIR="${ROOT_DIR}/backend-king-php"

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

log "Root: ${ROOT_DIR}"

run_step "backend launcher syntax" bash -lc "bash -n '${BACKEND_DIR}/run-dev.sh'"
run_step "backend php syntax" bash -lc "php -l '${BACKEND_DIR}/public/index.php'"
run_step "backend server php syntax" bash -lc "php -l '${BACKEND_DIR}/server.php'"
run_step "frontend launcher syntax" bash -lc "node --check '${FRONTEND_DIR}/scripts/dev-server.mjs'"

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
run_step "backend contract: API/WS catalog parity" bash -lc "'${BACKEND_DIR}/tests/contract-catalog-parity-contract.sh'"
run_step "backend contract: room join/presence" bash -lc "'${BACKEND_DIR}/tests/realtime-presence-contract.sh'"
run_step "backend contract: chat fanout" bash -lc "'${BACKEND_DIR}/tests/realtime-chat-contract.sh'"
run_step "backend contract: invite redeem" bash -lc "'${BACKEND_DIR}/tests/invite-code-redeem-contract.sh'"
run_step "backend contract: call signaling bootstrap" bash -lc "'${BACKEND_DIR}/tests/realtime-signaling-contract.sh'"

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
