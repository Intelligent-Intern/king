# King Active Issues

Purpose:
- This file contains exactly 20 active sprint issues.
- Detailed history, parked ideas, and overflow items belong in `BACKLOG.md`.
- Completion evidence belongs in `READYNESS_TRACKER.md`.

Active GitHub issue:
- #148 Batch 3: Core Runtime, Experiment Intake, And Release Closure (`1.0.7-beta`)

Sprint rule:
- Keep only issues that directly remove the current publisher source-readback bottleneck in the SFU/WLVC online video call path.
- Do not weaken King v1 contracts to make frame capture cheaper.
- Do not grow `CallWorkspaceView.vue`; new call-runtime behavior belongs in focused helpers/modules.
- Keep media-security, binary SFU envelopes, no-SQLite-frame-persistence, live relay, receiver feedback, and online pressure contracts intact.
- Video quality must not be user-selectable in the call UI; automatic profile selection must control the full capture/readback/encode/wire budget, not only the final SFU wire profile.

## Sprint: Publisher VideoFrame Readback Hotpath Replacement

Sprint branch:
- `sprint/video-call-hardening`

PR target:
- `development/1.0.7-beta`

Production symptom:
- Deployed bundle `CallWorkspaceView-Dt8LisTE.js` reports repeated send failures before encode:
  - `reason=sfu_source_readback_budget_exceeded`
  - `stage=dom_canvas_readback`
  - `source=canvas_draw_image_budget_exceeded`
  - `transport=publisher_source_readback`
  - `buffered=0`
- This means the bottleneck is local publisher source readback on the browser main thread, not SFU network backpressure.

Technical target:
- Move the hot path away from DOM `canvas.drawImage(video, ...)` plus `getImageData(...)` on the main thread.
- Prefer `MediaStreamTrackProcessor` and `VideoFrame` copy paths where supported.
- Use worker/off-main-thread processing with `OffscreenCanvas` as the fallback.
- Keep a DOM-canvas fallback only as a compatibility path with much lower capture size/FPS and explicit diagnostics.

## Top 20 Active Issues

1. [x] `[readback-path-trace]` Trace the full publisher path from `getUserMedia` frame delivery through source readback, WLVC encode, protected-frame wrapping, binary SFU envelope, and socket send; record exact timing fields and failure reasons for each stage.
2. [x] `[feature-detect-capture-pipeline]` Add a focused capability detector for `MediaStreamTrackProcessor`, `VideoFrame.copyTo`, `VideoFrame.close`, `OffscreenCanvas`, worker transfer support, and DOM-canvas fallback support.
3. [x] `[capture-worker-boundary]` Create a dedicated publisher capture worker module that owns off-main-thread frame scaling/readback where browser support allows it, without importing Vue or workspace state.
4. [x] `[video-frame-primary-path]` Implement the primary `MediaStreamTrackProcessor` -> `VideoFrame` path so camera frames can be pulled without drawing the `<video>` element into a DOM canvas each frame.
5. [x] `[video-frame-rgba-copy]` Feed WLVC with normalized RGBA/I420-derived pixel buffers from `VideoFrame.copyTo` when available, avoiding `getImageData` on the main thread.
6. [ ] `[offscreen-canvas-fallback]` Implement an `OffscreenCanvas` worker fallback for browsers that cannot copy `VideoFrame` planes directly but can move scaling/readback off the main thread.
7. [ ] `[dom-canvas-last-resort]` Keep DOM canvas as the last-resort fallback only; cap it to conservative dimensions/FPS and label diagnostics as compatibility fallback instead of normal operation.
8. [ ] `[source-budget-profile-coupling]` Make `quality`, `balanced`, `realtime`, and `rescue` automatic profiles set capture dimensions, readback FPS, keyframe cadence, and wire byte budgets together.
9. [x] `[quality-ui-removal-contract]` Remove the visible quality selector from the call UI and prove quality changes only through automatic profile switching and diagnostics.
10. [ ] `[auto-readback-downgrade]` On two consecutive source-readback budget failures, reduce capture resolution/FPS before another frame is read, not after repeated failed encode/send attempts.
11. [ ] `[auto-readback-recovery]` After a stable window with low readback timing and no source failures, probe one quality tier upward without causing SFU socket restart churn.
12. [ ] `[high-motion-readback-budget]` Add a high-motion local benchmark/contract proving the selected readback path stays inside budget at each supported profile.
13. [ ] `[portrait-aspect-preservation]` Preserve portrait and rotated camera aspect ratios through VideoFrame, worker scaling, WLVC metadata, remote canvas render, mini strip, and grid layouts.
14. [ ] `[background-tab-policy]` Handle minimized/background browser behavior explicitly: detect throttling, degrade to audio/status or low-FPS keepalive without pretending video is healthy.
15. [ ] `[processor-error-recovery]` Recover from `MediaStreamTrackProcessor`, worker, `VideoFrame`, and `OffscreenCanvas` failures by restarting only the capture pipeline first, not the whole SFU socket.
16. [ ] `[media-security-unchanged]` Prove protected-media security remains unchanged: source pipeline replacement must still emit the same protected-frame envelope and key/session semantics.
17. [ ] `[no-frame-persistence-regression]` Prove the new capture path still keeps SFU media on the live websocket path and does not persist video frames in SQLite or any backend database.
18. [ ] `[online-pressure-readback-proof]` Extend online SFU pressure acceptance so it fails on `sfu_source_readback_budget_exceeded`, not only send-buffer or SFU relay pressure.
19. [ ] `[diagnostic-surface]` Add clear client diagnostics for active capture backend, selected profile, source frame size/FPS, readback timing, dropped-source-frame count, and automatic quality transitions.
20. [ ] `[deploy-proof]` After implementation, deploy to `kingrt.com` and record production proof with moving remote video, no source-readback budget failures, no critical SFU pressure, and stable protected SFU media.

## Execution Order

1. Finish issues 1-2 before changing capture behavior.
2. Build the worker/VideoFrame path behind capability detection and keep the current DOM path as fallback until tests prove parity.
3. Couple automatic quality selection and downgrade to source readback before touching SFU restart policy.
4. Add contracts and high-motion pressure proof before deploying.
5. Update `READYNESS_TRACKER.md` only after the sprint is complete.

## Issue Reports

### 1. `[readback-path-trace]`

Status: Done.

Implementation:
- Added per-frame publisher trace IDs and stage chains from source delivery through DOM draw/readback, WLVC encode, protected-frame wrapping/skipping, binary envelope encoding, and browser websocket send.
- Propagated trace fields through frame payload normalization, SFU transport samples, send-failure details, and workspace diagnostics.
- Kept the live SFU websocket path unchanged while extracting trace/sample helpers so the oversized client files trend down instead of growing.

Verification:
- `npm run test:contract:sfu` in `demo/video-chat/frontend-vue`
- `node demo/video-chat/frontend-vue/tests/contract/client-diagnostics-contract.mjs`
- `npm run build` in `demo/video-chat/frontend-vue`
- `git diff --check`

Deploy proof:
- Deployed to `https://kingrt.com/`.
- `demo/video-chat/scripts/deploy-smoke.sh` passed.
- `https://api.kingrt.com/api/runtime` returned `{"service":"video-chat-backend-king-php","status":"ok"}`.
- Production asset version `20260429050408` served `CallWorkspaceView-CzO0dEHV.js` with `publisher_frame_trace_id`, `trace_binary_envelope_encode_ms`, `trace_browser_websocket_send_ms`, and `browser_websocket_send`.
- The same deploy still served `WorkspaceShell-3JCxkHpD.js` without `call-left-video-quality`, `SFU_VIDEO_QUALITY_PROFILE_OPTIONS`, or `callVideoQualityOptions`.

### 2. `[feature-detect-capture-pipeline]`

Status: Done.

Implementation:
- Added a focused publisher capture capability detector for `MediaStreamTrackProcessor`, `VideoFrame.copyTo`, `VideoFrame.close`, `OffscreenCanvas`, worker/message transfer support, and DOM canvas readback fallback.
- Added deterministic backend selection for `video_frame_copy`, `offscreen_canvas_worker`, `dom_canvas_fallback`, and `unsupported`.
- Wired the detector into existing local capture diagnostics so publish/reconfigure reports include the active capture backend and support flags.

Verification:
- `node demo/video-chat/frontend-vue/tests/contract/sfu-capture-pipeline-capabilities-contract.mjs`
- `npm run test:contract:sfu` in `demo/video-chat/frontend-vue`
- `npm run build` in `demo/video-chat/frontend-vue`
- `git diff --check`

Deploy proof:
- Deployed to `https://kingrt.com/`.
- `demo/video-chat/scripts/deploy-smoke.sh` passed.
- `https://api.kingrt.com/api/runtime` returned `{"service":"video-chat-backend-king-php","status":"ok"}`.
- Production asset version `20260429051000` served `CallWorkspaceView-CWQP-srz.js` with `supports_media_stream_track_processor`, `supports_video_frame_copy_to`, `supports_offscreen_canvas_transfer`, `supports_dom_canvas_fallback`, and `capture_backend`.

### 3. `[capture-worker-boundary]`

Status: Done.

Implementation:
- Added a dedicated publisher capture worker protocol with explicit init, readback, result, error, reset, and close messages plus transfer-list helpers.
- Added a module-worker factory and capability gate for worker-backed publisher capture without importing Vue or workspace state.
- Added the worker module that owns off-main-thread aspect-preserving scaling, `drawImage`, RGBA readback, timing reports, transferred readback buffers, and source-frame close handling.
- Kept runtime hotpath activation out of this checkbox; the primary `VideoFrame` path and `OffscreenCanvas` fallback integration remain issues 4-6.

Verification:
- `node demo/video-chat/frontend-vue/tests/contract/sfu-capture-worker-boundary-contract.mjs`
- `npm run test:contract:sfu` in `demo/video-chat/frontend-vue`
- `npm run build` in `demo/video-chat/frontend-vue`
- `git diff --check`

Deploy proof:
- Deployed to `https://kingrt.com/`.
- `demo/video-chat/scripts/deploy-smoke.sh` passed.
- `https://api.kingrt.com/api/runtime` returned `{"service":"video-chat-backend-king-php","status":"ok"}`.
- Production asset version `20260429051534` served `CallWorkspaceView-BjkUHtR9.js`; this checkbox deploys the worker boundary and contract proof, while runtime capture-path activation is tracked by issues 4-6.

### 4. `[video-frame-primary-path]`

Status: Done.

Implementation:
- Added a `MediaStreamTrackProcessor` source reader that pulls `VideoFrame` objects directly from the camera track with timeout/fatal fallback handling and explicit `VideoFrame.close()` cleanup.
- Added a publisher source-readback controller that chooses the `VideoFrame` source when browser capabilities allow it, falls back to DOM video canvas otherwise, and keeps source-readback budget checks before WLVC encode.
- Moved direct DOM canvas readback out of `publisherPipeline.js`; the pipeline now reads via the source controller and no longer calls `ctx.drawImage(video, ...)` itself.
- Extracted local stream lifecycle helpers so `publisherPipeline.js` shrank from 885 to 846 lines while adding the new capture path.

Verification:
- `node demo/video-chat/frontend-vue/tests/contract/sfu-video-frame-primary-path-contract.mjs`
- `npm run test:contract:sfu` in `demo/video-chat/frontend-vue`
- `npm run build` in `demo/video-chat/frontend-vue`
- `git diff --check`

Deploy proof:
- Deployed to `https://kingrt.com/`.
- `demo/video-chat/scripts/deploy-smoke.sh` passed.
- `https://api.kingrt.com/api/runtime` returned `{"service":"video-chat-backend-king-php","status":"ok"}`.
- Production asset version `20260429052354` served `CallWorkspaceView-D-HLrmll.js` with `MediaStreamTrackProcessor`, `video_frame_processor_canvas_readback`, and `publisher_video_frame_read_timeout`.

### 5. `[video-frame-rgba-copy]`

Status: Done.

Implementation:
- Added a direct `VideoFrame.copyTo(..., { format: 'RGBA' })` helper that produces `ImageData` for WLVC without `drawImage` or `getImageData`.
- Wired the source-readback controller to try direct `VideoFrame` RGBA copy before canvas fallback when the source frame already matches the active profile dimensions.
- Added budget handling and trace timing for `video_frame_copy_to_rgba`; fatal copy failures disable the copy path and fall back to the existing canvas readback.
- Kept scaling through the existing canvas fallback for mismatched source/profile sizes; profile-coupled capture sizing is tracked by issue 8.

Verification:
- `node demo/video-chat/frontend-vue/tests/contract/sfu-video-frame-rgba-copy-contract.mjs`
- `npm run test:contract:sfu` in `demo/video-chat/frontend-vue`
- `npm run build` in `demo/video-chat/frontend-vue`
- `git diff --check`

Deploy proof:
- Deployed to `https://kingrt.com/`.
- `demo/video-chat/scripts/deploy-smoke.sh` passed.
- `https://api.kingrt.com/api/runtime` returned `{"service":"video-chat-backend-king-php","status":"ok"}`.
- Production asset version `20260429052751` served `CallWorkspaceView-BigJQukE.js` with `video_frame_copy_to_rgba`, `trace_video_frame_copy_to_rgba_ms`, and `publisher_video_frame_copy_scale_required`.

### 9. `[quality-ui-removal-contract]`

Status: Done.

Implementation:
- Removed the manual outgoing video quality select from the call sidebar.
- Removed the user-facing SFU quality option export so profiles remain internal automatic runtime state.
- Retained automatic profile switching through existing SFU pressure/downgrade paths and changed profile-switch reset diagnostics from manual to automatic.

Verification:
- `node demo/video-chat/frontend-vue/tests/contract/call-layout-sidebar-controls-contract.mjs`
- `node demo/video-chat/frontend-vue/tests/contract/sfu-profile-switch-actuator-contract.mjs`
- `node demo/video-chat/frontend-vue/tests/contract/sfu-capture-constraints-contract.mjs`
- `node demo/video-chat/frontend-vue/tests/contract/wlvc-runtime-regression-contract.mjs`
- `node demo/video-chat/frontend-vue/tests/contract/sfu-online-acceptance-no-critical-pressure-contract.mjs`
- `node demo/video-chat/frontend-vue/tests/contract/sfu-motion-backpressure-contract.mjs`
- `node demo/video-chat/frontend-vue/tests/contract/sfu-profile-budget-contract.mjs`
- `node demo/video-chat/frontend-vue/tests/contract/sfu-wlvc-rate-control-contract.mjs`
- `npm run build` in `demo/video-chat/frontend-vue`

Deploy proof:
- Deployed to `https://kingrt.com/`.
- `demo/video-chat/scripts/deploy-smoke.sh` passed.
- `https://api.kingrt.com/api/runtime` returned `{"service":"video-chat-backend-king-php","status":"ok"}`.
- Production asset version `20260429045418` served `WorkspaceShell-bFusnFa4.js` without `call-left-video-quality`, `SFU_VIDEO_QUALITY_PROFILE_OPTIONS`, or `callVideoQualityOptions`.

## Parking Rule

Move an item to `BACKLOG.md` if any of the following is true:
- it does not directly improve publisher source readback, capture scaling, or SFU/WLVC video continuity
- it weakens media-security, live relay, binary SFU, or no-persistence contracts
- it is exploratory rather than required for production proof
- it is already completed and only needs archival evidence
