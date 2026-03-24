#!/usr/bin/env bash

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
EXT_DIR="$(cd "${SCRIPT_DIR}/.." && pwd)"
ROOT_DIR="$(cd "${EXT_DIR}/.." && pwd)"

cd "${ROOT_DIR}"

echo "Linting PHP entry surfaces..."
php -l stubs/king.php
php -l benchmarks/run.php
php -l extension/scripts/check-stub-parity.php

echo "Checking shell-script syntax..."
bash -n benchmarks/run-canonical.sh
bash -n infra/scripts/fuzz.sh
bash -n infra/scripts/go-live.sh
bash -n infra/scripts/package.sh
bash -n extension/scripts/audit-runtime-surface.sh
bash -n extension/scripts/audit-runtime-surface.sh
bash -n extension/scripts/build-extension.sh
bash -n extension/scripts/build-profile.sh
bash -n extension/scripts/build-extension.sh
bash -n extension/scripts/check-stub-parity.sh
bash -n extension/scripts/fuzz-runtime.sh
bash -n extension/scripts/fuzz-runtime.sh
bash -n extension/scripts/go-live-readiness.sh
bash -n extension/scripts/package-release.sh
bash -n extension/scripts/smoke-profile.sh
bash -n extension/scripts/static-checks.sh
bash -n extension/scripts/test-extension.sh
bash -n extension/scripts/verify-release-package.sh

echo "Checking GitHub Actions workflow syntax..."
ruby -e 'require "yaml"; YAML.load_file(ARGV[0])' .github/workflows/ci.yml

echo "Static checks passed."
