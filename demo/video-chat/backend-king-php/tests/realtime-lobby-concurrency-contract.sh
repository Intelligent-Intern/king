#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PHP_BIN="${PHP_BIN:-php}"

source "${SCRIPT_DIR}/sqlite-contract-runner.sh"
run_videochat_sqlite_contract "${SCRIPT_DIR}" "realtime-lobby-concurrency-contract" "realtime-lobby-concurrency-contract.php" "$(basename "${BASH_SOURCE[0]}")"
