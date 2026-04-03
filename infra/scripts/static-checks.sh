#!/usr/bin/env bash

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT_DIR="$(cd "${SCRIPT_DIR}/../.." && pwd)"
EXT_DIR="${ROOT_DIR}/extension"

cd "${ROOT_DIR}"

if ! command -v php >/dev/null 2>&1; then
    echo "Error: PHP is required for static checks but was not found in PATH." >&2
    echo "Please install PHP or ensure it is available on your PATH, then re-run this script." >&2
    exit 1
fi

if ! command -v composer >/dev/null 2>&1; then
    echo "Error: Composer is required for static checks but was not found in PATH." >&2
    echo "Please install Composer or ensure it is available on your PATH, then re-run this script." >&2
    exit 1
fi

if ! command -v ruby >/dev/null 2>&1; then
    echo "Error: Ruby is required for GitHub Actions workflow validation but was not found in PATH." >&2
    echo "Please install Ruby or ensure it is available on your PATH, then re-run this script." >&2
    exit 1
fi

echo "Linting PHP entry surfaces..."
php -l stubs/king.php
php -l benchmarks/run.php
php -l infra/scripts/check-stub-parity.php
php -l infra/scripts/runtime-config-compatibility.php
php -l infra/scripts/runtime-install-smoke.php
php -l infra/scripts/runtime-persistence-migration.php

echo "Validating Composer metadata..."
composer validate "${ROOT_DIR}/composer.json"

echo "Checking shell-script syntax..."
bash -n benchmarks/run-canonical.sh
for script in infra/scripts/*.sh; do
    bash -n "${script}"
done

echo "Checking GitHub Actions workflow syntax..."
for workflow in .github/workflows/*.yml; do
    ruby -e 'require "yaml"; YAML.safe_load(File.read(ARGV[0]), permitted_classes: [], permitted_symbols: [], aliases: false)' "${workflow}"
done

echo "Checking extension include layout..."
infra/scripts/check-include-layout.sh

echo "Checking deterministic quiche bootstrap..."
infra/scripts/check-quiche-bootstrap.sh

echo "Checking supported PHP matrix alignment..."
infra/scripts/check-php-support-matrix.sh

echo "Static checks passed."
