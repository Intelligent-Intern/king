#!/usr/bin/env bash
set -eu

SCRIPT_DIR="$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)"
source "${SCRIPT_DIR}/sqlite-contract-runner.sh"

run_videochat_sqlite_contract \
  "${SCRIPT_DIR}" \
  "chat-archive-contract" \
  "chat-archive-contract.php" \
  "$(basename "${BASH_SOURCE[0]}")"
