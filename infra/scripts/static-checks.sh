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

YAML_VALIDATOR="${ROOT_DIR}/infra/scripts/validate-yaml.rb"
if [ ! -r "${YAML_VALIDATOR}" ]; then
    echo "Error: YAML validator '${YAML_VALIDATOR}' is missing or not readable." >&2
    exit 1
fi

php_lint() {
    php -n -l "$1"
}

echo "Linting PHP entry surfaces..."
php_lint stubs/king.php
php_lint benchmarks/run.php
php_lint infra/scripts/check-stub-parity.php
php_lint infra/scripts/check-http3-lsquic-loader-contract.php
php_lint infra/scripts/runtime-config-compatibility.php
php_lint infra/scripts/runtime-install-smoke.php
php_lint infra/scripts/runtime-persistence-migration.php

echo "Validating Composer metadata..."
composer validate "${ROOT_DIR}/composer.json"

echo "Checking shell-script syntax..."
bash -n benchmarks/run-canonical.sh
for script in infra/scripts/*.sh; do
    bash -n "${script}"
done

echo "Checking GitHub Actions workflow syntax..."
shopt -s nullglob
workflow_files=(.github/workflows/*.yml)
shopt -u nullglob
for workflow in "${workflow_files[@]}"; do
    ruby "${YAML_VALIDATOR}" "${workflow}"
done

echo "Checking repository artifact hygiene..."
infra/scripts/check-repo-artifact-hygiene.sh

echo "Checking Linux reproducible CI build matrix..."
ruby infra/scripts/check-ci-linux-reproducible-builds.rb

echo "Checking CI HTTP/3 stack build gates..."
ruby infra/scripts/check-ci-builds-http3-stack.rb

echo "Checking macOS/dev dependency path policy..."
ruby infra/scripts/check-dev-path-configuration.rb

echo "Checking HTTP/3 product build path for Rust/Cargo bootstrap..."
ruby infra/scripts/check-http3-product-build-path.rb

echo "Checking HTTP/3 LSQUIC loader contract..."
php -n infra/scripts/check-http3-lsquic-loader-contract.php

echo "Checking extension include layout..."
infra/scripts/check-include-layout.sh

echo "Checking deterministic LSQUIC bootstrap..."
infra/scripts/check-lsquic-bootstrap.sh

echo "Checking dependency provenance documentation..."
infra/scripts/check-dependency-provenance-doc.sh

echo "Checking deterministic HTTP/3 test helper build plan..."
infra/scripts/build-http3-test-helpers.sh --verify-plan

echo "Checking supported PHP matrix alignment..."
infra/scripts/check-php-support-matrix.sh

echo "Static checks passed."
