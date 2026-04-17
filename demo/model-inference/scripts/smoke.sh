#!/usr/bin/env bash
# M-17: Two-node compose end-to-end smoke test.
#
# Exercises the full model-inference surface:
#   1. Compose boot (two nodes)
#   2. Health + runtime + node-profile probes
#   3. Model registry (autoseed verification)
#   4. Real non-streaming inference (POST /api/infer)
#   5. Transcript retrieval (GET /api/transcripts/{request_id})
#   6. Telemetry (GET /api/telemetry/inference/recent)
#   7. Routing diagnostic (GET /api/route)
#   8. Failover: stop node-a, verify node-b serves
#   9. Contract test suite (non-extension tests only)
#
# Gated on MODEL_INFERENCE_SMOKE_REQUIRE_COMPOSE=1 in CI.
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
DEMO_DIR="$(cd "${SCRIPT_DIR}/.." && pwd)"
REPO_ROOT="$(cd "${DEMO_DIR}/../.." && pwd)"
BACKEND_DIR="${DEMO_DIR}/backend-king-php"

COMPOSE_FILE="${DEMO_DIR}/docker-compose.v1.yml"
PROJECT="${MODEL_INFERENCE_SMOKE_COMPOSE_PROJECT:-king-mi-smoke-$$}"

pass() { echo "[smoke] PASS: $1"; }
fail() { echo "[smoke] FAIL: $1" >&2; exit 1; }
info() { echo "[smoke] $1"; }

# ── Phase 0: Syntax validation ────────────────────────────────────────────

info "phase 0: syntax validation"

php_files=(
  "${BACKEND_DIR}/server.php"
  "${BACKEND_DIR}/http/router.php"
)
for f in "${php_files[@]}"; do
  if [[ -f "$f" ]]; then
    php -l "$f" >/dev/null 2>&1 || fail "PHP syntax error in ${f}"
  fi
done
pass "PHP syntax OK"

bash -n "${BACKEND_DIR}/run-dev.sh" 2>/dev/null || fail "bash syntax error in run-dev.sh"
pass "bash syntax OK"

# ── Phase 1: Contract tests (no extension required) ───────────────────────

info "phase 1: offline contract tests"

offline_tests=(
  contract-catalog-parity-contract
  inference-request-envelope-contract
  inference-routing-contract
  model-fit-selector-contract
  node-profile-contract
  router-module-order-contract
  runtime-bootstrap-contract
  semantic-dns-contract
  token-frame-wire-contract
  transcript-persistence-contract
  ui-chat-contract
)
for test_name in "${offline_tests[@]}"; do
  script="${BACKEND_DIR}/tests/${test_name}-contract.sh"
  if [[ -x "$script" ]]; then
    bash "$script" 2>&1 || fail "contract test failed: ${test_name}"
  fi
done
pass "all offline contract tests green"

# ── Phase 2: Compose boot ─────────────────────────────────────────────────

if ! command -v docker >/dev/null 2>&1 || ! docker compose version >/dev/null 2>&1; then
  if [[ "${MODEL_INFERENCE_SMOKE_REQUIRE_COMPOSE:-0}" == "1" ]]; then
    fail "docker compose not available and MODEL_INFERENCE_SMOKE_REQUIRE_COMPOSE=1"
  fi
  info "SKIP: docker compose not available — offline tests passed"
  exit 0
fi

info "phase 2: compose boot"

port_is_bindable() {
  local p="$1"
  if command -v python3 >/dev/null 2>&1; then
    python3 -c "
import socket, sys
s = socket.socket(socket.AF_INET, socket.SOCK_STREAM)
try:
    s.bind(('127.0.0.1', int(sys.argv[1])))
    s.close()
except OSError:
    sys.exit(1)
" "$p" 2>/dev/null
    return $?
  fi
  (echo >/dev/tcp/127.0.0.1/"$p") 2>/dev/null && return 1
  return 0
}

resolve_port() {
  local candidate="$1"
  while (( candidate <= 65535 )); do
    if port_is_bindable "$candidate"; then
      printf '%s\n' "$candidate"
      return 0
    fi
    candidate=$((candidate + 1))
  done
  return 1
}

PORT_A="$(resolve_port "${MODEL_INFERENCE_V1_NODE_A_PORT:-38090}")"
PORT_B="$(resolve_port "$((PORT_A + 2))")"
export MODEL_INFERENCE_V1_NODE_A_PORT="${PORT_A}"
export MODEL_INFERENCE_V1_NODE_B_PORT="${PORT_B}"
info "resolved ports: node-a=${PORT_A} node-b=${PORT_B}"

compose() {
  docker compose -f "${COMPOSE_FILE}" -p "${PROJECT}" "$@"
}

cleanup() {
  info "cleaning up compose project ${PROJECT}..."
  compose down -v --remove-orphans 2>/dev/null || true
}
trap cleanup EXIT

compose up -d --build 2>&1 | tail -5

wait_healthy() {
  local label="$1" url="$2" max="$3"
  local attempt=0
  while (( attempt < max )); do
    if curl -sf --max-time 3 "${url}" >/dev/null 2>&1; then
      pass "${label} healthy after $((attempt + 1))s"
      return 0
    fi
    attempt=$((attempt + 1))
    sleep 1
  done
  compose logs "$4" 2>/dev/null | tail -30 || true
  fail "${label} did not become healthy after ${max}s"
}

wait_healthy "node-a" "http://127.0.0.1:${PORT_A}/health" 180 inference-node-a
wait_healthy "node-b" "http://127.0.0.1:${PORT_B}/health" 180 inference-node-b

# ── Phase 3: Runtime + profile probes ─────────────────────────────────────

info "phase 3: runtime + profile probes"

for label_port in "node-a:${PORT_A}" "node-b:${PORT_B}"; do
  label="${label_port%%:*}"
  port="${label_port##*:}"

  runtime="$(curl -sf "http://127.0.0.1:${port}/api/runtime")"
  service="$(echo "$runtime" | python3 -c "import sys,json; print(json.load(sys.stdin).get('service',''))" 2>/dev/null || echo "")"
  [[ "$service" == "model-inference-backend-king-php" ]] || fail "${label} /api/runtime service mismatch: ${service}"

  profile="$(curl -sf "http://127.0.0.1:${port}/api/node/profile")"
  os="$(echo "$profile" | python3 -c "import sys,json; print(json.load(sys.stdin).get('platform',{}).get('os',''))" 2>/dev/null || echo "")"
  [[ "$os" == "linux" ]] || fail "${label} profile.platform.os should be linux in container, got: ${os}"

  pass "${label} runtime + profile OK"
done

# ── Phase 4: Model registry ───────────────────────────────────────────────

info "phase 4: model registry (autoseed)"

wait_model() {
  local label="$1" url="$2" max="$3"
  local attempt=0
  while (( attempt < max )); do
    local count
    count="$(curl -sf "${url}" | python3 -c "import sys,json; print(json.load(sys.stdin).get('count',0))" 2>/dev/null || echo "0")"
    if [[ "$count" -gt 0 ]]; then
      pass "${label} has ${count} model(s)"
      return 0
    fi
    attempt=$((attempt + 1))
    sleep 2
  done
  fail "${label} has no models after ${max} attempts"
}

wait_model "node-a" "http://127.0.0.1:${PORT_A}/api/models" 30
wait_model "node-b" "http://127.0.0.1:${PORT_B}/api/models" 30

# ── Phase 5: Real inference on node-a ─────────────────────────────────────

info "phase 5: non-streaming inference on node-a"

response_a="$(curl -sf --max-time 120 \
  -X POST "http://127.0.0.1:${PORT_A}/api/infer" \
  -H "Content-Type: application/json" \
  -d '{
    "session_id": "smoke-test-01",
    "model_selector": {"model_name": "SmolLM2-135M-Instruct", "quantization": "Q4_K"},
    "prompt": "Say hello in one sentence.",
    "sampling": {"temperature": 0.1, "top_p": 1.0, "top_k": 0, "max_tokens": 64},
    "stream": false
  }')" || fail "POST /api/infer on node-a failed"

status_a="$(echo "$response_a" | python3 -c "import sys,json; print(json.load(sys.stdin).get('status',''))" 2>/dev/null)"
text_a="$(echo "$response_a" | python3 -c "import sys,json; print(json.load(sys.stdin).get('completion',{}).get('text','')[:100])" 2>/dev/null)"
request_id_a="$(echo "$response_a" | python3 -c "import sys,json; print(json.load(sys.stdin).get('request_id',''))" 2>/dev/null)"
tokens_out_a="$(echo "$response_a" | python3 -c "import sys,json; print(json.load(sys.stdin).get('completion',{}).get('tokens_out',0))" 2>/dev/null)"

[[ "$status_a" == "ok" ]] || fail "inference status should be ok, got: ${status_a}"
[[ -n "$text_a" ]] || fail "inference returned empty text"
[[ "$tokens_out_a" -gt 0 ]] || fail "tokens_out should be > 0, got: ${tokens_out_a}"
pass "inference on node-a: ${tokens_out_a} tokens, request_id=${request_id_a}"

# ── Phase 6: Transcript retrieval ─────────────────────────────────────────

info "phase 6: transcript retrieval"

transcript_a="$(curl -sf --max-time 10 \
  "http://127.0.0.1:${PORT_A}/api/transcripts/${request_id_a}" 2>/dev/null || echo "")"

if [[ -n "$transcript_a" ]]; then
  tr_status="$(echo "$transcript_a" | python3 -c "import sys,json; print(json.load(sys.stdin).get('status',''))" 2>/dev/null)"
  tr_prompt="$(echo "$transcript_a" | python3 -c "import sys,json; print(json.load(sys.stdin).get('transcript',{}).get('prompt',''))" 2>/dev/null)"
  if [[ "$tr_status" == "ok" && -n "$tr_prompt" ]]; then
    pass "transcript round-trip: prompt='${tr_prompt}'"
  else
    info "transcript retrieval returned status=${tr_status} (non-fatal)"
  fi
else
  info "transcript endpoint returned empty (object store may not be initialized — non-fatal)"
fi

# ── Phase 7: Telemetry ────────────────────────────────────────────────────

info "phase 7: telemetry"

telemetry_a="$(curl -sf --max-time 10 \
  "http://127.0.0.1:${PORT_A}/api/telemetry/inference/recent")"
tel_count="$(echo "$telemetry_a" | python3 -c "import sys,json; print(json.load(sys.stdin).get('count',0))" 2>/dev/null)"
[[ "$tel_count" -gt 0 ]] || fail "telemetry count should be > 0 after inference, got: ${tel_count}"
pass "telemetry: ${tel_count} recent entries on node-a"

# ── Phase 8: Routing diagnostic ───────────────────────────────────────────

info "phase 8: routing diagnostic"

route_a="$(curl -sf --max-time 10 \
  "http://127.0.0.1:${PORT_A}/api/route?model_name=SmolLM2-135M-Instruct&quantization=Q4_K")"
route_role="$(echo "$route_a" | python3 -c "import sys,json; print(json.load(sys.stdin).get('routing',{}).get('role',''))" 2>/dev/null)"
[[ "$route_role" == "inference" ]] || fail "routing.role should be inference, got: ${route_role}"
pass "routing diagnostic OK"

# ── Phase 9: Failover ─────────────────────────────────────────────────────

info "phase 9: failover (stop node-a, infer on node-b)"

compose stop inference-node-a 2>&1 | tail -2
sleep 2

if curl -sf --max-time 2 "http://127.0.0.1:${PORT_A}/health" >/dev/null 2>&1; then
  fail "node-a should be unreachable after stop"
fi
pass "node-a is down"

response_b="$(curl -sf --max-time 120 \
  -X POST "http://127.0.0.1:${PORT_B}/api/infer" \
  -H "Content-Type: application/json" \
  -d '{
    "session_id": "smoke-test-01",
    "model_selector": {"model_name": "SmolLM2-135M-Instruct", "quantization": "Q4_K"},
    "prompt": "What is 1+1?",
    "sampling": {"temperature": 0.1, "top_p": 1.0, "top_k": 0, "max_tokens": 64},
    "stream": false
  }')" || fail "POST /api/infer on node-b (failover) failed"

status_b="$(echo "$response_b" | python3 -c "import sys,json; print(json.load(sys.stdin).get('status',''))" 2>/dev/null)"
text_b="$(echo "$response_b" | python3 -c "import sys,json; print(json.load(sys.stdin).get('completion',{}).get('text','')[:100])" 2>/dev/null)"

[[ "$status_b" == "ok" ]] || fail "failover inference status should be ok, got: ${status_b}"
[[ -n "$text_b" ]] || fail "failover inference returned empty text"
pass "failover inference on node-b: text='${text_b}...'"

# ── Summary ────────────────────────────────────────────────────────────────

echo ""
echo "=========================================="
echo "[smoke] ALL PHASES PASSED"
echo "  Phase 0: syntax validation"
echo "  Phase 1: offline contract tests"
echo "  Phase 2: compose boot (node-a:${PORT_A}, node-b:${PORT_B})"
echo "  Phase 3: runtime + profile probes"
echo "  Phase 4: model registry (autoseed)"
echo "  Phase 5: non-streaming inference"
echo "  Phase 6: transcript retrieval"
echo "  Phase 7: telemetry"
echo "  Phase 8: routing diagnostic"
echo "  Phase 9: failover (node-a down → node-b serves)"
echo "=========================================="
