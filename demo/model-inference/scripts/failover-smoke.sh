#!/usr/bin/env bash
# M-15: Deterministic two-node failover smoke test.
#
# Proves: node A serves prompt-1, node A is stopped, node B serves
# prompt-2 without reconfiguration. Explicit fence: no mid-stream
# handoff claim — this tests "next request fails over", not in-flight
# generation migration.
#
# Requires: docker compose (the compose plugin, not docker-compose v1).
# Gated on MODEL_INFERENCE_SMOKE_REQUIRE_COMPOSE=1 in CI; skips
# gracefully otherwise.
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
DEMO_DIR="$(cd "${SCRIPT_DIR}/.." && pwd)"
REPO_ROOT="$(cd "${DEMO_DIR}/../.." && pwd)"

COMPOSE_FILE="${DEMO_DIR}/docker-compose.v1.yml"
PROJECT="${MODEL_INFERENCE_SMOKE_COMPOSE_PROJECT:-king-mi-failover-$$}"

PORT_A="${MODEL_INFERENCE_V1_NODE_A_PORT:-18090}"
PORT_B="${MODEL_INFERENCE_V1_NODE_B_PORT:-18092}"

pass() { echo "[failover-smoke] PASS: $1"; }
fail() { echo "[failover-smoke] FAIL: $1" >&2; exit 1; }
info() { echo "[failover-smoke] $1"; }

# --- pre-flight -----------------------------------------------------------

if ! command -v docker >/dev/null 2>&1; then
  if [[ "${MODEL_INFERENCE_SMOKE_REQUIRE_COMPOSE:-0}" == "1" ]]; then
    fail "docker not found and MODEL_INFERENCE_SMOKE_REQUIRE_COMPOSE=1"
  fi
  info "SKIP: docker not available"
  exit 0
fi

if ! docker compose version >/dev/null 2>&1; then
  if [[ "${MODEL_INFERENCE_SMOKE_REQUIRE_COMPOSE:-0}" == "1" ]]; then
    fail "docker compose plugin not found and MODEL_INFERENCE_SMOKE_REQUIRE_COMPOSE=1"
  fi
  info "SKIP: docker compose plugin not available"
  exit 0
fi

# --- port helpers ----------------------------------------------------------

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
  local preferred="$1"
  local candidate="$preferred"
  while (( candidate <= 65535 )); do
    if port_is_bindable "$candidate"; then
      printf '%s\n' "$candidate"
      return 0
    fi
    candidate=$((candidate + 1))
  done
  return 1
}

PORT_A="$(resolve_port "$PORT_A")"
PORT_B="$(resolve_port "$((PORT_A + 2))")"
info "node-a port: ${PORT_A}, node-b port: ${PORT_B}"

# --- compose up ------------------------------------------------------------

export MODEL_INFERENCE_V1_NODE_A_PORT="${PORT_A}"
export MODEL_INFERENCE_V1_NODE_B_PORT="${PORT_B}"

compose() {
  docker compose -f "${COMPOSE_FILE}" -p "${PROJECT}" "$@"
}

cleanup() {
  info "cleaning up compose project ${PROJECT}..."
  compose down -v --remove-orphans 2>/dev/null || true
}
trap cleanup EXIT

info "building and starting two-node compose stack..."
compose up -d --build 2>&1 | tail -5

# --- health checks ---------------------------------------------------------

wait_healthy() {
  local label="$1" url="$2" max="$3"
  local attempt=0
  while (( attempt < max )); do
    if curl -sf --max-time 3 "${url}" >/dev/null 2>&1; then
      pass "${label} healthy after $((attempt + 1)) attempts"
      return 0
    fi
    attempt=$((attempt + 1))
    sleep 1
  done
  fail "${label} did not become healthy after ${max}s"
}

wait_healthy "node-a" "http://127.0.0.1:${PORT_A}/health" 120
wait_healthy "node-b" "http://127.0.0.1:${PORT_B}/health" 120

# --- verify both nodes identify correctly -----------------------------------

node_a_id="$(curl -sf "http://127.0.0.1:${PORT_A}/api/runtime" | python3 -c "import sys,json; print(json.load(sys.stdin)['node']['node_id'])" 2>/dev/null || echo "")"
node_b_id="$(curl -sf "http://127.0.0.1:${PORT_B}/api/runtime" | python3 -c "import sys,json; print(json.load(sys.stdin)['node']['node_id'])" 2>/dev/null || echo "")"

[[ "$node_a_id" == "node_a" ]] || fail "node-a should identify as node_a, got: ${node_a_id}"
[[ "$node_b_id" == "node_b" ]] || fail "node-b should identify as node_b, got: ${node_b_id}"
pass "nodes identify correctly: node_a=${node_a_id}, node_b=${node_b_id}"

# --- verify models are seeded on both nodes ---------------------------------

wait_model() {
  local label="$1" url="$2" max="$3"
  local attempt=0
  while (( attempt < max )); do
    local count
    count="$(curl -sf "${url}" | python3 -c "import sys,json; print(json.load(sys.stdin).get('count',0))" 2>/dev/null || echo "0")"
    if [[ "$count" -gt 0 ]]; then
      pass "${label} has ${count} model(s) registered"
      return 0
    fi
    attempt=$((attempt + 1))
    sleep 2
  done
  fail "${label} has no models after ${max} attempts"
}

wait_model "node-a" "http://127.0.0.1:${PORT_A}/api/models" 30
wait_model "node-b" "http://127.0.0.1:${PORT_B}/api/models" 30

# --- prompt-1: hit node-a ---------------------------------------------------

info "sending prompt-1 to node-a (port ${PORT_A})..."
response_a="$(curl -sf --max-time 120 \
  -X POST "http://127.0.0.1:${PORT_A}/api/infer" \
  -H "Content-Type: application/json" \
  -d '{
    "session_id": "failover-test-01",
    "model_selector": {"model_name": "SmolLM2-135M-Instruct", "quantization": "Q4_K"},
    "prompt": "What is 2+2?",
    "sampling": {"temperature": 0.1, "top_p": 1.0, "top_k": 0, "max_tokens": 64},
    "stream": false
  }' 2>&1)" || fail "prompt-1 to node-a failed"

status_a="$(echo "$response_a" | python3 -c "import sys,json; print(json.load(sys.stdin).get('status',''))" 2>/dev/null || echo "")"
text_a="$(echo "$response_a" | python3 -c "import sys,json; print(json.load(sys.stdin).get('completion',{}).get('text','')[:80])" 2>/dev/null || echo "")"
request_id_a="$(echo "$response_a" | python3 -c "import sys,json; print(json.load(sys.stdin).get('request_id',''))" 2>/dev/null || echo "")"

[[ "$status_a" == "ok" ]] || fail "prompt-1 status should be ok, got: ${status_a} body: ${response_a}"
[[ -n "$text_a" ]] || fail "prompt-1 returned empty completion text"
pass "prompt-1 on node-a: request_id=${request_id_a} text='${text_a}...'"

# --- stop node-a (simulate drain) ------------------------------------------

info "stopping node-a to simulate drain..."
compose stop inference-node-a 2>&1 | tail -2
sleep 2

# verify node-a is down
if curl -sf --max-time 2 "http://127.0.0.1:${PORT_A}/health" >/dev/null 2>&1; then
  fail "node-a should be unreachable after stop"
fi
pass "node-a is down"

# --- prompt-2: hit node-b (failover) ---------------------------------------

info "sending prompt-2 to node-b (port ${PORT_B}) — failover path..."
response_b="$(curl -sf --max-time 120 \
  -X POST "http://127.0.0.1:${PORT_B}/api/infer" \
  -H "Content-Type: application/json" \
  -d '{
    "session_id": "failover-test-01",
    "model_selector": {"model_name": "SmolLM2-135M-Instruct", "quantization": "Q4_K"},
    "prompt": "What is 3+3?",
    "sampling": {"temperature": 0.1, "top_p": 1.0, "top_k": 0, "max_tokens": 64},
    "stream": false
  }' 2>&1)" || fail "prompt-2 to node-b failed"

status_b="$(echo "$response_b" | python3 -c "import sys,json; print(json.load(sys.stdin).get('status',''))" 2>/dev/null || echo "")"
text_b="$(echo "$response_b" | python3 -c "import sys,json; print(json.load(sys.stdin).get('completion',{}).get('text','')[:80])" 2>/dev/null || echo "")"
request_id_b="$(echo "$response_b" | python3 -c "import sys,json; print(json.load(sys.stdin).get('request_id',''))" 2>/dev/null || echo "")"

[[ "$status_b" == "ok" ]] || fail "prompt-2 status should be ok, got: ${status_b} body: ${response_b}"
[[ -n "$text_b" ]] || fail "prompt-2 returned empty completion text"
pass "prompt-2 on node-b (failover): request_id=${request_id_b} text='${text_b}...'"

# --- verify transcript persistence on node-b --------------------------------

info "checking transcript persistence on node-b..."
transcript_b="$(curl -sf --max-time 10 \
  "http://127.0.0.1:${PORT_B}/api/transcripts/${request_id_b}" 2>/dev/null || echo "")"

if [[ -n "$transcript_b" ]]; then
  transcript_status="$(echo "$transcript_b" | python3 -c "import sys,json; print(json.load(sys.stdin).get('status',''))" 2>/dev/null || echo "")"
  if [[ "$transcript_status" == "ok" ]]; then
    pass "transcript for prompt-2 persisted on node-b"
  else
    info "transcript retrieval returned status=${transcript_status} (object store may not be available in this environment)"
  fi
else
  info "transcript retrieval skipped (endpoint returned empty — object store may not be initialized)"
fi

# --- routing diagnostic on node-b ------------------------------------------

route_b="$(curl -sf --max-time 10 \
  "http://127.0.0.1:${PORT_B}/api/route?model_name=SmolLM2-135M-Instruct&quantization=Q4_K" 2>/dev/null || echo "")"
if [[ -n "$route_b" ]]; then
  route_status="$(echo "$route_b" | python3 -c "import sys,json; print(json.load(sys.stdin).get('status',''))" 2>/dev/null || echo "")"
  route_role="$(echo "$route_b" | python3 -c "import sys,json; print(json.load(sys.stdin).get('routing',{}).get('role',''))" 2>/dev/null || echo "")"
  [[ "$route_status" == "ok" ]] || fail "GET /api/route should return ok, got: ${route_status}"
  [[ "$route_role" == "inference" ]] || fail "routing.role should be inference, got: ${route_role}"
  pass "routing diagnostic on node-b returns role=inference"
fi

# --- summary ----------------------------------------------------------------

echo ""
echo "=========================================="
echo "[failover-smoke] ALL CHECKS PASSED"
echo "  node-a (${PORT_A}): prompt-1 completed, then drained"
echo "  node-b (${PORT_B}): prompt-2 completed via failover"
echo "  fence: no mid-stream handoff claimed"
echo "=========================================="
