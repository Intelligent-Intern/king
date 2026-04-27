# King Active Issues

Purpose:
- This file contains exactly 20 active sprint issues.
- Detailed history, parked ideas, and overflow items belong in `BACKLOG.md`.
- Completion evidence belongs in `READYNESS_TRACKER.md`.
- Branch-specific comparison notes live in `documentation/dev/video-chat/branch-compare-1.0.7-video-codec.md`.

Active GitHub issue:
- #148 Batch 3: Core Runtime, Experiment Intake, And Release Closure (`1.0.7-beta`)

Sprint rule:
- Keep only issues that are currently actionable and release-relevant.
- Do not keep completed work in this file.
- Do not weaken King v1 contracts to make a merge easier.

## Top 20 Active Issues

1. [x] Define the merge strategy for `origin/experiments/1.0.7-video-codec`: codec donor branch only, not wholesale runtime replacement.
2. [x] Port the experiment branch WLVC payload/header v2 semantics into the current codec path without regressing the current transport contract.
3. [x] Port the richer WASM/native codec configuration surface (`waveletType`, `entropyCoding`, `dwtLevels`, `colorSpace`, `motionEstimation`) into the current runtime.
4. [x] Decide which experiment blur/processor-pipeline pieces are worth porting and integrate only the parts that reduce duplication with the current background/media pipeline.
5. [x] Keep the current binary SFU transport as the production contract and remove the remaining legacy JSON/base64 fallback from the hot video path.
6. [x] Replace generic `sfu_chunk_send_failed` handling with exact-stage diagnostics and enforce sender backpressure at the real transport boundary.
7. [x] Finish a versioned binary media envelope contract that carries frame identity, timing, protection state, codec/runtime id, and tile/layer metadata without base64 expansion.
8. [x] Preserve and prove the current protected-media/media-security contract on the merged codec path; no hidden downgrade to transport-only media.
9. [x] Fix the realtime join race by gating media-security activation on publisher discovery plus a short settle window instead of multi-second retries.
10. [x] Stabilize native audio bridge negotiation and recovery with a strict state machine for capability, key exchange, SDP readiness, ICE, transform attach, remote track arrival, and playback.
11. [x] Prove whether selective tile/segmentation transport stays valuable with the improved codec and keep it only if it still reduces real wire bytes materially.
12. [x] Keep tile/layer/cache generation semantics explicit so stale or mixed-generation patches fail closed instead of rendering corruption.
13. [x] Preserve multi-worker SFU correctness: room affinity or broker fanout must remain explicit and bounded for realtime media.
14. [x] Audit the backend SFU store/gateway after codec merge so binary replay, protected-frame parsing, and cross-worker fanout remain contract-correct.
15. [x] Reconcile the test surface: keep the current stronger transport/runtime/media-security contracts and add only the useful new codec-focused tests from the experiment branch.
16. [x] Remove or rewrite contracts/tests that only prove superseded experimental paths once the merged codec/runtime path is in place.
17. [x] Keep `CallWorkspaceView` and its extracted runtime modules modular; do not re-monolithize the realtime workspace while merging codec work.
18. [x] Produce a keep/port/delete/superseded checklist for all branch-difference files so cleanup after the merge is deliberate instead of ad hoc.
19. [ ] Run the merged path against the HD acceptance gate: 1280x720 at 30 fps, 60-second two-browser call, no hidden degraded state, no remote stall, no unbounded sender queue.
20. [ ] Update `READYNESS_TRACKER.md`, `BACKLOG.md`, and release notes only after the merged codec + transport + media-security contract is proven by tests and live evidence.

## Parking Rule

Move an item to `BACKLOG.md` if any of the following is true:
- it is not required for the current release bar
- it depends on unresolved work in one of the 20 issues above
- it is exploratory rather than contract-critical
- it is already completed and only needs archival evidence

## Contract Compatibility Anchors

- [x] Preserve contributor credit for the experiment work.
- [x] Separate reusable topology/signaling ideas from experiment-only behavior.
- [x] Port only compatible GossipMesh runtime pieces; do not import `.DS_Store`, `tmp_*`, debug PHPTs, generated test results, generated build churn, or submodule gitlinks.
- [x] Add `documentation/gossipmesh.md` only after the production contract matches the implementation.
- [x] GossipMesh is either rejected with documented reasons or ported as a tested King runtime capability.
- [x] Keep current WASM MIME/cache-buster handling unless a better production-safe replacement exists.
- [x] Keep current SFU origin, call-id, snake_case compatibility, and room-binding behavior.
- [x] Document the outcome in `READYNESS_TRACKER.md`.
- [x] Remaining codec experiment diffs are either ported with tests or explicitly classified as superseded by current implementation.
- [x] Review `extension/src/gossip_mesh/*` and decide the production King API surface.
- [x] Review `extension/src/gossip_mesh/sfu_signaling.php` against the current video-chat SFU room-binding and admission model.
- [x] Treat direct P2P transport in the experiment branch as research until it is re-specified under current backend-authoritative room/call contracts.
- [x] Explicitly decide whether transport-level DataChannel protection is sufficient for any intended payloads or whether app-level protected envelopes are required.
- [x] Decide whether `demo/video-chat/frontend-vue/src/lib/sfu/gossip_mesh_client.js` should be ported, replaced, or folded into the current SFU client.
- [x] Add contract tests for GossipMesh message routing, membership, IIBIN envelope use, duplicate suppression, TTL handling, relay fallback, and failure behavior.
- [x] Keep the current stronger SFU constraints: explicit room/call binding, DB-backed admission, no process-local room identity, and no client-invented call state.
- [x] Reject any experiment behavior that weakens current room/admission/security guarantees.
- [x] Video-chat SFU remains compatible with current room/admission/security contracts.
- [x] Compare `codec-test.html`, `codec-test.md`, `src/lib/wasm`, `src/lib/wavelet`, `src/lib/kalman`, and `mediaRuntime*` against the experiment branch.
- [x] Keep current debug-log abstraction and avoid reintroducing noisy direct `console.*` paths in hot codec loops.
- [x] Keep current WASM encoder/decoder binding-mismatch recovery unless disproven by tests.
- [x] Port only verified codec correctness or performance improvements with targeted frontend tests.
- [x] Add explicit regression checks for encode/decode parity, crash-free decode failure, runtime-path switching, and remote render continuity.
