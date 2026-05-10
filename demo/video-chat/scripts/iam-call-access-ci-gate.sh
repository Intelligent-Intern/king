#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
FRONTEND_DIR="${ROOT_DIR}/frontend-vue"
MODE="full"
LOG_PREFIX="[iam-call-access-ci-gate]"

usage() {
  cat <<'USAGE'
Usage: demo/video-chat/scripts/iam-call-access-ci-gate.sh [--static|--sqlite|--docker|--full]

Modes:
  --static  Run host-safe IAM command hygiene contracts.
  --sqlite  Run the SQLite IAM backend runtime proof. The backend wrapper uses
            host pdo_sqlite when available and Docker PHP fallback otherwise.
  --docker  Run IAM backend docker-proof wrappers discovered in backend tests.
  --full    Run the canonical test:contract:iam-call-access package gate.
USAGE
}

while [[ "$#" -gt 0 ]]; do
  case "$1" in
    --static)
      MODE="static"
      ;;
    --sqlite)
      MODE="sqlite"
      ;;
    --docker)
      MODE="docker"
      ;;
    --full)
      MODE="full"
      ;;
    -h|--help)
      usage
      exit 0
      ;;
    *)
      echo "${LOG_PREFIX} ERROR: unknown argument: $1" >&2
      usage >&2
      exit 64
      ;;
  esac
  shift
done

log() {
  printf '%s %s\n' "${LOG_PREFIX}" "$*"
}

run_frontend_shell_step() {
  local label="$1"
  local command="$2"

  log "START: ${label}"
  (cd "${FRONTEND_DIR}" && bash -lc "${command}")
  log "OK: ${label}"
}

STATIC_CONTRACTS=(
  "node tests/contract/iam-call-access-ci-wire-contract.mjs"
  "node tests/contract/iam-call-access-audit-events-contract.mjs"
  "node tests/contract/iam-local-run-docs-contract.mjs"
)

run_static_gate() {
  local command=""
  local count=0

  for command in "${STATIC_CONTRACTS[@]}"; do
    count=$((count + 1))
    run_frontend_shell_step "frontend/static ${command#node }" "${command}"
  done

  if [[ "${count}" -eq 0 ]]; then
    echo "${LOG_PREFIX} ERROR: static gate found no Node IAM contracts" >&2
    exit 65
  fi
}

case "${MODE}" in
  static)
    run_static_gate
    ;;
  sqlite)
    run_frontend_shell_step \
      "backend/sqlite iam-call-access-sqlite-runtime-proof.sh" \
      "../backend-king-php/tests/iam-call-access-sqlite-runtime-proof.sh"
    ;;
  docker)
    run_frontend_shell_step \
      "backend/docker iam-backend-docker-runtime-proof-wrapper.sh" \
      "../backend-king-php/tests/iam-backend-docker-runtime-proof-wrapper.sh"
    ;;
  full)
    run_frontend_shell_step \
      "frontend/full npm run test:contract:iam-call-access" \
      "npm run test:contract:iam-call-access"
    ;;
esac

log "PASS: mode=${MODE}"
