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

## Sprint: Video Call Performance And Quality Hardening

Sprint branch:
- `sprint/video-call-performance-quality`

PR target:
- `development/1.0.7-beta`

Deployed baseline:
- `development/1.0.7-beta` includes the deployed bounded SQLite SFU frame-buffer hotfix from merge `d1da7fe`.
- Production deploy smoke passed after the hotfix deploy on `https://kingrt.com/`.

Production symptoms:
- High-motion calls can still collapse into visibly blocky video or freeze/reconnect loops.
- The current diagnostics identify the publisher-side bottleneck at source readback, WLVC motion delta pressure, or SFU replay/send pacing, but the next sprint must remove the remaining throughput waste instead of hiding it behind lower quality.
- Browser console warnings such as leaked `VideoFrame` objects are release blockers because they can cause stalls under pressure.

Technical target:
- Keep protected SFU media on.
- Preserve bounded SQLite buffering for short replay/backfill.
- Maximize real moving-video quality by removing unnecessary main-thread readback work, stabilizing WLVC delta generation under motion, and preventing slow subscribers or replay bursts from pressuring healthy publishers.

## Active Issues

1. [ ] `[readback-zero-copy-gate]` Remove source readback as a normal hotpath bottleneck.

   Scope:
   - Audit the full publisher path from `MediaStreamTrackProcessor` frame pull through `VideoFrame.copyTo`, worker fallback, WLVC input normalization, protected frame wrapping, and SFU socket send.
   - Make the normal supported-browser path avoid DOM canvas readback entirely for profile-matched frames.
   - Keep DOM canvas only as a capped compatibility fallback with explicit backend-routed diagnostics.
   - Close every `VideoFrame` deterministically on success, skip, timeout, worker transfer, fallback, and error paths.
   - Add an acceptance gate that fails on `sfu_source_readback_budget_exceeded`, leaked `VideoFrame` warnings, or main-thread canvas readback being selected when zero-copy support is available.

   Done when:
   - High-motion online pressure runs do not emit `sfu_source_readback_budget_exceeded`.
   - Console-visible `VideoFrame was garbage collected without being closed` warnings are gone.
   - Backend diagnostics still record the exact active capture backend and source timing.

2. [ ] `[wlvc-motion-delta-rate-control]` Stabilize WLVC quality under movement instead of collapsing into blocky frames.

   Scope:
   - Analyze the WLVC encode path for high-motion delta explosion, tile/ROI churn, keyframe cadence, and profile downgrade thresholds.
   - Prefer framerate and delta cadence control before destructive resolution collapse when motion spikes.
   - Add motion-aware keyframe and recovery probing so quality can return after a stable window without SFU socket churn.
   - Keep profile selection automatic and remove any remaining user-facing quality control paths.
   - Route codec and profile warnings to backend diagnostics instead of browser console noise.

   Done when:
   - High-motion calls stay moving at the best sustainable profile instead of dropping straight to unusable block quality.
   - Automatic downgrade and recovery decisions are visible in backend diagnostics.
   - The call UI has no manual quality selector.

3. [ ] `[sfu-replay-pacing-slow-subscriber-isolation]` Smooth SFU send/replay pressure without punishing healthy publishers.

   Scope:
   - Trace the backend SFU receive, live relay, bounded SQLite frame buffer, subscriber cursor, and replay loops for throughput stalls.
   - Add or tighten per-subscriber pacing so slow consumers drop stale deltas, request/receive keyframes first, and cannot create publisher backpressure.
   - Keep the bounded SQLite buffer as a short replay/backfill mechanism, not an unbounded frame store.
   - Prove binary envelope validation, media-security, room admission, and duplicate suppression still hold.
   - Add production diagnostics that distinguish live-send pressure, replay pressure, slow-subscriber pressure, and publisher-source pressure.

   Done when:
   - Slow subscriber simulations do not trigger healthy publisher downgrades or reconnect loops.
   - Replayed frames are bounded, ordered, and stale-frame-pruned.
   - Backend diagnostics identify the exact pressure station without browser console warnings.

## Execution Order

1. Finish `[readback-zero-copy-gate]` first; source readback failures are currently the clearest hard bottleneck.
2. Then harden `[wlvc-motion-delta-rate-control]`; this determines visible quality once source readback is no longer the choke point.
3. Finish with `[sfu-replay-pacing-slow-subscriber-isolation]`; this protects multi-party and slow-client cases without weakening media security or buffering.
4. Commit after each checkbox, deploy after each completed issue, and add a short report under the issue before checking it off.
