# King Sprint Issues

> Sprint: Q-Batch, 2026-04-22
> Focus: remove Quiche from the active HTTP/3/QUIC product path and replace it with a clean, reproducible C-based stack.

## Sprint Rules

- This document contains only open sprint work.
- Completed issues are removed in the commit that closes them.
- Quiche is removed from the active HTTP/3/QUIC product path.
- The existing King v1 contract remains intact: HTTP/3, QUIC, TLS, session tickets, 0-RTT, stream lifecycle, cancel, stats, listener, and WebSocket-over-HTTP3.
- No stub replacement: a new loader must bind real runtime symbols, initialize them, and be proven by wire tests.
- No local paths, especially no Homebrew paths such as `/opt/homebrew/Cellar/...`.
- No Quiche-driven Rust/Cargo bootstrap in the HTTP/3 product path.
- No generated test results, build trees, libtool/phpize churn, or local lockfiles as sprint output.
- Contributor credits are preserved: matching commits are cherry-picked; manual ports get a source-branch reference and `Co-authored-by` once the author is identified.

## Open Issues

### #Q-4 Build System Without Local Paths And Without Cargo Bootstrap

Goal:
- Move `config.m4`, build scripts, CI, and release builds to the new C-based stack.

Checklist:
- [x] Update `extension/config.m4` to portable detection: pkg-config, env overrides, system paths, or vendored build outputs.
- [x] Remove or replace Quiche scripts: `bootstrap-quiche.sh`, `check-quiche-bootstrap.sh`, `ensure-quiche-toolchain.sh`.
- [x] Remove Cargo/Rust bootstrap from the HTTP/3 build path.
- [x] Make CI build reproducibly on Linux amd64 and arm64.
- [x] Support macOS/dev only through documented env/pkg-config paths.
- [x] Update release package manifests for new artifacts and new provenance.

Done:
- [x] Fresh HTTP/3 build needs no local Rust/Cargo configuration.
- [x] Fresh HTTP/3 build needs no local Homebrew paths.
- [x] CI blocks Quiche/Cargo bootstrap in the active HTTP/3 path.

Detection Contract:

- `extension/config.m4` exposes `--with-king-lsquic[=DIR]` and `--with-king-boringssl[=DIR]`.
- LSQUIC can be configured via `KING_LSQUIC_CFLAGS/KING_LSQUIC_LIBS`, `KING_LSQUIC_ROOT`, explicit include/library dirs, pkg-config (`lsquic` or `liblsquic`), or default system paths.
- BoringSSL can be configured via `KING_BORINGSSL_CFLAGS/KING_BORINGSSL_LIBS`, `KING_BORINGSSL_ROOT`, explicit include/library dirs, or default system paths.
- `config.m4` no longer injects an in-tree `../quiche/quiche/include` path.

Bootstrap Contract:

- `infra/scripts/bootstrap-lsquic.sh` is the active deterministic source bootstrap entrypoint.
- `infra/scripts/check-lsquic-bootstrap.sh` validates LSQUIC, BoringSSL, ls-qpack, and ls-hpack against pinned archives.
- The old Quiche script entrypoints are removed from the active tree.

Build Path Contract:

- `infra/scripts/build-profile.sh` stages `king.so` without invoking Cargo, Rust, or Quiche runtime artifact builds.
- `extension/Makefile.frag` no longer extends `all` or `install` with Quiche runtime targets.
- Release packages no longer require or ship `runtime/libquiche.so` or `runtime/quiche-server`.

CI Reproducibility Contract:

- `install-package-matrix` and `build-release-packages` cover PHP 8.1-8.5 on `linux-amd64` and `linux-arm64`.
- Both package jobs run `package-release.sh --verify-reproducible` before upload.
- `infra/scripts/check-ci-linux-reproducible-builds.rb` blocks missing Linux arch rows or Rust/Cargo bootstrap in the product package jobs.

macOS/Dev Path Contract:

- macOS/dev builds are documented through `PKG_CONFIG_PATH`, `KING_LSQUIC_*`, and `KING_BORINGSSL_*` overrides only.
- Active build scripts, CI workflows, and user-facing build docs must not commit local Homebrew/Cellar dependency paths.
- `infra/scripts/check-dev-path-configuration.rb` enforces the documented path contract from `static-checks.sh`.

Release Manifest Contract:

- `manifest.json` records the active HTTP/3 stack as `lsquic` + `boringssl` and identifies `modules/king.so` as the PHP extension artifact.
- `manifest.json` carries LSQUIC, BoringSSL, ls-qpack, and ls-hpack archive hashes plus dependency provenance from `infra/scripts/lsquic-bootstrap.lock`.
- `verify-release-package.sh` validates the modern manifest shape, and `verify-release-supply-chain.sh` compares it against the local pinned lock.

Fresh Build Contract:

- PIE/user-facing install docs no longer require Rust, Cargo, `libquiche.so`, `quiche-server`, or `KING_QUICHE_TOOLCHAIN_CONFIRM`.
- `infra/scripts/check-http3-product-build-path.rb` blocks Rust/Cargo/Quiche runtime bootstrap from active HTTP/3 build, release, CI, and install docs.
- The remaining allowed Cargo/Quiche strings in source-packaging are artifact-exclusion hygiene lines only.

Fresh Local Path Contract:

- `documentation/pie-install.md` now follows the same Homebrew/Cellar rule as README and operations docs.
- `infra/scripts/check-dev-path-configuration.rb` scans the fresh PIE/user install path for local Homebrew prefixes and Homebrew-specific env assumptions.

CI Guard Contract:

- `infra/scripts/check-ci-linux-reproducible-builds.rb` blocks Cargo build steps, old Quiche bootstrap entrypoints, Quiche locks, and Quiche runtime artifacts in package/release workflows.
- The CI workflow runs `static-checks.sh`, and `static-checks.sh` runs `check-http3-product-build-path.rb`, so workflow changes cannot bypass the active HTTP/3 product-path guard.

---

### #Q-5 Replace Client HTTP/3 Loader

Goal:
- Replace `extension/src/client/http3/quiche_loader.inc` with a real loader for the new stack.

Checklist:
- [x] Implement a new loader with real symbol binding and initialization.
- [x] Prevent failure stubs or fake feature checks.
- [x] Map error paths to existing King exceptions.
- [x] Bind the real LSQUIC request-runtime symbol surface for settings, packet ingress, stream headers, stream contexts, and stats.
- [x] Add the LSQUIC runtime adapter for engine init, UDP egress, stream callbacks, ticket seed/publish, destroy, and live-counter refresh.
- [x] Add the LSQUIC request callback bridge for header encoding, body streaming, response header decode, body read, and request stream creation.
- [x] Wire the one-shot `king_http3_request_send()` dispatch loop behind the configured LSQUIC backend.
- [x] Move LSQUIC request lifecycle state onto stream-owned request contexts for future multi-stream dispatch.
- [x] Wire runtime init, request/response, multi-request, ticket reuse, and stats.
- [x] Remove or migrate old Quiche symbols, handles, and runtime names.

Done:
- [x] `extension/src/client/http3/lsquic_loader.inc` binds LSQUIC symbols via `dlsym()` and initializes client globals via `lsquic_global_init`.
- [x] `infra/scripts/check-http3-lsquic-loader-contract.php` runs in static checks and rejects placeholder success paths or compile-flag-only readiness.
- [x] LSQUIC library, symbol, and global-init loader failures map through existing King exception classes via `king_http3_throw_lsquic_unavailable()`.
- [x] LSQUIC runtime binding now uses the official packet-in, header, context, stream-shutdown, settings, and live-counter API surface instead of stale intermediate signatures.
- [x] `extension/src/client/http3/lsquic_runtime.inc` owns the real LSQUIC engine API wiring and callback bridge, including session resume information into the King ticket ring.
- [x] The LSQUIC request bridge now drives `lsquic_conn_make_stream`, `lsquic_stream_send_headers`, `lsquic_stream_write`, `lsquic_stream_read`, and the header-set decode interface.
- [x] `king_http3_request_send()` now has a LSQUIC one-shot dispatch path with shared UDP socket init, packet ingress, egress processing, cancel close, response materialization, and LSQUIC backend metadata.
- [x] LSQUIC stream callbacks now resolve a `king_http3_lsquic_request_state_t` from stream context instead of reading mutable request fields from the shared runtime.
- [x] The active LSQUIC client path now drives one-shot and multi-request dispatch through shared runtime init, a stream-state queue, ticket reuse, transport stats, and response materialization.
- [x] The active LSQUIC client path uses King-owned HTTP/3 request headers and excludes Quiche headers, handles, loader, init, dispatch, ticket, and stats code via backend guards.
- [x] `king_http3_request_send()` uses the new stack in real wire tests.
- [x] `extension/tests/190-http3-request-send-roundtrip.phpt` now loads `KING_LSQUIC_LIBRARY`, exercises `king_http3_request_send()` on the wire, and asserts `transport_backend = lsquic_h3`.
- [x] OO HTTP3 client uses the new stack in real wire tests.
- [x] `extension/tests/191-oo-http3-client-runtime.phpt` now loads `KING_LSQUIC_LIBRARY`, checks a same-fixture `lsquic_h3` warmup, and exercises `King\Client\Http3Client` on the wire.
- [x] Old Quiche loader is no longer referenced by any active include.

---

### #Q-6 Replace Server HTTP/3 Listener

Goal:
- Replace server-side Quiche assumptions with the new stack.

Checklist:
- [x] Implement a server loader with real initialization.
- [x] Move `king_http3_server_listen_once` and listener paths to the new runtime context.
- [x] Prove request headers, body drain, early hints, response normalization, and CORS behavior stay unchanged.
- [x] Preserve TLS reload, cancel, and shutdown paths.
- [x] Keep WebSocket-over-HTTP3 honesty slices covered.

Done:
- [x] `extension/src/server/http3/lsquic_loader.inc` binds real LSQUIC server symbols via `dlsym()` and initializes server globals with `lsquic_global_init(KING_LSQUIC_GLOBAL_SERVER)`.
- [x] `infra/scripts/check-http3-lsquic-loader-contract.php` now checks the server LSQUIC loader for real symbol binding, initialization, and non-placeholder readiness.
- [x] `king_http3_server_listen_once` dispatches LSQUIC builds through a server runtime context with LSQUIC engine settings, TLS context, stream callbacks, packet ingress, egress, and response writes.
- [x] `extension/tests/680-server-http3-lsquic-behavior-contract.phpt` proves the LSQUIC listener keeps shared request header normalization, body-FIN gating, early-hints session state, response normalization, and CORS semantics.
- [x] `extension/tests/681-server-http3-lsquic-lifecycle-contract.phpt` covers TLS option/reload semantics, LSQUIC cancel bridging, premature close handling, and shutdown cleanup without leaking unowned listener sockets.
- [x] `extension/tests/682-server-websocket-http3-onwire-honesty-contract.phpt` keeps local HTTP/3 WebSocket upgrades explicit while rejecting fake on-wire HTTP/3 Extended CONNECT upgrades.
- [x] `extension/tests/384-http3-server-listen-on-wire-runtime.phpt` now targets LSQUIC-built server listeners with real direct and dispatcher HTTP/3 clients, asserting `lsquic_h3` client responses and `server_http3_lsquic_socket` server captures.
- [x] `extension/tests/683-server-http3-quiche-free-contract.phpt` proves the server HTTP/3 path has no Quiche headers, loader, event loop, runtime handles, or fallback dispatch and fails closed unless built with LSQUIC.

---

### #Q-7 QUIC Options, Stats, And Semantic Mapping

Goal:
- Correctly map existing `quic.*` configurations and live stats to the new stack.

Checklist:
- [x] `extension/tests/684-quic-option-inventory-contract.phpt` inventories all 24 `quic.*` options across defaults, php.ini directives, userland overrides, key routing, `King\Config` reads/exports, HTTP/3 option snapshots, and current LSQUIC server settings.
- [x] `extension/tests/685-quic-lsquic-option-diagnostics-contract.phpt` proves LSQUIC client/server paths apply supported `quic.*` settings, reject unsupported non-default settings with explicit diagnostics, and validate before engine settings are accepted.
- [x] `extension/tests/686-client-http3-lsquic-live-stats-contract.phpt` proves LSQUIC client response packet stats bind to `lsquic_conn_get_info()` counters instead of placeholder loss/retransmit zeros.
- [x] `extension/tests/687-http3-lsquic-option-runtime-contract.phpt` proves LSQUIC client/server runtime mapping for congestion control, pacing, flow-control transport parameters, and idle timeout before settings validation.
- [x] `extension/tests/688-client-http3-lsquic-byte-stats-contract.phpt` proves LSQUIC lost/retransmit byte response stats are derived from live connection byte and packet counters instead of stale bookkeeping or permanent zero placeholders.

Done:
- [x] `extension/tests/147-oo-config-parity.phpt`, `148-oo-config-policy-and-read-slice.phpt`, and `684`-`688` pass together as the config/stats proof set for the LSQUIC HTTP/3 stack.
- [x] `PROJECT_ASSESSMENT.md` and `READYNESS_TRACKER.md` name LSQUIC/BoringSSL and `lsquic_conn_get_info()` as the active QUIC stats/bootstrap surface instead of legacy counter or poll-loop wording.

---

### #Q-8 HTTP/3 Test Peer Harness Without Quiche/Cargo Dependency

Goal:
- Migrate HTTP/3 tests to the new stack without Quiche/Cargo bootstrap.

Checklist:
- [x] `extension/tests/http3_rust_peer_classification.inc` and `689-http3-rust-peer-classification-contract.phpt` classify every tracked HTTP/3 Rust peer and Cargo lock as temporary, non-product-bootstrap test context.
- [x] `extension/tests/http3_peer_replacement_strategy.inc` and `690-http3-peer-replacement-strategy-contract.phpt` choose repo-owned C/LSQUIC test helpers with King-owned listeners only where equivalent, no CI binary artifacts, no Rust/Cargo bootstrap.
- [x] `extension/tests/http3_behavior_preservation_matrix.inc` and `691-http3-behavior-preservation-matrix-contract.phpt` preserve the required HTTP/3 behavior regression matrix across 8 behaviors and 16 PHPTs.
- [x] `infra/scripts/build-http3-test-helpers.sh`, `static-checks.sh`, and `692-http3-test-helper-deterministic-build-contract.phpt` build C helper binaries reproducibly under `.cache` and keep build leftovers out of Git.
- [x] `extension/tests/http3_skip_rule_audit.inc` and `693-http3-skip-rule-audit-contract.phpt` audit all behavior-matrix skip rules and block final success if legacy Quiche/Cargo gates return.

Done:
- [x] HTTP/3 tests prove the new stack without Quiche or Cargo bootstrap.
- [x] Temporary Rust helpers are not product bootstrap and have an expiry issue.

---

### #Q-9 Remove Quiche From Source, Scripts, And Docs

Goal:
- Fully remove Quiche as an active dependency.

Checklist:
- [x] Remove `extension/src/**/quiche_loader.inc` and fail closed without a Quiche loader fallback.
- [x] Remove or replace Quiche-specific build scripts, locks, and docs.
- [x] Update `README.md`, `PROJECT_ASSESSMENT.md`, `READYNESS_TRACKER.md`, `DEPENDENCY_PROVENANCE.md`, and `documentation/quic-and-tls.md`.
- [x] Mark remaining Quiche mentions as historical notes or remove them.
- [x] Extend artifact hygiene gate for Quiche/Cargo artifacts.

Done:
- [x] `rg -n "quiche|QUICHE"` finds no active product-path references.
- [x] Remaining `rg -n "quiche|QUICHE"` matches are classified as historical migration notes, release history, guard literals, contract-test literals, or temporary test fixtures.

---

### #Q-10 CI, Release, And Supply Chain Gates

Goal:
- Permanently protect the migration with CI and release gates.

Checklist:
- [x] CI builds the new stack.
- [x] CI runs HTTP/3 client/server contract suites.
- [ ] CI blocks local absolute paths, Homebrew paths, Cargo HTTP/3 bootstrap, and Quiche locks.
- [ ] Release supply-chain verification checks new provenance pins.
- [ ] Package manifests contain new dependency hashes and no Quiche manifests.

Done:
- [ ] A PR cannot silently bring back Quiche or local paths.
- [ ] Release artifacts are reproducible and traceable for the new stack.

---

### #Q-11 Full HTTP/3 Regression Against New Stack

Goal:
- Prove the new stack carries the previous HTTP/3/QUIC contract.

Checklist:
- [ ] Client one-shot request/response tests are green.
- [ ] OO `Http3Client` exception matrix is green.
- [ ] Server one-shot listener tests are green.
- [ ] Session-ticket and 0-RTT tests are green.
- [ ] Stream lifecycle, reset, stop-sending, cancel, and timeout tests are green.
- [ ] Packet loss, retransmit, congestion control, flow control, and long-duration soak are green.
- [ ] WebSocket-over-HTTP3 relevant slices are green.
- [ ] Performance baseline against the previous Quiche state is documented.

Done:
- [ ] New stack is proven at the existing contract level.
- [ ] Deviations are fixed or registered as new blocker issues.

---

### #Q-12 Migration Closure And Repo Cleanup

Goal:
- Close the sprint cleanly: no leftover artifacts, no half-renamed paths, no old build assumptions.

Checklist:
- [ ] Complete `rg` sweep for Quiche, Cargo, Rust-HTTP3, local paths, and stub loaders.
- [ ] `git status` contains no generated build or test artifacts.
- [ ] Docs, tests, CI, and release manifests reference the same new stack.
- [ ] Add closure note to `PROJECT_ASSESSMENT.md` and `READYNESS_TRACKER.md` with test evidence.
- [ ] Split migration work into logical commits: inventory, build, client, server, tests, docs/cleanup.

Done:
- [ ] Quiche is removed from the active product path.
- [ ] HTTP/3/QUIC is fully proven on the new stack.
- [ ] Repository state is artifact-clean and release-ready.
