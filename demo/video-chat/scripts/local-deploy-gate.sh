#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
VIDEOCHAT_DIR="$(cd -- "${SCRIPT_DIR}/.." && pwd)"
FRONTEND_DIR="${VIDEOCHAT_DIR}/frontend-vue"
BACKEND_TESTS_DIR="${VIDEOCHAT_DIR}/backend-king-php/tests"

MODE="local"

log() {
  printf '[videochat-local-deploy-gate] %s\n' "$*"
}

fail() {
  printf '[videochat-local-deploy-gate] ERROR: %s\n' "$*" >&2
  exit 1
}

usage() {
  cat <<'USAGE'
Usage: demo/video-chat/scripts/local-deploy-gate.sh [--local|--print-online-smoke|--online-smoke]

Runs the local deploy gate only by default. The local gate does not deploy, push,
change DNS, request certbot certificates, or contact production smoke endpoints.

Modes:
  --local               Run local backend contracts, package/config validation,
                        and frontend build. This is the default.
  --print-online-smoke  Print the separate online smoke command without running it.
  --online-smoke        Run deploy-smoke.sh only when
                        VIDEOCHAT_LOCAL_DEPLOY_GATE_ALLOW_ONLINE_SMOKE=1 is set.
USAGE
}

while (($# > 0)); do
  case "$1" in
    --local)
      MODE="local"
      ;;
    --print-online-smoke)
      MODE="print-online-smoke"
      ;;
    --online-smoke)
      MODE="online-smoke"
      ;;
    -h|--help)
      usage
      exit 0
      ;;
    *)
      fail "unknown argument: $1"
      ;;
  esac
  shift
done

run_step() {
  local label="$1"
  shift
  log "START: ${label}"
  "$@"
  log "OK: ${label}"
}

run_in_dir() {
  local dir="$1"
  shift
  (cd "${dir}" && "$@")
}

validate_package_and_config() {
  run_step "deploy script syntax" bash -n "${SCRIPT_DIR}/deploy.sh"
  run_step "deploy hetzner helper syntax" bash -n "${SCRIPT_DIR}/lib/deploy-hetzner.sh"
  run_step "deploy remote status helper syntax" bash -n "${SCRIPT_DIR}/lib/deploy-remote-status.sh"
  run_step "deploy smoke syntax" bash -n "${SCRIPT_DIR}/deploy-smoke.sh"
  run_step "local smoke syntax" bash -n "${SCRIPT_DIR}/smoke.sh"
  run_step "local deploy gate syntax" bash -n "${SCRIPT_DIR}/local-deploy-gate.sh"
  run_step "deploy idempotency markers" "${SCRIPT_DIR}/check-deploy-idempotency.sh"

  run_step "frontend package and lockfile contract" run_in_dir "${FRONTEND_DIR}" node --input-type=module -e '
    import fs from "node:fs";
    const pkg = JSON.parse(fs.readFileSync("package.json", "utf8"));
    const lock = JSON.parse(fs.readFileSync("package-lock.json", "utf8"));
    const scripts = pkg.scripts || {};
    for (const name of [
      "build",
      "test:contract:iam-call-access",
      "test:e2e:release-gate",
      "test:e2e:call-access"
    ]) {
      if (typeof scripts[name] !== "string" || scripts[name].trim() === "") {
        throw new Error(`missing package script: ${name}`);
      }
    }
    if (lock.name !== pkg.name || lock.version !== pkg.version) {
      throw new Error("package-lock root metadata does not match package.json");
    }
    if (!lock.packages || !lock.packages[""]) {
      throw new Error("package-lock is missing the root package entry");
    }
    console.log("[package-config] PASS");
  '

  if command -v docker >/dev/null 2>&1 && docker compose version >/dev/null 2>&1; then
    run_step "compose config renders" docker compose -f "${VIDEOCHAT_DIR}/docker-compose.v1.yml" config --quiet
  else
    log "SKIP: compose config renders; docker compose is unavailable"
  fi
}

run_backend_contracts() {
  local contracts=(
    "call-access-session-contract.sh"
    "call-access-privacy-contract.sh"
    "call-access-strong-mismatch-privacy-contract.sh"
    "call-access-membership-removal-contract.sh"
    "call-access-stale-organization-role-contract.sh"
    "call-access-session-fixation-contract.sh"
    "call-access-session-route-guard-contract.sh"
    "call-owner-transfer-lifecycle-contract.sh"
  )

  run_step "backend SQLite contract bundle" \
    env IAM_SQLITE_CONTRACTS="${contracts[*]}" \
    "${BACKEND_TESTS_DIR}/iam-call-access-sqlite-runtime-proof.sh"
}

run_frontend_gate() {
  run_step "frontend IAM contract gate" run_in_dir "${FRONTEND_DIR}" npm run test:contract:iam-call-access
  run_step "frontend release gate package contract" run_in_dir "${FRONTEND_DIR}" npm run test:e2e:release-gate
  run_step "frontend build" run_in_dir "${FRONTEND_DIR}" npm run build
}

print_online_smoke_command() {
  cat <<'SMOKE'
Online smoke is intentionally separate from the local deploy gate.
It must not be used as a deploy, DNS, or certbot step.

Command, when explicitly authorized:

  VIDEOCHAT_LOCAL_DEPLOY_GATE_ALLOW_ONLINE_SMOKE=1 \
    demo/video-chat/scripts/local-deploy-gate.sh --online-smoke

Equivalent direct smoke command:

  demo/video-chat/scripts/deploy-smoke.sh
SMOKE
}

case "${MODE}" in
  local)
    log "local gate only: no push, no deploy, no DNS, no certbot, no online smoke"
    run_step "package and config validation" validate_package_and_config
    run_step "backend contracts" run_backend_contracts
    run_step "frontend gate" run_frontend_gate
    log "PASS"
    ;;
  print-online-smoke)
    print_online_smoke_command
    ;;
  online-smoke)
    if [[ "${VIDEOCHAT_LOCAL_DEPLOY_GATE_ALLOW_ONLINE_SMOKE:-0}" != "1" ]]; then
      fail "--online-smoke requires VIDEOCHAT_LOCAL_DEPLOY_GATE_ALLOW_ONLINE_SMOKE=1"
    fi
    log "online smoke only: no deploy, no DNS, no certbot"
    run_step "production deploy smoke" "${SCRIPT_DIR}/deploy-smoke.sh"
    ;;
  *)
    fail "unsupported mode: ${MODE}"
    ;;
esac
