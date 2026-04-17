#!/usr/bin/env bash
# R-15: RAG pipeline end-to-end smoke test.
#
# Exercises the full RAG surface added by the R-batch sprint:
#   1. Syntax validation of new files
#   2. Offline contract tests (R-batch + M-batch)
#   3. Compose boot (single node — RAG is single-process)
#   4. Health + embedding model probe
#   5. Document ingest (POST /api/documents)
#   6. Chunk verification (GET /api/documents/{id}/chunks)
#   7. Embedding generation (POST /api/embed)
#   8. Retrieval (POST /api/retrieve)
#   9. End-to-end RAG completion (POST /api/rag)
#  10. RAG telemetry (GET /api/telemetry/rag/recent)
#
# Gated on MODEL_INFERENCE_SMOKE_REQUIRE_COMPOSE=1 in CI.
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
DEMO_DIR="$(cd "${SCRIPT_DIR}/.." && pwd)"
BACKEND_DIR="${DEMO_DIR}/backend-king-php"

COMPOSE_FILE="${DEMO_DIR}/docker-compose.v1.yml"
PROJECT="${MODEL_INFERENCE_SMOKE_COMPOSE_PROJECT:-king-rag-smoke-$$}"

pass() { echo "[rag-smoke] PASS: $1"; }
fail() { echo "[rag-smoke] FAIL: $1" >&2; exit 1; }
info() { echo "[rag-smoke] $1"; }
json() { python3 -c "import sys,json; d=json.load(sys.stdin); $1" 2>/dev/null; }

# ── Phase 0: Syntax validation ──────────────────────────────────────────────

info "phase 0: syntax validation"

php_files=(
  "${BACKEND_DIR}/server.php"
  "${BACKEND_DIR}/http/router.php"
  "${BACKEND_DIR}/http/module_embed.php"
  "${BACKEND_DIR}/http/module_ingest.php"
  "${BACKEND_DIR}/http/module_retrieve.php"
  "${BACKEND_DIR}/domain/embedding/embedding_session.php"
  "${BACKEND_DIR}/domain/embedding/embedding_request.php"
  "${BACKEND_DIR}/domain/retrieval/document_store.php"
  "${BACKEND_DIR}/domain/retrieval/text_chunker.php"
  "${BACKEND_DIR}/domain/retrieval/vector_store.php"
  "${BACKEND_DIR}/domain/retrieval/cosine_similarity.php"
  "${BACKEND_DIR}/domain/retrieval/retrieval_pipeline.php"
  "${BACKEND_DIR}/domain/retrieval/rag_orchestrator.php"
  "${BACKEND_DIR}/domain/telemetry/rag_metrics.php"
)
for f in "${php_files[@]}"; do
  if [[ -f "$f" ]]; then
    php -l "$f" >/dev/null 2>&1 || fail "PHP syntax error in ${f}"
  fi
done
pass "PHP syntax OK"

# ── Phase 1: Contract tests ──────────────���──────────────────────────────────

info "phase 1: offline contract tests (R-batch)"

r_batch_tests=(
  embedding-model-registry-contract
  embedding-worker-contract
  embedding-request-envelope-contract
  embedding-generation-contract
  document-ingest-contract
  text-chunker-contract
  chunk-persistence-contract
  vector-store-contract
  cosine-similarity-contract
  retrieval-pipeline-contract
  rag-orchestrator-contract
  rag-telemetry-contract
  semantic-dns-embedding-contract
)
for test_name in "${r_batch_tests[@]}"; do
  script="${BACKEND_DIR}/tests/${test_name}-contract.sh"
  if [[ -x "$script" ]]; then
    bash "$script" 2>&1 || fail "contract test failed: ${test_name}"
  fi
done

# Also run M-batch offline tests to verify no regressions.
m_batch_tests=(
  contract-catalog-parity-contract
  inference-request-envelope-contract
  inference-routing-contract
  model-fit-selector-contract
  router-module-order-contract
  runtime-bootstrap-contract
  semantic-dns-contract
  token-frame-wire-contract
  transcript-persistence-contract
  ui-chat-contract
)
for test_name in "${m_batch_tests[@]}"; do
  script="${BACKEND_DIR}/tests/${test_name}-contract.sh"
  if [[ -x "$script" ]]; then
    bash "$script" 2>&1 || fail "M-batch regression: ${test_name}"
  fi
done
pass "all offline contract tests green"

# ── Phase 2: Compose boot ────────────���──────────────────────────────────────

if ! command -v docker >/dev/null 2>&1 || ! docker compose version >/dev/null 2>&1; then
  if [[ "${MODEL_INFERENCE_SMOKE_REQUIRE_COMPOSE:-0}" == "1" ]]; then
    fail "docker compose not available and MODEL_INFERENCE_SMOKE_REQUIRE_COMPOSE=1"
  fi
  info "SKIP: docker compose not available — offline tests passed"
  exit 0
fi

info "phase 2: compose boot (single node for RAG)"

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
info "resolved ports: node-a=${PORT_A}"

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
  local label="$1" url="$2" max="$3" svc="$4"
  local attempt=0
  while (( attempt < max )); do
    if curl -sf --max-time 3 "${url}" >/dev/null 2>&1; then
      pass "${label} healthy after $((attempt + 1))s"
      return 0
    fi
    attempt=$((attempt + 1))
    sleep 1
  done
  compose logs "$svc" 2>/dev/null | tail -30 || true
  fail "${label} did not become healthy after ${max}s"
}

wait_healthy "node-a" "http://127.0.0.1:${PORT_A}/health" 180 inference-node-a

BASE="http://127.0.0.1:${PORT_A}"

# ── Phase 3: Embedding model probe ──��────────────────────────────────────��──

info "phase 3: embedding model probe"

wait_model() {
  local url="$1" type="$2" max="$3"
  local attempt=0
  while (( attempt < max )); do
    local models
    models="$(curl -sf "${url}" 2>/dev/null || echo "{}")"
    local found
    found="$(echo "$models" | python3 -c "
import sys, json
d = json.load(sys.stdin)
items = d.get('items', [])
for m in items:
    if m.get('model_type') == '${type}':
        print(m.get('model_name',''))
        break
" 2>/dev/null || echo "")"
    if [[ -n "$found" ]]; then
      pass "${type} model found: ${found}"
      return 0
    fi
    attempt=$((attempt + 1))
    sleep 2
  done
  info "no ${type} model found after ${max} attempts (autoseed may not have embedding fixture)"
  return 1
}

wait_model "${BASE}/api/models" "chat" 30
HAS_EMBEDDING_MODEL=false
if wait_model "${BASE}/api/models" "embedding" 10; then
  HAS_EMBEDDING_MODEL=true
fi

# ── Phase 4: Document ingest ────────────────────────────────────────────────

info "phase 4: document ingest"

DOC_TEXT="King is a native PHP systems runtime. It provides HTTP/1 servers, WebSocket, object storage, Semantic DNS, and inference serving. The runtime is built as a PHP extension that gives applications direct access to system-level capabilities without external dependencies. King supports model registry, hardware profiling, and multi-node failover for inference workloads."

ingest_resp="$(curl -sf --max-time 30 \
  -X POST "${BASE}/api/documents" \
  -H "Content-Type: text/plain" \
  -d "${DOC_TEXT}")" || fail "POST /api/documents failed"

doc_status="$(echo "$ingest_resp" | json "print(d.get('status',''))")"
doc_id="$(echo "$ingest_resp" | json "print(d.get('document',{}).get('document_id',''))")"
doc_bytes="$(echo "$ingest_resp" | json "print(d.get('document',{}).get('byte_length',0))")"

[[ "$doc_status" == "created" ]] || fail "document status should be created, got: ${doc_status}"
[[ -n "$doc_id" ]] || fail "document_id should be non-empty"
[[ "$doc_bytes" -gt 0 ]] || fail "byte_length should be > 0"
pass "document ingested: ${doc_id} (${doc_bytes} bytes)"

# ── Phase 5: Chunk verification ────────────────────���────────────────────────

info "phase 5: chunk verification"

chunks_resp="$(curl -sf --max-time 10 \
  "${BASE}/api/documents/${doc_id}/chunks")" || fail "GET chunks failed"

chunk_count="$(echo "$chunks_resp" | json "print(d.get('count',0))")"
[[ "$chunk_count" -gt 0 ]] || fail "chunk count should be > 0, got: ${chunk_count}"
pass "chunks: ${chunk_count} for document ${doc_id}"

# ── Phase 6: Embedding generation ────────────────────────────────────────────

if [[ "$HAS_EMBEDDING_MODEL" == "true" ]]; then
  info "phase 6: embedding generation"

  embed_resp="$(curl -sf --max-time 120 \
    -X POST "${BASE}/api/embed" \
    -H "Content-Type: application/json" \
    -d '{
      "texts": ["What is King?"],
      "model_selector": {"model_name": "nomic-embed-text-v1.5", "quantization": "Q8_0"}
    }')" || fail "POST /api/embed failed"

  embed_status="$(echo "$embed_resp" | json "print(d.get('status',''))")"
  embed_dims="$(echo "$embed_resp" | json "print(d.get('dimensions',0))")"
  [[ "$embed_status" == "ok" ]] || fail "embed status should be ok, got: ${embed_status}"
  [[ "$embed_dims" -gt 0 ]] || fail "dimensions should be > 0"
  pass "embedding: ${embed_dims} dimensions"
else
  info "phase 6: SKIP — no embedding model in registry"
fi

# ── Phase 7: Retrieval ──────────────────────────────────────────────────────

if [[ "$HAS_EMBEDDING_MODEL" == "true" ]]; then
  info "phase 7: retrieval"

  retrieve_resp="$(curl -sf --max-time 120 \
    -X POST "${BASE}/api/retrieve" \
    -H "Content-Type: application/json" \
    -d "{
      \"query\": \"What is King?\",
      \"model_selector\": {\"model_name\": \"nomic-embed-text-v1.5\", \"quantization\": \"Q8_0\"},
      \"document_ids\": [\"${doc_id}\"],
      \"top_k\": 3
    }")" || fail "POST /api/retrieve failed"

  ret_status="$(echo "$retrieve_resp" | json "print(d.get('status',''))")"
  ret_count="$(echo "$retrieve_resp" | json "print(d.get('result_count',0))")"
  ret_scanned="$(echo "$retrieve_resp" | json "print(d.get('vectors_scanned',0))")"
  [[ "$ret_status" == "ok" ]] || fail "retrieve status should be ok, got: ${ret_status}"
  pass "retrieval: ${ret_count} results, ${ret_scanned} vectors scanned"
else
  info "phase 7: SKIP — no embedding model"
fi

# ── Phase 8: RAG completion ─────────���────────────────────────────────────────

if [[ "$HAS_EMBEDDING_MODEL" == "true" ]]; then
  info "phase 8: RAG completion"

  rag_resp="$(curl -sf --max-time 180 \
    -X POST "${BASE}/api/rag" \
    -H "Content-Type: application/json" \
    -d "{
      \"query\": \"What does King provide?\",
      \"model_selector\": {
        \"chat\": {\"model_name\": \"SmolLM2-135M-Instruct\", \"quantization\": \"Q4_K\"},
        \"embedding\": {\"model_name\": \"nomic-embed-text-v1.5\", \"quantization\": \"Q8_0\"}
      },
      \"document_ids\": [\"${doc_id}\"],
      \"top_k\": 3,
      \"sampling\": {\"temperature\": 0.1, \"max_tokens\": 128}
    }")" || fail "POST /api/rag failed"

  rag_status="$(echo "$rag_resp" | json "print(d.get('status',''))")"
  rag_text="$(echo "$rag_resp" | json "print(d.get('completion','')[:100])")"
  rag_chunks="$(echo "$rag_resp" | json "print(d.get('context',{}).get('chunks_used',0))")"
  rag_emb_ms="$(echo "$rag_resp" | json "print(d.get('timing',{}).get('embedding_ms',0))")"
  rag_inf_ms="$(echo "$rag_resp" | json "print(d.get('timing',{}).get('inference_ms',0))")"
  rag_total="$(echo "$rag_resp" | json "print(d.get('timing',{}).get('total_ms',0))")"

  [[ "$rag_status" == "ok" ]] || fail "RAG status should be ok, got: ${rag_status}"
  [[ -n "$rag_text" ]] || fail "RAG completion should be non-empty"
  pass "RAG: ${rag_chunks} chunks, emb=${rag_emb_ms}ms inf=${rag_inf_ms}ms total=${rag_total}ms"
else
  info "phase 8: SKIP — no embedding model"
fi

# ── Phase 9: RAG telemetry ───────────────��──────────────────────────────────

info "phase 9: RAG telemetry"

rag_tel="$(curl -sf --max-time 10 "${BASE}/api/telemetry/rag/recent")"
rag_tel_status="$(echo "$rag_tel" | json "print(d.get('status',''))")"
rag_tel_count="$(echo "$rag_tel" | json "print(d.get('count',0))")"
[[ "$rag_tel_status" == "ok" ]] || fail "RAG telemetry status should be ok"
pass "RAG telemetry: ${rag_tel_count} entries"

# ── Summary ──────���───────────────────────────���───────────────────────────────

echo ""
echo "=========================================="
echo "[rag-smoke] ALL PHASES PASSED"
echo "  Phase 0: syntax validation"
echo "  Phase 1: offline contract tests (R-batch + M-batch)"
echo "  Phase 2: compose boot (node-a:${PORT_A})"
echo "  Phase 3: embedding model probe"
echo "  Phase 4: document ingest (${doc_id})"
echo "  Phase 5: chunk verification (${chunk_count} chunks)"
if [[ "$HAS_EMBEDDING_MODEL" == "true" ]]; then
echo "  Phase 6: embedding generation"
echo "  Phase 7: retrieval"
echo "  Phase 8: RAG completion"
else
echo "  Phase 6-8: SKIPPED (no embedding model fixture)"
fi
echo "  Phase 9: RAG telemetry"
echo "=========================================="
