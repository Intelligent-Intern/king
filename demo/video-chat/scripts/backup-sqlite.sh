#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT_DIR="$(cd "${SCRIPT_DIR}/.." && pwd)"
DEFAULT_DB_PATH="${ROOT_DIR}/backend-king-php/.local/video-chat.sqlite"
DB_PATH="${1:-${VIDEOCHAT_KING_DB_PATH:-${DEFAULT_DB_PATH}}}"
BACKUP_DIR="${2:-${ROOT_DIR}/.backups}"
STAMP="$(date -u +%Y%m%dT%H%M%SZ)"
BACKUP_PATH="${BACKUP_DIR}/video-chat-${STAMP}.sqlite"

if [[ ! -f "${DB_PATH}" ]]; then
  echo "[video-chat-backup] database file not found: ${DB_PATH}" >&2
  exit 1
fi

mkdir -p "${BACKUP_DIR}"

if command -v sqlite3 >/dev/null 2>&1; then
  sqlite3 "${DB_PATH}" ".timeout 5000" ".backup '${BACKUP_PATH}'"
else
  cp "${DB_PATH}" "${BACKUP_PATH}"
fi

sha256sum "${BACKUP_PATH}" > "${BACKUP_PATH}.sha256"

echo "[video-chat-backup] created: ${BACKUP_PATH}"
echo "[video-chat-backup] checksum: ${BACKUP_PATH}.sha256"
