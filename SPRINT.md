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
- The quality selector must control the full capture/readback/encode/wire budget, not only the final SFU wire profile.

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

1. [ ] `[readback-path-trace]` Trace the full publisher path from `getUserMedia` frame delivery through source readback, WLVC encode, protected-frame wrapping, binary SFU envelope, and socket send; record exact timing fields and failure reasons for each stage.
2. [ ] `[feature-detect-capture-pipeline]` Add a focused capability detector for `MediaStreamTrackProcessor`, `VideoFrame.copyTo`, `VideoFrame.close`, `OffscreenCanvas`, worker transfer support, and DOM-canvas fallback support.
3. [ ] `[capture-worker-boundary]` Create a dedicated publisher capture worker module that owns off-main-thread frame scaling/readback where browser support allows it, without importing Vue or workspace state.
4. [ ] `[video-frame-primary-path]` Implement the primary `MediaStreamTrackProcessor` -> `VideoFrame` path so camera frames can be pulled without drawing the `<video>` element into a DOM canvas each frame.
5. [ ] `[video-frame-rgba-copy]` Feed WLVC with normalized RGBA/I420-derived pixel buffers from `VideoFrame.copyTo` when available, avoiding `getImageData` on the main thread.
6. [ ] `[offscreen-canvas-fallback]` Implement an `OffscreenCanvas` worker fallback for browsers that cannot copy `VideoFrame` planes directly but can move scaling/readback off the main thread.
7. [ ] `[dom-canvas-last-resort]` Keep DOM canvas as the last-resort fallback only; cap it to conservative dimensions/FPS and label diagnostics as compatibility fallback instead of normal operation.
8. [ ] `[source-budget-profile-coupling]` Make `balanced`, `realtime`, `rescue`, and user-selected quality profiles set capture dimensions, readback FPS, keyframe cadence, and wire byte budgets together.
9. [ ] `[quality-select-contract]` Ensure the visible quality selector actually changes the capture/readback profile immediately and is not only a cosmetic or encode-wire setting.
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
3. Couple quality selection and automatic downgrade to source readback before touching SFU restart policy.
4. Add contracts and high-motion pressure proof before deploying.
5. Update `READYNESS_TRACKER.md` only after the sprint is complete.

## Parking Rule

Move an item to `BACKLOG.md` if any of the following is true:
- it does not directly improve publisher source readback, capture scaling, or SFU/WLVC video continuity
- it weakens media-security, live relay, binary SFU, or no-persistence contracts
- it is exploratory rather than required for production proof
- it is already completed and only needs archival evidence
