#!/usr/bin/env bash

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
EXT_DIR="$(cd "${SCRIPT_DIR}/.." && pwd)"
ROOT_DIR="$(cd "${EXT_DIR}/.." && pwd)"

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

require_line "${ROOT_DIR}/extension/scripts/container-smoke-matrix.sh" \
    "PHP_VERSIONS_CSV=\"\${PHP_VERSIONS:-${EXPECTED_CSV}}\""

require_line "${ROOT_DIR}/CONTRIBUTE.md" \
    "./scripts/container-smoke-matrix.sh --php-versions ${EXPECTED_CSV}"

ruby - "${ROOT_DIR}" "${EXPECTED_CSV}" <<'RUBY'
require "yaml"

root_dir = ARGV[0]
expected = ARGV[1].split(",")

docker = YAML.load_file(File.join(root_dir, ".github/workflows/docker.yml"))
docker_versions = docker.dig("jobs", "build-and-push", "strategy", "matrix", "php-version")
docker_include = docker.dig("jobs", "build-and-push", "strategy", "matrix", "include")
docker_include_versions = docker_include.map { |entry| entry["php-version"] }

ci = YAML.load_file(File.join(root_dir, ".github/workflows/ci.yml"))
ci_versions = ci.dig("jobs", "install-package-matrix", "strategy", "matrix", "php-version")

if docker_versions != expected
  abort("Docker workflow PHP matrix mismatch: expected #{expected.inspect}, got #{docker_versions.inspect}")
end

if docker_include_versions != expected
  abort("Docker workflow include PHP matrix mismatch: expected #{expected.inspect}, got #{docker_include_versions.inspect}")
end

if ci_versions != expected
  abort("CI install-package PHP matrix mismatch: expected #{expected.inspect}, got #{ci_versions.inspect}")
end
RUBY

printf 'Supported PHP matrix aligned: %s\n' "${EXPECTED_CSV}"
