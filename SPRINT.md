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

## Realtime Media Recovery Sprint

Goal:
- Root-cause and fix the tiled/freezing video signal, missing audio transfer, and unstable E2EE readiness across WLVC/SFU, native audio bridge, media security, and King realtime worker paths.
- Treat this as a production media transport blocker, not as a cosmetic UI issue.

Field evidence:
- [ ] Browser logs show SFU send-buffer backpressure above 2 MiB while outgoing WLVC frames are skipped.
- [ ] Remote video stalls after advertised SFU tracks, with no decoded frame for roughly 10 seconds.
- [ ] Native audio bridge first waits for E2EE readiness, then exhausts recovery because no encrypted remote audio track arrives.
- [ ] Current video SFU sends WLVC frames as base64 JSON over WebSocket chunk messages, with 8 KiB chunks and SQLite broker fanout for cross-worker paths.
- [ ] Current `SFU_PROTECTED_MEDIA_ENABLED` is false, so video media E2EE is not proven on the active SFU publishing path.

Non-negotiable contracts:
- [ ] No hidden downgrade from protected/E2EE media to transport-only media.
- [ ] Any fallback or unavailable protected-media state must be visible in UI and diagnostics.
- [ ] No client-created call, room, or admission authority.
- [ ] SFU behavior must work across multiple King workers or explicitly pin/route room media to one worker.
- [ ] Reconnect/retry logic must not create duplicate publishers, stale tracks, or bad-request leave/rejoin loops.
- [ ] HD video must be stable before compression work starts: target 1280x720 at 30 fps, no tiled artifacts, no remote freeze, and no sustained sender backpressure for a 60 second two-participant call.

HD-first video quality plan:
- [ ] Treat the current pixelated/freezing stream as a transport correctness issue, not as a compression-quality issue.
- [ ] Establish an HD baseline profile with camera capture at 1280x720, 30 fps, no forced low-resolution fallback, and no aggressive WLVC quality reduction while the transport is being debugged.
- [ ] Move the realtime media hot path away from JSON/base64 frame carriage before tuning compression, because base64 chunking inflates payloads and amplifies websocket backpressure.
- [ ] Prefer binary WebSocket/IIBIN or real WebRTC media transport for the HD baseline; only keep WLVC-over-JSON if instrumentation proves it can sustain HD without skipped frames.
- [ ] Add a hard contract that any skipped, aborted, late, or missing frame sequence forces the next video frame to be a keyframe and resets stale decoder state.
- [ ] Add bounded send queues with explicit drop policy: drop only obsolete delta frames, never silently drop keyframes, and surface all drops in diagnostics.
- [ ] Add receiver-side frame continuity checks so out-of-order chunks, missing chunks, stale deltas, and decoder resets are visible instead of becoming tiled video.
- [ ] Keep compression, quantizer tuning, lower FPS fallback, and adaptive downscale as phase-two optimizations after the HD baseline is stable.
- [ ] Define an explicit quality gate: two real browser clients must show 720p remote video continuously for 60 seconds with zero `sfu_remote_video_stalled` events and no websocket buffer growth beyond the agreed high-water mark.
- [ ] Record HD performance metrics in diagnostics: capture resolution, encode resolution, decoded resolution, fps, encode time, frame byte size, chunk count, send wait time, websocket bufferedAmount, broker latency, decode time, and rendered frame age.

Investigation and hardening plan:
- [ ] Instrument SFU sender and receiver diagnostics with frame byte size, encoded base64 size, chunk count, websocket bufferedAmount, dropped-frame reason, keyframe flag, publisher worker PID, subscriber worker PID, broker write latency, and broker poll lag.
- [ ] Prove whether WLVC-over-JSON-WebSocket can sustain the target profiles. If not, replace the hot path with binary WebSocket/IIBIN or a real WebRTC media SFU path instead of threshold tuning.
- [ ] Add a forced keyframe/decoder reset contract after any skipped, aborted, or missing chunk sequence so delta frames cannot mosaic after transport loss.
- [ ] Audit cross-worker SFU fanout. SQLite broker writes per frame are a suspected hot path and must either be removed from realtime media delivery or bounded with explicit backpressure and room-worker affinity.
- [ ] Enable and prove protected SFU video frames, or block E2EE claims for video until the protected path is active and tested.
- [ ] Split native audio bridge readiness into explicit phases: media-security active, local mic live, offer SDP sendable, answer SDP sendable, ICE connected, encrypted receiver transform attached, remote track arrived, playback unblocked.
- [ ] Add a two-participant E2E gate: encrypted remote audio RMS must exceed threshold and remote video must render continuously without SFU stall for at least 60 seconds.
- [ ] Add backend contracts for SFU chunk ordering, chunk loss, chunk timeout, protected-frame required mode, broker fanout ordering, and multi-worker publisher/subscriber paths.

Open root-cause candidates:
- [ ] JSON/base64 frame carriage is too expensive for realtime video and produces the observed send-buffer growth.
- [ ] Chunk loss or skipped frame sends leave remote decoders on stale delta state, causing tiled video before freeze.
- [ ] SQLite broker fanout under multiple workers can add latency, locks, or frame gaps that are fatal for realtime media.
- [ ] Media-security handshake reaches neither active sender-key state nor native receiver transform readiness reliably enough before audio negotiation.
- [ ] Native audio SDP can negotiate connected ICE without a usable remote audio track when local mic state, transceiver reuse, or E2EE transform attach happens in the wrong order.

## Video Chat Domain Refactor Queue

Goal:
- Keep `demo/video-chat/frontend-vue/src/domain` navigable with fewer than 10 files per folder and no production source file above 800 LOC.
- Refactor large files iteratively: first split files above 6,000 LOC into roughly 1,500 LOC slices, then tighten extracted slices toward the 750 LOC target and never leave a production source file above 800 LOC.
- Preserve realtime, admission, media, and E2EE contracts while moving code; every extraction needs build and targeted contract coverage.

Folder hygiene:
- [x] Move realtime domain helpers into focused folders: `background`, `chat`, `layout`, `media`, `native`, `sfu`, and `workspace`.
- [x] Move calls domain files into focused folders: `access`, `admin`, `chat`, `components`, and `dashboard`.
- [x] Move users domain files into focused folders: `admin`, `components`, and `overview`.
- [x] Keep every current `src/domain` folder below 10 direct files after the first cleanup pass.

Mega files, largest first:
- [ ] `demo/video-chat/frontend-vue/src/domain/realtime/CallWorkspaceView.vue` (9,080 LOC)
- [ ] Iteration 1: split into roughly 1,500 LOC slices for shell/state wiring, SFU/WLVC transport, E2EE/media security, native audio bridge, local media/background, participant roster/layout, and chat/activity.
- [ ] Iteration 2: tighten extracted modules/components toward roughly 750 LOC each.
- [ ] Add or update targeted realtime contracts for every extraction.
- [x] `demo/video-chat/frontend-vue/src/domain/calls/admin/CallsView.vue` split to 742 LOC plus focused controllers below 800 LOC.
- [x] `demo/video-chat/frontend-vue/src/domain/calls/dashboard/UserDashboardView.vue` split to 534 LOC plus focused controllers below 800 LOC.

Near-limit follow-up:
- [x] `demo/video-chat/frontend-vue/src/domain/users/overview/OverviewView.vue` reduced to 799 LOC.
- [x] `demo/video-chat/frontend-vue/src/domain/realtime/media/security.js` reduced to 792 LOC.
- [ ] `demo/video-chat/frontend-vue/src/domain/calls/access/JoinView.vue` (752 LOC)

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
- [x] Port branchless varint encode and architecture-safe decode improvements into `extension/include/iibin/iibin_internal.h`.
- [x] Review whether ARM64-specific unrolling belongs in production or should stay behind a guarded helper.
- [x] Consolidate float/double bit helpers without duplicating logic across encode/decode/prelude paths.
- [x] Add `king_proto_encode_batch(schema, records[])` and `king_proto_decode_batch(schema, binary_records[], options)` as stable public API only if the contract is fully validated.
- [x] Add internal `king_iibin_encode_batch()` and `king_iibin_decode_batch()` with bounded memory behavior and per-record error handling.
- [x] Add or port batch encode/decode PHPT coverage under the current test numbering scheme.
- [x] Add benchmarks for batch encode/decode and omega-vs-varint only as clean source files, not generated results.
- [x] Add explicit failure coverage for malformed batch boundaries, truncated records, schema mismatch, oversized batches, and bounded allocation behavior.
- [x] Update documentation for IIBIN/proto batch behavior and performance expectations.

Done:
- [x] IIBIN batch APIs are public, documented, tested, and wired through arginfo/function-table surfaces.
- [x] Varint performance changes are proven on supported architectures without undefined behavior.

### #Q-14 Port Experiment GossipMesh/SFU Research As King Runtime Contract

Source:
- `origin/experiments/v1.0.6-beta` through `4e58bef`.
- Relevant source commits: `d92dfdd`, `dca5e98`, `b338a87`, `9f7f544`.

Goal:
- Evaluate and port the useful GossipMesh/SFU pieces as a real King runtime contract, not as raw experiment code.

Checklist:
- [x] Preserve contributor credit for the experiment work.
- [x] Review `extension/src/gossip_mesh/*` and decide the production King API surface.
- [x] Review `extension/src/gossip_mesh/sfu_signaling.php` against the current video-chat SFU room-binding and admission model.
- [x] Separate reusable topology/signaling ideas from experiment-only behavior.
- [x] Treat direct P2P transport in the experiment branch as research until it is re-specified under current backend-authoritative room/call contracts.
- [x] Explicitly decide whether transport-level DataChannel protection is sufficient for any intended payloads or whether app-level protected envelopes are required.
- [x] Port only compatible GossipMesh runtime pieces; do not import `.DS_Store`, `tmp_*`, debug PHPTs, generated test results, generated build churn, or submodule gitlinks.
- [x] Decide whether `demo/video-chat/frontend-vue/src/lib/sfu/gossip_mesh_client.js` should be ported, replaced, or folded into the current SFU client.
- [x] Add contract tests for GossipMesh message routing, membership, IIBIN envelope use, duplicate suppression, TTL handling, relay fallback, and failure behavior.
- [x] Add `documentation/gossipmesh.md` only after the production contract matches the implementation.
- [x] Keep the current stronger SFU constraints: explicit room/call binding, DB-backed admission, no process-local room identity, and no client-invented call state.
- [x] Reject any experiment behavior that weakens current room/admission/security guarantees.

Done:
- [x] GossipMesh is either rejected with documented reasons or ported as a tested King runtime capability.
- [x] Video-chat SFU remains compatible with current room/admission/security contracts.

### #Q-15 Audit Remaining WLVC/WASM/Kalman Experiment Diffs

Source:
- `origin/experiments/v1.0.6-beta` through `4e58bef`.

Goal:
- Verify whether any remaining codec/WASM/Kalman experiment diffs should be ported, while keeping the stronger current implementation where it already improved the experiment.

Checklist:
- [x] Compare `codec-test.html`, `codec-test.md`, `src/lib/wasm`, `src/lib/wavelet`, `src/lib/kalman`, and `mediaRuntime*` against the experiment branch.
- [x] Keep current WASM MIME/cache-buster handling unless a better production-safe replacement exists.
- [x] Keep current debug-log abstraction and avoid reintroducing noisy direct `console.*` paths in hot codec loops.
- [x] Keep current WASM encoder/decoder binding-mismatch recovery unless disproven by tests.
- [x] Keep current SFU origin, call-id, snake_case compatibility, and room-binding behavior.
- [x] Port only verified codec correctness or performance improvements with targeted frontend tests.
- [x] Add explicit regression checks for encode/decode parity, crash-free decode failure, runtime-path switching, and remote render continuity.
- [x] Document the outcome in `READYNESS_TRACKER.md`.

Done:
- [x] Remaining codec experiment diffs are either ported with tests or explicitly classified as superseded by current implementation.
