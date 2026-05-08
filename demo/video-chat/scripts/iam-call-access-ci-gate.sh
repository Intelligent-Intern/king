#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
FRONTEND_DIR="${ROOT_DIR}/frontend-vue"
BACKEND_DIR="${ROOT_DIR}/backend-king-php"
PHP_BIN="${PHP_BIN:-php}"

MODE="available"

usage() {
  cat <<'USAGE'
Usage: demo/video-chat/scripts/iam-call-access-ci-gate.sh [--available|--static|--sqlite|--full]

Modes:
  --available  Run host-safe IAM contracts and any SQLite backend proofs available
               in the current PHP runtime. Known unavailable contracts are
               reported as blockers and exit 0 for CI environments that prove
               those paths through compose containers or dedicated workers.
  --static     Run only host-safe Node/static IAM contracts and report known
               static blockers.
  --sqlite     Run only SQLite-backed backend IAM contracts and fail if the
               current PHP runtime lacks pdo_sqlite.
  --full       Run static contracts plus SQLite-backed backend contracts and
               fail if the current PHP runtime lacks pdo_sqlite.
USAGE
}

while [[ "$#" -gt 0 ]]; do
  case "$1" in
    --available)
      MODE="available"
      ;;
    --static)
      MODE="static"
      ;;
    --sqlite)
      MODE="sqlite"
      ;;
    --full)
      MODE="full"
      ;;
    -h|--help)
      usage
      exit 0
      ;;
    *)
      echo "[iam-call-access-ci-gate] ERROR: unknown argument: $1" >&2
      usage >&2
      exit 64
      ;;
  esac
  shift
done

log() {
  printf '[iam-call-access-ci-gate] %s\n' "$*"
}

run_step() {
  local label="$1"
  shift
  log "START: ${label}"
  "$@"
  log "OK: ${label}"
}

php_has_pdo_sqlite() {
  "${PHP_BIN}" -m 2>/dev/null | grep -qi '^pdo_sqlite$'
}

run_node_contract() {
  local contract="$1"
  (cd "${FRONTEND_DIR}" && node "${contract}")
}

run_backend_contract() {
  local contract="$1"

  if [[ "${contract}" == *.php ]]; then
    "${PHP_BIN}" "${BACKEND_DIR}/${contract}"
  else
    PHP_BIN="${PHP_BIN}" "${BACKEND_DIR}/${contract}"
  fi
}

STATIC_CONTRACTS=(
  "tests/contract/call-access-verified-context-ui-contract.mjs"
  "tests/contract/call-access-strong-mismatch-privacy-contract.mjs"
  "tests/contract/call-access-link-privacy-contract.mjs"
  "tests/contract/call-access-privacy-foreign-data-contract.mjs"
  "tests/contract/call-access-security-manipulation-contract.mjs"
  "tests/contract/call-access-parallel-account-tabs-contract.mjs"
  "tests/contract/call-access-duplicate-review-email-contract.mjs"
  "tests/contract/iam-king-participants-owner-timeout-contract.mjs"
  "tests/contract/iam-call-access-e2e-foundation-contract.mjs"
  "tests/contract/iam-lobby-concurrency-remaining-contract.mjs"
  "tests/contract/iam-call-access-audit-events-contract.mjs"
  "tests/contract/iam-active-call-kick-contract.mjs"
  "tests/contract/e2e-release-gate-contract.mjs"
)

FULL_STATIC_CONTRACTS=(
  "tests/contract/call-access-verified-context-ui-contract.mjs"
  "tests/contract/call-access-strong-mismatch-privacy-contract.mjs"
  "tests/contract/call-access-link-privacy-contract.mjs"
  "tests/contract/call-access-privacy-foreign-data-contract.mjs"
  "tests/contract/call-access-security-manipulation-contract.mjs"
  "tests/contract/call-access-parallel-account-tabs-contract.mjs"
  "tests/contract/call-access-duplicate-review-email-contract.mjs"
  "tests/contract/iam-king-participants-owner-timeout-contract.mjs"
  "tests/contract/iam-call-access-e2e-foundation-contract.mjs"
  "tests/contract/iam-lobby-concurrency-remaining-contract.mjs"
  "tests/contract/iam-call-access-audit-events-contract.mjs"
  "tests/contract/iam-active-call-kick-contract.mjs"
  "tests/contract/e2e-release-gate-contract.mjs"
)

BLOCKED_STATIC_CONTRACTS=()

HOST_SAFE_BACKEND_CONTRACTS=(
  "tests/audit-call-access-privacy-minimization-contract.sh"
)

SQLITE_BACKEND_CONTRACTS=(
  "tests/call-access-decision-contract.sh"
  "tests/call-access-invalidation-contract.sh"
  "tests/call-access-privacy-contract.sh"
  "tests/call-access-strong-mismatch-privacy-contract.sh"
  "tests/call-access-session-contract.sh"
  "tests/call-access-session-fixation-contract.sh"
  "tests/call-access-session-route-guard-contract.sh"
  "tests/call-access-security-manipulation-contract.sh"
  "tests/call-access-anonymous-disabled-link-contract.sh"
  "tests/call-access-parallel-account-tabs-contract.sh"
  "tests/call-access-duplicate-review-contract.sh"
  "tests/call-access-email-confirmation-contract.sh"
  "tests/call-access-admin-prevention-contract.sh"
  "tests/call-access-cross-org-contract.sh"
  "tests/call-access-active-permission-change-contract.sh"
  "tests/call-access-membership-active-removal-contract.sh"
  "tests/call-access-membership-removal-contract.sh"
  "tests/call-access-invited-user-org-removal-contract.sh"
  "tests/call-access-stale-organization-role-contract.sh"
  "tests/audit-call-access-events-contract.sh"
  "tests/call-access-rejoin-kick-contract.sh"
  "tests/call-creation-owner-rights-contract.sh"
  "tests/call-guest-lifecycle-contract.sh"
  "tests/call-guest-list-direct-join-contract.sh"
  "tests/call-owner-moderation-contract.sh"
  "tests/org-admin-call-rights-contract.sh"
  "tests/system-admin-call-rights-contract.php"
  "tests/realtime-call-scope-contract.sh"
  "tests/realtime-lobby-concurrency-contract.sh"
  "tests/realtime-lobby-security-contract.sh"
  "tests/realtime-reconnect-backfill-contract.sh"
  "tests/call-access-owner-timeout-contract.sh"
  "tests/call-guest-cleanup-sqlite-proof.sh"
)

run_static_gate() {
  local include_full_static="$1"
  local contract=""
  local -a contracts=("${STATIC_CONTRACTS[@]}")

  if [[ "${include_full_static}" == "1" ]]; then
    contracts=("${FULL_STATIC_CONTRACTS[@]}")
  fi

  for contract in "${contracts[@]}"; do
    run_step "frontend/static ${contract}" run_node_contract "${contract}"
  done
  for contract in "${HOST_SAFE_BACKEND_CONTRACTS[@]}"; do
    run_step "backend/static ${contract}" run_backend_contract "${contract}"
  done
}

report_static_blockers() {
  local item=""
  local contract=""
  local reason=""

  for item in "${BLOCKED_STATIC_CONTRACTS[@]}"; do
    contract="${item%%|*}"
    reason="${item#*|}"
    log "BLOCKED: ${contract} was not executed in this available gate: ${reason}."
  done
}

report_sqlite_blocker() {
  log "BLOCKED: ${PHP_BIN} does not load pdo_sqlite; SQLite-backed IAM backend proofs were not executed in this host gate."
  log "BLOCKED: run --full or --sqlite in a PHP runtime with pdo_sqlite, or use the compose smoke where backend containers install pdo_sqlite."
  log "Blocked backend contracts:"
  local contract=""
  for contract in "${SQLITE_BACKEND_CONTRACTS[@]}"; do
    log "  ${contract}"
  done
}

run_sqlite_gate() {
  local require_sqlite="$1"
  local contract=""

  if ! php_has_pdo_sqlite; then
    report_sqlite_blocker
    if [[ "${require_sqlite}" == "1" ]]; then
      return 1
    fi
    return 0
  fi

  for contract in "${SQLITE_BACKEND_CONTRACTS[@]}"; do
    run_step "backend/sqlite ${contract}" run_backend_contract "${contract}"
  done
}

case "${MODE}" in
  available)
    run_static_gate 0
    report_static_blockers
    run_sqlite_gate 0
    ;;
  static)
    run_static_gate 0
    report_static_blockers
    ;;
  sqlite)
    run_sqlite_gate 1
    ;;
  full)
    run_static_gate 1
    run_sqlite_gate 1
    ;;
esac

log "PASS: mode=${MODE}"
