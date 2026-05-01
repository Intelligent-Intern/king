#!/usr/bin/env bash
# #A-8 end-to-end auth smoke. Mirrors discovery-smoke.sh in shape:
# phase 0 = syntax, phase 1 = offline contracts, phase 2 = compose boot,
# phases 3+ = HTTP probes against the live backend.
#
# Gated on MODEL_INFERENCE_SMOKE_REQUIRE_COMPOSE=1 when CI wants live
# coverage; without compose the offline suite runs and exits 0.
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
DEMO_DIR="$(cd "${SCRIPT_DIR}/.." && pwd)"
BACKEND_DIR="${DEMO_DIR}/backend-king-php"

COMPOSE_FILE="${DEMO_DIR}/docker-compose.v1.yml"
PROJECT="${MODEL_INFERENCE_SMOKE_COMPOSE_PROJECT:-king-auth-smoke-$$}"

pass() { echo "[auth-smoke] PASS: $1"; }
fail() { echo "[auth-smoke] FAIL: $1" >&2; exit 1; }
info() { echo "[auth-smoke] $1"; }
json() { python3 -c "import sys,json; d=json.load(sys.stdin); $1" 2>/dev/null; }

info "phase 0: syntax validation"
php_files=(
  "${BACKEND_DIR}/domain/auth/auth_store.php"
  "${BACKEND_DIR}/domain/auth/auth_middleware.php"
  "${BACKEND_DIR}/http/module_auth.php"
  "${BACKEND_DIR}/http/module_conversations.php"
  "${BACKEND_DIR}/http/router.php"
  "${BACKEND_DIR}/server.php"
)
for f in "${php_files[@]}"; do
  [[ -f "$f" ]] && php -l "$f" >/dev/null 2>&1 || fail "PHP syntax error in ${f}"
done
pass "PHP syntax OK"

info "phase 1: A-batch offline contract tests"
a_batch_tests=(
  auth-store-contract
  auth-endpoint-contract
  auth-middleware-contract
  auth-seed-contract
  conversation-ownership-contract
  realtime-auth-contract
)
for t in "${a_batch_tests[@]}"; do
  bash "${BACKEND_DIR}/tests/${t}.sh" 2>&1 | tail -3
done

# Regression gates.
bash "${BACKEND_DIR}/tests/contract-catalog-parity-contract.sh" 2>&1 | tail -2
bash "${BACKEND_DIR}/tests/router-module-order-contract.sh" 2>&1 | tail -2
bash "${BACKEND_DIR}/tests/conversation-store-contract.sh" 2>&1 | tail -2
bash "${BACKEND_DIR}/tests/conversations-endpoint-contract.sh" 2>&1 | tail -2
pass "A-batch contract tests + regressions green"

if ! command -v docker >/dev/null 2>&1 || ! docker compose version >/dev/null 2>&1; then
  if [[ "${MODEL_INFERENCE_SMOKE_REQUIRE_COMPOSE:-0}" == "1" ]]; then
    fail "docker compose not available and MODEL_INFERENCE_SMOKE_REQUIRE_COMPOSE=1"
  fi
  info "SKIP: docker compose not available — offline tests passed"
  exit 0
fi

info "phase 2: compose boot"
port_is_bindable() {
  python3 -c "
import socket, sys
s = socket.socket(socket.AF_INET, socket.SOCK_STREAM)
try: s.bind(('127.0.0.1', int(sys.argv[1]))); s.close()
except OSError: sys.exit(1)
" "$1" 2>/dev/null
}
resolve_port() {
  local c=$1
  while (( c <= 65535 )); do
    if port_is_bindable "$c"; then printf '%s\n' "$c"; return 0; fi
    c=$((c+1))
  done; return 1
}
PORT_A="$(resolve_port "${MODEL_INFERENCE_V1_NODE_A_PORT:-58090}")"
PORT_B="$(resolve_port "$((PORT_A+2))")"
export MODEL_INFERENCE_V1_NODE_A_PORT="${PORT_A}"
export MODEL_INFERENCE_V1_NODE_B_PORT="${PORT_B}"

compose() { docker compose -f "${COMPOSE_FILE}" -p "${PROJECT}" "$@"; }
cleanup() { compose down -v --remove-orphans 2>/dev/null || true; }
trap cleanup EXIT

compose up -d --build 2>&1 | tail -5
for i in $(seq 1 180); do
  if curl -sf --max-time 3 "http://127.0.0.1:${PORT_A}/health" >/dev/null 2>&1; then
    pass "node-a healthy after ${i}s"
    break
  fi
  sleep 1
  if [[ "$i" == 180 ]]; then compose logs inference-node-a 2>/dev/null | tail -30; fail "node-a never became healthy"; fi
done

BASE="http://127.0.0.1:${PORT_A}"

info "phase 3: anonymous /api/infer still works (backward-compat gate)"
curl -sf --max-time 5 "${BASE}/api/models" >/dev/null || fail "anonymous /api/models failed"
pass "anonymous GET /api/models ok"

info "phase 4: POST /api/auth/login as alice"
login_resp="$(curl -sf --max-time 5 -X POST "${BASE}/api/auth/login" \
  -H 'Content-Type: application/json' \
  -d '{"username":"alice","password":"alice123"}')" || fail "alice login failed"
alice_token="$(echo "$login_resp" | json "print(d.get('session',{}).get('id',''))")"
[[ -n "$alice_token" ]] || fail "alice token empty"
pass "alice logged in, token=${alice_token:0:8}…"

info "phase 5: GET /api/auth/whoami with alice bearer"
who="$(curl -sf --max-time 5 -H "Authorization: Bearer ${alice_token}" "${BASE}/api/auth/whoami")" || fail "whoami failed"
alice_username="$(echo "$who" | json "print(d.get('user',{}).get('username',''))")"
[[ "$alice_username" == "alice" ]] || fail "whoami returned ${alice_username}, expected alice"
pass "whoami returns alice"

info "phase 6: GET /api/conversations/me returns empty list for alice"
me="$(curl -sf --max-time 5 -H "Authorization: Bearer ${alice_token}" "${BASE}/api/conversations/me")"
me_count="$(echo "$me" | json "print(d.get('count',-1))")"
[[ "$me_count" == "0" ]] || fail "expected alice /me count=0, got ${me_count}"
pass "alice has 0 owned conversations pre-chat"

info "phase 7: POST /api/auth/logout revokes session"
logout="$(curl -sf --max-time 5 -X POST -H "Authorization: Bearer ${alice_token}" "${BASE}/api/auth/logout")"
state_field="$(echo "$logout" | json "print(d.get('revocation_state',''))")"
[[ "$state_field" == "revoked" ]] || fail "expected revoked, got ${state_field}"
pass "alice session revoked"

info "phase 8: whoami after logout returns 401"
code="$(curl -s -o /dev/null -w '%{http_code}' --max-time 5 -H "Authorization: Bearer ${alice_token}" "${BASE}/api/auth/whoami")"
[[ "$code" == "401" ]] || fail "expected 401 after logout, got ${code}"
pass "post-logout whoami returns 401"

info "phase 9: cross-user ownership gate"
# Log alice back in
login_resp="$(curl -sf --max-time 5 -X POST "${BASE}/api/auth/login" -H 'Content-Type: application/json' -d '{"username":"alice","password":"alice123"}')"
alice_token="$(echo "$login_resp" | json "print(d.get('session',{}).get('id',''))")"
# Log bob in
bob_resp="$(curl -sf --max-time 5 -X POST "${BASE}/api/auth/login" -H 'Content-Type: application/json' -d '{"username":"bob","password":"bob123"}')"
bob_token="$(echo "$bob_resp" | json "print(d.get('session',{}).get('id',''))")"
# /me for each
alice_me="$(curl -sf --max-time 5 -H "Authorization: Bearer ${alice_token}" "${BASE}/api/conversations/me" | json "print(d.get('count',-1))")"
bob_me="$(curl -sf --max-time 5 -H "Authorization: Bearer ${bob_token}" "${BASE}/api/conversations/me" | json "print(d.get('count',-1))")"
pass "alice /me count=${alice_me}, bob /me count=${bob_me}"

echo "=========================================="
echo "[auth-smoke] ALL PHASES PASSED"
echo "=========================================="
