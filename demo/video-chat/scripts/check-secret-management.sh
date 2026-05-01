#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
REPO_ROOT="$(cd "${ROOT_DIR}/../.." && pwd)"
BACKEND_DIR="${ROOT_DIR}/backend-king-php"
DOC="${REPO_ROOT}/documentation/dev/video-chat/secret-management.md"
SUPPORT="${BACKEND_DIR}/support/config_hardening.php"
SERVER="${BACKEND_DIR}/server.php"
DATABASE="${BACKEND_DIR}/support/database.php"
DATABASE_SEED="${BACKEND_DIR}/support/database_demo_seed.php"
CONTRACT="${BACKEND_DIR}/tests/config-hardening-contract.sh"

fail() {
  printf '[secret-management] FAIL: %s\n' "$*" >&2
  exit 1
}

require_file() {
  local path="$1"
  [[ -f "${path}" ]] || fail "missing required file: ${path}"
}

require_text() {
  local path="$1"
  local needle="$2"
  grep -Fq -- "${needle}" "${path}" || fail "missing '${needle}' in ${path}"
}

require_file "${DOC}"
require_file "${SUPPORT}"
require_file "${SERVER}"
require_file "${DATABASE}"
require_file "${DATABASE_SEED}"
require_file "${CONTRACT}"

for marker in \
  'VIDEOCHAT_REQUIRE_SECRET_SOURCES=1' \
  'VIDEOCHAT_DEMO_ADMIN_PASSWORD_FILE' \
  'VIDEOCHAT_DEMO_USER_PASSWORD_FILE' \
  'VIDEOCHAT_V1_TURN_STATIC_AUTH_SECRET_FILE' \
  'VIDEOCHAT_TURN_STATIC_AUTH_SECRET_FILE' \
  'Der aktive Video-Chat-Backendpfad nutzt keine JWT-Signing-Secrets.' \
  'Rotation Runbook' \
  'check-secret-management.sh'
do
  require_text "${DOC}" "${marker}"
done

for marker in \
  'videochat_config_hardening_report' \
  'VIDEOCHAT_REQUIRE_SECRET_SOURCES' \
  'VIDEOCHAT_DEMO_ADMIN_PASSWORD_FILE' \
  'VIDEOCHAT_DEMO_USER_PASSWORD_FILE' \
  'VIDEOCHAT_DEMO_SEED_CALLS must be 0/false/off/no' \
  'VIDEOCHAT_V1_TURN_STATIC_AUTH_SECRET'
do
  require_text "${SUPPORT}" "${marker}"
done

require_text "${SERVER}" 'videochat_config_hardening_report'
require_text "${SERVER}" 'secret/config hardening failed'
require_text "${DATABASE}" "require_once __DIR__ . '/config_hardening.php';"
require_text "${DATABASE}" "require_once __DIR__ . '/database_demo_seed.php';"
require_text "${DATABASE_SEED}" "videochat_config_secret_value('VIDEOCHAT_DEMO_ADMIN_PASSWORD', 'admin123')"
require_text "${DATABASE_SEED}" "videochat_config_secret_value('VIDEOCHAT_DEMO_USER_PASSWORD', 'user123')"

php -l "${SUPPORT}" >/dev/null
bash -n "${CONTRACT}"
"${CONTRACT}"

if [[ -e "${ROOT_DIR}/deploy" || -e "${ROOT_DIR}/docker-compose.edge.yml" ]]; then
  fail "edge deploy path exists; secret management must be redefined in a dedicated future deploy issue"
fi

printf '[secret-management] PASS\n'
