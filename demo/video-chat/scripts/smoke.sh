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

run_step "backend scaffold boot check" bash -lc "
  set -euo pipefail
  tmp_log=\$(mktemp)
  VIDEOCHAT_KING_PORT='${BACKEND_PORT}' '${BACKEND_DIR}/run-dev.sh' >\"\${tmp_log}\" 2>&1 &
  pid=\$!
  trap 'kill \"\${pid}\" >/dev/null 2>&1 || true; rm -f \"\${tmp_log}\"' EXIT
  for _ in {1..30}; do
    if curl -fsS \"http://127.0.0.1:${BACKEND_PORT}/health\" >/dev/null; then
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
