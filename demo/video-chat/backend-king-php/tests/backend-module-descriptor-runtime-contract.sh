#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "${SCRIPT_DIR}/../../../.." && pwd)"
EXTENSION_PATH="${KING_EXTENSION_PATH:-${REPO_ROOT}/extension/modules/king.so}"
STATE_PATH="$(mktemp "${TMPDIR:-/tmp}/king-backend-module-orchestrator-state.XXXXXX")"
rm -f "${STATE_PATH}"

cleanup() {
  rm -f "${STATE_PATH}"
}
trap cleanup EXIT

if [[ ! -f "${EXTENSION_PATH}" ]]; then
  echo "[backend-module-descriptor-runtime-contract] FAIL: missing King extension at ${EXTENSION_PATH}" >&2
  exit 1
fi

KING_BACKEND_MODULE_DESCRIPTOR_REQUIRE_ORCHESTRATOR=1 \
php -n \
  -d "extension=${EXTENSION_PATH}" \
  -d king.security_allow_config_override=1 \
  -d king.orchestrator_execution_backend=local \
  -d "king.orchestrator_state_path=${STATE_PATH}" \
  "${SCRIPT_DIR}/backend-module-descriptor-runtime-contract.php"
