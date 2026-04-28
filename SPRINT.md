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

## Sprint: Online SFU Media Closure

Observed online failure:
- `TypeError: p is not a function` in the minified `CallWorkspaceView` bundle maps to native peer roster sync calling `nativeAudioBridgeIsQuarantined(userId)` without the callback being wired into `createCallWorkspaceNativeStack`.
- SFU control messages are still JSON by design today, but large media frames still have active `legacy_chunked_json` paths through `sfu/frame-chunk`; that contradicts the intended binary/IIBIN media transport contract.
- Available experiment refs in this checkout are `origin/experiments/1.0.7-video-codec` and `origin/experiments/1.0.7-gossip-mesh`; no local or remote-tracking `voltron` ref is currently present.

## Top 20 Active Issues

1. [ ] Deploy and verify the native audio quarantine callback wiring fix so online `syncNativePeerConnectionsWithRoster()` cannot call an undefined callback.
2. [ ] Add a production crash capture contract for minified Call Workspace errors, including asset version, route, media runtime path, native bridge state, and last SFU transport sample.
3. [ ] Enable or publish production sourcemaps for internal deployments, or add a deterministic bundle-position mapping artifact, so online `CallWorkspaceView-*.js:line:column` reports resolve to source without guesswork.
4. [x] Remove the frontend SFU media hot-path branch that sends oversized frames through `sendChunkedFramePayload()` and `transport_path: 'legacy_chunked_json'`.
5. [ ] Replace JSON/base64 media chunking with a binary continuation envelope for oversized SFU frames if the King WebSocket boundary needs chunking.
6. [ ] Keep JSON only for explicit SFU control-plane commands until the IIBIN control envelope lands; media payloads must not use JSON/base64 as the active production path.
7. [ ] Update frontend contracts that currently require `sfu/frame-chunk`, `data_base64_chunk`, `protected_frame_chunk`, or `legacy_chunked_json` so they prove the binary media contract instead of preserving the fallback.
8. [ ] Update backend SFU store/gateway tests that currently assert outbound JSON chunk expansion so replay/fanout proves binary media envelopes or binary continuation frames.
9. [ ] Make backend SFU gateway reject JSON media frame sends in required binary mode while preserving authenticated JSON control commands and clear compatibility diagnostics.
10. [ ] Define the IIBIN schema boundary for SFU control and metadata: room binding, publisher lifecycle, track lifecycle, diagnostics, and binary media-envelope metadata.
11. [ ] Implement the IIBIN control/metadata path on native King PHP APIs instead of introducing a Node or browser-only transport shim.
12. [ ] Audit `origin/experiments/1.0.7-video-codec` residual diffs after the codec port and document any remaining keep/port/reject decisions.
13. [ ] Audit `origin/experiments/1.0.7-gossip-mesh` for reusable membership/routing ideas without weakening current backend-authoritative room binding, call admission, or protected-media guarantees.
14. [ ] Do not import any Voltron work into this sprint unless a real branch/ref and contract delta are identified; keep this sprint scoped to proven refs and live blockers.
15. [ ] Prove that selective tile/background snapshot transport still reduces real wire bytes after binary media transport, or simplify it only after equivalent HD evidence exists.
16. [ ] Verify SFU broker fanout and replay across worker boundaries with binary envelopes, including protected-frame parsing and codec/runtime/layout metadata preservation.
17. [ ] Run the HD acceptance gate online: 1280x720 at 30 fps, two-browser call for 60 seconds, no remote stall, no hidden degraded state, no unbounded sender queue.
18. [ ] Add live diagnostics for every SFU frame send path with exact `transport_path`, wire bytes, payload bytes, queue pressure, and binary continuation state.
19. [ ] Confirm deploy asset invalidation so online clients cannot keep a stale `CallWorkspaceView-*.js` bundle after the callback or transport changes ship.
20. [ ] Update `READYNESS_TRACKER.md`, `BACKLOG.md`, and release notes only after the online crash, JSON media fallback, experiment audit, and HD acceptance gate are proven.

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
