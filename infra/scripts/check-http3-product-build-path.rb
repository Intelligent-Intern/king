#!/usr/bin/env ruby
# frozen_string_literal: true

ROOT = File.expand_path('../..', __dir__)

def read_repo_file(path)
  File.read(File.join(ROOT, path))
end

active_files = [
  'README.md',
  'documentation/operations-and-release.md',
  'documentation/pie-install.md',
  'extension/Makefile.frag',
  'infra/scripts/build-extension.sh',
  'infra/scripts/build-profile.sh',
  'infra/scripts/package-pie-source.sh',
  'infra/scripts/package-release.sh',
  'infra/scripts/smoke-profile.sh',
  '.github/workflows/ci.yml',
  '.github/workflows/release-merge-publish.yml'
]

forbidden = [
  'cargo build',
  'rustup',
  'rustc',
  'bootstrap-quiche.sh',
  'check-quiche-bootstrap.sh',
  'ensure-quiche-toolchain.sh',
  'quiche-bootstrap.lock',
  'quiche-workspace.Cargo.lock',
  'KING_QUICHE_TOOLCHAIN_CONFIRM',
  'KING_QUICHE_LIBRARY',
  'KING_QUICHE_SERVER',
  'toolchain-lock.sh --verify-rust',
  'libquiche.so',
  'quiche-server'
]

allowed_lines = [
  "    --exclude='./.cargo' \\",
  "    --exclude='./extension/quiche/target' \\",
  "    --exclude='./quiche/target' \\",
  '# The active King extension build must not bootstrap Quiche or Cargo artifacts.'
]

failures = []

active_files.each do |relative_path|
  read_repo_file(relative_path).lines.each_with_index do |line, index|
    next if allowed_lines.include?(line.chomp)

    forbidden.each do |literal|
      failures << "#{relative_path}:#{index + 1} contains #{literal}" if line.include?(literal)
    end
  end
end

pie_doc = read_repo_file('documentation/pie-install.md').gsub(/\s+/, ' ')
[
  'No Rust or Cargo toolchain is required for this PIE path.',
  'LSQUIC/BoringSSL provenance',
  'infra/scripts/lsquic-bootstrap.lock'
].each do |literal|
  failures << "documentation/pie-install.md is missing #{literal}" unless pie_doc.include?(literal)
end

if failures.empty?
  puts 'HTTP/3 product build path is free of Rust/Cargo bootstrap configuration.'
  exit 0
end

warn 'HTTP/3 product build path check failed:'
failures.each { |failure| warn "- #{failure}" }
exit 1
