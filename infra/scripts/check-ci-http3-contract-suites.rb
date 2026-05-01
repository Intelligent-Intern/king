#!/usr/bin/env ruby

require "yaml"

ROOT_DIR = File.expand_path("../..", __dir__)
CI_WORKFLOW = ".github/workflows/ci.yml"
TEST_RUNNER = "infra/scripts/test-extension.sh"
SHARD_TOTAL = 3

CLIENT_CONTRACTS = [
  "tests/190-http3-request-send-roundtrip.phpt",
  "tests/191-oo-http3-client-runtime.phpt",
  "tests/204-http3-connect-timeout-direct-and-dispatch.phpt",
  "tests/338-http3-one-shot-churn-isolation.phpt",
  "tests/377-http3-handshake-failure-contract.phpt",
  "tests/378-http3-transport-close-contract.phpt",
  "tests/380-http3-session-ticket-reuse-contract.phpt",
  "tests/485-http3-timeout-slow-peer-contract.phpt",
  "tests/486-http3-multi-backpressure-contract.phpt",
  "tests/487-http3-sustained-fairness-contract.phpt",
  "tests/488-http3-long-duration-soak-contract.phpt",
  "tests/503-http3-early-data-session-ticket-contract.phpt",
  "tests/504-http3-packet-loss-recovery-contract.phpt",
  "tests/526-http3-quic-session-lifecycle-contract.phpt",
  "tests/527-http3-quic-idle-timeout-and-application-close-contract.phpt",
  "tests/528-http3-quic-stream-lifecycle-contract.phpt",
  "tests/529-http3-quic-reset-and-stop-sending-contract.phpt",
  "tests/530-http3-quic-userland-cancel-propagation-contract.phpt",
  "tests/531-oo-http3-client-public-exception-mapping-contract.phpt",
  "tests/532-http3-quic-event-loop-wake-idle-timeout-contract.phpt",
  "tests/533-http3-quic-congestion-control-constrained-link-contract.phpt",
  "tests/534-http3-quic-flow-control-recovery-contract.phpt",
  "tests/535-http3-quic-zero-rtt-acceptance-and-fallback-contract.phpt",
  "tests/536-oo-http3-client-error-mapping-matrix-contract.phpt",
  "tests/537-http3-quic-stats-live-counter-contract.phpt",
  "tests/538-http3-quic-network-interruption-rewake-contract.phpt",
  "tests/645-http3-quic-stress-and-partial-failure-matrix-contract.phpt",
].freeze

SERVER_CONTRACTS = [
  "tests/384-http3-server-listen-on-wire-runtime.phpt",
  "tests/491-server-request-normalization-real-requests-contract.phpt",
  "tests/492-server-close-drain-restart-contract.phpt",
  "tests/493-server-response-normalization-real-clients-contract.phpt",
  "tests/494-server-cancel-callbacks-real-traffic-contract.phpt",
  "tests/542-server-websocket-http3-local-honesty.phpt",
  "tests/546-server-tls-reload-live-traffic-contract.phpt",
  "tests/547-server-cors-and-header-real-clients-contract.phpt",
  "tests/646-server-http123-on-wire-listener-session-matrix-contract.phpt",
  "tests/679-server-http3-lsquic-loader-contract.phpt",
  "tests/680-server-http3-lsquic-behavior-contract.phpt",
  "tests/681-server-http3-lsquic-lifecycle-contract.phpt",
  "tests/682-server-websocket-http3-onwire-honesty-contract.phpt",
  "tests/683-server-http3-quiche-free-contract.phpt",
].freeze

def fail_check(message)
  warn "CI HTTP/3 contract suite check failed: #{message}"
  exit 1
end

def read_repo(path)
  full_path = File.join(ROOT_DIR, path)
  fail_check("missing #{path}") unless File.file?(full_path)
  File.read(full_path)
end

def load_workflow(path)
  source = read_repo(path)
  data = YAML.safe_load(source, permitted_classes: [], permitted_symbols: [], aliases: false)
  [source, data]
rescue Psych::Exception => error
  fail_check("#{path} is not valid YAML: #{error.message}")
end

def require_literal(source, literal, label)
  fail_check("missing #{label}: #{literal}") unless source.include?(literal)
end

def canonical_baseline_job(workflow)
  jobs = workflow["jobs"]
  fail_check("#{CI_WORKFLOW} has no jobs") unless jobs.is_a?(Hash)

  job = jobs["canonical-baseline"]
  fail_check("#{CI_WORKFLOW} missing canonical-baseline job") unless job.is_a?(Hash)
  job
end

def require_canonical_shards(job)
  shards = job.dig("strategy", "matrix", "shard-index")
  fail_check("canonical-baseline has no shard-index matrix") unless shards.is_a?(Array)

  normalized = shards.map(&:to_i).sort
  expected = (1..SHARD_TOTAL).to_a
  fail_check("canonical-baseline shard matrix must cover #{expected.inspect}, got #{normalized.inspect}") unless normalized == expected
end

def require_runner_contract(source)
  require_literal(source, "find tests -type f -name '*.phpt' | LC_ALL=C sort", "complete PHPT discovery")
  require_literal(source, '"${SHARD_TEST_FILES[@]}"', "sharded PHPT execution")
  require_literal(source, '"${TEST_FILES[@]}"', "full PHPT execution")

  forbidden = [
    "SKIP_HTTP3",
    "NO_HTTP3",
    "grep -v http3",
    "grep -v HTTP3",
    "grep -v HTTP/3",
  ]
  forbidden.each do |literal|
    fail_check("#{TEST_RUNNER} excludes HTTP/3 tests via #{literal}") if source.include?(literal)
  end
end

def require_ci_runs_runner(job)
    steps = job["steps"]
    fail_check("canonical-baseline has no steps") unless steps.is_a?(Array)

  step = steps.find { |candidate| candidate.is_a?(Hash) && candidate["name"] == "Run canonical PHPT suite" }
  fail_check("canonical-baseline does not contain a Run canonical PHPT suite step") unless step.is_a?(Hash)

  env = step["env"]
  fail_check("Run canonical PHPT suite has no env map") unless env.is_a?(Hash)
  fail_check("Run canonical PHPT suite must set SHARD_TOTAL=#{SHARD_TOTAL}") unless env["SHARD_TOTAL"].to_s == SHARD_TOTAL.to_s
  fail_check("Run canonical PHPT suite must use matrix shard index") unless env["SHARD_INDEX"].to_s == "${{ matrix.shard-index }}"

  run = step["run"].to_s
  require_literal(run, "../infra/scripts/test-extension.sh", "canonical PHPT runner")
  require_literal(run, "tee ../compat-artifacts/phpt/canonical-shard-${{ matrix.shard-index }}/run-tests.log", "canonical PHPT shard log capture")
end

def extension_phpt_inventory
  Dir.glob(File.join(ROOT_DIR, "extension/tests/*.phpt"))
    .map { |path| "tests/#{File.basename(path)}" }
    .sort
end

def require_suite_covered(inventory, contracts, suite_name, covered_shards)
  contracts.each do |path|
    index = inventory.index(path)
    fail_check("#{suite_name} contract is missing from extension PHPT inventory: #{path}") if index.nil?

    shard = (index % SHARD_TOTAL) + 1
    fail_check("#{suite_name} contract #{path} maps to uncovered shard #{shard}") unless covered_shards.include?(shard)
  end
end

_workflow_source, workflow = load_workflow(CI_WORKFLOW)
job = canonical_baseline_job(workflow)
require_canonical_shards(job)
require_ci_runs_runner(job)
require_runner_contract(read_repo(TEST_RUNNER))

inventory = extension_phpt_inventory
fail_check("no PHPT inventory found under extension/tests") if inventory.empty?

covered_shards = (1..SHARD_TOTAL).to_a
require_suite_covered(inventory, CLIENT_CONTRACTS, "HTTP/3 client", covered_shards)
require_suite_covered(inventory, SERVER_CONTRACTS, "HTTP/3 server", covered_shards)

puts "CI canonical PHPT shards cover #{CLIENT_CONTRACTS.count} HTTP/3 client and #{SERVER_CONTRACTS.count} HTTP/3 server contracts."
