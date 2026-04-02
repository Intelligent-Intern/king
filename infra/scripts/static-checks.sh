#!/usr/bin/env bash

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT_DIR="$(cd "${SCRIPT_DIR}/../.." && pwd)"
EXT_DIR="${ROOT_DIR}/extension"

cd "${ROOT_DIR}"

echo "Linting PHP entry surfaces..."
php -l stubs/king.php
php -l benchmarks/run.php
php -l infra/scripts/check-stub-parity.php
php -l infra/scripts/runtime-config-compatibility.php
php -l infra/scripts/runtime-install-smoke.php
php -l infra/scripts/runtime-persistence-migration.php

echo "Validating Composer metadata..."
composer validate composer.json

echo "Checking shell-script syntax..."
bash -n benchmarks/run-canonical.sh
for script in infra/scripts/*.sh; do
    bash -n "${script}"
done

echo "Checking GitHub Actions workflow syntax..."
for workflow in .github/workflows/*.yml; do
    ruby -e 'require "yaml"; YAML.load_file(ARGV[0])' "${workflow}"
done

echo "Checking extension include layout..."
infra/scripts/check-include-layout.sh

echo "Checking deterministic quiche bootstrap..."
infra/scripts/check-quiche-bootstrap.sh

echo "Checking supported PHP matrix alignment..."
infra/scripts/check-php-support-matrix.sh

echo "Static checks passed."
