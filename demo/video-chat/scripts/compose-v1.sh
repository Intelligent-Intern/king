#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
VIDEOCHAT_DIR="$(cd -- "${SCRIPT_DIR}/.." && pwd)"
COMPOSE_FILE="${VIDEOCHAT_DIR}/docker-compose.v1.yml"

env_args=()
if [[ -f "${VIDEOCHAT_DIR}/.env" ]]; then
  env_args+=(--env-file "${VIDEOCHAT_DIR}/.env")
fi
if [[ -f "${VIDEOCHAT_DIR}/.env.local" ]]; then
  env_args+=(--env-file "${VIDEOCHAT_DIR}/.env.local")
fi

exec docker compose \
  --project-directory "${VIDEOCHAT_DIR}" \
  "${env_args[@]}" \
  -f "${COMPOSE_FILE}" \
  "$@"
