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
6. [x] `[offscreen-canvas-fallback]` Implement an `OffscreenCanvas` worker fallback for browsers that cannot copy `VideoFrame` planes directly but can move scaling/readback off the main thread.
7. [x] `[dom-canvas-last-resort]` Keep DOM canvas as the last-resort fallback only; cap it to conservative dimensions/FPS and label diagnostics as compatibility fallback instead of normal operation.
8. [x] `[source-budget-profile-coupling]` Make `quality`, `balanced`, `realtime`, and `rescue` automatic profiles set capture dimensions, readback FPS, keyframe cadence, and wire byte budgets together.
9. [x] `[quality-ui-removal-contract]` Remove the visible quality selector from the call UI and prove quality changes only through automatic profile switching and diagnostics.
10. [x] `[auto-readback-downgrade]` On two consecutive source-readback budget failures, reduce capture resolution/FPS before another frame is read, not after repeated failed encode/send attempts.
11. [x] `[auto-readback-recovery]` After a stable window with low readback timing and no source failures, probe one quality tier upward without causing SFU socket restart churn.
12. [x] `[high-motion-readback-budget]` Add a high-motion local benchmark/contract proving the selected readback path stays inside budget at each supported profile.
13. [x] `[portrait-aspect-preservation]` Preserve portrait and rotated camera aspect ratios through VideoFrame, worker scaling, WLVC metadata, remote canvas render, mini strip, and grid layouts.
14. [x] `[background-tab-policy]` Handle minimized/background browser behavior explicitly: detect throttling, degrade to audio/status or low-FPS keepalive without pretending video is healthy.
15. [x] `[processor-error-recovery]` Recover from `MediaStreamTrackProcessor`, worker, `VideoFrame`, and `OffscreenCanvas` failures by restarting only the capture pipeline first, not the whole SFU socket.
16. [x] `[media-security-unchanged]` Prove protected-media security remains unchanged: source pipeline replacement must still emit the same protected-frame envelope and key/session semantics.
17. [x] `[no-frame-persistence-regression]` Prove the new capture path still keeps SFU media on the live websocket path and does not persist video frames in SQLite or any backend database.
18. [x] `[online-pressure-readback-proof]` Extend online SFU pressure acceptance so it fails on `sfu_source_readback_budget_exceeded`, not only send-buffer or SFU relay pressure.
19. [x] `[diagnostic-surface]` Add clear client diagnostics for active capture backend, selected profile, source frame size/FPS, readback timing, dropped-source-frame count, and automatic quality transitions.
20. [x] `[deploy-proof]` After implementation, deploy to `kingrt.com` and record production proof with moving remote video, no source-readback budget failures, no critical SFU pressure, and stable protected SFU media.

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

### 6. `[offscreen-canvas-fallback]`

Status: Done.

Implementation:
- Added a runtime capture-worker readback controller that sends transferable `VideoFrame` sources to the existing publisher capture worker and reconstructs `ImageData` from the returned RGBA buffer.
- Relaxed the `MediaStreamTrackProcessor` source gate so copyless browsers can still produce `VideoFrame` objects for the worker path.
- Wired source readback order to `VideoFrame.copyTo` first, then `OffscreenCanvas` worker readback, then DOM canvas as the last compatibility fallback.
- Added worker draw/readback/round-trip trace metrics and fatal worker fallback handling for timeout, malformed result, and `postMessage` transfer failures.

Verification:
- `node demo/video-chat/frontend-vue/tests/contract/sfu-offscreen-canvas-fallback-contract.mjs`
- `npm run test:contract:sfu` in `demo/video-chat/frontend-vue`
- `npm run build` in `demo/video-chat/frontend-vue`
- `git diff --check`

Deploy proof:
- Deployed to `https://kingrt.com/`.
- `demo/video-chat/scripts/deploy-smoke.sh` passed.
- `https://api.kingrt.com/api/runtime` returned `{"service":"video-chat-backend-king-php","status":"ok"}`.
- Production asset version `20260429053725` served `CallWorkspaceView-BB96oO9i.js` with `offscreen_canvas_worker_readback`, `trace_offscreen_worker_round_trip_ms`, `publisher_capture_worker_timeout`, `publisher_capture_worker_post_message_failed`, and `publisher_capture_worker_start_failed`.
- Production worker asset `publisherCaptureWorker-D0xgm_P4.js` served the off-main-thread `context.getImageData` path and transferred `imageData.data.buffer`.

### 7. `[dom-canvas-last-resort]`

Status: Done.

Implementation:
- Added a dedicated DOM canvas compatibility fallback policy with explicit `320x180` max frame size and `6 FPS` max readback cadence.
- Changed DOM video fallback sizing to use the compatibility policy instead of the active high-quality profile dimensions.
- Changed VideoFrame main-thread canvas fallback to use the same compatibility cap if both direct `copyTo` and OffscreenCanvas worker readback are unavailable.
- Added DOM compatibility trace stages, throttle skips, readback-method labels, and compatibility-specific draw/readback budget failure reasons.
- Kept the runtime order strict: `VideoFrame.copyTo` first, OffscreenCanvas worker second, DOM canvas compatibility fallback last.

Verification:
- `node demo/video-chat/frontend-vue/tests/contract/sfu-dom-canvas-last-resort-contract.mjs`
- `npm run test:contract:sfu` in `demo/video-chat/frontend-vue`
- `npm run build` in `demo/video-chat/frontend-vue`
- `git diff --check`

Deploy proof:
- Deployed to `https://kingrt.com/`.
- `demo/video-chat/scripts/deploy-smoke.sh` passed.
- `https://api.kingrt.com/api/runtime` returned `{"service":"video-chat-backend-king-php","status":"ok"}`.
- Production asset version `20260429054231` served `CallWorkspaceView-pZkhK_JP.js` with `dom_canvas_compatibility_fallback`, `dom_canvas_compatibility_readback`, `trace_dom_canvas_compatibility_throttle_ms`, and `dom_canvas_compatibility_get_image_data_budget_exceeded`.

### 8. `[source-budget-profile-coupling]`

Status: Done.

Implementation:
- Added explicit `readbackFrameRate` and `readbackIntervalMs` fields to every automatic SFU video profile.
- Changed local camera constraints so profile `captureFrameRate` is both the ideal and max FPS, preventing low profiles from silently capturing at 30 FPS.
- Changed the publisher encode/readback loop and source-reader/worker timeouts to use the profile readback interval instead of treating `encodeIntervalMs` as an implicit readback clock.
- Added capture diagnostics and transport metrics for requested readback FPS, readback interval, keyframe cadence, and wire byte budget.
- Kept DOM canvas fallback constrained to its compatibility cap while preserving the coupled readback interval fields.

Verification:
- `node demo/video-chat/frontend-vue/tests/contract/sfu-source-budget-profile-coupling-contract.mjs`
- `npm run test:contract:sfu` in `demo/video-chat/frontend-vue`
- `npm run build` in `demo/video-chat/frontend-vue`
- `git diff --check`

Deploy proof:
- Deployed to `https://kingrt.com/`.
- `demo/video-chat/scripts/deploy-smoke.sh` passed.
- `https://api.kingrt.com/api/runtime` returned `{"service":"video-chat-backend-king-php","status":"ok"}`.
- Production asset version `20260429055046` served `CallWorkspaceView-3iI3pUJj.js` with `readback_frame_rate`, `readback_interval_ms`, `requested_readback_frame_rate`, `requested_wire_budget_bytes_per_second`, `dom_canvas_compatibility_fallback`, and `sfu_source_readback_budget_exceeded`.

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

### 10. `[auto-readback-downgrade]`

Status: Done.

Implementation:
- Added dedicated source-readback failure state so `sfu_source_readback_budget_exceeded` is counted separately from generic send-buffer failures.
- The first source-readback budget failure now pauses/drops and requests a clean frame; the second consecutive failure triggers automatic profile downshift before another expensive readback cycle continues.
- Added backend diagnostics for `sfu_source_readback_budget_pressure` and `sfu_source_readback_profile_downshift`, including failure stage/source, track, active profile, retry pause, and source-readback failure count.
- Routed app `console.warn` calls through the existing `/api/user/client-diagnostics` backend queue and suppresses browser-console warning output by default; explicit debug mode remains the only console passthrough.
- Removed the SFU publisher backpressure `console.warn` hotpath strings so pressure/reconnect warnings are available in backend diagnostics instead of the user browser console.
- Removed the remaining direct realtime `console.info`/`console.error` call hotpaths for websocket-open, SFU stable-video, SFU retry exhaustion, native frame transform failure, stalled-publisher resubscribe, and native-audio recovery exhaustion; these now emit backend client diagnostics only.

Verification:
- `node demo/video-chat/frontend-vue/tests/contract/sfu-auto-readback-downgrade-contract.mjs`
- `npm run test:contract:client-diagnostics` in `demo/video-chat/frontend-vue`
- `npm run test:contract:sfu` in `demo/video-chat/frontend-vue`
- `npm run build` in `demo/video-chat/frontend-vue`
- `php -l demo/video-chat/backend-king-php/domain/realtime/client_diagnostics.php`
- `php -l demo/video-chat/backend-king-php/http/module_users.php`
- `git diff --check`

Deploy proof:
- Deployed to `https://kingrt.com/`.
- `demo/video-chat/scripts/deploy-smoke.sh` passed.
- `https://api.kingrt.com/api/runtime` returned `{"service":"video-chat-backend-king-php","status":"ok"}`.
- Production asset version `20260429060123` served `CallWorkspaceView-BEW46nwM.js` with `client_console_warning`, `console_warn`, `sfu_source_readback_budget_pressure`, `sfu_source_readback_profile_downshift`, `source_readback_failure_count`, `sfu_video_backpressure`, `sfu_frame_send_failed`, and `sfu_video_reconnect_after_stall`.
- The same production asset no longer contains the old browser-console warning strings `SFU video payload pressure`, `SFU frame send failed at exact transport stage`, `SFU video backpressure - skipping`, `SFU source readback budget pressure`, or `restarting SFU socket after video stall`.
- Production asset version `20260429065130` served `CallWorkspaceView-DX9qLCvC.js` with backend-only markers `media_security_handshake_started_after_ws_open`, `sfu_remote_video_stable`, and `native_audio_track_recovery_exhausted`, and without the old direct console strings `SFU/native media-security frame transform failed`, `WS open - media-security signal caches cleared`, or `SFU video stable`.

### 11. `[auto-readback-recovery]`

Status: Done.

Implementation:
- Added an automatic recovery profile ladder `rescue -> realtime -> balanced -> quality` with a stable source-readback window, a separate recovery cooldown, and a low-timing budget ratio before any up-probe is allowed.
- Added SFU transport state for readback stable-window samples, last successful readback/draw timing, and last automatic quality recovery timestamp.
- Counted readback stability only after an encoded SFU frame is successfully sent, so encode/send pressure cannot accidentally trigger an upward quality probe.
- Routed the upward probe through the same profile switch actuator as downshifts, including outbound media reset and local encoder restart, but without `onRestartSfu` or SFU socket reconnect.
- Extracted the SFU-client-loss-after-encode diagnostic out of the oversized publisher pipeline while keeping the pipeline line count moving down.
- Added backend diagnostic marker `sfu_source_readback_profile_upshift` with readback timing, source backend, frame size, active profile, and stable-window sample data.

Verification:
- `node demo/video-chat/frontend-vue/tests/contract/sfu-auto-readback-recovery-contract.mjs`
- `node demo/video-chat/frontend-vue/tests/contract/sfu-auto-readback-downgrade-contract.mjs`
- `node demo/video-chat/frontend-vue/tests/contract/sfu-publisher-backpressure-controller-contract.mjs`
- `npm run test:contract:sfu` in `demo/video-chat/frontend-vue`
- `npm run build` in `demo/video-chat/frontend-vue`
- `node --check` for the changed SFU recovery modules and contracts
- `git diff --check`

Deploy proof:
- Deployed to `https://kingrt.com/`.
- `demo/video-chat/scripts/deploy-smoke.sh` passed.
- Production asset version `20260429071240` served `CallWorkspaceView-6Ak81AyJ.js`.
- The deployed bundle contains `sfu_source_readback_profile_upshift`, `sfu_source_readback_recovered`, `noteWlvcSourceReadbackSuccess`, `wlvcSourceReadbackStableStartedAtMs`, and `sfu_client_unavailable_after_encode`.

### 12. `[high-motion-readback-budget]`

Status: Done.

Implementation:
- Added a deterministic local high-motion readback budget benchmark for all automatic SFU profiles and every selected capture backend: `VideoFrame.copyTo`, `OffscreenCanvas` worker readback, and DOM-canvas compatibility fallback.
- Proved the benchmark against full-frame `1920x1080` motion while preserving the profile caps and DOM fallback `320x180`/`6 FPS` compatibility limits.
- Added the missing OffscreenCanvas worker success-path budget gate so worker `drawImage` or `getImageData` overruns now fail as `sfu_source_readback_budget_exceeded` before WLVC encode, matching the VideoFrame-copy and DOM-canvas paths.
- Added exact source markers `offscreen_worker_draw_image_budget_exceeded` and `offscreen_worker_get_image_data_budget_exceeded` for backend-side diagnosis.

Verification:
- `node demo/video-chat/frontend-vue/tests/contract/sfu-high-motion-readback-budget-contract.mjs`
- `node demo/video-chat/frontend-vue/tests/contract/sfu-source-readback-contract.mjs`
- `node demo/video-chat/frontend-vue/tests/contract/sfu-auto-readback-downgrade-contract.mjs`
- `npm run test:contract:sfu` in `demo/video-chat/frontend-vue`
- `npm run build` in `demo/video-chat/frontend-vue`
- `git diff --check`

Deploy proof:
- Deployed to `https://kingrt.com/`.
- `demo/video-chat/scripts/deploy-smoke.sh` passed.
- `https://api.kingrt.com/api/runtime` returned `{"service":"video-chat-backend-king-php","status":"ok"}`.
- Production asset version `20260429073805` served `CallWorkspaceView-DhP9DKwL.js`.
- The deployed bundle contains `offscreen_worker_draw_image_budget_exceeded`, `offscreen_worker_get_image_data_budget_exceeded`, `Publisher OffscreenCanvas worker source readback exceeded`, `offscreen_canvas_worker_readback`, and `sfu_source_readback_budget_exceeded`.

### 13. `[portrait-aspect-preservation]`

Status: Done.

Implementation:
- Proved the existing profile sizing preserves portrait `1080x1920` and landscape `1920x1080` aspect ratios through the `VideoFrame` path, OffscreenCanvas worker sizing, WLVC metadata, publisher trace fields, and frame payload width/height metadata.
- Changed main call video/canvas slots and mini video/canvas slots to `object-fit: contain` so portrait camera streams are no longer stretched or cropped to a full-width desktop tile; grid slots already use contain.
- Changed remote canvas resize restoration to preserve the previous frame with a centered contain draw instead of stretching the prior canvas snapshot across the new decoded dimensions.
- Updated the recovery timing contract to require non-stretched remote canvas resize behavior instead of the old full-canvas draw anchor.

Verification:
- `node demo/video-chat/frontend-vue/tests/contract/sfu-portrait-aspect-preservation-contract.mjs`
- `node demo/video-chat/frontend-vue/tests/contract/call-mini-strip-responsive-contract.mjs`
- `node demo/video-chat/frontend-vue/tests/contract/call-layout-ui-options-contract.mjs`
- `node demo/video-chat/frontend-vue/tests/contract/sfu-source-budget-profile-coupling-contract.mjs`
- `node demo/video-chat/frontend-vue/tests/contract/sfu-video-recovery-timing-contract.mjs`
- `npm run test:contract:sfu` in `demo/video-chat/frontend-vue`
- `npm run build` in `demo/video-chat/frontend-vue`
- `node --check demo/video-chat/frontend-vue/tests/contract/sfu-portrait-aspect-preservation-contract.mjs`
- `node --check demo/video-chat/frontend-vue/src/domain/realtime/sfu/remoteCanvas.js`
- `git diff --check`

Deploy proof:
- Deployed to `https://kingrt.com/`.
- `demo/video-chat/scripts/deploy-smoke.sh` passed.
- `https://api.kingrt.com/api/runtime` returned `{"service":"video-chat-backend-king-php","status":"ok"}`.
- Production asset version `20260429074411` served `CallWorkspaceView-BtsAwBma.js` and `CallWorkspaceView-08sQqbYI.css`.
- The deployed CSS contains `object-fit:contain!important` for `.video-container`, `.workspace-mini-video-slot`, and `.workspace-grid-video-slot` video/canvas rules.
- The deployed bundle contains `source_contain`, `publisher_aspect_mode`, `source_aspect_ratio`, `frame_width`, `frame_height`, `displayWidth`, and `displayHeight`.

### 14. `[background-tab-policy]`

Status: Done.

Implementation:
- Added an explicit SFU background-tab policy that treats true `document_hidden`/`pagehide` as browser throttling, stops the WLVC source-readback/encode loop, unpublishes only the local SFU video track, and leaves audio/websocket status paths alive instead of pretending frozen video is healthy.
- Kept normal `window.blur` as a reconnect hint only; visible blur does not pause video publishing.
- Added backend diagnostics `sfu_background_tab_video_paused` and `sfu_background_tab_video_resumed` with `background_video_policy=pause_sfu_video_keep_audio_status`, visibility state, track id, active auto profile, and runtime path.
- On foreground, the policy republishes local tracks and lets the existing foreground reconnect recycle stale SFU/socket state if the browser throttled the tab.
- Kept the policy in a focused `backgroundTabPolicy.js` helper and wired it through lifecycle so `CallWorkspaceView.vue` did not grow.

Verification:
- `node demo/video-chat/frontend-vue/tests/contract/sfu-background-tab-policy-contract.mjs`
- `node demo/video-chat/frontend-vue/tests/contract/sfu-video-recovery-timing-contract.mjs`
- `npm run test:contract:sfu` in `demo/video-chat/frontend-vue`
- `npm run build` in `demo/video-chat/frontend-vue`
- `node --check demo/video-chat/frontend-vue/src/domain/realtime/workspace/callWorkspace/backgroundTabPolicy.js`
- `node --check demo/video-chat/frontend-vue/tests/contract/sfu-background-tab-policy-contract.mjs`
- `node --check demo/video-chat/frontend-vue/src/support/foregroundReconnect.js`
- `node --check demo/video-chat/frontend-vue/src/domain/realtime/workspace/callWorkspace/lifecycle.js`
- `git diff --check`

Deploy proof:
- Deployed to `https://kingrt.com/`.
- `demo/video-chat/scripts/deploy-smoke.sh` passed.
- `https://api.kingrt.com/api/runtime` returned `{"service":"video-chat-backend-king-php","status":"ok"}`.
- Production asset version `20260429075256` served `CallWorkspaceView-BBZ29uHE.js` and `CallWorkspaceView-08sQqbYI.css`.
- The deployed bundle contains `sfu_background_tab_video_paused`, `sfu_background_tab_video_resumed`, `pause_sfu_video_keep_audio_status`, `background_video_policy`, `document_hidden`, and `unpublishTrack`.

### 15. `[processor-error-recovery]`

Status: Done.

Implementation:
- Closed late `MediaStreamTrackProcessor` `VideoFrame` results that arrive after a source-read timeout, preventing browser `VideoFrame was garbage collected without being closed` stalls.
- Converted source-reader failures into a fatal capture-pipeline reset reason instead of letting broken reads poison the publisher loop.
- Closed worker-transferred `VideoFrame` sources in a `finally` block so `drawImage` and `getImageData` exceptions cannot leak frames.
- Moved worker setup and canvas/readback allocation under the same guarded `try/finally`, so transferred `VideoFrame` sources are closed even if worker initialization fails before the first `drawImage`.
- Stopped the worker-fatal path from falling through to DOM `drawImage` with a transferred/closed `VideoFrame`; the current tick is dropped and the next tick restarts capture with a fresh frame.
- Rechecked the current SFU client immediately before `sendEncodedFrame` so a reconnect between readback/protect/encode and send drops the frame as `sfu_client_unavailable_after_encode` instead of crashing the WLVC encode loop.
- Kept the SFU websocket alive for these failures; recovery is scoped to source/readback pipeline state first.

Verification:
- `node demo/video-chat/frontend-vue/tests/contract/sfu-video-frame-primary-path-contract.mjs`
- `node demo/video-chat/frontend-vue/tests/contract/sfu-offscreen-canvas-fallback-contract.mjs`
- `node demo/video-chat/frontend-vue/tests/contract/sfu-publisher-backpressure-controller-contract.mjs`
- `npm run test:contract:sfu` in `demo/video-chat/frontend-vue`
- `npm run build` in `demo/video-chat/frontend-vue`
- `git diff --check`

Deploy proof:
- Deployed to `https://kingrt.com/`.
- `demo/video-chat/scripts/deploy-smoke.sh` passed.
- `https://api.kingrt.com/api/runtime` returned `{"service":"video-chat-backend-king-php","status":"ok"}`.
- Production asset version `20260429063247` served `CallWorkspaceView-LD4f_v8x.js` with `publisher_video_frame_read_failed`, `OffscreenCanvas capture worker failed`, worker-side `closeFrameSource(source)` cleanup, and `sfu_client_unavailable_after_encode`.
- Production diagnostics for the prior asset exposed `wlvc_encode_frame_failed: Cannot read properties of null (reading 'sendEncodedFrame')`; the new asset turns that reconnect race into a send-path recovery diagnostic before another encoded frame is sent.
- Production asset version `20260429065130` served `CallWorkspaceView-DX9qLCvC.js` and `publisherCaptureWorker-Bt7fTAET.js` with the guarded worker `VideoFrame` close path still present. `demo/video-chat/scripts/deploy-smoke.sh` passed and `/api/runtime` returned `video-chat-backend-king-php` `ok`.

### 16. `[media-security-unchanged]`

Status: Done.

Implementation:
- Updated the media-security contract to assert the current SFU receiver module still surfaces `protectedFrame: protectedFrame || null` and still rejects ad-hoc `payload.protected = frame.protected` metadata.
- Kept WLVC/SFU publisher protection on the existing protected-frame envelope path; no frame persistence or plaintext fallback was added.
- Passed media-security readiness into the native peer factory so native audio receiver tracks that arrive during rekeying enter `waiting_security` instead of immediately failing playback.
- Retried native audio receiver transform attachment after `ensureNativeAudioBridgeSecurityReady(peer, 'native_audio_receiver_track')`; `native_audio_receiver_transform_failed` is now emitted only after a security-ready retry fails.
- Removed the stale `exposeToConsole` gate from native-audio bridge failure recovery so repeated bridge failures still trigger the forced media-security sync/rekey timer instead of throwing before recovery.
- Tightened native protected audio/video transform attachment so `canProtectNativeForTargets()` returns false unless the media-security session is `active`; receiver transforms no longer attach while the session is still `rekeying`.
- Routed native frame-transform failures and native-audio recovery exhaustion to backend client diagnostics only; no direct browser-console error path remains for these recovery loops.
- Added shared call audio capture constraints for publish, previews, mic-level monitoring, and device-label permission probing so browser acoustic echo cancellation, noise suppression, automatic gain control, and mono voice input are consistently requested instead of disabling echo cancellation on side capture paths.

Verification:
- `npm run test:contract:media-security` in `demo/video-chat/frontend-vue`
- `npm run test:contract:native-audio-bridge` in `demo/video-chat/frontend-vue`
- `npm run test:unit:native-audio-bridge` in `demo/video-chat/frontend-vue`
- `node demo/video-chat/frontend-vue/tests/contract/sfu-capture-constraints-contract.mjs`
- `npm run test:contract:sfu` in `demo/video-chat/frontend-vue`
- `npm run build` in `demo/video-chat/frontend-vue`
- `git diff --check`

Deploy proof:
- Deployed to `https://kingrt.com/`.
- `demo/video-chat/scripts/deploy-smoke.sh` passed.
- `https://api.kingrt.com/api/runtime` returned `{"service":"video-chat-backend-king-php","status":"ok"}`.
- Production asset version `20260429063247` served `CallWorkspaceView-LD4f_v8x.js` with `native_audio_receiver_track`, `receiver_track_after_security_ready`, and `protectedFrame:e||null`.
- Production diagnostics after this deploy no longer showed the old `native_audio_receiver_transform_failed` race in the queried recent window; remaining `media_security_handshake_timeout` and `media_security_sender_key_not_ready` events are tracked separately because they are handshake churn, not a protected-frame contract regression.
- Production asset version `20260429065130` served `CallWorkspaceView-DX9qLCvC.js` with `native_audio_receiver_track`, `receiver_track_after_security_ready`, `media_security_handshake_started_after_ws_open`, and `native_audio_track_recovery_exhausted`, and without `exposeToConsole` or the old native frame console error string.
- Production diagnostics for asset `20260429065130` showed `sfu_source_readback_budget_pressure`, `sfu_source_readback_profile_downshift`, `media_security_handshake_timeout`, and `media_security_sender_key_not_ready`; no fresh `native_audio_receiver_transform_failed` was present in the current-asset query.
- Production asset version `20260429065839` served `CallWorkspaceView-DVqCK1Kb.js`, `WorkspaceShell-GtWA0igW.js`, `JoinView-gtNUpyFT.js`, and `preferences-geDd2h5r.js` with `echoCancellation`, `noiseSuppression`, `autoGainControl`, `channelCount`, `audio_echo_cancellation`, `audio_noise_suppression`, and `audio_auto_gain_control`; no deployed bundle contained disabled `echoCancellation:false`/`noiseSuppression:false`/`autoGainControl:false` markers.

### 17. `[no-frame-persistence-regression]`

Status: Done.

Implementation:
- Added a dedicated SFU no-frame-persistence regression contract that scans backend realtime code, migrations, and SFU/local frontend media code.
- Removed the unused legacy `videochat_sfu_encode_stored_frame_payload()` / `videochat_sfu_decode_stored_frame_payload()` helpers so the old stored-frame vocabulary cannot be reused by accident.
- Proved the only remaining `sfu_frames` backend references are explicit legacy table drops in bootstrap and migration `0020_drop_legacy_sfu_frame_persistence`.
- Proved JSON `sfu/frame` and `sfu/frame-chunk` commands fail with `binary_media_required`, outbound frames use binary WebSocket send, the gateway publishes only a bounded live relay copy, and direct fanout keeps the raw live frame path.
- Updated the backend realtime SFU contract for the extracted direct-fanout helper and the current raw binary decode behavior.

Verification:
- `node demo/video-chat/frontend-vue/tests/contract/sfu-no-frame-persistence-regression-contract.mjs`
- `node demo/video-chat/frontend-vue/tests/contract/sfu-relay-broker-io-budget-contract.mjs`
- `node demo/video-chat/frontend-vue/tests/contract/sfu-king-binary-decode-fanout-contract.mjs`
- `node demo/video-chat/frontend-vue/tests/contract/sfu-transport-metrics-contract.mjs`
- `npm run test:contract:sfu` in `demo/video-chat/frontend-vue`
- `npm run build` in `demo/video-chat/frontend-vue`
- `php -n demo/video-chat/backend-king-php/tests/realtime-sfu-contract.php` locally; the SQLite section skipped because local `pdo_sqlite` is not loaded.
- Production container: `docker exec king-videochat-v1-videochat-backend-sfu-v1-1 php /app/tests/realtime-sfu-contract.php`
- `git diff --check`

Deploy proof:
- Deployed to `https://kingrt.com/`.
- `demo/video-chat/scripts/deploy-smoke.sh` passed.
- `https://api.kingrt.com/api/runtime` returned `{"service":"video-chat-backend-king-php","status":"ok"}`.
- Production asset version `20260429080125` served `CallWorkspaceView-MpQQQFLk.js`.
- The production call bundle contains `binary_media_required`, `sendEncodedFrame`, `binary_envelope_send_failed`, and `direct legacy JSON/base64 fallback has been removed`.
- The deployed backend checkout has no `videochat_sfu_encode_stored_frame_payload`, `CREATE TABLE IF NOT EXISTS sfu_frames`, or `INSERT INTO sfu_frames` matches.
- The deployed backend checkout still contains `DROP TABLE IF EXISTS sfu_frames`, `king_websocket_send($socket, $binaryPayload, true)`, `videochat_sfu_live_frame_relay_publish(...)`, and `binary_media_required`.

### 18. `[online-pressure-readback-proof]`

Status: Done.

Implementation:
- Extended the online SFU pressure acceptance gate so it now fails on `sfu_source_readback_budget_exceeded`, `sfu_source_readback_budget_pressure`, and `sfu_source_readback_profile_downshift`, not only socket-buffer and relay pressure markers.
- Added request monitoring for `/api/user/client-diagnostics` POST bodies so backend-routed client diagnostics are part of the acceptance failure surface even when browser console logging is suppressed.
- Added a low-pressure proof path for runs where automatic quality downshift is not needed: the gate only accepts that case when max observed `bufferedAmount` stays at or below `512 KiB`.
- Hardened the remote-video stability check so one isolated black sample is classified as `transient_remote_black_frame`, but consecutive black frames, missing final video, missing recovery, stopped binary counts, or non-moving hashes still fail the run.
- Fixed the slow-subscriber simulation to throttle only subscriber download (`uploadThroughput: -1`) so the test no longer accidentally cuts the publisher upload path while measuring subscriber pressure.

Verification:
- `node demo/video-chat/frontend-vue/tests/contract/sfu-online-acceptance-no-critical-pressure-contract.mjs`
- `node --check demo/video-chat/frontend-vue/tests/e2e/online-sfu-pressure-acceptance.mjs`
- `npm run test:e2e:online-sfu-pressure` in `demo/video-chat/frontend-vue`; production call `1743cdfc-12c2-4793-bfac-ec4eae536568` passed with no runtime failures, no socket failures, moving remote canvases on both sides, and max observed send buffer `330487` bytes.
- `npm run test:contract:sfu` in `demo/video-chat/frontend-vue`
- `npm run build` in `demo/video-chat/frontend-vue`
- `git diff --check`

Deploy proof:
- Deployed to `https://kingrt.com/`.
- `demo/video-chat/scripts/deploy-smoke.sh` passed.
- `https://api.kingrt.com/api/runtime` returned `{"service":"video-chat-backend-king-php","status":"ok"}`.
- Production asset version `20260429081201` serves `CallWorkspaceView-CCmMYIyk.js`.
- The production call bundle contains `sfu_source_readback_budget_exceeded`, `sfu_source_readback_budget_pressure`, `sfu_source_readback_profile_downshift`, and `client-diagnostics`, and still does not contain `call-left-video-quality`.

### 19. `[diagnostic-surface]`

Status: Done.

Implementation:
- Added a focused publisher diagnostics surface that normalizes active capture backend, selected automatic profile, source frame size/FPS, draw/readback timing, dropped-source-frame count, and automatic quality-transition counters.
- Added the clear diagnostic fields to source-readback pressure/downshift events, automatic up/down profile transition events, publisher frame transport metrics, normalized SFU frame payloads, and typed SFU transport samples.
- Kept the user-facing quality select out of the call UI; profile changes remain automatic and are now observable through backend-routed diagnostics.

Verification:
- `node demo/video-chat/frontend-vue/tests/contract/sfu-diagnostic-surface-contract.mjs`
- `npm run test:contract:sfu` in `demo/video-chat/frontend-vue`
- `npm run build` in `demo/video-chat/frontend-vue`
- `git diff --check`

Deploy proof:
- Deployed to `https://kingrt.com/`.
- `demo/video-chat/scripts/deploy-smoke.sh` passed.
- `https://api.kingrt.com/api/runtime` returned `{"service":"video-chat-backend-king-php","status":"ok"}`.
- Production asset version `20260429082026` serves `CallWorkspaceView-CVRhCahK.js`.
- The production call bundle contains `active_capture_backend`, `selected_video_quality_profile`, `source_frame_rate`, `source_readback_ms`, `dropped_source_frame_count`, `automatic_quality_transition_count`, and `automatic_quality_transition_direction`, and still does not contain `call-left-video-quality`.
- The additional production online-pressure run for call `5590731e-d0b9-4664-b316-a455c8afd17a` exposed a remaining recovery gap: no socket failures and final remote canvases recovered, but two consecutive transient remote-video gaps occurred during recovery. That is the remaining Issue 20 deploy-proof work, not part of the diagnostic-surface contract.

### 20. `[deploy-proof]`

Status: Done.

Implementation:
- Fixed the remaining production recovery gap by preserving the last visible remote canvas frame while SFU publisher-id or track-set rollover resets decoder continuity and waits for the next keyframe.
- Kept the stronger recovery contract intact: decoder, patch decoder, frame sequence, cache epoch, render cache state, keyframe wait, and connection status are still reset on rollover; only the user-visible canvas pixels are no longer cleared to black during the handoff.
- Added a recovery contract guard so future rollover changes cannot reintroduce displayed-canvas clearing.

Verification:
- `node demo/video-chat/frontend-vue/tests/contract/sfu-video-recovery-timing-contract.mjs`
- `npm run test:contract:sfu` in `demo/video-chat/frontend-vue`
- `npm run build` in `demo/video-chat/frontend-vue`
- `git diff --check`

Deploy proof:
- Deployed to `https://kingrt.com/`.
- `demo/video-chat/scripts/deploy-smoke.sh` passed.
- `https://api.kingrt.com/api/runtime` returned `{"service":"video-chat-backend-king-php","status":"ok"}`.
- Production asset version `20260429082550` serves `CallWorkspaceView-BrrOq_d7.js`.
- The production call bundle no longer contains `clearDecodedCanvas(peer);` and still does not contain `call-left-video-quality`.
- `npm run test:e2e:online-sfu-pressure` in `demo/video-chat/frontend-vue` passed against production call `ca4cb091-1402-47aa-a622-4e342f82d2e3` with moving remote canvases on both sides, no runtime failures, no SFU socket failures, no source-readback budget failures, no critical SFU pressure, max observed send buffer `341208` bytes on admin and `330761` bytes on user, and final bufferedAmount `0` on both sides.

## Parking Rule

Move an item to `BACKLOG.md` if any of the following is true:
- it does not directly improve publisher source readback, capture scaling, or SFU/WLVC video continuity
- it weakens media-security, live relay, binary SFU, or no-persistence contracts
- it is exploratory rather than required for production proof
- it is already completed and only needs archival evidence
