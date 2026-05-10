#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
REPO_ROOT="$(cd "${ROOT_DIR}/../.." && pwd)"
BACKEND_DIR="${ROOT_DIR}/backend-king-php"
PHP_BIN="${PHP_BIN:-php}"
DOCKER_BIN="${DOCKER_BIN:-docker}"
DOCKER_IMAGE="${VIDEOCHAT_KING_PARTICIPANT_PHP_IMAGE:-php:8.4-cli-trixie}"
ARTIFACT_DIR="${VIDEOCHAT_KING_PARTICIPANT_ARTIFACT_DIR:-${TMPDIR:-/tmp}/king-participant-container-proof}"

mkdir -p "${ARTIFACT_DIR}"

echo "[king-participant-container-proof] artifact_dir=${ARTIFACT_DIR}"

if "${PHP_BIN}" -m 2>/dev/null | grep -qi "^pdo_sqlite$"; then
  VIDEOCHAT_KING_PARTICIPANT_ARTIFACT_DIR="${ARTIFACT_DIR}" \
  PHP_BIN="${PHP_BIN}" \
  "${BACKEND_DIR}/tests/call-access-king-container-contract.sh"
  exit 0
fi

if command -v "${DOCKER_BIN}" >/dev/null 2>&1; then
  echo "[king-participant-container-proof] Host PHP lacks pdo_sqlite; using container fallback: ${DOCKER_IMAGE}"
  "${DOCKER_BIN}" run --rm \
    -v "${REPO_ROOT}:/workspace" \
    -v "${ARTIFACT_DIR}:/king-artifacts" \
    -w /workspace/demo/video-chat/backend-king-php \
    -e PHP_BIN=php \
    -e VIDEOCHAT_KING_PARTICIPANT_ARTIFACT_DIR=/king-artifacts \
    "${DOCKER_IMAGE}" \
    bash -lc '
      set -euo pipefail
      php -m | grep -i "^pdo_sqlite$"
      tests/call-access-king-container-contract.sh
    '
  exit 0
fi

VIDEOCHAT_KING_PARTICIPANT_ARTIFACT_DIR="${ARTIFACT_DIR}" \
PHP_BIN="${PHP_BIN}" \
"${BACKEND_DIR}/tests/call-access-king-container-contract.sh"
