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
- [ ] HD video must be stable at the product contract: target 1280x720 at 30 fps, no tiled artifacts, no remote freeze, no hidden transport downgrade, and no sustained sender backpressure for a 60 second two-participant call.

Transport-first media architecture plan:
- [ ] Three-step implementation plan:
- [ ] Step 1: Keep the current binary SFU hot path and finish it properly. Remove the legacy JSON chunk fallback, align sender backpressure with the actual transport limits, and make every `sfu_chunk_send_failed` report the exact failing stage, queue state, and source location instead of a generic send failure.
- [ ] Step 2: Import the codec structure from `origin/experiments/1.0.7-video-codec` without importing its wire format. Reuse the experiment's stronger codec ideas where they help compression and frame structure, but do not revive `Array.from(Uint8Array)` plus per-frame JSON transport on the production path.
- [ ] Step 3: Gate protected media activation on SFU publisher discovery plus a short settle window. A joining participant must first complete pub/sub visibility and target sync, then start E2EE/media-security exchange, so we solve the real ~50 ms race instead of masking it with multi-second retries.
- [ ] Treat the current pixelated/freezing stream as a transport and framing correctness issue, not as proof that “video compression quality must be lowered”.
- [x] Establish an HD baseline profile with camera capture at 1280x720, 30 fps, and visible diagnostics for any degraded runtime state while the transport is being debugged.
- [ ] Remove JSON/base64 carriage from the realtime video hot path. The active path must not inflate encoded frame bytes with base64, stringify, and tiny JSON chunk messages if King already has frontend/backend IIBIN available.
- [ ] Replace the active SFU media payload path with binary WebSocket/IIBIN framing or a real WebRTC media SFU path. WLVC-over-JSON may remain only as a temporary debug path and must not be treated as the production transport contract.
- [ ] Define a versioned binary media envelope that carries frame id, sequence, keyframe flag, codec/runtime id, sender timestamp, payload byte length, protection mode, optional key id, and chunk/tile metadata without base64 expansion.
- [ ] Preserve the strongest media-security contract on the binary path: no hidden fallback from protected media to transport-only media, and visible UI/diagnostics for any per-track degraded state.
- [x] Add a hard contract that any skipped, aborted, late, or missing frame sequence forces the next video frame to be a keyframe and resets stale decoder state.
- [x] Add bounded send queues with explicit drop policy: drop only obsolete delta frames, never silently drop keyframes, and surface all drops in diagnostics.
- [x] Add receiver-side frame continuity checks so out-of-order chunks, missing chunks, stale deltas, and decoder resets are visible instead of becoming tiled video.
- [ ] Treat compression tuning, quantizer changes, lower-FPS fallback, and adaptive downscale as secondary controls after the transport container is correct and binary.
- [ ] Define an explicit quality gate: two real browser clients must show 720p remote video continuously for 60 seconds with zero `sfu_remote_video_stalled` events, zero hidden degraded-state transitions, and no unbounded sender queue growth.
- [ ] Record transport metrics in diagnostics: capture resolution, encode resolution, decoded resolution, fps, encode time, raw encoded byte size, wire payload byte size, chunk/tile count, send queue bytes, websocket bufferedAmount, broker latency, decode time, rendered frame age, and protected/degraded state.

Selective transport target state:
- [ ] Design the production path around region-of-interest/layered transport instead of mandatory full-frame retransmission for every tick.
- [ ] Split outgoing video state into at least: contour/mask channel, foreground pixel/tile channel, background channel, and control/sync channel.
- [ ] Support selective tile transmission: unchanged tiles must be reused from receiver cache; changed foreground tiles must be prioritized; background tiles may be sent once, sent rarely, or refreshed only when they materially change.
- [ ] Make tile/layer caching explicit in the protocol: receiver state must be versioned so stale, missing, mixed-generation, or out-of-order tiles cannot be rendered as corruption.
- [ ] Prove whether contour-bounded foreground transmission materially reduces wire bytes for the current background-filter/media-security pipeline; if so, make it the preferred contract instead of brute-force whole-frame streaming.
- [ ] Keep the room/admission/security contract stronger than the experiment path: selective transport must not create client-authoritative scene state, hidden replay windows, or unverifiable media-security claims.

Implemented first hardening pass:
- [x] Default outgoing SFU video profile is HD/Sharp for stale and new browser preferences.
- [x] SFU backpressure skips reset the WLVC encoder so the next sent frame is a keyframe.
- [x] Remote decoders wait for a keyframe after subscription, freeze recovery, reconfigure, empty decode, or decode error.
- [x] Frontend SFU diagnostics now include payload size, chunk count, send wait time, websocket buffer pressure, and frame-send abort reason.
- [x] Backend SFU runtime logs now surface broker insert pressure and direct fanout chunk pressure with worker PID context.

Implemented second hardening pass:
- [x] SFU client now prepares frame payloads outside the socket client so chunk/base64 metrics are reusable and testable.
- [x] Outgoing SFU media frames now pass through a bounded send queue.
- [x] Queued delta frames are replaced/dropped under pressure; keyframes are never silently dropped and emit explicit diagnostics if the queue cannot accept them.
- [x] Public SFU send pressure now includes websocket buffered bytes plus active/queued frame payload pressure, so the WLVC encode loop sees queue pressure before the browser buffer explodes.
- [x] `sfuClient.ts` remains under the 800 LOC limit after the queue extraction.
- [x] `sendEncodedFrame=false` with `bufferedAmount=0` is no longer reported as websocket backpressure; failed sends now emit `sfu_frame_send_failed`.
- [x] Native `malformed_protected_frame` receiver errors now force media-security recovery plus a bounded audio-bridge rebuild instead of staying in a waiting state.
- [x] WLVC encode scheduling is self-paced after each encode tick instead of a fixed `setInterval`, reducing timer backlog while preserving the HD profile.

Implemented third hardening pass:
- [x] SFU frame transport now carries protocol version, per-track frame sequence, sender timestamp, advertised payload length, chunk count, frame id, frame type, and protection mode through frontend, direct gateway fanout, and broker fanout.
- [x] Same-worker SFU `sfu/frame` carriage now uses a binary WebSocket envelope on the live publisher->gateway->subscriber hot path; JSON/base64 chunk messages remain only as compatibility fallback for broker/cross-worker fanout until the binary path is extended there too.
- [x] Broker-backed SFU frame persistence now stores the same binary envelope in `sfu_frames.data_blob`, and cross-worker fanout replays that binary payload directly when available instead of rebuilding a base64 JSON frame from storage.
- [x] Frontend/client diagnostics now distinguish binary-envelope sends from legacy JSON/base64 fallback, and backend SFU runtime logs distinguish binary broker replay from legacy broker replay fallback.
- [x] Frontend and gateway chunk assembly now fail closed on invalid chunk headers, duplicate chunks with different bytes, metadata drift, advertised payload-length mismatch, oversized chunk counts, and out-of-order chunk arrival.
- [x] Incomplete inbound chunks expire after the TTL and emit `sfu_frame_chunk_timeout` diagnostics instead of silently leaving partial frame state behind.
- [x] Direct inbound SFU frames with an advertised payload length mismatch are rejected with `sfu_frame_rejected` diagnostics before decode.
- [x] Remote SFU receivers now drop stale, duplicate, reordered, and sequence-gap delta frames before WLVC decode, reset decoder state, and require a fresh keyframe.
- [x] Inbound SFU chunk assembly was extracted to `inboundFrameAssembler.ts`, keeping `sfuClient.ts` below the 800 LOC limit after the hardening work.
- [x] Contract coverage was extended for versioned frame metadata, chunk metadata preservation, chunk length validation, out-of-order chunk rejection, stale-frame TTLs, and receiver continuity diagnostics.

Implemented fourth hardening pass:
- [x] The binary SFU frame envelope now has a layout-aware v2 that preserves ROI/tile/layer metadata on-wire without regressing v1 full-frame compatibility.
- [x] Legacy JSON chunk fallback now preserves the same `layout_mode`, `layer_id`, `cache_epoch`, tile grid, tile index list, and ROI bounds so broker fallback does not silently erase selective-transport intent.
- [x] Frontend chunk reassembly now treats layout/tile metadata as part of frame identity, so mixed-generation tile metadata fails closed instead of being merged into one frame.
- [x] Contract coverage now pins the binary tile/layout envelope, tile metadata serializer, fallback chunk preservation, and reassembly metadata carry-through.

Implemented fifth hardening pass:
- [x] The live WLVC sender can now replace some full-frame delta sends with selective tile-patch keyframes derived from per-tile RGBA diffing against the last acknowledged full-frame base.
- [x] Selective tile-patches are bounded by changed-tile ratio and patch-area ratio, and the sender forces periodic full-frame refresh so the room never becomes patch-only indefinitely.
- [x] Remote SFU peers now keep a separate patch decoder path and composite tile-patch frames into the last full-frame canvas instead of resizing the primary render surface down to patch dimensions.
- [x] Contract coverage now pins the selective tile planner plus the sender/receiver runtime wiring that keeps tile-patch transport active.

Implemented sixth hardening pass:
- [x] Full-frame base sends now carry explicit `layout_mode=full_frame` and `cache_epoch`, so base generations no longer depend on implicit timing.
- [x] Remote peers now track accepted full-frame base availability and cache generation per track, and invalidate that cache fail-closed on stale frames, sequence gaps, and rollover.
- [x] Foreground/background tile-patch frames are dropped when they reference a missing or mismatched base cache generation instead of being composited onto stale pixels.
- [x] Contract coverage now pins the explicit cache-epoch send path plus the receiver-side invalidation helpers.

Implemented seventh hardening pass:
- [x] Sender-side SFU transport metrics now sample the active wire path and include `binary_envelope`, `legacy_json_fallback`, and `legacy_chunked_json` overhead instead of only surfacing failures.
- [x] Transport metrics now distinguish `transport_frame_kind`, `roi_area_ratio`, projected binary-envelope overhead, and legacy base64 overhead so tile/ROI wins can be measured against full-frame sends.
- [x] Backend broker replay and binary-fallback runtime logs now carry the same layer/ROI transport fields, so cross-worker paths do not hide where wire overhead is coming from.
- [x] Contract coverage now pins the transport-metrics fields and periodic sample diagnostic on frontend plus backend replay/fallback metric helpers.

Implemented eighth hardening pass:
- [x] The live sender now has a separate coarse `background_snapshot` patch planner for broader scene changes that do not fit the tighter `tile_foreground` budget.
- [x] Background snapshots are rate-limited and carried as their own layout/layer kind instead of being misclassified as foreground patches.
- [x] The outbound send queue now deprioritizes and ages out `background_snapshot` frames before foreground or full-frame recovery traffic.
- [x] Contract coverage now pins the background-snapshot planner, sender wiring, and queue-priority behavior.

Implemented ninth hardening pass:
- [x] Receiver-side SFU render state now keeps explicit per-track background and foreground layer canvases instead of painting all selective patches directly onto the visible canvas.
- [x] A new `full_frame` base now resets the foreground overlay layer, preventing stale foreground ghosts from surviving across patch generations.
- [x] `background_snapshot` patches now update the background layer, while `tile_foreground` patches update a separate foreground layer that is recomposited onto the visible canvas.
- [x] Cache invalidation now clears per-track layered render state in addition to decoder state so sequence gaps and cache-epoch mismatches cannot resurrect stale pixels.

Implemented tenth hardening pass:
- [x] The sender now feeds the live background-filter matte into selective tile planning, so `tile_foreground` and `background_snapshot` are split by the same segmentation signal that already drives the outgoing blur pipeline.
- [x] Selective tile planners now expose whether matte guidance was active and what fraction of the tile grid was selected, instead of only exposing raw patch area.
- [x] Transport metrics now carry `selection_tile_count`, `selection_total_tile_count`, `selection_tile_ratio`, and `selection_mask_guided` through frontend diagnostics and backend SFU metric helpers.

Implemented eleventh hardening pass:
- [x] Admin video-operations snapshots now aggregate recent SFU transport samples instead of leaving the new selective-transport metrics buried in per-frame diagnostics only.
- [x] The operations API now reports recent frame count, matte-guided frame count, average selected-tile ratio, average ROI ratio, and a frame-kind breakdown derived from stored SFU transport metadata.
- [x] The overview/admin UI now surfaces the SFU transport mix directly, so full-frame, foreground-tile, and background-snapshot behavior can be compared without opening ad hoc debug logs.

Investigation and hardening plan:
- [ ] Instrument SFU sender and receiver diagnostics with frame byte size, encoded base64 size, chunk count, websocket bufferedAmount, dropped-frame reason, keyframe flag, publisher worker PID, subscriber worker PID, broker write latency, and broker poll lag.
- [ ] Prove whether WLVC-over-JSON-WebSocket can sustain the target profiles. If not, replace the hot path with binary WebSocket/IIBIN or a real WebRTC media SFU path instead of threshold tuning.
- [ ] Quantify the current overhead split: raw encoded frame bytes vs base64-expanded bytes vs JSON framing vs chunk metadata vs broker fanout delay, so the replacement protocol removes measured waste instead of guessing.
- [ ] Add a forced keyframe/decoder reset contract after any skipped, aborted, or missing chunk sequence so delta frames cannot mosaic after transport loss.
- [ ] Audit cross-worker SFU fanout. SQLite broker writes per frame are a suspected hot path and must either be removed from realtime media delivery or bounded with explicit backpressure and room-worker affinity.
- [ ] Enable and prove protected SFU video frames, or block E2EE claims for video until the protected path is active and tested.
- [ ] Split native audio bridge readiness into explicit phases: media-security active, local mic live, offer SDP sendable, answer SDP sendable, ICE connected, encrypted receiver transform attached, remote track arrived, playback unblocked.
- [ ] Add a two-participant E2E gate: encrypted remote audio RMS must exceed threshold and remote video must render continuously without SFU stall for at least 60 seconds.
- [ ] Add backend contracts for SFU chunk ordering, chunk loss, chunk timeout, protected-frame required mode, broker fanout ordering, and multi-worker publisher/subscriber paths.
- [ ] Move the WLVC encode hot path off the UI thread or replace it with binary/WebRTC media transport; Chrome long-task warnings are a symptom of 720p encode work still running on the UI thread.

Additional hardening backlog:
- [ ] Move WLVC capture, pixel readback, encode, and frame-payload preparation into a Worker/OffscreenCanvas pipeline so 720p encode cannot block the UI thread or trigger timer/render long tasks.
- [ ] Add a capability check for Worker/OffscreenCanvas/ImageBitmap/WASM support and expose a visible degraded-state banner when the browser cannot run the hardened media path.
- [ ] Replace JSON/base64 SFU frame transport with binary WebSocket/IIBIN framing, or switch the active video media path to WebRTC media SFU once the room/admission/security contracts are preserved.
- [ ] Define a versioned SFU binary frame protocol with frame id, sequence number, keyframe flag, codec/runtime id, chunk or tile index, total chunks or tiles, payload byte length, protection mode, key id, sender timestamp, and cache generation ids.
- [ ] Add an IIBIN media-envelope schema shared by frontend and backend so frame/tile/layer/control payloads do not drift between browser, gateway, and broker fanout paths.
- [ ] Add sender-side tile/layer prioritization so foreground/keyframe data bypasses obsolete delta/background backlog during congestion.
- [ ] Add receiver-side tile/layer cache invalidation so reconnect, worker switch, or sequence gap cannot resurrect stale background or stale contour state.
- [x] Make chunk assembly fail-fast: missing, late, duplicate, oversized, mixed-key, or out-of-order chunks must discard the full frame, reset delta decode state, and request a fresh keyframe.
- [ ] Add sender-side keyframe priority lanes so keyframes bypass obsolete delta backlog and cannot be delayed behind dropped or expired delta frames.
- [x] Add receiver-side stale-frame TTLs so old frames are discarded instead of rendered after reconnect, worker switch, or browser tab suspension.
- [ ] Add explicit SFU room-worker affinity or a proven broker fanout path; realtime media must not depend on best-effort cross-worker SQLite polling under load.
- [ ] Move any SQLite writes out of the per-frame hot path or enforce bounded media queues with backpressure diagnostics before database locks can stall media delivery.
- [ ] Add SFU per-room metrics: publisher count, subscriber count, active worker, queued bytes, dropped delta frames, delayed keyframes, fanout latency, broker lag, and reconnect count.
- [ ] Add client metrics: capture fps, encode fps, encode ms, payload bytes, chunk count, send queue bytes, socket bufferedAmount, decode ms, render fps, rendered frame age, and stall duration.
- [ ] Rate-limit noisy media diagnostics while preserving first occurrence, state transitions, and aggregate counters so console output remains useful during failure.
- [ ] Promote media-security into an explicit per-peer, per-track state machine instead of scattered booleans: capability, hello, key exchange, sender key, transform attached, decrypt ok, audio bridge ok.
- [ ] Add media-security generation ids so stale websocket handshakes, reconnect signals, old keys, and old tracks cannot update the current peer state.
- [ ] Treat `wrong_key_id`, `malformed_protected_frame`, `replay_detected`, and decrypt-auth failures as separate recovery classes with bounded retries and visible final states.
- [ ] Clear replay counters and pending decrypt state only on a proven rekey boundary, never on generic reconnect noise.
- [ ] Require protected-media readiness before advertising audio as available; if fail-closed blocks audio, the UI must state exactly which phase is missing.
- [ ] Add a visible non-E2EE/degraded/fail-closed indicator for every media kind independently: video, audio, chat files, and activity signals.
- [ ] Add a native audio bridge state machine with local mic live, sender transceiver attached, offer sent, answer applied, ICE connected, receiver transform attached, remote track received, playback started, and RMS detected.
- [ ] Add audio watchdog recovery that checks remote track mute/ended state and WebAudio RMS, not just whether a `MediaStreamTrack` object exists.
- [ ] Add deterministic two-browser E2E tests with fake camera and fake microphone that assert encrypted remote audio RMS and continuous remote video for at least 60 seconds.
- [ ] Add negative E2E tests for missing Insertable Streams, wrong key id, dropped chunks, reconnect during handshake, leave/rejoin, mobile reload, and background/foreground resume.
- [ ] Add backend SFU tests for multi-worker publisher/subscriber routing, room affinity, chunk loss, chunk timeout, duplicate chunks, oversized frames, and broker-fanout ordering.
- [ ] Add leave/rejoin idempotency tests so stale publishers, stale sockets, and already-left sessions cannot cause `400 bad request` on the next join.
- [ ] Add browser lifecycle recovery tests for focus regain, mobile reload, tab suspension, media-device restart, and owner/admin admission persistence.
- [ ] Keep HD as the first acceptance gate: no adaptive quality reduction, no compression tuning, and no hidden fallback until 720p/30fps is stable in the hardened transport path.

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
