#!/usr/bin/env ruby

require "yaml"

ROOT_DIR = File.expand_path("../..", __dir__)
CI_WORKFLOW = ".github/workflows/ci.yml"
BUILD_PROFILE = "infra/scripts/build-profile.sh"
CONFIG_M4 = "extension/config.m4"
CLIENT_HTTP3 = "extension/src/client/http3.c"
SERVER_HTTP3 = "extension/src/server/http3.c"

def fail_check(message)
  warn "CI HTTP/3 stack build check failed: #{message}"
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

def workflow_jobs(workflow)
  jobs = workflow["jobs"]
  fail_check("#{CI_WORKFLOW} has no jobs") unless jobs.is_a?(Hash)
  jobs
end

def job_steps(jobs, job_name)
  job = jobs[job_name]
  fail_check("#{CI_WORKFLOW} missing job #{job_name}") unless job.is_a?(Hash)

  steps = job["steps"]
  fail_check("#{CI_WORKFLOW} job #{job_name} has no steps") unless steps.is_a?(Array)
  steps
end

def combined_steps(steps)
  steps.map { |step| step.is_a?(Hash) ? step.values.join("\n") : step.to_s }.join("\n")
end

def require_job_step_literal(jobs, job_name, literal, label)
  require_literal(combined_steps(job_steps(jobs, job_name)), literal, "#{job_name} #{label}")
end

workflow_source, workflow = load_workflow(CI_WORKFLOW)
jobs = workflow_jobs(workflow)
build_profile = read_repo(BUILD_PROFILE)
config_m4 = read_repo(CONFIG_M4)
client_http3 = read_repo(CLIENT_HTTP3)
server_http3 = read_repo(SERVER_HTTP3)

require_job_step_literal(
  jobs,
  "canonical-baseline",
  "../infra/scripts/build-profile.sh release",
  "release profile build"
)
require_job_step_literal(
  jobs,
  "install-package-matrix",
  "../infra/scripts/build-profile.sh release",
  "release package profile build"
)
require_job_step_literal(
  jobs,
  "profile-smoke",
  "../infra/scripts/build-profile.sh \"${{ matrix.profile }}\"",
  "debug/sanitizer profile build"
)
require_job_step_literal(
  jobs,
  "sanitizer-soak",
  "../infra/scripts/build-profile.sh \"${{ matrix.profile }}\"",
  "sanitizer soak profile build"
)

%w[cmake ninja-build pkg-config libssl-dev].each do |package_name|
  require_literal(workflow_source, package_name, "system dependency for LSQUIC/BoringSSL build inputs")
end

require_literal(build_profile, "LSQUIC_BOOTSTRAP_SCRIPT=\"${SCRIPT_DIR}/bootstrap-lsquic.sh\"", "build-profile LSQUIC bootstrap script binding")
require_literal(build_profile, "\"${LSQUIC_BOOTSTRAP_SCRIPT}\" --verify-lock", "build-profile pinned LSQUIC lock verification")
require_literal(build_profile, "\"${LSQUIC_BOOTSTRAP_SCRIPT}\" --verify-current", "build-profile pinned LSQUIC source cache verification")
require_literal(build_profile, "Pinned LSQUIC source cache is missing in CI; bootstrapping pinned source cache.", "build-profile CI source cache bootstrap diagnostic")
require_literal(build_profile, "\"${LSQUIC_BOOTSTRAP_SCRIPT}\"", "build-profile LSQUIC source bootstrap invocation")
require_literal(build_profile, "./configure --enable-king", "build-profile configure entrypoint")

require_literal(config_m4, "PHP_ARG_WITH([king-lsquic]", "config.m4 LSQUIC configure option")
require_literal(config_m4, "PHP_ARG_WITH([king-boringssl]", "config.m4 BoringSSL configure option")
require_literal(config_m4, "AC_DEFINE([KING_HTTP3_BACKEND_LSQUIC]", "config.m4 LSQUIC backend macro")
require_literal(config_m4, "AC_DEFINE([HAVE_KING_BORINGSSL]", "config.m4 BoringSSL macro")
require_literal(config_m4, "lsquic_global_init", "config.m4 LSQUIC symbol check")
require_literal(config_m4, "SSL_CTX_new", "config.m4 TLS library check")

require_literal(client_http3, "#if defined(KING_HTTP3_BACKEND_LSQUIC)", "client HTTP/3 LSQUIC compile guard")
require_literal(client_http3, "#include <lsquic.h>", "client HTTP/3 LSQUIC header")
require_literal(server_http3, "#if defined(KING_HTTP3_BACKEND_LSQUIC)", "server HTTP/3 LSQUIC compile guard")
require_literal(server_http3, "#include <lsquic.h>", "server HTTP/3 LSQUIC header")
require_literal(server_http3, "#include <openssl/ssl.h>", "server HTTP/3 TLS header")

puts "CI builds the HTTP/3 stack through pinned LSQUIC/BoringSSL build-profile gates."
