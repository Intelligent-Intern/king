#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT_DIR="$(cd "${SCRIPT_DIR}/.." && pwd)"
DEFAULT_DB_PATH="${ROOT_DIR}/backend-king-php/.local/video-chat.sqlite"
BACKUP_PATH="${1:-}"
TARGET_PATH="${2:-${VIDEOCHAT_KING_DB_PATH:-${DEFAULT_DB_PATH}}}"
STAMP="$(date -u +%Y%m%dT%H%M%SZ)"

fail() {
  printf '[video-chat-restore] FAIL: %s\n' "$*" >&2
  exit 1
}

if [[ -z "${BACKUP_PATH}" ]]; then
  fail "usage: restore-sqlite.sh <backup.sqlite> [target.sqlite]"
fi

if [[ ! -f "${BACKUP_PATH}" ]]; then
  fail "backup file not found: ${BACKUP_PATH}"
fi

if ! command -v sqlite3 >/dev/null 2>&1; then
  fail "sqlite3 is required for restore integrity checks"
fi

checksum_path="${BACKUP_PATH}.sha256"
if [[ -f "${checksum_path}" ]]; then
  (cd "$(dirname "${BACKUP_PATH}")" && sha256sum -c "$(basename "${checksum_path}")") >/dev/null
fi

backup_integrity="$(sqlite3 "${BACKUP_PATH}" 'PRAGMA integrity_check;' | tr -d '\r')"
if [[ "${backup_integrity}" != "ok" ]]; then
  fail "backup integrity_check failed: ${backup_integrity}"
fi

mkdir -p "$(dirname "${TARGET_PATH}")"

if [[ -e "${TARGET_PATH}" && "${VIDEOCHAT_RESTORE_OVERWRITE:-0}" != "1" ]]; then
  fail "target exists: ${TARGET_PATH}; set VIDEOCHAT_RESTORE_OVERWRITE=1 to replace it"
fi

if [[ -e "${TARGET_PATH}" ]]; then
  pre_restore_path="${TARGET_PATH}.pre-restore-${STAMP}"
  cp "${TARGET_PATH}" "${pre_restore_path}"
  printf '[video-chat-restore] previous target copied: %s\n' "${pre_restore_path}"
fi

tmp_path="${TARGET_PATH}.restore-tmp-${STAMP}"
rm -f "${tmp_path}"
cp "${BACKUP_PATH}" "${tmp_path}"

tmp_integrity="$(sqlite3 "${tmp_path}" 'PRAGMA integrity_check;' | tr -d '\r')"
if [[ "${tmp_integrity}" != "ok" ]]; then
  rm -f "${tmp_path}"
  fail "restored temp integrity_check failed: ${tmp_integrity}"
fi

mv "${tmp_path}" "${TARGET_PATH}"
sha256sum "${TARGET_PATH}" > "${TARGET_PATH}.sha256"

printf '[video-chat-restore] restored: %s\n' "${TARGET_PATH}"
printf '[video-chat-restore] checksum: %s\n' "${TARGET_PATH}.sha256"
