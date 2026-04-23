# King Active Issues

Purpose:
- This file contains only the active sprint extraction from `BACKLOG.md`.
- The complete open backlog is in `BACKLOG.md`.
- Completion notes go to `READYNESS_TRACKER.md`.

Active GitHub issue:
- #148 Batch 3: Core Runtime, Experiment Intake, And Release Closure (`1.0.7-beta`)

Rules:
- Keep active work small enough for clean commits and bisectable reviews.
- Do not mix ownership lanes unless the backlog item explicitly requires coordination.
- Do not weaken King v1 contracts to close a task faster.
- Preserve contributor credit when porting experiment-branch work.
- No path may be labeled secure, encrypted, E2EE, or post-quantum unless implementation, contracts, negative tests, and runtime/UI state prove the claim.

## Batch 3: Core Runtime, Experiment Intake, And Release Closure (`1.0.7-beta`)

### #Q-11 Full HTTP/3 Regression Against New Stack

Goal:
- Prove the new stack carries the previous HTTP/3/QUIC contract.

Checklist:
- [x] Client one-shot request/response tests are green.
- [x] OO `Http3Client` exception matrix is green.
- [x] Server one-shot listener tests are green.
- [x] Session-ticket and 0-RTT tests are green.
- [x] Stream lifecycle, reset, stop-sending, cancel, and timeout tests are green.
- [x] Packet loss, retransmit, congestion control, flow control, and long-duration soak are green.
- [x] WebSocket-over-HTTP3 relevant slices are green.
- [x] Performance baseline against the previous Quiche state is documented.

Done:
- [x] New stack is proven at the existing contract level.
- [x] Deviations are fixed or registered as new blocker issues.

### #Q-12 Migration Closure And Repo Cleanup

Goal:
- Close the sprint cleanly: no leftover artifacts, no half-renamed paths, no old build assumptions.

Checklist:
- [x] Complete `rg` sweep for Quiche, Cargo, Rust-HTTP3, local paths, and stub loaders.
- [x] `git status` contains no generated build or test artifacts.
- [x] Docs, tests, CI, and release manifests reference the same new stack.
- [x] Add closure note to `documentation/project-assessment.md` and `READYNESS_TRACKER.md` with test evidence.
- [x] Split migration work into logical commits: inventory, build, client, server, tests, docs/cleanup.

Done:
- [x] Quiche is removed from the active product path.
- [x] HTTP/3/QUIC is fully proven on the new stack.

### #Q-13 Port Experiment IIBIN/Proto Batch And Varint Work

Source:
- `origin/experiments/v1.0.6-beta` through `4e58bef`.
- Relevant source commits: `3267785`, `a669b09`, `e16af6f`, `c9f6cf6`, `2914b03`, `b6507fc`, `8e0a539`, `79df7a9`.

Goal:
- Bring the useful IIBIN/proto performance and batch API work into the current tree without importing experiment artifacts or weakening existing contracts.

Checklist:
- [x] Preserve contributor credit for the experiment work.
- [ ] Port branchless varint encode and architecture-safe decode improvements into `extension/include/iibin/iibin_internal.h`.
- [ ] Review whether ARM64-specific unrolling belongs in production or should stay behind a guarded helper.
- [ ] Consolidate float/double bit helpers without duplicating logic across encode/decode/prelude paths.
- [ ] Add `king_proto_encode_batch(schema, records[])` and `king_proto_decode_batch(schema, binary_records[], options)` as stable public API only if the contract is fully validated.
- [ ] Add internal `king_iibin_encode_batch()` and `king_iibin_decode_batch()` with bounded memory behavior and per-record error handling.
- [ ] Add or port batch encode/decode PHPT coverage under the current test numbering scheme.
- [ ] Add benchmarks for batch encode/decode and omega-vs-varint only as clean source files, not generated results.
- [ ] Add explicit failure coverage for malformed batch boundaries, truncated records, schema mismatch, oversized batches, and bounded allocation behavior.
- [ ] Update documentation for IIBIN/proto batch behavior and performance expectations.

Done:
- [ ] IIBIN batch APIs are public, documented, tested, and wired through arginfo/function-table surfaces.
- [ ] Varint performance changes are proven on supported architectures without undefined behavior.

### #Q-14 Port Experiment GossipMesh/SFU Research As King Runtime Contract

Source:
- `origin/experiments/v1.0.6-beta` through `4e58bef`.
- Relevant source commits: `d92dfdd`, `dca5e98`, `b338a87`, `9f7f544`.

Goal:
- Evaluate and port the useful GossipMesh/SFU pieces as a real King runtime contract, not as raw experiment code.

Checklist:
- [ ] Preserve contributor credit for the experiment work.
- [ ] Review `extension/src/gossip_mesh/*` and decide the production King API surface.
- [ ] Review `extension/src/gossip_mesh/sfu_signaling.php` against the current video-chat SFU room-binding and admission model.
- [ ] Separate reusable topology/signaling ideas from experiment-only behavior.
- [ ] Treat direct P2P transport in the experiment branch as research until it is re-specified under current backend-authoritative room/call contracts.
- [ ] Explicitly decide whether transport-level DataChannel protection is sufficient for any intended payloads or whether app-level protected envelopes are required.
- [ ] Port only compatible GossipMesh runtime pieces; do not import `.DS_Store`, `tmp_*`, debug PHPTs, generated test results, generated build churn, or submodule gitlinks.
- [ ] Decide whether `demo/video-chat/frontend-vue/src/lib/sfu/gossip_mesh_client.js` should be ported, replaced, or folded into the current SFU client.
- [ ] Add contract tests for GossipMesh message routing, membership, IIBIN envelope use, duplicate suppression, TTL handling, relay fallback, and failure behavior.
- [ ] Add `documentation/gossipmesh.md` only after the production contract matches the implementation.
- [ ] Keep the current stronger SFU constraints: explicit room/call binding, DB-backed admission, no process-local room identity, and no client-invented call state.
- [ ] Reject any experiment behavior that weakens current room/admission/security guarantees.

Done:
- [ ] GossipMesh is either rejected with documented reasons or ported as a tested King runtime capability.
- [ ] Video-chat SFU remains compatible with current room/admission/security contracts.

### #Q-15 Audit Remaining WLVC/WASM/Kalman Experiment Diffs

Source:
- `origin/experiments/v1.0.6-beta` through `4e58bef`.

Goal:
- Verify whether any remaining codec/WASM/Kalman experiment diffs should be ported, while keeping the stronger current implementation where it already improved the experiment.

Checklist:
- [ ] Compare `codec-test.html`, `codec-test.md`, `src/lib/wasm`, `src/lib/wavelet`, `src/lib/kalman`, and `mediaRuntime*` against the experiment branch.
- [ ] Keep current WASM MIME/cache-buster handling unless a better production-safe replacement exists.
- [ ] Keep current debug-log abstraction and avoid reintroducing noisy direct `console.*` paths in hot codec loops.
- [ ] Keep current WASM encoder/decoder binding-mismatch recovery unless disproven by tests.
- [ ] Keep current SFU origin, call-id, snake_case compatibility, and room-binding behavior.
- [ ] Port only verified codec correctness or performance improvements with targeted frontend tests.
- [ ] Add explicit regression checks for encode/decode parity, crash-free decode failure, runtime-path switching, and remote render continuity.
- [ ] Document the outcome in `READYNESS_TRACKER.md`.

Done:
- [ ] Remaining codec experiment diffs are either ported with tests or explicitly classified as superseded by current implementation.
