#!/usr/bin/env ruby

ROOT_DIR = File.expand_path("../..", __dir__)

STATIC_CHECKS = "infra/scripts/static-checks.sh"
CI_WORKFLOW = ".github/workflows/ci.yml"
DEV_PATH_GUARD = "infra/scripts/check-dev-path-configuration.rb"
HTTP3_PRODUCT_GUARD = "infra/scripts/check-http3-product-build-path.rb"
CI_REPRO_GUARD = "infra/scripts/check-ci-linux-reproducible-builds.rb"

def fail_check(message)
  warn "CI migration guardrail check failed: #{message}"
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

def require_literals(source, literals, label)
  literals.each { |literal| require_literal(source, literal, label) }
end

static_checks = read_repo(STATIC_CHECKS)
ci_workflow = read_repo(CI_WORKFLOW)
dev_path_guard = read_repo(DEV_PATH_GUARD)
http3_product_guard = read_repo(HTTP3_PRODUCT_GUARD)
ci_repro_guard = read_repo(CI_REPRO_GUARD)

require_literal(ci_workflow, "../infra/scripts/static-checks.sh", "canonical CI static checks invocation")
require_literal(static_checks, "ruby #{DEV_PATH_GUARD}", "local/Homebrew path static guard")
require_literal(static_checks, "ruby #{HTTP3_PRODUCT_GUARD}", "HTTP/3 Cargo/Quiche product-path static guard")
require_literal(static_checks, "ruby #{CI_REPRO_GUARD}", "CI reproducible build static guard")

require_literals(
  dev_path_guard,
  [
    "/opt/homebrew",
    "/usr/local/Cellar",
    "brew --prefix",
    "HOMEBREW_PREFIX",
    "%r{/home/[A-Za-z0-9._-]+/}",
    "%r{/Users/[A-Za-z0-9._-]+/}",
    "Windows user home absolute path",
  ],
  "developer path guard"
)

cargo_quiche_forbidden = [
  "cargo build",
  "rustup",
  "rustc",
  "bootstrap-quiche.sh",
  "check-quiche-bootstrap.sh",
  "ensure-quiche-toolchain.sh",
  "quiche-bootstrap.lock",
  "quiche-workspace.Cargo.lock",
  "KING_QUICHE_TOOLCHAIN_CONFIRM",
  "KING_QUICHE_LIBRARY",
  "KING_QUICHE_SERVER",
  "toolchain-lock.sh --verify-rust",
  "libquiche.so",
  "quiche-server",
]

require_literals(http3_product_guard, cargo_quiche_forbidden, "HTTP/3 product-path guard")
require_literals(ci_repro_guard, cargo_quiche_forbidden - ["rustup", "rustc"], "CI reproducible build guard")

puts "CI blocks local absolute paths, Homebrew paths, Cargo HTTP/3 bootstrap, and Quiche locks."
