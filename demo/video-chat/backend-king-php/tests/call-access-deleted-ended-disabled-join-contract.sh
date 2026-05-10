#!/usr/bin/env bash
set -euo pipefail

PHP_BIN="${PHP_BIN:-php}"
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

source "${SCRIPT_DIR}/sqlite-contract-runner.sh"
run_videochat_sqlite_contract "${SCRIPT_DIR}" "call-access-deleted-ended-disabled-join-contract" "call-access-deleted-ended-disabled-join-contract.php" "$(basename "${BASH_SOURCE[0]}")"
