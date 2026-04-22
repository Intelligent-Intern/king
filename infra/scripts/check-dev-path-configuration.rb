#!/usr/bin/env ruby
# frozen_string_literal: true

ROOT = File.expand_path('../..', __dir__)

def read_repo_file(path)
  File.read(File.join(ROOT, path))
end

def require_literals!(failures, label, source, literals)
  literals.each do |literal|
    failures << "#{label} is missing #{literal}" unless source.include?(literal)
  end
end

config_m4 = read_repo_file('extension/config.m4')
docs = [
  read_repo_file('README.md'),
  read_repo_file('documentation/operations-and-release.md'),
  read_repo_file('documentation/pie-install.md')
].join("\n")
normalized_docs = docs.gsub(/\s+/, ' ')

failures = []

require_literals!(
  failures,
  'extension/config.m4',
  config_m4,
  [
    '--with-king-lsquic[=DIR]',
    '--with-king-boringssl[=DIR]',
    'KING_LSQUIC_CFLAGS',
    'KING_LSQUIC_LIBS',
    'KING_LSQUIC_ROOT',
    'KING_LSQUIC_INCLUDE_DIR',
    'KING_LSQUIC_LIBRARY_DIR',
    'KING_BORINGSSL_CFLAGS',
    'KING_BORINGSSL_LIBS',
    'KING_BORINGSSL_ROOT',
    'KING_BORINGSSL_INCLUDE_DIR',
    'KING_BORINGSSL_SSL_LIBRARY_DIR',
    'KING_BORINGSSL_CRYPTO_LIBRARY_DIR',
    'pkg-config',
    'lsquic',
    'liblsquic'
  ]
)

require_literals!(
  failures,
  'developer dependency documentation',
  normalized_docs,
  [
    'macOS / Dev Dependency Paths',
    'PKG_CONFIG_PATH',
    'KING_LSQUIC_CFLAGS',
    'KING_LSQUIC_LIBS',
    'KING_BORINGSSL_CFLAGS',
    'KING_BORINGSSL_LIBS',
    'KING_LSQUIC_ROOT',
    'KING_BORINGSSL_ROOT',
    'Homebrew/Cellar paths must stay local',
    'infra/scripts/check-dev-path-configuration.rb'
  ]
)

active_files = [
  'extension/config.m4',
  'extension/Makefile.frag',
  'infra/scripts/build-extension.sh',
  'infra/scripts/build-profile.sh',
  'infra/scripts/package-pie-source.sh',
  'infra/scripts/package-release.sh',
  'infra/scripts/static-checks.sh',
  '.github/workflows/ci.yml',
  '.github/workflows/release-merge-publish.yml',
  'README.md',
  'documentation/operations-and-release.md',
  'documentation/pie-install.md'
]

forbidden = {
  '/opt/homebrew' => 'hard-coded Homebrew prefix',
  '/usr/local/Cellar' => 'hard-coded Homebrew Cellar path',
  'brew --prefix' => 'repo build logic must not shell out to Homebrew',
  'HOMEBREW_PREFIX' => 'repo build logic must not depend on Homebrew-specific env'
}

active_files.each do |relative_path|
  source = read_repo_file(relative_path)
  forbidden.each do |literal, reason|
    failures << "#{relative_path} contains #{reason}: #{literal}" if source.include?(literal)
  end
end

if failures.empty?
  puts 'Developer dependency path contract passed.'
  exit 0
end

warn 'Developer dependency path contract failed:'
failures.each { |failure| warn "- #{failure}" }
exit 1
