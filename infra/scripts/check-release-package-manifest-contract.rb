#!/usr/bin/env ruby

ROOT_DIR = File.expand_path("../..", __dir__)

PACKAGE_SCRIPT = "infra/scripts/package-release.sh"
PACKAGE_VERIFIER = "infra/scripts/verify-release-package.sh"

PROVENANCE_HASH_KEYS = %w[
  lsquic_bootstrap_lock_sha256
  lsquic_archive_sha256
  boringssl_archive_sha256
  ls_qpack_archive_sha256
  ls_hpack_archive_sha256
].freeze

DEPENDENCY_ARCHIVE_LOCK_KEYS = {
  "lsquic" => "KING_LSQUIC_ARCHIVE_SHA256",
  "boringssl" => "KING_LSQUIC_BORINGSSL_ARCHIVE_SHA256",
  "ls-qpack" => "KING_LSQUIC_LS_QPACK_ARCHIVE_SHA256",
  "ls-hpack" => "KING_LSQUIC_LS_HPACK_ARCHIVE_SHA256",
}.freeze

TOP_LEVEL_PROVENANCE_KEYS = {
  "lsquic" => "lsquic_archive_sha256",
  "boringssl" => "boringssl_archive_sha256",
  "ls-qpack" => "ls_qpack_archive_sha256",
  "ls-hpack" => "ls_hpack_archive_sha256",
}.freeze

LEGACY_HTTP3_TOKENS = [
  "qui" + "che",
  "KING_" + "QUI" + "CHE",
  "lib" + "qui" + "che",
  "qui" + "che-server",
  "qui" + "che-bootstrap",
  "qui" + "che-workspace",
  "Cargo.toml",
  "Cargo.lock",
].freeze

def fail_check(message)
  warn "Release package manifest check failed: #{message}"
  exit 1
end

def read_repo(path)
  full_path = File.join(ROOT_DIR, path)
  fail_check("missing #{path}") unless File.file?(full_path)
  File.read(full_path)
end

def require_literal(source, literal, label)
  fail_check("missing #{label}: #{literal}") unless source.include?(literal)
end

package_script = read_repo(PACKAGE_SCRIPT)
package_verifier = read_repo(PACKAGE_VERIFIER)

require_literal(package_script, "'http3_stack' => [", "package HTTP/3 stack manifest section")
require_literal(package_script, "'transport' => 'lsquic'", "package LSQUIC transport manifest value")
require_literal(package_script, "'tls' => 'boringssl'", "package BoringSSL TLS manifest value")
require_literal(package_script, "'bootstrap_lock' => 'infra/scripts/lsquic-bootstrap.lock'", "package LSQUIC lock manifest path")
require_literal(package_script, "'bootstrap_script' => 'infra/scripts/bootstrap-lsquic.sh'", "package LSQUIC bootstrap manifest path")
require_literal(package_script, "'components' => array_keys($dependencyProvenance)", "package dependency component manifest list")
require_literal(package_script, "'dependency_provenance' => $dependencyProvenance", "package dependency provenance manifest")

PROVENANCE_HASH_KEYS.each do |hash_key|
  require_literal(package_script, "'#{hash_key}'", "package top-level provenance hash #{hash_key}")
  require_literal(package_verifier, "'#{hash_key}'", "package verifier provenance hash #{hash_key}")
end

DEPENDENCY_ARCHIVE_LOCK_KEYS.each do |component, lock_key|
  require_literal(package_script, "'#{component}' => [", "package dependency component #{component}")
  require_literal(package_script, "'archive_sha256' => $readLock('#{lock_key}')", "package dependency hash lock #{lock_key}")
  require_literal(package_verifier, "'#{component}'", "package verifier dependency component #{component}")
end

TOP_LEVEL_PROVENANCE_KEYS.each do |component, provenance_key|
  require_literal(package_verifier, "'#{component}' => '#{provenance_key}'", "package verifier provenance binding #{component}")
end

require_literal(package_verifier, "$assertLegacyHttp3Free($manifest, 'manifest');", "manifest legacy HTTP/3 exclusion scan")
require_literal(package_verifier, "array_keys($dependencyProvenance) !== $expectedComponents", "exact dependency component list gate")
require_literal(package_verifier, "Manifest dependency provenance component list is invalid.", "dependency component list failure")
require_literal(package_verifier, "Manifest dependency provenance hash does not match top-level provenance", "dependency hash binding failure")

LEGACY_HTTP3_TOKENS.each do |token|
  fail_check("#{PACKAGE_SCRIPT} contains legacy HTTP/3 token #{token}") if package_script.downcase.include?(token.downcase)
end

puts "Release package manifests enforce LSQUIC dependency hashes and legacy HTTP/3 manifest exclusion."
