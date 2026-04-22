#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
REPO_ROOT="$(cd "${ROOT_DIR}/../.." && pwd)"
DOC="${REPO_ROOT}/documentation/dev/video-chat/ops-hardening.md"
CATALOG="${ROOT_DIR}/ops/metrics-alerts.catalog.json"
BACKUP_SCRIPT="${ROOT_DIR}/scripts/backup-sqlite.sh"
RESTORE_SCRIPT="${ROOT_DIR}/scripts/restore-sqlite.sh"
README="${REPO_ROOT}/documentation/dev/video-chat.md"
BACKLOG="${REPO_ROOT}/BACKLOG.md"
SMOKE="${ROOT_DIR}/scripts/smoke.sh"
COMPOSE="${ROOT_DIR}/docker-compose.v1.yml"
RUN_DEV="${ROOT_DIR}/backend-king-php/run-dev.sh"

fail() {
  printf '[ops-hardening] FAIL: %s\n' "$*" >&2
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
require_file "${CATALOG}"
require_file "${BACKUP_SCRIPT}"
require_file "${RESTORE_SCRIPT}"
require_file "${README}"
require_file "${BACKLOG}"
require_file "${SMOKE}"
require_file "${COMPOSE}"
require_file "${RUN_DEV}"

bash -n "${BACKUP_SCRIPT}"
bash -n "${RESTORE_SCRIPT}"

for marker in \
  'Restore-Drill' \
  'Metrics/Logs/Alerts Pipeline' \
  'K-01..K-15' \
  'A-01..A-15' \
  'VIDEOCHAT_OTEL_EXPORTER_ENDPOINT' \
  'Rollout/Rollback Runbook' \
  'Local Compose Dev/Staging' \
  'Hardened Single-Node Staging' \
  'Future Multi-Node Production'
do
  require_text "${DOC}" "${marker}"
done

for marker in \
  'VIDEOCHAT_OTEL_EXPORTER_ENDPOINT' \
  'VIDEOCHAT_OTEL_SERVICE_NAME' \
  'VIDEOCHAT_OTEL_METRICS_ENABLE' \
  'VIDEOCHAT_OTEL_LOGS_ENABLE'
do
  require_text "${COMPOSE}" "${marker}"
  require_text "${RUN_DEV}" "${marker}"
done

php -r '
$catalogPath = $argv[1];
$catalog = json_decode((string) file_get_contents($catalogPath), true);
if (!is_array($catalog)) {
    fwrite(STDERR, "catalog is not valid JSON\n");
    exit(1);
}
$metrics = $catalog["metrics"] ?? null;
$alerts = $catalog["alerts"] ?? null;
if (!is_array($metrics) || count($metrics) !== 15) {
    fwrite(STDERR, "catalog must contain exactly 15 metrics\n");
    exit(1);
}
if (!is_array($alerts) || count($alerts) !== 15) {
    fwrite(STDERR, "catalog must contain exactly 15 alerts\n");
    exit(1);
}
for ($i = 1; $i <= 15; $i++) {
    $metricId = sprintf("K-%02d", $i);
    $alertId = sprintf("A-%02d", $i);
    if (($metrics[$i - 1]["id"] ?? null) !== $metricId) {
        fwrite(STDERR, "missing metric id {$metricId}\n");
        exit(1);
    }
    if (($alerts[$i - 1]["id"] ?? null) !== $alertId) {
        fwrite(STDERR, "missing alert id {$alertId}\n");
        exit(1);
    }
}
$labels = $catalog["pipeline"]["required_labels"] ?? [];
foreach (["build", "env", "room_size_tier", "client_device_class", "codec_stage"] as $label) {
    if (!in_array($label, $labels, true)) {
        fwrite(STDERR, "missing required label {$label}\n");
        exit(1);
    }
}
' "${CATALOG}"

if ! command -v sqlite3 >/dev/null 2>&1; then
  fail "sqlite3 is required for restore drill"
fi

tmp_dir="$(mktemp -d)"
cleanup() {
  rm -rf "${tmp_dir}"
}
trap cleanup EXIT

source_db="${tmp_dir}/source.sqlite"
restore_db="${tmp_dir}/restored.sqlite"
backup_dir="${tmp_dir}/backups"

sqlite3 "${source_db}" "CREATE TABLE restore_drill(id INTEGER PRIMARY KEY, name TEXT NOT NULL); INSERT INTO restore_drill(name) VALUES ('alpha'), ('beta');"
"${BACKUP_SCRIPT}" "${source_db}" "${backup_dir}" >/dev/null
backup_path="$(find "${backup_dir}" -maxdepth 1 -type f -name 'video-chat-*.sqlite' | sort | tail -n 1)"
[[ -n "${backup_path}" ]] || fail "backup drill did not create a sqlite backup"
"${RESTORE_SCRIPT}" "${backup_path}" "${restore_db}" >/dev/null
row_count="$(sqlite3 "${restore_db}" 'SELECT COUNT(*) FROM restore_drill;' | tr -d '\r')"
[[ "${row_count}" == "2" ]] || fail "restore drill row-count mismatch: ${row_count}"
restored_integrity="$(sqlite3 "${restore_db}" 'PRAGMA integrity_check;' | tr -d '\r')"
[[ "${restored_integrity}" == "ok" ]] || fail "restore drill integrity failed: ${restored_integrity}"

require_text "${README}" 'documentation/dev/video-chat/ops-hardening.md'
require_text "${README}" 'check-ops-hardening.sh'
require_text "${SMOKE}" 'check-ops-hardening.sh'
require_text "${BACKLOG}" '### #Q-19 Video-Chat Admin Operations And Production Deploy Readiness'
require_text "${BACKLOG}" 'Correct live call and participant counts'
require_text "${BACKLOG}" 'fresh production deploy is repeatable'

printf '[ops-hardening] PASS\n'
