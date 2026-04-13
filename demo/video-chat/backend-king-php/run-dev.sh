#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
HOST="${VIDEOCHAT_KING_HOST:-127.0.0.1}"
PORT="${VIDEOCHAT_KING_PORT:-18080}"

echo "[video-chat][king-php-backend] listening on http://${HOST}:${PORT}"
exec php -S "${HOST}:${PORT}" -t "${SCRIPT_DIR}/public"
