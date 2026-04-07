#!/usr/bin/env bash

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT_DIR="$(cd "${SCRIPT_DIR}/../.." && pwd)"
EXT_DIR="${ROOT_DIR}/extension"

EXPECTED_VERSIONS=("8.1" "8.2" "8.3" "8.4" "8.5")
EXPECTED_CSV="8.1,8.2,8.3,8.4,8.5"

require_line() {
    local file_path="$1"
    local literal="$2"

    if ! grep -Fqx "${literal}" "${file_path}"; then
        echo "Missing expected line in ${file_path}: ${literal}" >&2
        exit 1
    fi
}

require_line "${ROOT_DIR}/infra/scripts/container-smoke-matrix.sh" \
    "PHP_VERSIONS_CSV=\"\${PHP_VERSIONS:-${EXPECTED_CSV}}\""

require_line "${ROOT_DIR}/CONTRIBUTE.md" \
    "./infra/scripts/container-smoke-matrix.sh --php-versions ${EXPECTED_CSV}"

require_line "${ROOT_DIR}/CONTRIBUTE.md" \
    "./infra/scripts/php-version-docker-matrix.sh --php-versions ${EXPECTED_CSV}"

ruby - "${ROOT_DIR}" "${EXPECTED_CSV}" <<'RUBY'
require "yaml"

root_dir = ARGV[0]
expected = ARGV[1].split(",")

ci = YAML.safe_load(
  File.read(File.join(root_dir, ".github/workflows/ci.yml")),
  permitted_classes: [],
  permitted_symbols: [],
  aliases: false
)
release_publish = YAML.safe_load(
  File.read(File.join(root_dir, ".github/workflows/release-merge-publish.yml")),
  permitted_classes: [],
  permitted_symbols: [],
  aliases: false
)
ci_include = ci.dig("jobs", "install-package-matrix", "strategy", "matrix", "include")
ci_versions = ci_include.map { |entry| entry["php-version"] }.uniq

docker_runtime_versions = release_publish.dig("jobs", "docker-build-and-push", "strategy", "matrix", "php-version")
docker_runtime_include = release_publish.dig("jobs", "docker-build-and-push", "strategy", "matrix", "include")
docker_runtime_include_versions = docker_runtime_include.map { |entry| entry["php-version"] }
docker_demo_versions = release_publish.dig("jobs", "docker-build-demo", "strategy", "matrix", "php-version")
docker_demo_include = release_publish.dig("jobs", "docker-build-demo", "strategy", "matrix", "include")
docker_demo_include_versions = docker_demo_include.map { |entry| entry["php-version"] }

if docker_runtime_versions != expected
  abort("CI docker runtime PHP matrix mismatch: expected #{expected.inspect}, got #{docker_runtime_versions.inspect}")
end

if docker_runtime_include_versions != expected
  abort("CI docker runtime include PHP matrix mismatch: expected #{expected.inspect}, got #{docker_runtime_include_versions.inspect}")
end

if docker_demo_versions != expected
  abort("CI docker demo PHP matrix mismatch: expected #{expected.inspect}, got #{docker_demo_versions.inspect}")
end

if docker_demo_include_versions != expected
  abort("CI docker demo include PHP matrix mismatch: expected #{expected.inspect}, got #{docker_demo_include_versions.inspect}")
end

if ci_versions != expected
  abort("CI install-package PHP matrix mismatch: expected #{expected.inspect}, got #{ci_versions.inspect}")
end
RUBY

printf 'Supported PHP matrix aligned: %s\n' "${EXPECTED_CSV}"
