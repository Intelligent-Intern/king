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

## Sprint: Video Call Fullscreen Quality Path

Sprint branch:
- `sprint/video-call-fullscreen-quality-path`

PR target:
- `development/1.0.7-beta`

Deployed baseline:
- `development/1.0.7-beta` includes deterministic `VideoFrame` closure, copyTo-first capture, automatic profile/track-constraint coupling, and backend diagnostics for source-readback and encode pressure.

Production symptom:
- The call now transmits again, but fullscreen quality is still far below browser-native conferencing systems.
- The remaining gap is not a single CSS scale issue. The current protected SFU path still spends too much budget in manual RGBA/WLVC processing and renders received frames through a canvas pipeline without a fullscreen-aware scheduler or adaptive high/low stream layers.

Technical target:
- Move the protected media path toward browser-grade quality without dropping King media-security or backend-authoritative SFU contracts.
- Replace per-frame RGBA hotpath work where browser-native frame/codec primitives can carry more detail per byte.
- Render remote frames with correct timing, aspect, and fullscreen priority instead of treating every view as the same canvas consumer.
- Add automatic high/low quality layering so grid tiles do not dictate fullscreen quality and fullscreen does not overload thumbnail delivery.

## Active Issues

1. [ ] `[protected-browser-encoder-path]` Build the next protected browser-encoder transport step beyond RGBA/WLVC.

   Scope:
   - Map the complete local path from camera frame to protected SFU envelope and identify every forced copy, RGBA conversion, lossy size clamp, and wire-budget gate.
   - Add a capability-gated browser encoder path using `VideoFrame`/WebCodecs primitives where supported, while keeping frames inside King protected binary envelopes.
   - Keep the current WLVC path as compatibility, not as the quality ceiling for capable browsers.
   - Prove that encoded chunks are closed/disposed deterministically and diagnostics go to backend telemetry, not console noise.

   Done when:
   - Capable browsers can avoid the manual RGBA/WLVC encode hotpath for the primary protected SFU stream.
   - The fallback path remains bounded and tested.
   - Contract tests pin the capability gate, protected envelope carriage, frame lifecycle, and diagnostics.

   Report:
   - Pending.

2. [ ] `[fullscreen-remote-render-scheduler]` Make remote render quality fullscreen-aware instead of canvas-fill driven.

   Scope:
   - Separate remote decode cadence from UI canvas mount/layout cadence.
   - Preserve source aspect ratio for portrait and landscape video in grid, main, and fullscreen surfaces.
   - Add timestamp/sequence-aware frame scheduling so stale deltas are dropped and the newest complete frame wins.
   - Prefer the active fullscreen/main participant for render budget without starving grid thumbnails.

   Done when:
   - Fullscreen/main remote video is not stretched, not cropped into blocky tiles, and does not depend on grid tile dimensions.
   - Decoder resets/profile switches keep the last good frame until a valid replacement keyframe lands.
   - Contract and Playwright checks cover portrait, landscape, grid, main, and fullscreen rendering behavior.

   Report:
   - Pending.

3. [ ] `[adaptive-sfu-quality-layers]` Add automatic high/low quality layers for fullscreen and grid.

   Scope:
   - Introduce an automatic primary layer for selected/fullscreen participants and a cheaper thumbnail layer for grid/mini surfaces.
   - Let receiver visibility and layout drive requested layer priority through backend-authoritative SFU control messages.
   - Keep quality selection out of the UI and keep downgrade/upgrade decisions automatic.
   - Reuse receiver feedback, backpressure, and bounded buffering so slow thumbnails cannot drag down the fullscreen stream.

   Done when:
   - Grid view can use low-cost thumbnail delivery while the focused participant receives a higher-detail stream.
   - The system can downgrade and later re-upgrade layers automatically after pressure/freeze events.
   - Tests prove layer negotiation, pressure isolation, backend diagnostics, and no manual quality selector.

   Report:
   - Pending.

## Execution Order

1. Finish `[protected-browser-encoder-path]`.
2. Finish `[fullscreen-remote-render-scheduler]`.
3. Finish `[adaptive-sfu-quality-layers]`.
4. Deploy after each completed issue and record production evidence in that issue's report.
5. Push the sprint branch after each completed issue.
