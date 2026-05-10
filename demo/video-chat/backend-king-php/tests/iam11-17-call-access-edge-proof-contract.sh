#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
PHP_BIN="${PHP_BIN:-php}"

source "${SCRIPT_DIR}/sqlite-contract-runner.sh"
run_videochat_sqlite_contract "${SCRIPT_DIR}" "iam11-17-call-access-edge-proof-contract" "iam11-17-call-access-edge-proof-contract.php" "$(basename "${BASH_SOURCE[0]}")"
