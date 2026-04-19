#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
DECISION="${ROOT_DIR}/EDGE_DEPLOYMENT_DECISION.md"
COMPOSE_FILE="${ROOT_DIR}/docker-compose.v1.yml"

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

for marker in \
  'Status: verworfen fuer den aktiven Demo-Pfad.' \
  'kein internes Edge-Deploy-Pack' \
  'demo/video-chat/frontend-vue' \
  'demo/video-chat/backend-king-php' \
  'demo/video-chat/docker-compose.v1.yml' \
  'eigenes Issue' \
  '/ws' \
  '/sfu'
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
require_text "${COMPOSE_FILE}" 'VIDEOCHAT_KING_WS_PATH: /ws'
require_text "${COMPOSE_FILE}" 'VIDEOCHAT_KING_WS_PATH: /sfu'

if grep -Eiq 'image:[[:space:]]*(nginx|caddy|traefik|haproxy)(:|$)' "${COMPOSE_FILE}"; then
  fail "compose file contains an implicit reverse-proxy/edge image"
fi

printf '[edge-deployment-decision] PASS\n'
