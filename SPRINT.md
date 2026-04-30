# King Active Issues

Purpose:
- This file contains the active sprint issues for the current branch only.
- Detailed history, parked ideas, and overflow items belong in `BACKLOG.md`.
- Completion evidence belongs in `READYNESS_TRACKER.md`.

Sprint rule:
- Keep only issues that directly increase online video-call fullscreen quality and throughput on the protected SFU media path.
- Do not weaken King v1 contracts to make capture, encoding, buffering, transport, or rendering cheaper.
- Do not grow `CallWorkspaceView.vue`; new call-runtime behavior belongs in focused helpers/modules.
- Keep media-security, binary SFU envelopes, bounded SQLite frame buffering, live relay, receiver feedback, and online pressure contracts intact.
- Video quality must stay automatic; there must be no user-facing quality selector in the call UI.
- Layout-driven portrait framing must be applied before encode when possible; do not fake portrait video by stretching a landscape frame in CSS.

## Sprint: Video Call SFU Layer State Wiring

Sprint branch:
- `hotfix/video-call-sfu-layer-state-wiring`

PR target:
- `development/1.0.7-beta`

Deployed baseline:
- `development/1.0.7-beta` includes the fullscreen quality path with protected browser encoder support, fullscreen-aware rendering, and automatic SFU layer preference signaling.

Production symptom:
- Production bundle `CallWorkspaceView-VElCjgkt.js` throws `Cannot set properties of undefined (setting 'sfuRemoteLayerPreferenceLastAtMs')` inside the websocket message handler when an adaptive SFU layer preference arrives.
- The crash stops subsequent signaling handling and can break the call even though media transport is otherwise connected.

Technical target:
- Wire adaptive SFU layer state into the socket lifecycle helper and keep the helper defensive so missing isolated-test refs cannot crash runtime signaling.
- Keep the adaptive layer contract intact: quality remains automatic, receiver feedback remains backend-routed, and primary-layer requests still protect fullscreen/main quality.
- Record the next improvements that can materially raise quality beyond this hotfix.

## Active Issues

1. [x] `[socket-layer-state-wiring-hotfix]` Fix adaptive SFU layer preference socket crash.

   Scope:
   - Inject `sfuTransportState` into `createCallWorkspaceSocketHelpers`.
   - Add a defensive socket-lifecycle fallback state so adaptive layer messages cannot throw if a test harness or future caller omits optional refs.
   - Extend the adaptive layer contract to prove the wiring exists and is guarded.

   Done when:
   - `call/media-quality-pressure` with `prefer_primary_video_layer` or `prefer_thumbnail_video_layer` no longer crashes the websocket handler.
   - The primary-layer TTL and thumbnail-ignore behavior still use the shared SFU transport state in the real app.
   - Contract tests fail if the socket helper loses `sfuTransportState` wiring again.

   Report:
   - Added `sfuTransportState` to the socket lifecycle helper refs in `CallWorkspaceView.vue`.
   - Added `sfuTransportStateForSocketLifecycle()` with a local fallback state to prevent websocket-handler crashes in omitted-ref contexts.
   - Extended `sfu-adaptive-quality-layers-contract.mjs` to pin both real app injection and defensive helper behavior.
   - Verification: `node tests/contract/sfu-adaptive-quality-layers-contract.mjs`, `npm run test:contract:sfu`, `npm run build`, `git diff --check`.

2. [x] `[server-authoritative-subscriber-layer-routing]` Move layer choice from global publisher profile pressure to per-subscriber SFU routing.

   Scope:
   - Add subscriber layer preference state to the SFU websocket session.
   - Filter direct fanout by subscriber preference so thumbnail receivers cannot force the fullscreen receiver down.
   - Preserve SQLite buffering, binary envelopes, and media-security metadata.

   Done when:
   - A subscriber in mini/grid can receive low-cost frames while a fullscreen subscriber keeps primary frames from the same publisher.
   - Slow-subscriber isolation remains intact and diagnostics show which layer each subscriber received.
   - Backend and frontend contracts prove layer preferences are server-authoritative, not just local UI hints.

   Report:
   - Root cause found in production logs: high `king_receive_latency_ms` showed frames were already seconds old before the SFU received them; repeated `sfu_frame_sqlite_buffer_insert_failed` showed the bounded SQLite replay buffer was being bypassed; most cross-worker frames then arrived through `live_relay_poll`, adding latency and replay churn.
   - Added post-drain stale-frame rejection in the SFU client, so frames that age past their profile queue budget while waiting for websocket backpressure are dropped before `WebSocket.send`.
   - Preserved measured `payload_bytes` when the SFU gateway creates broker frames, so protected frames are budgeted by actual bytes instead of falling back to a too-small record cap.
   - Added SFU websocket `sfu/layer-preference` control messages and server-side subscriber preference state.
   - Routed direct fanout, SQLite replay, and live-relay replay through subscriber-aware layer decisions so thumbnail/grid receivers cannot force the publisher profile down for fullscreen/main receivers.
   - Verification: `node tests/contract/sfu-browser-ws-send-drain-contract.mjs`, `node tests/contract/sfu-relay-broker-io-budget-contract.mjs`, `node tests/contract/sfu-adaptive-quality-layers-contract.mjs`, `npm run test:contract:sfu`, `npm run build`, `php -l` on touched PHP modules, `git diff --check`.

3. [ ] `[dual-encoder-primary-thumbnail-publish]` Publish separate protected primary and thumbnail streams from one camera capture.

   Scope:
   - Keep one camera source and one media-security session, but produce separate encoded outputs for primary and thumbnail profiles.
   - Prefer browser `VideoEncoder` for both layers where available; keep WLVC fallback bounded.
   - Tie layer selection to backend subscriber routing instead of forcing one global publisher profile.

   Done when:
   - Fullscreen quality is no longer capped by thumbnail/grid delivery.
   - Thumbnail streams do not trigger primary stream source-readback or wire-rate pressure.
   - Contracts prove deterministic `VideoFrame`/chunk closure for both layers.

   Report:
   - Proposed next improvement.

4. [ ] `[online-video-quality-regression-probes]` Add automated online probes for blockiness, frame lifecycle, and console cleanliness.

   Scope:
   - Extend production E2E to detect repeated `VideoFrame was garbage collected without being closed`, websocket handler exceptions, and critical SFU pressure logs.
   - Add a canvas/video visual quality probe that catches extreme macroblocking or 2-participant grid over-downshift.
   - Send all relevant warnings into backend diagnostics instead of browser console-only output.

   Done when:
   - A deployed build fails smoke/acceptance if the websocket handler throws, remote video stops rendering, or `VideoFrame` lifecycle warnings recur.
   - Two-participant calls assert primary-layer quality instead of accepting thumbnail-level output.
   - The report includes backend diagnostic IDs for failures instead of requiring browser console screenshots.

   Report:
   - Proposed next improvement.

5. [x] `[client-side-portrait-roi-crop-before-encode]` Encode the visible portrait crop instead of transmitting unused landscape side bands.

   Scope:
   - Add automatic layout-aware region-of-interest framing before the publisher encode step: when the target tile is portrait, zoom the camera frame until the portrait viewport is filled and crop the left/right landscape margins.
   - Apply the crop in the capture/composition pipeline before `VideoEncoder` or WLVC encode, not as receiver-only CSS, so fewer pixels go over the SFU path and the remaining visible area can use higher effective quality.
   - Preserve correct aspect ratio and avoid stretching for portrait camera sources, landscape camera sources, fullscreen, mini-video, and grid tiles.
   - Treat fullscreen as a first-class framing target: fullscreen landscape should keep full-width detail, fullscreen portrait should crop/zoom intentionally without desktop stretching.
   - Toggle fullscreen by double-clicking a video tile; a second double-click on the fullscreen video exits fullscreen and restores the previous grid/mini framing target.
   - Treat every mini-video as a square framing target with client-side crop/zoom, no rounded visual masking, and no transport of unused side bands.
   - Keep quality automatic; the UI may expose framing affordances later, but this sprint must not add a manual quality selector.

   Done when:
   - A landscape camera rendered into a portrait tile is center-cropped before encode and does not transmit the discarded side bands.
   - A portrait camera stays portrait without desktop stretching or horizontal overfill.
   - Fullscreen playback selects the correct landscape or portrait crop and remains sharp instead of falling back to thumbnail framing.
   - Double-clicking a video enters fullscreen for that participant, and double-clicking again exits without breaking the SFU layer preference state.
   - Mini-video thumbnails are square, crop/zoom correctly, and do not distort the source aspect ratio.
   - The receiver sees the intended crop with sharper visible detail at the same or lower wire budget.
   - Contracts cover the crop math for landscape-to-portrait, portrait-to-portrait, fullscreen landscape, fullscreen portrait, and square mini-video cases.

   Report:
   - Added layout-derived framing metadata (`contain`/`cover` plus target aspect ratio) to mounted video surfaces.
   - Added client-side source crop math before publisher encode, including DOM canvas, OffscreenCanvas worker, and VideoFrame copy-scale readback paths.
   - Mini-videos are square crop/zoom targets; portrait/square main or fullscreen surfaces crop before encode when the surface aspect demands it.
   - Added double-click fullscreen toggle: double-click a grid, mini, or main video to enter fullscreen; double-click the fullscreen video again to restore the previous layout.
   - Added contract coverage for crop math, fullscreen toggling, worker crop propagation, and CSS framing mode.

6. [x] `[remote-video-reconnect-loop-backoff]` Stop repeated hard SFU reconnects during remote video recovery.

   Scope:
   - Keep fast recovery actions intact: resubscribe, full-keyframe request, and automatic sender quality pressure.
   - Add per-remote-peer backoff for hard SFU socket restarts so stale `createdAtMs` or `lastFrameAtMs` cannot trigger a reconnect loop.
   - Reset the hard-reconnect backoff as soon as a fresh decoded frame renders.
   - Preserve backend diagnostics so the reason is visible without browser console screenshots.

   Done when:
   - A missing or frozen remote video can still recover automatically.
   - Hard SFU socket restarts are gated per peer with increasing backoff instead of firing again from the same stale peer state.
   - Successful remote rendering clears the backoff and returns the participant media state to live.
   - Contracts prove the reconnect gate, diagnostics, and reset-on-render behavior.

   Report:
   - Root cause: after `remote_video_never_started`/`remote_video_frozen`, the peer kept its old timing fields, so the health timer could see the same peer as immediately eligible again after the global reconnect cooldown.
   - Added per-peer restart state (`sfuSocketRestartCount`, `lastSfuSocketRestartAtMs`, `nextSfuSocketRestartAllowedAtMs`) and exponential backoff around hard socket restarts.
   - Kept lightweight recovery fast: `sfu/subscribe`, full-keyframe request, and remote quality-pressure still run before a hard reconnect.
   - Fresh rendered frames now clear the hard-reconnect debt.
   - Verification: `node tests/contract/sfu-video-recovery-timing-contract.mjs`, `npm run test:contract:sfu`.

7. [x] `[remote-wlvc-tile-deblocking]` Smooth visible WLVC tile and block artifacts on remote canvases.

   Scope:
   - Keep the current canvas resolution and automatic quality control intact.
   - Add a lightweight receiver-side deblocking pass after remote SFU frame compositing.
   - Target selective tile composites and lower-quality WLVC full frames, where block edges are visible in large desktop tiles.
   - Preserve original detail by blending a small blur over the decoded canvas instead of replacing it with a blurred-only image.

   Done when:
   - Large remote canvas tiles no longer show harsh checker/tile boundaries from WLVC quantization or selective patch seams.
   - The smoothing path runs after `tile_foreground` / `background_snapshot` composition and after low-quality full-frame decode.
   - Contract coverage pins the deblocking helper and decode integration.

   Report:
   - Added `softDeblockDecodedCanvas()` with scratch-canvas reuse, small blur radius, and alpha blending.
   - Applied the deblock pass after remote frame composition in the SFU decode path.
   - Kept high-quality full frames untouched unless they are selective tile composites.
   - Verification: `node tests/contract/sfu-selective-tile-runtime-contract.mjs`.

8. [x] `[room-leave-roster-video-prune]` Remove departed participants from roster and video layout immediately.

   Scope:
   - Treat `room/left` as an authoritative local prune signal, not only as a reason to poll a snapshot.
   - Remove the departed participant from roster state, SFU remote peers, native peer connection state, pinned/muted/control state, and activity state.
   - After backend leave/disconnect cleanup, broadcast a fresh per-viewer room snapshot so stale DB presence cannot re-add the departed participant.

   Done when:
   - Clicking leave removes that user from other clients' participant list and video tiles immediately.
   - The grid recomputes from the remaining connected participants so another participant can move into the freed visible slot.
   - Room snapshots after leave/disconnect are emitted only after call-presence DB cleanup.

   Report:
   - `room/left` now calls the same local cleanup path as `call/hangup` before requesting a snapshot backfill.
   - Backend leave/disconnect paths remember the previous room, remove DB presence, mark `left_at`, then broadcast an authoritative room snapshot to remaining participants.
   - Verification: `node tests/contract/call-room-leave-cleanup-contract.mjs`, `php demo/video-chat/backend-king-php/tests/realtime-room-leave-snapshot-contract.php`.

## Execution Order

1. Finish `[socket-layer-state-wiring-hotfix]`.
2. Deploy the hotfix and verify production smoke.
3. Open PR to `development/1.0.7-beta`.
4. Use the proposed issues as the next quality-hardening sprint after this hotfix is merged.
