#!/usr/bin/env bash
set -euo pipefail

PHP_BIN="${PHP_BIN:-php}"
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

"${PHP_BIN}" "${SCRIPT_DIR}/call-access-identity-mismatch-review-flow-contract.php"
