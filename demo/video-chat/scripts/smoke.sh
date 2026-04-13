#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
FRONTEND_DIR="${ROOT_DIR}/frontend"
BACKEND_DIR="${ROOT_DIR}/backend"

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

run_step "frontend type-check" bash -lc "cd '${FRONTEND_DIR}' && npm run type-check"
run_step "frontend unit tests" bash -lc "cd '${FRONTEND_DIR}' && npm run test -- --run"
run_step "frontend production build" bash -lc "cd '${FRONTEND_DIR}' && npm run build"

run_step "backend contract tests" bash -lc "cd '${BACKEND_DIR}' && npm run test"
run_step "backend syntax check" bash -lc "cd '${BACKEND_DIR}' && node --check dev-backend.mjs"

log "All smoke checks passed."
