#!/usr/bin/env bash
set -euo pipefail

cd "$(dirname "$0")"
php runtime-public-safety-contract.php
