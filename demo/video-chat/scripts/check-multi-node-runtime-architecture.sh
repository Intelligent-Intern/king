#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
REPO_ROOT="$(cd "${ROOT_DIR}/../.." && pwd)"
DOC="${REPO_ROOT}/documentation/dev/video-chat/multi-node-runtime-architecture.md"
README="${REPO_ROOT}/documentation/dev/video-chat.md"
BACKLOG="${REPO_ROOT}/BACKLOG.md"
SMOKE="${ROOT_DIR}/scripts/smoke.sh"

fail() {
  printf '[multi-node-architecture] FAIL: %s\n' "$*" >&2
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
require_file "${README}"
require_file "${BACKLOG}"
require_file "${SMOKE}"

for marker in \
  'Status: verbindlicher Architektur- und Migrationsvertrag' \
  'Session/Auth' \
  'Call State' \
  'Roster/Presence' \
  'Realtime Fanout' \
  'Media/SFU' \
  'SQLite bleibt nur Single-Node-/Dev-Storage' \
  'shared SQL store' \
  'King Object Store' \
  'presence TTL' \
  'inter-node bus' \
  'videochat.room.{room_id}.fanout' \
  'videochat.call.{call_id}.signaling' \
  'videochat.call.{call_id}.sfu' \
  'zero-downtime' \
  'dual-write' \
  'read switch' \
  'rollback' \
  'no manual Refresh' \
  'server-authoritative' \
  'Acceptance Gates'
do
  require_text "${DOC}" "${marker}"
done

require_text "${README}" 'documentation/dev/video-chat/multi-node-runtime-architecture.md'
require_text "${README}" 'check-multi-node-runtime-architecture.sh'
require_text "${SMOKE}" 'check-multi-node-runtime-architecture.sh'
require_text "${BACKLOG}" '### #Q-19 Video-Chat Admin Operations And Production Deploy Readiness'
require_text "${BACKLOG}" 'Keep Hetzner-specific discovery behind provider abstractions'

printf '[multi-node-architecture] PASS\n'
