#!/usr/bin/env ruby

require "yaml"

ROOT_DIR = File.expand_path("../..", __dir__)
PHP_VERSIONS = %w[8.1 8.2 8.3 8.4 8.5].freeze
ARCH_RUNNERS = {
  "linux-amd64" => "ubuntu-24.04",
  "linux-arm64" => "ubuntu-24.04-arm",
}.freeze
FORBIDDEN_PRODUCT_BOOTSTRAP = [
  "Setup Rust pinned toolchain",
  "Verify pinned Rust toolchain",
  "Cache cargo registry",
  "dtolnay/rust-toolchain",
  "toolchain-lock.sh --verify-rust",
  "cargo --version",
].freeze
WORKFLOWS = {
  "ci" => {
    path: ".github/workflows/ci.yml",
    job: "install-package-matrix",
  },
  "release" => {
    path: ".github/workflows/release-merge-publish.yml",
    job: "build-release-packages",
  },
}.freeze


def fail_check(message)
  warn "CI Linux reproducible build check failed: #{message}"
  exit 1
end


def load_workflow(path)
  full_path = File.join(ROOT_DIR, path)
  fail_check("missing workflow #{path}") unless File.file?(full_path)

  source = File.read(full_path)
  data = YAML.safe_load(source, permitted_classes: [], permitted_symbols: [], aliases: false)
  [source, data]
rescue Psych::Exception => error
  fail_check("#{path} is not valid YAML: #{error.message}")
end


def require_literal(source, literal, label)
  fail_check("missing #{label}: #{literal}") unless source.include?(literal)
end


def require_forbidden_absent(source, path)
  FORBIDDEN_PRODUCT_BOOTSTRAP.each do |literal|
    fail_check("#{path} reintroduced product-path Rust/Cargo bootstrap: #{literal}") if source.include?(literal)
  end
end


def require_release_matrix(path, job)
  include_rows = job.dig("strategy", "matrix", "include")
  fail_check("#{path} release job has no strategy.matrix.include") unless include_rows.is_a?(Array)

  PHP_VERSIONS.each do |php_version|
    ARCH_RUNNERS.each do |arch_label, runner|
      found = include_rows.any? do |row|
        row.is_a?(Hash) &&
          row["php-version"].to_s == php_version &&
          row["arch-label"].to_s == arch_label &&
          row["runner"].to_s == runner
      end

      fail_check("#{path} missing reproducible package matrix entry for PHP #{php_version} #{arch_label} on #{runner}") unless found
    end
  end
end


def require_job_steps(path, job)
  steps = job["steps"]
  fail_check("#{path} release job has no steps") unless steps.is_a?(Array)

  combined = steps.map { |step| step.is_a?(Hash) ? step.values.join("\n") : step.to_s }.join("\n")
  require_literal(combined, "../infra/scripts/build-profile.sh release", "release profile build step")
  require_literal(combined, "../infra/scripts/package-release.sh --verify-reproducible --output-dir ../dist", "byte-reproducible package step")
  require_literal(combined, "../infra/scripts/verify-release-supply-chain.sh --archive", "supply-chain verification step")
  require_literal(combined, "../infra/scripts/install-package-matrix.sh --archive", "clean-host install verification step")
  require_literal(combined, "king-release-package-php${{ matrix.php-version }}-${{ matrix.arch-label }}", "arch-specific release artifact upload")
end

WORKFLOWS.each_value do |config|
  path = config.fetch(:path)
  source, data = load_workflow(path)
  require_forbidden_absent(source, path)

  jobs = data["jobs"]
  fail_check("#{path} has no jobs") unless jobs.is_a?(Hash)

  job_name = config.fetch(:job)
  job = jobs[job_name]
  fail_check("#{path} missing job #{job_name}") unless job.is_a?(Hash)

  require_release_matrix(path, job)
  require_job_steps(path, job)
end

puts "CI Linux reproducible build matrix covers PHP #{PHP_VERSIONS.join(', ')} on linux-amd64 and linux-arm64 without Rust/Cargo product bootstrap."
