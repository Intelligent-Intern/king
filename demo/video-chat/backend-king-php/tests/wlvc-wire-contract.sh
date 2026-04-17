#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PHP_BIN="${PHP_BIN:-php}"

cd "${ROOT_DIR}"
"${PHP_BIN}" tests/wlvc-wire-contract.php
