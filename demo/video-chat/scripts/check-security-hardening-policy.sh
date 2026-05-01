#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
REPO_ROOT="$(cd "${ROOT_DIR}/../.." && pwd)"
POLICY="${REPO_ROOT}/documentation/dev/video-chat/security-hardening.md"

fail() {
  printf '[security-hardening-policy] FAIL: %s\n' "$*" >&2
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

require_absent() {
  local path="$1"
  [[ ! -e "${path}" ]] || fail "legacy insecure path must stay inactive/absent: ${path}"
}

require_file "${POLICY}"

for policy_marker in \
  'SEC-DS-001' \
  'SEC-DS-002' \
  'SEC-DS-003' \
  'geschlossen/mitigiert' \
  'absichern' \
  'akzeptiert/demo-only' \
  'serverseitigen Session' \
  'Loopback-Peers'
do
  require_text "${POLICY}" "${policy_marker}"
done

VERIFY_RELEASE_SCRIPT="${REPO_ROOT}/infra/scripts/verify-release-supply-chain.sh"
require_file "${VERIFY_RELEASE_SCRIPT}"
require_text "${VERIFY_RELEASE_SCRIPT}" 'archive_entry_path_is_safe'
require_text "${VERIFY_RELEASE_SCRIPT}" 'tar -xOf "${archive}" -- "${manifest_entry}"'

require_absent "${ROOT_DIR}/dev-backend.mjs"

MCP_HOST="${REPO_ROOT}/demo/userland/flow-php/src/McpHost.php"
MCP_STOP_CONTRACT="${REPO_ROOT}/extension/tests/675-flow-php-mcp-host-stop-loopback-guard-contract.phpt"
require_file "${MCP_HOST}"
require_file "${MCP_STOP_CONTRACT}"
require_text "${MCP_HOST}" 'isLoopbackPeer'
require_text "${MCP_HOST}" 'mcp host STOP command is restricted to loopback clients.'
require_text "${MCP_STOP_CONTRACT}" 'rejects STOP shutdown from non-loopback peers'

printf '[security-hardening-policy] PASS\n'
