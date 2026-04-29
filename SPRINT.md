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
- Record three next improvements that can materially raise quality beyond this hotfix.

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

2. [ ] `[server-authoritative-subscriber-layer-routing]` Move layer choice from global publisher profile pressure to per-subscriber SFU routing.

   Scope:
   - Add subscriber layer preference state to the SFU websocket session.
   - Filter direct fanout by subscriber preference so thumbnail receivers cannot force the fullscreen receiver down.
   - Preserve SQLite buffering, binary envelopes, and media-security metadata.

   Done when:
   - A subscriber in mini/grid can receive low-cost frames while a fullscreen subscriber keeps primary frames from the same publisher.
   - Slow-subscriber isolation remains intact and diagnostics show which layer each subscriber received.
   - Backend and frontend contracts prove layer preferences are server-authoritative, not just local UI hints.

   Report:
   - Proposed next improvement.

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

## Execution Order

1. Finish `[socket-layer-state-wiring-hotfix]`.
2. Deploy the hotfix and verify production smoke.
3. Open PR to `development/1.0.7-beta`.
4. Use the three proposed issues as the next quality-hardening sprint after this hotfix is merged.
