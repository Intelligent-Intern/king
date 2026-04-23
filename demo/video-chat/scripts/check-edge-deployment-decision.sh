#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
REPO_ROOT="$(cd "${ROOT_DIR}/../.." && pwd)"
DECISION="${REPO_ROOT}/documentation/dev/video-chat/edge-deployment.md"
COMPOSE_FILE="${ROOT_DIR}/docker-compose.v1.yml"
EDGE_DIR="${ROOT_DIR}/edge"

fail() {
  printf '[edge-deployment-decision] FAIL: %s\n' "$*" >&2
  exit 1
}

require_file() {
  local path="$1"
  [[ -f "${path}" ]] || fail "missing required file: ${path}"
}

require_text() {
  local path="$1"
  local needle="$2"
  grep -Fq "${needle}" "${path}" || fail "missing '${needle}' in ${path}"
}

require_absent_path() {
  local path="$1"
  [[ ! -e "${path}" ]] || fail "discarded edge deploy path is present: ${path}"
}

require_file "${DECISION}"
require_file "${COMPOSE_FILE}"
require_file "${EDGE_DIR}/Dockerfile"
require_file "${EDGE_DIR}/edge.php"

for marker in \
  'Status: aktiv fuer den Production-Deploy-Pfad.' \
  'King/PHP Edge-Container' \
  'demo/video-chat/frontend-vue' \
  'demo/video-chat/backend-king-php' \
  'demo/video-chat/edge' \
  'demo/video-chat/docker-compose.v1.yml' \
  'videochat-edge-v1' \
  '/ws' \
  '/sfu' \
  'VIDEOCHAT_DEPLOY_CDN_DOMAIN'
do
  require_text "${DECISION}" "${marker}"
done

require_absent_path "${ROOT_DIR}/deploy"
require_absent_path "${ROOT_DIR}/docker-compose.edge.yml"

while IFS= read -r edge_path; do
  fail "discarded top-level edge proxy artifact is present: ${edge_path}"
done < <(find "${ROOT_DIR}" -maxdepth 1 \( \
  -iname 'nginx*' -o \
  -iname 'caddy*' -o \
  -iname 'traefik*' -o \
  -iname 'haproxy*' \
\) -print)

require_text "${COMPOSE_FILE}" 'videochat-backend-v1:'
require_text "${COMPOSE_FILE}" 'videochat-backend-ws-v1:'
require_text "${COMPOSE_FILE}" 'videochat-backend-sfu-v1:'
require_text "${COMPOSE_FILE}" 'videochat-frontend-v1:'
require_text "${COMPOSE_FILE}" 'videochat-edge-v1:'
require_text "${COMPOSE_FILE}" 'profiles:'
require_text "${COMPOSE_FILE}" 'demo/video-chat/edge/Dockerfile'
require_text "${COMPOSE_FILE}" 'VIDEOCHAT_KING_WS_PATH: /ws'
require_text "${COMPOSE_FILE}" 'VIDEOCHAT_KING_WS_PATH: /sfu'
require_text "${COMPOSE_FILE}" 'VIDEOCHAT_EDGE_WRITE_STALL_TIMEOUT_SECONDS'
require_text "${COMPOSE_FILE}" 'VIDEOCHAT_EDGE_READ_STALL_TIMEOUT_SECONDS'
require_text "${EDGE_DIR}/edge.php" "'wasm' => 'application/wasm'"
require_text "${EDGE_DIR}/edge.php" "str_starts_with(\$path, '/assets/')"
require_text "${EDGE_DIR}/edge.php" "str_starts_with(\$path, '/cdn/')"
require_text "${EDGE_DIR}/edge.php" "\$path === '/ws' || \$host === \$wsDomain"
require_text "${EDGE_DIR}/edge.php" "\$path === '/sfu' || \$host === \$sfuDomain"
require_text "${EDGE_DIR}/edge.php" 'VIDEOCHAT_EDGE_CDN_DOMAIN'
require_text "${EDGE_DIR}/edge.php" 'Access-Control-Allow-Origin'
require_text "${EDGE_DIR}/edge.php" 'VIDEOCHAT_EDGE_WRITE_STALL_TIMEOUT_SECONDS'
require_text "${EDGE_DIR}/edge.php" 'VIDEOCHAT_EDGE_READ_STALL_TIMEOUT_SECONDS'
require_text "${EDGE_DIR}/edge.php" '$written === 0'
require_text "${EDGE_DIR}/edge.php" "\$chunk === ''"
require_text "${EDGE_DIR}/edge.php" '$needsBackoff'
require_text "${EDGE_DIR}/edge.php" 'Connection: close'
require_text "${ROOT_DIR}/frontend-vue/src/lib/wasm/wasm-codec.ts" "WASM_MIME_CACHE_BUSTER"

if grep -Eiq 'image:[[:space:]]*(nginx|caddy|traefik|haproxy)(:|$)' "${COMPOSE_FILE}"; then
  fail "compose file contains an implicit reverse-proxy/edge image"
fi

if grep -RiqE 'nginx|caddy|traefik|haproxy' "${EDGE_DIR}"; then
  fail "King/PHP edge path references a third-party edge stack"
fi

printf '[edge-deployment-decision] PASS\n'
