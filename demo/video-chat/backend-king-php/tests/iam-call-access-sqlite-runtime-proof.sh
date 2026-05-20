#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
BACKEND_ROOT="$(cd "${SCRIPT_DIR}/.." && pwd)"
REPO_ROOT="$(cd "${BACKEND_ROOT}/../../.." && pwd)"
PHP_BIN="${PHP_BIN:-php}"
DOCKER_BIN="${DOCKER_BIN:-docker}"
DOCKER_IMAGE="${IAM_SQLITE_PHP_IMAGE:-php:8.4-cli-trixie}"

DEFAULT_CONTRACTS=(
  "call-access-anonymous-temp-rights-docker-proof.sh"
  "call-access-admin-prevention-contract.sh"
  "call-access-session-contract.sh"
  "call-access-cross-org-contract.sh"
  "system-admin-call-rights-contract.sh"
  "org-admin-call-rights-contract.sh"
  "call-owner-transfer-contract.sh"
  "call-access-decision-contract.sh"
  "call-access-edge-error-matrix-contract.sh"
  "call-access-identity-mismatch-review-flow-contract.sh"
  "call-access-foreign-link-review-audit-contract.sh"
  "audit-call-access-events-contract.sh"
  "audit-call-access-membership-contract.sh"
  "call-access-invalid-expired-anonymous-link-contract.sh"
  "call-access-email-confirmation-contract.sh"
  "call-access-invalidation-contract.sh"
  "call-access-anonymous-disabled-link-contract.sh"
  "call-access-anonymous-lobby-contract.sh"
  "call-access-anonymous-logged-in-rights-contract.sh"
  "call-access-membership-removal-contract.sh"
  "call-access-membership-stale-invite-rights-contract.sh"
  "call-access-invited-user-org-removal-contract.sh"
  "call-access-active-permission-change-contract.sh"
  "call-access-org-removal-active-privilege-downgrade-contract.sh"
  "call-access-owner-absence-realtime-sync-contract.sh"
  "iam-core-org-session-journey-contract.sh"
  "call-access-session-fixation-contract.sh"
  "call-access-session-route-guard-contract.sh"
  "call-access-authorized-rejoin-contract.sh"
  "call-access-privacy-contract.sh"
  "call-access-safe-screen-privacy-contract.sh"
  "call-access-stale-organization-role-contract.sh"
  "call-access-strong-mismatch-privacy-contract.sh"
  "call-access-deleted-ended-disabled-join-contract.sh"
  "call-access-deleted-ended-hardening-contract.sh"
  "call-access-terminal-join-contract.sh"
  "call-lifecycle-contract.sh"
  "iam11-17-call-access-edge-proof-contract.sh"
  "call-calendar-invitation-flow-contract.sh"
  "call-guest-list-direct-join-contract.sh"
  "call-temporary-moderator-contract.sh"
  "realtime-lobby-security-contract.sh"
  "realtime-lobby-concurrency-contract.sh"
  "realtime-lobby-state-cleanup-contract.sh"
  "realtime-lobby-timeout-consistency-contract.sh"
  "call-owner-transfer-lifecycle-contract.sh"
)

if [[ -n "${IAM_SQLITE_CONTRACTS:-}" ]]; then
  # Optional space-separated override for broader/manual backend sweeps.
  read -r -a CONTRACTS <<<"${IAM_SQLITE_CONTRACTS}"
else
  CONTRACTS=("${DEFAULT_CONTRACTS[@]}")
fi

php_has_pdo_sqlite() {
  "${PHP_BIN}" -m 2>/dev/null | grep -qi '^pdo_sqlite$'
}

run_contracts_with_php_bin() {
  echo "[iam-call-access-sqlite-runtime-proof] PHP runtime: $("${PHP_BIN}" -r 'echo PHP_VERSION;' 2>/dev/null)"
  "${PHP_BIN}" -m | grep -i '^pdo_sqlite$'
  for contract in "${CONTRACTS[@]}"; do
    echo "[iam-call-access-sqlite-runtime-proof] ${contract}"
    IAM_SQLITE_RUNTIME_PROOF_ACTIVE=1 PHP_BIN="${PHP_BIN}" "${SCRIPT_DIR}/${contract}"
  done
}

run_contracts_with_docker() {
  if ! command -v "${DOCKER_BIN}" >/dev/null 2>&1; then
    echo "[iam-call-access-sqlite-runtime-proof] FAIL: ${PHP_BIN} lacks pdo_sqlite and ${DOCKER_BIN} is unavailable" >&2
    exit 1
  fi

  echo "[iam-call-access-sqlite-runtime-proof] Host PHP lacks pdo_sqlite; using container fallback"
  echo "[iam-call-access-sqlite-runtime-proof] Container runtime: ${DOCKER_IMAGE}"
  "${DOCKER_BIN}" run --rm \
    -v "${REPO_ROOT}:/workspace" \
    -w /workspace/demo/video-chat/backend-king-php \
    -e PHP_BIN=php \
    -e IAM_SQLITE_CONTRACTS="${CONTRACTS[*]}" \
    "${DOCKER_IMAGE}" \
    bash -lc '
      set -euo pipefail
      if ! php -m | grep -qi "^pdo_sqlite$"; then
        if command -v docker-php-ext-install >/dev/null 2>&1; then
          export DEBIAN_FRONTEND=noninteractive
          apt-get update >/tmp/iam-sqlite-apt-update.log
          apt-get install -y --no-install-recommends libsqlite3-dev >/tmp/iam-sqlite-apt-install.log
          docker-php-ext-install pdo_sqlite >/tmp/iam-sqlite-pdo-build.log
        fi
      fi
      php -m | grep -i "^pdo_sqlite$"
      for contract in ${IAM_SQLITE_CONTRACTS}; do
        echo "[iam-call-access-sqlite-runtime-proof] ${contract}"
        IAM_SQLITE_RUNTIME_PROOF_ACTIVE=1 tests/"${contract}"
      done
    '
}

if php_has_pdo_sqlite; then
  run_contracts_with_php_bin
else
  run_contracts_with_docker
fi

echo "[iam-call-access-sqlite-runtime-proof] PASS"
