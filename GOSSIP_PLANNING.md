# Gossip Mesh Planning

Last updated: 2026-05-05

## Operating Rule

Every gossip mesh iteration must update both:

- `GOSSIP_CURRENT_BUILD.md`
- `GOSSIP_PLANNING.md`

Every gossip mesh iteration must also add or update executable regression coverage and run it successfully before the step is considered complete.

## Ranked Next Tasks

### 1. Bind Gossip Data Transport To Live Native WebRTC Peers

Impact: very high.

Complexity: high.

Status: complete for the current scaffold.

Why:

This is the main missing piece between a decentralized controller boundary and a real peer-to-peer media data lane. The existing native WebRTC signaling path can create peer connections; the gossip data lane needs to bind only assigned neighbors to `GossipRtcDataChannelTransport`.

Done when:

- Assigned gossip neighbors get RTCDataChannel bindings. Complete for native peer connections behind `shadow`/`active`.
- Remote RTCDataChannel data messages feed `GossipController.handleData()`. Complete in `active`.
- Local deliveries from `onDataMessage()` can enter the remote frame path behind a feature flag. Complete for `sfu/frame`.
- Non-neighbor peers are not opened solely for gossip data. Complete through server-assigned neighbor filtering.
- New executable contracts pass. Complete for native peer binding, cleanup, server topology ingestion, and outbound live publication.

### 2. Add Server-Style Topology Snapshot Contract

Impact: high.

Complexity: medium.

Status: complete.

Why:

The server should be the source of admission and topology hints, not the frame distributor. The client needs a stable topology payload shape before live integration.

Done when:

- A topology hint message shape is documented in the wire contract.
- Client can apply a server-provided neighbor set.
- Local deterministic selection remains available for harness/testing fallback.
- New executable contract passes.

### 3. Feature Flag The Gossip Data Lane

Impact: high.

Complexity: medium.

Status: complete.

Why:

The gossip data lane should be introduced without destabilizing the current SFU path. A feature flag allows side-by-side telemetry and rollback.

Done when:

- Gossip data lane can be enabled independently from SFU publishing.
- Default production behavior remains conservative.
- Diagnostics indicate whether a frame arrived by SFU or gossip.
- New executable contract passes.

### 4. Wire Local Deliveries Into Remote Frame Decode

Impact: high.

Complexity: high.

Status: complete for active inbound `sfu/frame` gossip deliveries.

Why:

Decentralized transport is only useful once delivered gossip frames can be decoded/rendered by the existing remote video path.

Done when:

- `GossipController.onDataMessage()` feeds the same validation/decode path as server frames.
- Late-frame dropping is explicit.
- Keyframe requirements are respected.
- New executable contract passes.

### 5. Add Neighbor Health And Topology Repair

Impact: medium-high.

Complexity: medium-high.

Status: complete for client request and backend requester-scoped replacement hints.

Why:

Real peer links fail. The mesh needs pressure signals, lost carrier detection, and server-assisted replacement neighbors.

Done when:

- RTCDataChannel close/error updates carrier state. Complete for assigned gossip neighbors.
- Lost neighbor triggers reconnect/topology repair request over ops lane. Complete for `gossip/topology-repair/request`.
- Backend consumes repair requests, validates context/membership/authenticated peer, and emits bounded replacement topology hints. Complete.
- Cooldowns prevent reconnect storms. Complete with per-neighbor client cooldown.
- New executable contract passes. Complete.

### 6. Add Gossip Telemetry

Impact: medium.

Complexity: medium.

Status: complete for local controller/workspace/RTC transport telemetry.

Why:

The scaling win needs proof. We need counts for server fanout avoided, peer outbound fanout, duplicates, TTL exhaustion, late drops, and per-hop latency.

Done when:

- Diagnostics expose gossip send/receive/forward/drop counters.
- Events distinguish in-memory harness transport vs RTCDataChannel transport.
- Counters include avoided server fanout, peer outbound fanout, duplicates, TTL exhaustion, stale generation drops, RTC queue late drops, and hop latency when timestamp metadata is available.
- New executable contract passes.

## Current Priority

The previous open gossip implementation tasks are complete for the current scaffold:

- Backend topology repair handling now consumes `gossip/topology-repair/request`, validates room/call membership and authenticated peer context, rejects media/signaling/secret fields, and emits bounded replacement topology hints without becoming a media distributor.
- Gossip telemetry now exposes local controller/workspace/RTC transport counters and transport-kind events through executable frontend contracts.

The next highest-impact step is continued conservative rollout observation. The backend now records repair/link-health observations, reads recent object_store health records back into topology planning, avoids recent failed pairs across reconnects/processes, and exposes diagnostic-only rollout gates from sanitized room telemetry aggregates while preserving SFU-first behavior until active-mode gossip quality is proven.

Prerequisite now complete:

- The live gossip data lane must use IIBIN binary envelopes, not JSON text frames.
- Native/server relay work must align with King object_store for control-plane/topology persistence.
- Native/server relay and fallback work must keep LSQUIC/HTTP3 and King binary WebSocket compatibility in the transport contract.
- Browser peer links can remain RTCDataChannel, but server-assisted relay/repair should prefer the native LSQUIC stack where available.
- The King PHP extension builds and loads locally when passed explicitly to PHP. Targeted native PHPTs pass; the full extension suite still has broader existing failures and LSQUIC migration gaps that should stay separate from frontend gossip rollout contracts.

Recent completed step:

- Topology repair planning now reads bounded recent King object_store health observations for the room/call, validates schema/version/kind/context/peer fields, rejects unsafe media/signaling/secret-bearing records, ignores malformed/stale records, and feeds recent failed pairs into topology avoidance.
- Gossip rollout gates now derive duplicate, TTL exhaustion, late-drop, repair-rate, and RTC/topology readiness metrics from sanitized telemetry aggregates or acks; frontend diagnostics remain observational and active mode is only allowed when explicit active mode plus readiness thresholds are met.
- Persistent topology-health records now write sanitized failed-link observations to King object_store-compatible keys and avoid fresh failed pairs during replacement topology generation.
- Room-level gossip telemetry aggregation now accepts sanitized `gossip/telemetry/snapshot` ops-lane messages, validates counters/transport labels, aggregates by room in presence state, and returns `gossip/telemetry/ack`.
- Native linker selector cleanup now lets `make -C extension` link on macOS without passing ELF-only `-soname`; Linux keeps soname behavior.
- Production gossip fanout now defaults to degree 4 and clamps to degree 3..5 in frontend routing and backend topology/repair/forward planning, preventing eligible rooms from degrading into degree-2 cycle graphs.
- The standalone four-peer local gossip harness now has adjustable fanout, defaults to degree 4, clamps to degree 3..5 plus available peers, and is covered by `gossip-harness-faults-contract.mjs` inside `npm run test:contract:gossip`.
- Backend topology repair handling is implemented and covered by `realtime-gossipmesh-runtime-contract.sh`.
- Gossip telemetry is implemented and covered by `gossip-telemetry-contract.mjs` inside `npm run test:contract:gossip`.
- Outbound live gossip publication now runs only after successful conservative SFU send and only when the gossip data lane is `active`.
- Live native gossip data channels are now bound only for server-assigned gossip neighbors.
- Executable contracts cover outbound live publication and server topology ingestion.
- Assigned-neighbor RTCDataChannel health now updates gossip carrier state and requests cooldown-bound topology repair over the ops lane in active mode.
- Live gossip workspace glue has been extracted into `workspace/callWorkspace/gossipDataLane.ts`; shell/sidebar viewport state has been extracted into `workspace/callWorkspace/shellViewport.ts`; diagnostics context registration has been moved into `clientDiagnostics.ts`; the refactor-boundary contract now passes with `CallWorkspaceView.vue` at 2149 lines.

Current failure and warning follow-up:

- Backend topology repair handling is no longer open for the current scaffold.
- Gossip telemetry is no longer open for the current scaffold.
- Persistent topology-health records, object_store readback, room-level telemetry aggregation, and rollout-gate diagnostics are no longer open for the current scaffold.
- The previous Vite `CallWorkspaceView` large route chunk warning is resolved through manual route-graph chunks and a new build-size contract.
- Native MCP remote-control tests `340` and `341` now pass after aligning native runtime-control monotonic milliseconds with PHP `hrtime(true)` via `zend_hrtime() / 1000000`.
- Native cleanup removed generated/autotools/no-op extension churn from the worktree. The macOS `-soname` linker blocker is fixed for `make -C extension`.
- Native curl/pkg-config prerequisite cleanup is complete. Top-level `make build` reaches configure/compile with `pkg-config` absent by selecting the documented Homebrew curl include path on this host.
- Native OpenSSL header/library prerequisite cleanup is complete. Top-level `make build` now selects the documented Homebrew OpenSSL include/library paths on this host and stages release artifacts successfully.
- Native compiler/linker warning cleanup is complete. `make build` now passes on this macOS host without compiler/linker warnings; Darwin libtool generation avoids deprecated `-undefined suppress`, and native prerequisite selectors now explicitly cover Linux, macOS, and Windows candidate families.

## Live Three-Participant Call Log Action Queue

Source:

- Primary bundle: `demo/video-chat/deploy-logs/20260505T153742Z`.
- Focused live call: `26b6a2d6-8368-4bbf-a303-92604db24d32`.
- Relevant slice: 146 client diagnostics with `connected_participant_count = 3` or `remote_peer_count = 2`.

Observed highest-count live failure buckets:

- `media_security_sender_key_not_ready`: 48.
- `sfu_remote_frame_dropped`: 20.
- `sfu_remote_video_decoder_waiting_keyframe`: 19.
- `media_security_sync_failed` with `participant_set_mismatch`: 18.
- `sfu_profile_switch_outbound_reset`: 16.
- `sfu_protected_frame_decrypt_failed`: 11.
- `sfu_protected_frame_waiting_for_media_security`: 9.
- `sfu_publish_waiting_for_media_security`: 7.
- `sfu_remote_video_stalled`: 6.
- `sfu_publisher_frame_stall`: 6.
- `call_workspace_unhandled_rejection` from participant-set mismatch: 6.
- `sfu_browser_encoder_frame_failed`: 5.
- `realtime_signaling_publish_failed`: 4.

### A. Media-Security Participant-Set Recovery Must Become A Contracted Rollout Gate

Impact: very high.

Status: contracted and deployed for participant-set recovery; rollout-gate integration is implemented and contracted; fresh live verification remains.

Why:

The 3-user call showed participant-set drift cascading into `wrong_key_id`, `wrong_epoch`, keyframe storms, held protected frames, and publisher pauses. The immediate runtime fix normalizes descriptive `participant_set_mismatch` errors and schedules participant-set recovery, but the behavior must be contracted before active gossip can rely on protected media frames.

Done when:

- Done: frontend contract covers descriptive messages such as `Participant set mismatch detected (participant_set_mismatch)` entering the same recovery path as the exact legacy code.
- Contract proves no `call_workspace_unhandled_rejection` is emitted for participant-set drift.
- Done: contract proves sender-key cache clearing, room snapshot request, watchdog restart, and participant sync scheduling happen on recovery.
- Done: gossip rollout gate consumes sanitized media-security readiness and blocks `active` data-lane publication/receive when participant-set recovery is still in flight.
- Remaining live verification: new live 3-user log slice shows `media_security_participant_set_recover` replacing repeated `media_security_sync_failed` bursts.

### B. Stale Target And Ghost Peer Pruning Must Be Shared By SFU And Gossip Ops

Impact: high.

Status: partially implemented and deployed for frontend media-security stale target pruning.

Why:

The 3-user logs include `realtime_signaling_publish_failed` with `target_not_in_room`, including stale synthetic target IDs in older rows. Stale targets waste ops-lane retries and can keep media-security participant sets larger than the actual room, which is bad for SFU and would be worse for active gossip topology.

Done when:

- `target_not_in_room` for media-security, call/media-quality-pressure, call/ice, and call/offer prunes stale local participant/peer state consistently.
- Done for the frontend recovery path: pruning invalidates active native peer state, SFU remote peer state, local roster state, and assigned gossip neighbor state when applicable.
- Backend topology repair rejects stale/left targets and never assigns them as gossip neighbors.
- Done for media-security stale targets: `gossip-stale-target-pruning-contract.mjs` covers shared SFU/gossip pruning and the forced keyframe recovery path.
- Diagnostics distinguish expected stale-target pruning from real signaling broker failure.

### C. Keyframe Recovery Needs One Owner And A Bounded Storm Policy

Impact: high.

Status: implemented locally and contracted for SFU keyframe recovery ownership; live browser verification pending.

Why:

The 3-user call shows decoder waiting, delta-before-keyframe drops, full-keyframe requests, quality-pressure full-frame requests, protected-frame waits, and reconnect gates occurring together. Multiple subsystems are asking for the same recovery, which creates churn and visible washed/striped recovery.

Done when:

- Done for SFU: `keyframeRecoveryCoordinator.ts` owns per-publisher full-frame/keyframe request coalescing across receiver feedback and SFU recovery call sites.
- Done: repeated remote full-frame keyframe requests are coalesced by reason, sender, publisher, and minimum interval while recovery is active.
- Done for SFU decode: delta frames are explicitly suppressed until a matching keyframe arrives after decoder reset or sender-key epoch change, and coordinator state clears on rendered keyframes.
- Done: diagnostics report coalesced vs emitted keyframe requests.
- Remaining: run a fresh live 3-user browser call to confirm bounded keyframe request/coalesce diagnostics replace decoder-waiting-keyframe storms.
- Remaining: active gossip receive recovery should use the same coordinator when gossip becomes a primary media path.

### D. WebCodecs Encoder Close Must Be Treated As Lifecycle, Not Fatal Media Failure

Impact: medium-high.

Status: implemented locally; contract covered; live verification still pending.

Why:

The logs show `sfu_browser_encoder_frame_failed` with `Aborted due to close()` and `Cannot call 'encode' on a closed codec` during profile switches/reconnects/background transitions. These should be expected lifecycle races, not repeated error-level failures that trigger quality downgrades and reset loops.

Done when:

- Done: encoder close/abort during intentional profile switch or transport teardown is classified as lifecycle cancellation.
- Done: encode attempts are gated on the current encoder generation before calling `VideoEncoder.encode()`.
- Done: stale async encode completions cannot reset the active generation.
- Done: contract covers profile-switch close, transport reconnect close, and background-pause close.
- Done: diagnostics keep true encoder failures as errors while lifecycle closes are warnings with a distinct close reason.
- Gap: needs a fresh live multi-user browser run to confirm profile-switch, reconnect, and background-pause closes stay as bounded lifecycle warnings rather than `sfu_browser_encoder_frame_failed` bursts.

### E. WASM Codec ABI/Version Mismatch Must Fail Closed Before Live Decode

Impact: high.

Status: complete.

Why:

The 3-user logs include `wlvc_encode_frame_failed`, `sfu_remote_decoder_reconfigure_failed`, and patch decoder failures with constructor arity mismatches, plus decode failures such as out-of-bounds memory access and height mismatch. That points to stale or mismatched WASM/JS codec contracts in the live asset path.

Done when:

- WLVC/WASM module exports an explicit ABI/version descriptor checked before encode/decode.
- JS refuses to construct encoder/decoder when expected constructor arity or ABI version differs.
- Asset version diagnostics include WLVC JS version and WASM ABI version.
- Contract simulates stale JS/new WASM and new JS/stale WASM and requires a clean compatibility fallback instead of runtime constructor errors.
- Gossip data-lane receive path refuses gossip media frames when codec ABI is not proven compatible.

### F. SFU Send Backpressure And Chunk Size Need A Three-Participant Budget

Impact: medium-high.

Status: implemented locally and contracted for receiver-count-aware SFU send budget; live browser verification pending.

Why:

The 3-user call shows frame send aborts while waiting for WebSocket drain and large payloads in the 120 KiB to 460 KiB range with 20 to 76 chunks. Backpressure and chunk volume interact with keyframe storms and increase latency.

Done when:

- Done: per-sender SFU send budget accounts for connected remote receiver count and chunk count.
- Done: keyframe/send pressure can trigger profile downshift when buffered amount, chunk volume, and receiver fanout cross profile thresholds.
- Done: rescue/realtime/balanced profile selection considers `websocketBufferedAmount`, chunk count, and receiver count together.
- Done: diagnostics include frame/backpressure reason, receiver count, chunk count, active profile, and receiver-count budget details.
- Remaining: run a fresh live 3-user browser call to confirm bounded `sfu_frame_send_*`/backpressure diagnostics without repeated send-drain abort storms.
- Remaining: active gossip publication should inherit this sender budget if it starts publishing independently of the conservative SFU-success mirror.

### G. Background Tab Publishing Policy Needs Explicit Multi-Participant Semantics

Impact: medium.

Status: implemented locally and contract-covered; live browser verification pending.

Why:

The logs show `sfu_background_tab_video_paused` while a 3-user call still has remote peers. If several test users are on one computer, background tabs can pause publishers and look like network/media instability.

Done when:

- Done: background-tab policy distinguishes preview-only throttling from remote publisher obligations.
- Done: hidden tabs with remote peers keep publishing active and request `sfu_background_tab_publisher_marker` instead of silently unpublishing.
- Done: diagnostics identify browser visibility state, active publisher layer, remote peer count, and whether pause was intentional.
- Done: `kingrt-three-user-regression-harness.mjs` emulates three KingRT participants and background-publisher behavior with production runtime helpers.
- Remaining: run a real live/browser three-tab call with one foreground and two background publishers to verify the keyframe-marker path in browser media timing.

### H. Publisher Stall Recovery Should Prefer Targeted Resubscribe Before Socket Reconnect

Impact: medium.

Status: implemented locally and contract-covered; live browser verification pending.

Why:

`sfu_publisher_frame_stall`, `sfu_remote_video_stalled`, `sfu_video_reconnect_blocked`, and `sfu_socket_closed` appear together. Hard reconnect is expensive and can restart media-security/keyframe churn; targeted per-publisher resubscribe should be preferred.

Done when:

- Done for SFU: stall recovery attempts publisher-scoped resubscribe, keyframe request, and security resync before full SFU socket reconnect.
- Done: backoff is per publisher, reason, and ladder step.
- Done: decoder/keyframe/security waits try targeted recovery before full reconnect; transport-level failures can still use reconnect.
- Done: diagnostics show recovery ladder step: resubscribe, keyframe, security resync, or reconnect fallback.
- Remaining: run a fresh live 3-user browser call to confirm `sfu_publisher_stall_recovery_ladder` diagnostics appear before any full reconnect for remote-video stalls.
- Remaining: active gossip missing-frame recovery should call the same ladder when gossip becomes a primary media path.

### I. SQLite Login Locks Need Backend Startup/Write Serialization

Impact: medium.

Status: open.

Why:

Post-deploy logs show intermittent `SQLSTATE[HY000]: General error: 5 database is locked` during login under the 24-worker HTTP startup pattern. That blocks users from joining calls and contaminates call stability debugging.

Done when:

- Done: backend bootstrap/demo seeding is serialized with a per-database `.bootstrap.lock` file lock.
- Done: login retries transient SQLite locks with bounded jitter and returns retryable `auth_login_retryable_locked` / HTTP 503 when SQLite remains locked.
- SQLite pragmas and write transaction scopes are audited for WAL/busy-timeout consistency across HTTP, WS, SFU, and broker DBs.
- Done: `auth-sqlite-lock-contract.php` simulates login against a locked SQLite path and proves the retryable response contract.

### J. Three-User Browser Regression Harness Must Replay These Failure Classes

Impact: high.

Status: open.

Why:

The existing standalone harnesses are useful, but the live 3-user failure combines participant churn, background-tab behavior, media-security rekeying, keyframe waits, encoder profile switches, and stale targets. This needs a browser-visible regression harness before enabling active gossip.

Done when:

- Done: `tests/standalone/kingrt-three-user-regression-harness.mjs` emulates three KingRT participants using production layout, background policy, media-security, keyframe/backpressure, gossip, and browser-encoder helpers.
- Done: scenarios cover participant churn, background publisher handling, stale target prune, keyframe coalescing, encoder lifecycle close diagnostics, and protected-frame recovery diagnostics.
- Done: harness emits live diagnostic names including `sfu_background_tab_publisher_obligation_preserved`, `gossip_assigned_neighbor_pruned`, `sfu_remote_full_keyframe_request_coalesced`, `sfu_browser_encoder_lifecycle_close`, and `sfu_protected_frame_decrypt_failed`.
- Done: `kingrt-three-user-regression-harness-contract.mjs` prevents synthetic-only replacement by asserting production helper imports/use.
- Remaining: run the harness against actual browser tabs/media devices and compare fresh live deploy logs against the previous three-user failure slice.

### K. Gossip Rollout Gates Must Include SFU Baseline Health Before Active Data Lane

Impact: high.

Status: implemented and contracted; fresh live verification remains before active gossip rollout.

Why:

The current call remains SFU-first, but active gossip would otherwise inherit unstable inputs and possibly amplify them. The 3-user logs define concrete baseline health signals that must be clean before gossip can carry media.

Done when:

- Done: rollout gate blocks `active` gossip when recent SFU baseline has participant-set recovery storms, protected-frame decrypt bursts, keyframe storm rate above threshold, stale-target prune storms, encoder lifecycle close storms, or send-backpressure abort storms.
- Done: gate thresholds are computed from sanitized telemetry, not raw media or secrets.
- Done: `shadow` mode records what active gossip would have done without publishing media.
- Done: dashboard/diagnostic output identifies which baseline bucket blocks active gossip.

Implemented in this iteration:

- Frontend rollout gates now require clean sanitized SFU baseline/media-security readiness before active gossip media publish or receive.
- Gate buckets cover participant-set recovery storms/in-flight recovery, protected-frame decrypt bursts, keyframe storms, stale-target prune storms, encoder lifecycle close storms, and send-backpressure abort storms.
- Shadow mode records sanitized `gossip_data_lane_shadow_would_publish` diagnostics and `would_publish_frames` telemetry without publishing media payloads.
- `gossip-sfu-baseline-rollout-gate-contract.mjs` pins the gate buckets, sanitized output, active blocking behavior, and shadow would-publish behavior.

Remaining live verification:

- Fresh multi-user live logs must show sanitized `gossip/telemetry/ack` SFU-baseline and media-security buckets staying below thresholds before active gossip carries media.

## Next Candidate Tasks

### 1. Windows Native Build CI Runner

Impact: medium.

Complexity: medium-high.

Status: future-blocked.

Why:

The native build scripts now have explicit Windows selector branches for Cygwin/MINGW/MSYS-style environments and vcpkg/MSYS2 curl/OpenSSL candidates, but this repository cannot prove the full Windows native extension build without a real Windows runner.

Blocked on:

- Access to a Windows CI runner or equivalent hosted Windows native build environment.
- A decided Windows toolchain target, for example MSYS2/MinGW, Cygwin, or a PHP SDK/vcpkg-based flow.

Done when:

- CI has a Windows native build lane that runs the relevant prerequisite selector contracts.
- The Windows lane exercises `make build` or the Windows-equivalent native extension build path.
- Any Windows-specific curl/OpenSSL/LSQUIC path differences are contracted.
- The lane is documented as future validation, not a local developer requirement.

### 2. Native Compiler Warning Cleanup

Impact: low-medium.

Complexity: medium.

Status: complete.

Why:

Top-level `make build` now succeeds, but it still emits existing compiler warnings. Warning cleanup should be separate from prerequisite/build unblocking so it can be reviewed safely.

Done when:

- macOS deprecated `syscall` diagnostics are resolved or intentionally isolated behind platform selectors. Complete with `pthread_threadid_np()` on Darwin and `SYS_gettid` on Linux.
- `zend_long` format warnings use the correct portable format specifier/cast. Complete across `extension/src`.
- Focused native PHPTs and `make build` still pass. Complete.

### 3. Native OpenSSL Header/Library Prerequisite Cleanup

Impact: medium.

Complexity: medium.

Status: complete.

Why:

The curl/pkg-config blocker was resolved for this host and `make build` then reached extension compilation. The next native blocker was missing OpenSSL headers at `extension/src/media/rtp.c`.

Done when:

- Build scripts select a documented vendored/system OpenSSL header path on macOS and Linux. Complete.
- The failure message names the exact install/bootstrap command. Complete.
- `make build` reaches the next compile/link stage on a clean macOS checkout when system OpenSSL headers are installed. Complete; it now builds and stages release artifacts on this host.
- Existing focused native PHPTs still pass. Complete.

### 4. Native Curl/pkg-config Build Prerequisite Cleanup

Impact: medium.

Complexity: medium.

Status: complete.

Why:

The macOS `-soname` linker failure is fixed, and `make -C extension` passes. The top-level `make build` path still fails early when curl headers are missing and `pkg-config` is unavailable.

Done when:

- Build scripts select a documented vendored/system curl header path on macOS and Linux. Complete.
- Build scripts document matching macOS/Linux libcurl library/runtime candidates without adding a hard libcurl link. Complete.
- The failure message names the exact install/bootstrap command. Complete.
- `make build` reaches configure/compile on a clean macOS checkout when system curl headers are installed but `pkg-config` is absent. Complete on this host with `-I/opt/homebrew/opt/curl/include`.
- Existing focused PHPTs still pass. Complete.

Known remaining native follow-up:

- Windows native build validation remains future-blocked because no Windows machine/runner is currently available locally.

## Step Checklist Template

For each step:

1. Update implementation.
2. Add or update executable contract/test.
3. Run the focused new check.
4. Run adjacent existing checks.
5. Update `GOSSIP_CURRENT_BUILD.md`.
6. Update `GOSSIP_PLANNING.md`.
7. Record known gaps honestly.
