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
- [ ] Remove Cargo/Rust bootstrap from the HTTP/3 build path.
- [ ] Make CI build reproducibly on Linux amd64 and arm64.
- [ ] Support macOS/dev only through documented env/pkg-config paths.
- [ ] Update release package manifests for new artifacts and new provenance.

Done:
- [ ] Fresh HTTP/3 build needs no local Rust/Cargo configuration.
- [ ] Fresh HTTP/3 build needs no local Homebrew paths.
- [ ] CI blocks Quiche/Cargo bootstrap in the active HTTP/3 path.

Detection Contract:

- `extension/config.m4` exposes `--with-king-lsquic[=DIR]` and `--with-king-boringssl[=DIR]`.
- LSQUIC can be configured via `KING_LSQUIC_CFLAGS/KING_LSQUIC_LIBS`, `KING_LSQUIC_ROOT`, explicit include/library dirs, pkg-config (`lsquic` or `liblsquic`), or default system paths.
- BoringSSL can be configured via `KING_BORINGSSL_CFLAGS/KING_BORINGSSL_LIBS`, `KING_BORINGSSL_ROOT`, explicit include/library dirs, or default system paths.
- `config.m4` no longer injects an in-tree `../quiche/quiche/include` path.

Bootstrap Contract:

- `infra/scripts/bootstrap-lsquic.sh` is the active deterministic source bootstrap entrypoint.
- `infra/scripts/check-lsquic-bootstrap.sh` validates LSQUIC, BoringSSL, ls-qpack, and ls-hpack against pinned archives.
- The old Quiche script entrypoints are removed from the active tree.

---

### #Q-5 Replace Client HTTP/3 Loader

Goal:
- Replace `extension/src/client/http3/quiche_loader.inc` with a real loader for the new stack.

Checklist:
- [ ] Implement a new loader with real symbol binding and initialization.
- [ ] Prevent failure stubs or fake feature checks.
- [ ] Map error paths to existing King exceptions.
- [ ] Wire runtime init, request/response, multi-request, ticket reuse, and stats.
- [ ] Remove or migrate old Quiche symbols, handles, and runtime names.

Done:
- [ ] `king_http3_request_send()` uses the new stack in real wire tests.
- [ ] OO HTTP3 client uses the new stack in real wire tests.
- [ ] Old Quiche loader is no longer referenced by any active include.

---

### #Q-6 Replace Server HTTP/3 Listener

Goal:
- Replace server-side Quiche assumptions with the new stack.

Checklist:
- [ ] Implement a server loader with real initialization.
- [ ] Move `king_http3_server_listen_once` and listener paths to the new runtime context.
- [ ] Prove request headers, body drain, early hints, response normalization, and CORS behavior stay unchanged.
- [ ] Preserve TLS reload, cancel, and shutdown paths.
- [ ] Keep WebSocket-over-HTTP3 honesty slices covered.

Done:
- [ ] HTTP/3 server listeners run on the new stack against real clients/peers.
- [ ] No server path needs Quiche code.

---

### #Q-7 QUIC Options, Stats, And Semantic Mapping

Goal:
- Correctly map existing `quic.*` configurations and live stats to the new stack.

Checklist:
- [ ] Inventory and map all `quic.*` options.
- [ ] Handle unsupported options fail-closed or with explicit diagnostics.
- [ ] Bind live stats to real runtime counters.
- [ ] Verify congestion control, pacing, flow control, and idle timeout.
- [ ] Prevent stale bookkeeping fields or permanently zeroed counters.

Done:
- [ ] Existing stats and config tests stay green or have equally strong new proof.
- [ ] Documentation names the new stack, not Quiche counters.

---

### #Q-8 HTTP/3 Test Peer Harness Without Quiche/Cargo Dependency

Goal:
- Migrate HTTP/3 tests to the new stack without Quiche/Cargo bootstrap.

Checklist:
- [ ] Classify Rust test peers and Cargo locks in the HTTP/3 context.
- [ ] Choose replacement strategy: C helper, King-owned listeners, CI artifacts with provenance, or another reproducible path.
- [ ] Preserve tests for handshake failure, transport close, timeout, flow control, packet loss, 0-RTT, session tickets, and multi-stream fairness.
- [ ] Build helper binaries deterministically and do not commit build leftovers.
- [ ] Audit skip rules so a missing new stack cannot count as success.

Done:
- [ ] HTTP/3 tests prove the new stack without Quiche or Cargo bootstrap.
- [ ] Temporary Rust helpers are not product bootstrap and have an expiry issue.

---

### #Q-9 Remove Quiche From Source, Scripts, And Docs

Goal:
- Fully remove Quiche as an active dependency.

Checklist:
- [ ] Remove `extension/src/**/quiche_loader.inc`.
- [ ] Remove or replace Quiche-specific build scripts, locks, and docs.
- [ ] Update `README.md`, `PROJECT_ASSESSMENT.md`, `READYNESS_TRACKER.md`, `DEPENDENCY_PROVENANCE.md`, and `documentation/quic-and-tls.md`.
- [ ] Mark remaining Quiche mentions as historical notes or remove them.
- [ ] Extend artifact hygiene gate for Quiche/Cargo artifacts.

Done:
- [ ] `rg -n "quiche|QUICHE"` finds no active product-path references.
- [ ] Remaining matches are only historical migration notes or release history.

---

### #Q-10 CI, Release, And Supply Chain Gates

Goal:
- Permanently protect the migration with CI and release gates.

Checklist:
- [ ] CI builds the new stack.
- [ ] CI runs HTTP/3 client/server contract suites.
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
