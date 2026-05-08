#!/usr/bin/env bash
set -euo pipefail

PHP_BIN="${PHP_BIN:-php}"

exec "$PHP_BIN" "$(dirname "$0")/call-owner-moderation-contract.php"
