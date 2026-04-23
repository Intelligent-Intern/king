#!/usr/bin/env ruby

require "yaml"

ROOT_DIR = File.expand_path("../..", __dir__)
CI_WORKFLOW = ".github/workflows/ci.yml"
BUILD_PROFILE = "infra/scripts/build-profile.sh"
BUILD_LSQUIC_RUNTIME = "infra/scripts/build-lsquic-runtime.sh"
TEST_EXTENSION = "infra/scripts/test-extension.sh"
CI_SYSTEM_DEPS = "infra/scripts/install-ci-system-dependencies.sh"
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
build_lsquic_runtime = read_repo(BUILD_LSQUIC_RUNTIME)
test_extension = read_repo(TEST_EXTENSION)
ci_system_deps = read_repo(CI_SYSTEM_DEPS)
config_m4 = read_repo(CONFIG_M4)
client_http3 = read_repo(CLIENT_HTTP3)
server_http3 = read_repo(SERVER_HTTP3)

require_job_step_literal(
  jobs,
  "lsquic-runtime",
  "./infra/scripts/build-lsquic-runtime.sh",
  "pinned runtime build"
)
require_job_step_literal(
  jobs,
  "lsquic-runtime",
  "actions/upload-artifact@v6",
  "runtime upload artifact"
)
require_job_step_literal(
  jobs,
  "canonical-baseline",
  "actions/download-artifact@v7",
  "runtime download artifact"
)
require_job_step_literal(
  jobs,
  "canonical-baseline",
  "KING_LSQUIC_RUNTIME_PREFIX",
  "runtime prefix build env"
)
require_job_step_literal(
  jobs,
  "canonical-baseline",
  "KING_LSQUIC_LIBRARY",
  "runtime library PHPT env"
)
require_job_step_literal(
  jobs,
  "canonical-baseline",
  "../infra/scripts/build-profile.sh release",
  "release profile build"
)
require_job_step_literal(
  jobs,
  "install-package-matrix",
  "actions/download-artifact@v7",
  "runtime download artifact"
)
require_job_step_literal(
  jobs,
  "install-package-matrix",
  "KING_LSQUIC_RUNTIME_PREFIX",
  "runtime prefix package build env"
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
require_literal(workflow_source, "./infra/scripts/install-ci-system-dependencies.sh", "shared CI system dependency installer")
require_literal(workflow_source, "king-lsquic-runtime-${{ matrix.arch-label }}", "architecture-specific LSQUIC runtime artifact")

%w[cmake ninja-build pkg-config libssl-dev zlib1g-dev].each do |package_name|
  require_literal(ci_system_deps, package_name, "system dependency for LSQUIC/BoringSSL build inputs")
end

require_literal(build_profile, "LSQUIC_BOOTSTRAP_SCRIPT=\"${SCRIPT_DIR}/bootstrap-lsquic.sh\"", "build-profile LSQUIC bootstrap script binding")
require_literal(build_profile, "LSQUIC_RUNTIME_SCRIPT=\"${SCRIPT_DIR}/build-lsquic-runtime.sh\"", "build-profile LSQUIC runtime script binding")
require_literal(build_profile, "\"${LSQUIC_BOOTSTRAP_SCRIPT}\" --verify-lock", "build-profile pinned LSQUIC lock verification")
require_literal(build_profile, "\"${LSQUIC_BOOTSTRAP_SCRIPT}\" --verify-current", "build-profile pinned LSQUIC source cache verification")
require_literal(build_profile, "Pinned LSQUIC source cache is missing in CI; bootstrapping pinned source cache.", "build-profile CI source cache bootstrap diagnostic")
require_literal(build_profile, "\"${LSQUIC_BOOTSTRAP_SCRIPT}\"", "build-profile LSQUIC source bootstrap invocation")
require_literal(build_profile, "KING_LSQUIC_INCLUDE_DIR=\"${lsquic_runtime_prefix}/include/lsquic\"", "build-profile LSQUIC runtime include path")
require_literal(build_profile, "KING_LSQUIC_LIBRARY_DIR=\"${lsquic_runtime_prefix}/lib\"", "build-profile LSQUIC runtime library path")
require_literal(build_profile, "KING_BORINGSSL_CFLAGS=\"-DKING_BORINGSSL_STATIC_LINK=1\"", "build-profile BoringSSL static-link marker")
require_literal(build_profile, "KING_BORINGSSL_LIBS=\"-Wl,--exclude-libs,ALL ${lsquic_runtime_prefix}/boringssl/lib/libssl.a ${lsquic_runtime_prefix}/boringssl/lib/libcrypto.a -lstdc++\"", "build-profile pinned BoringSSL static libraries")
require_literal(build_profile, "stage_lsquic_runtime", "build-profile staged runtime copy")
require_literal(build_profile, "./configure --enable-king", "build-profile configure entrypoint")

require_literal(build_lsquic_runtime, "KING_LSQUIC_RUNTIME_PREFIX", "runtime builder configurable prefix")
require_literal(build_lsquic_runtime, "KING_LSQUIC_RUNTIME_LOCK_SHA256", "runtime builder lock metadata")
require_literal(build_lsquic_runtime, "-DLSQUIC_SHARED_LIB=OFF", "runtime builder static LSQUIC archive path")
require_literal(build_lsquic_runtime, "-Wl,--whole-archive", "runtime builder BoringSSL static link")
require_literal(build_lsquic_runtime, "include/boringssl", "runtime builder BoringSSL header staging")
require_literal(build_lsquic_runtime, "boringssl/lib/libssl.a", "runtime builder BoringSSL ssl archive staging")
require_literal(build_lsquic_runtime, "boringssl/lib/libcrypto.a", "runtime builder BoringSSL crypto archive staging")
require_literal(build_lsquic_runtime, "liblsquic.so", "runtime builder shared artifact")
require_literal(build_lsquic_runtime, "unresolved BoringSSL symbols", "runtime builder fail-closed symbol audit")

require_literal(test_extension, "PROFILE_RUNTIME_DIR=\"${EXT_DIR}/build/profiles/release/runtime\"", "PHPT runner profile runtime directory")
require_literal(test_extension, "KING_LSQUIC_LIBRARY=\"${PROFILE_RUNTIME_DIR}/liblsquic.so\"", "PHPT runner LSQUIC library fallback")
require_literal(test_extension, "LD_LIBRARY_PATH", "PHPT runner LSQUIC dynamic loader path")

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
