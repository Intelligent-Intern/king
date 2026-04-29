# King Active Issues

Purpose:
- This file contains the active sprint issues for the current branch only.
- Detailed history, parked ideas, and overflow items belong in `BACKLOG.md`.
- Completion evidence belongs in `READYNESS_TRACKER.md`.

Sprint rule:
- Keep only issues that directly increase online video-call quality and throughput on the protected SFU/WLVC path.
- Do not weaken King v1 contracts to make frame capture, buffering, or transport cheaper.
- Do not grow `CallWorkspaceView.vue`; new call-runtime behavior belongs in focused helpers/modules.
- Keep media-security, binary SFU envelopes, bounded SQLite frame buffering, live relay, receiver feedback, and online pressure contracts intact.
- Video quality must stay automatic; there must be no user-facing quality selector in the call UI.

## Sprint: Video Call Capture Profile Coupling

Sprint branch:
- `sprint/video-call-capture-profile-coupling`

PR target:
- `development/1.0.7-beta`

Deployed baseline:
- `development/1.0.7-beta` includes the merged video-call performance/quality hardening branch with deterministic `VideoFrame` closure and the copyTo-first capture path.

Production symptom:
- Video quality improved after fixing the `VideoFrame` lifecycle, but publisher capture can still waste throughput when the browser returns a larger camera track than the active automatic SFU profile needs.
- `getUserMedia` asks for profile-capped dimensions, but fallback/browser selection can still leave the real track oversized until readback diagnostics notice it.

Technical target:
- Couple the real camera track to the active automatic SFU profile before source readback.
- Avoid copying/scaling oversized source frames when the browser supports track constraints.
- Keep quality selection fully automatic and report the exact requested/applied capture envelope to backend diagnostics.

## Active Issues

1. [x] `[capture-profile-track-constraints]` Enforce automatic SFU profile constraints on the actual camera track.

   Scope:
   - Add a focused helper for SFU capture-profile track constraints instead of growing `CallWorkspaceView.vue` or `mediaOrchestration.js`.
   - Apply `width`, `height`, and `frameRate` caps to the selected video track after strict, loose, and boolean-fallback `getUserMedia` acquisition.
   - Respect browser-reported camera capabilities so impossible min/max constraints do not break capture.
   - Keep capture settings and constraint failures in backend diagnostics, not as browser-console noise.
   - Prove the real track cap happens before WLVC source readback.

   Done when:
   - Capable camera tracks receive `applyConstraints()` for the active automatic SFU profile after browser track selection.
   - Boolean `getUserMedia` fallback cannot silently leave an oversized HD source without a follow-up profile constraint attempt.
   - `mediaOrchestration.js` stays below the 800-line target by extracting the constraint/reporting code.

   Report:
   - Added `sfuCaptureProfileConstraints.js` with `buildSfuVideoProfileTrackConstraints(...)`, `applySfuVideoProfileConstraintsToStream(...)`, and shared capture diagnostics.
   - Enforced active profile constraints immediately after strict, loose-retry, and boolean-fallback camera acquisition.
   - Moved local capture settings reporting out of `mediaOrchestration.js`, reducing it from 801 to 778 lines.
   - Extended SFU contracts to assert actual track `applyConstraints()` enforcement, capability clamping, diagnostics, and fallback coverage.

## Execution Order

1. Finish `[capture-profile-track-constraints]`.
2. Deploy after the issue is completed.
3. Push the sprint branch after deploy verification.
