#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PHP_BIN="${PHP_BIN:-php}"

"${PHP_BIN}" "${SCRIPT_DIR}/embedding-request-envelope-contract.php"
