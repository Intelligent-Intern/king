#!/usr/bin/env bash
set -euo pipefail

# Drive the model-inference backend through every live endpoint currently
# landed on feature/model-inference. Starts a fresh backend, exercises
# /health → /api/node/profile → seed a real GGUF → /api/models →
# /api/models/{id} → DELETE, and shuts down.
#
# Intended for eyeballing the demo during development and for use by
# docker-compose smoke scripts landing with #M-17.

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
BACKEND_DIR="$(cd "${SCRIPT_DIR}/.." && pwd)"
REPO_ROOT="$(cd "${BACKEND_DIR}/../../.." && pwd)"
PHP_BIN="${PHP_BIN:-php}"
HOST="127.0.0.1"
PORT="${MODEL_INFERENCE_DEMO_PORT:-18090}"
TMPROOT="${MODEL_INFERENCE_DEMO_ROOT:-/tmp/model-inference-demo}"
GGUF_PATH="${MODEL_INFERENCE_GGUF_FIXTURE_PATH:-${BACKEND_DIR}/.local/fixtures/SmolLM2-135M-Instruct-Q4_K_S.gguf}"
DEFAULT_EXT="${REPO_ROOT}/extension/modules/king.so"
KING_EXTENSION_PATH="${KING_EXTENSION_PATH:-${DEFAULT_EXT}}"

if ! "${PHP_BIN}" -m | grep -qi '^king$'; then
  if [[ ! -f "${KING_EXTENSION_PATH}" ]]; then
    echo "[demo-walkthrough] FAIL: King extension missing (set KING_EXTENSION_PATH)." >&2
    exit 1
  fi
fi
if [[ ! -f "${GGUF_PATH}" ]]; then
  echo "[demo-walkthrough] FAIL: GGUF fixture missing at ${GGUF_PATH}. Run scripts/install-llama-runtime.sh fixture first." >&2
  exit 1
fi

rm -rf "${TMPROOT}"
mkdir -p "${TMPROOT}/object-store"
export MODEL_INFERENCE_KING_HOST="${HOST}"
export MODEL_INFERENCE_KING_PORT="${PORT}"
export MODEL_INFERENCE_KING_WS_PORT="${PORT}"
export MODEL_INFERENCE_KING_DB_PATH="${TMPROOT}/model-inference.sqlite"
export MODEL_INFERENCE_KING_OBJECT_STORE_ROOT="${TMPROOT}/object-store"
export MODEL_INFERENCE_KING_NODE_ID="node_demo_walkthrough"

SERVER_PID_FILE="${TMPROOT}/server.pid"
SERVER_LOG_FILE="${TMPROOT}/server.log"

cleanup() {
  if [[ -f "${SERVER_PID_FILE}" ]]; then
    local pid
    pid="$(cat "${SERVER_PID_FILE}")"
    if [[ -n "${pid}" ]]; then
      kill -TERM "${pid}" >/dev/null 2>&1 || true
      sleep 0.2
      kill -KILL "${pid}" >/dev/null 2>&1 || true
    fi
  fi
  pkill -f "model-inference.*server.php" >/dev/null 2>&1 || true
}
trap cleanup EXIT INT TERM

"${BACKEND_DIR}/run-dev.sh" > "${SERVER_LOG_FILE}" 2>&1 &
echo "$!" > "${SERVER_PID_FILE}"

for i in $(seq 1 40); do
  sleep 0.25
  if curl -s -o /dev/null "http://${HOST}:${PORT}/health"; then
    break
  fi
  if [[ "${i}" -eq 40 ]]; then
    echo "[demo-walkthrough] FAIL: server did not answer /health in time." >&2
    echo "--- server log ---" >&2
    tail -30 "${SERVER_LOG_FILE}" >&2
    exit 1
  fi
done

echo "============================================================="
echo " 1. GET /health"
echo "============================================================="
curl -s "http://${HOST}:${PORT}/health" | "${PHP_BIN}" -r '$d=json_decode(file_get_contents("php://stdin"),true); printf("status=%s service=%s king_version=%s node_id=%s sqlite_schema_v=%d\n", $d["status"], $d["service"], $d["runtime"]["king_version"], $d["node"]["node_id"], $d["database"]["schema_version"]);'

echo
echo "============================================================="
echo " 2. GET /api/node/profile (real hardware probe)"
echo "============================================================="
curl -s "http://${HOST}:${PORT}/api/node/profile" | "${PHP_BIN}" -r '$d=json_decode(file_get_contents("php://stdin"),true); printf("os=%s arch=%s cpu.logical=%d memory.available=%.1f GiB gpu.present=%s\n", $d["platform"]["os"], $d["platform"]["arch"], $d["cpu"]["logical_count"], $d["memory"]["available_bytes"]/1024/1024/1024, $d["gpu"]["present"] ? "yes" : "no");'

echo
echo "============================================================="
echo " 3. GET /api/models (empty)"
echo "============================================================="
curl -s "http://${HOST}:${PORT}/api/models"
echo

echo
echo "============================================================="
echo " 4. Seed SmolLM2-135M GGUF via scripts/seed-model.php"
echo "    (HTTP body is 1-MiB-capped by king_http1_server_listen_once;"
echo "     the seed path uses the same domain function the route calls)"
echo "============================================================="
php_args=()
if ! "${PHP_BIN}" -m | grep -qi '^king$'; then
  php_args+=("-d" "extension=${KING_EXTENSION_PATH}")
fi
php_args+=("-d" "king.security_allow_config_override=1")
"${PHP_BIN}" "${php_args[@]}" "${SCRIPT_DIR}/seed-model.php" \
  --gguf "${GGUF_PATH}" \
  --name "SmolLM2-135M-Instruct" \
  --family smollm2 \
  --quantization Q4_K \
  --parameter-count 135000000 \
  --context-length 2048 \
  --license apache-2.0 \
  --min-ram-bytes 268435456 \
  --source-url "https://huggingface.co/bartowski/SmolLM2-135M-Instruct-GGUF"

echo
echo "============================================================="
echo " 5. GET /api/models (after seed)"
echo "============================================================="
curl -s "http://${HOST}:${PORT}/api/models" | "${PHP_BIN}" -r '$d=json_decode(file_get_contents("php://stdin"),true); printf("count=%d\n", $d["count"]); foreach($d["items"] as $m) { printf("  %s %-30s %-5s %10d bytes sha=%s...\n", $m["model_id"], $m["model_name"], $m["quantization"], $m["artifact"]["byte_length"], substr($m["artifact"]["sha256_hex"], 0, 12)); }'

MODEL_ID="$(curl -s "http://${HOST}:${PORT}/api/models" | "${PHP_BIN}" -r 'echo (json_decode(file_get_contents("php://stdin"), true)["items"][0]["model_id"] ?? "");')"

echo
echo "============================================================="
echo " 6. GET /api/models/${MODEL_ID}"
echo "============================================================="
curl -s "http://${HOST}:${PORT}/api/models/${MODEL_ID}" | "${PHP_BIN}" -r '$d=json_decode(file_get_contents("php://stdin"),true); printf("status=%s model_id=%s sha256=%s\n", $d["status"], $d["model"]["model_id"], $d["model"]["artifact"]["sha256_hex"]);'

echo
echo "============================================================="
echo " 7. Object-store on-disk proof"
echo "============================================================="
du -sh "${TMPROOT}/object-store"
ls -1 "${TMPROOT}/object-store" | head -5

echo
echo "============================================================="
echo " 8. DELETE /api/models/${MODEL_ID}"
echo "============================================================="
curl -s -X DELETE "http://${HOST}:${PORT}/api/models/${MODEL_ID}"
echo

echo
echo "============================================================="
echo " 9. GET /api/models (after delete)"
echo "============================================================="
curl -s "http://${HOST}:${PORT}/api/models"
echo

echo
echo "[demo-walkthrough] done. Server and temp root cleaned up automatically."
