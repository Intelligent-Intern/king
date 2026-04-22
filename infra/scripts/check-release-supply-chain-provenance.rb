#!/usr/bin/env ruby

ROOT_DIR = File.expand_path("../..", __dir__)

PACKAGE_SCRIPT = "infra/scripts/package-release.sh"
SUPPLY_CHAIN_VERIFIER = "infra/scripts/verify-release-supply-chain.sh"
CI_WORKFLOW = ".github/workflows/ci.yml"
RELEASE_WORKFLOW = ".github/workflows/release-merge-publish.yml"

HASH_PROVENANCE = {
  "lsquic_bootstrap_lock_sha256" => "hash_file('sha256', $lockPath)",
  "lsquic_archive_sha256" => "$readLock('KING_LSQUIC_ARCHIVE_SHA256')",
  "boringssl_archive_sha256" => "$readLock('KING_LSQUIC_BORINGSSL_ARCHIVE_SHA256')",
  "ls_qpack_archive_sha256" => "$readLock('KING_LSQUIC_LS_QPACK_ARCHIVE_SHA256')",
  "ls_hpack_archive_sha256" => "$readLock('KING_LSQUIC_LS_HPACK_ARCHIVE_SHA256')",
}.freeze

DEPENDENCY_LOCK_KEYS = {
  "lsquic" => %w[
    KING_LSQUIC_REPO_URL
    KING_LSQUIC_TAG
    KING_LSQUIC_COMMIT
    KING_LSQUIC_ARCHIVE_URL
    KING_LSQUIC_ARCHIVE_SHA256
    KING_LSQUIC_ARCHIVE_BYTES
    KING_LSQUIC_LICENSE_FILES
  ],
  "boringssl" => %w[
    KING_LSQUIC_BORINGSSL_REPO_URL
    KING_LSQUIC_BORINGSSL_TAG
    KING_LSQUIC_BORINGSSL_COMMIT
    KING_LSQUIC_BORINGSSL_ARCHIVE_URL
    KING_LSQUIC_BORINGSSL_ARCHIVE_SHA256
    KING_LSQUIC_BORINGSSL_ARCHIVE_BYTES
    KING_LSQUIC_BORINGSSL_LICENSE_FILES
  ],
  "ls-qpack" => %w[
    KING_LSQUIC_LS_QPACK_PATH
    KING_LSQUIC_LS_QPACK_REPO_URL
    KING_LSQUIC_LS_QPACK_COMMIT
    KING_LSQUIC_LS_QPACK_ARCHIVE_URL
    KING_LSQUIC_LS_QPACK_ARCHIVE_SHA256
    KING_LSQUIC_LS_QPACK_ARCHIVE_BYTES
    KING_LSQUIC_LS_QPACK_LICENSE_FILES
  ],
  "ls-hpack" => %w[
    KING_LSQUIC_LS_HPACK_PATH
    KING_LSQUIC_LS_HPACK_REPO_URL
    KING_LSQUIC_LS_HPACK_COMMIT
    KING_LSQUIC_LS_HPACK_ARCHIVE_URL
    KING_LSQUIC_LS_HPACK_ARCHIVE_SHA256
    KING_LSQUIC_LS_HPACK_ARCHIVE_BYTES
    KING_LSQUIC_LS_HPACK_LICENSE_FILES
  ],
}.freeze

def fail_check(message)
  warn "Release supply-chain provenance check failed: #{message}"
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
verifier = read_repo(SUPPLY_CHAIN_VERIFIER)
ci_workflow = read_repo(CI_WORKFLOW)
release_workflow = read_repo(RELEASE_WORKFLOW)

require_literal(package_script, "PKG_PROVENANCE_LSQUIC_BOOTSTRAP_LOCK_SHA256", "package lock hash provenance export")
require_literal(package_script, "PKG_LSQUIC_BOOTSTRAP_LOCK_PATH", "package lock path provenance export")
require_literal(package_script, "'dependency_provenance' => $dependencyProvenance", "package dependency provenance manifest")
require_literal(package_script, "'components' => array_keys($dependencyProvenance)", "package HTTP/3 component provenance list")

HASH_PROVENANCE.each do |manifest_key, verifier_expression|
  require_literal(package_script, "'#{manifest_key}'", "package manifest provenance key")
  require_literal(verifier, "'#{manifest_key}' => #{verifier_expression}", "supply-chain verifier provenance pin")
end

DEPENDENCY_LOCK_KEYS.each do |component, lock_keys|
  require_literal(package_script, "'#{component}' => [", "package dependency component #{component}")
  require_literal(verifier, "'#{component}' => [", "verifier dependency component #{component}")

  lock_keys.each do |lock_key|
    require_literal(package_script, "$readLock('#{lock_key}')", "package lock key #{lock_key}")
    require_literal(verifier, "$readLock('#{lock_key}')", "verifier lock key #{lock_key}")
  end
end

%w[ci release].zip([ci_workflow, release_workflow]).each do |label, workflow|
  require_literal(workflow, "../infra/scripts/package-release.sh --verify-reproducible --output-dir ../dist", "#{label} reproducible package step")
  require_literal(workflow, "../infra/scripts/verify-release-supply-chain.sh --archive", "#{label} supply-chain verifier step")
  require_literal(workflow, "--expected-git-commit", "#{label} expected commit binding")
end

puts "Release supply-chain verification checks LSQUIC/BoringSSL provenance pins."
