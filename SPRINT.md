# King Active Issues

Purpose:
- This file contains exactly 20 active sprint issues.
- Detailed history, parked ideas, and overflow items belong in `BACKLOG.md`.
- Completion evidence belongs in `READYNESS_TRACKER.md`.
- Branch-specific comparison notes live in `documentation/dev/video-chat/branch-compare-1.0.7-video-codec.md`.

Active GitHub issue:
- #148 Batch 3: Core Runtime, Experiment Intake, And Release Closure (`1.0.7-beta`)

Sprint rule:
- Keep only issues that directly eliminate SFU/WLVC encode pressure and end-to-end media throughput stalls.
- Do not keep completed work in this file.
- Do not weaken King v1 contracts to make a merge easier.
- Do not grow `CallWorkspaceView.vue`; new call-runtime behavior belongs in focused helpers/modules.
- Treat the current hotfix PR as the base for this sprint branch until it is merged into `development/1.0.7-beta`.

## Sprint: SFU Encode Pressure Elimination

Sprint branch:
- `sprint/video-call-hardening`

Active failure:
- Online publisher logs `SFU encode pressure - lowering outgoing video quality from=realtime to=rescue reason=sfu_send_backpressure_critical`.
- This means the publisher is producing or retaining media bytes faster than the end-to-end SFU path can accept them.
- The existing downgrade path is not enough. The release fix must identify and remove the bottleneck across the full path, not just keep lowering quality after the socket is already congested.

Already present and not enough:
- Frontend SFU transport has bounded send queue, `bufferedAmount` pressure checks, frame drops, payload-pressure drops, and quality downgrade from `quality` to `balanced` to `realtime` to `rescue`.
- Quality profile changes reconfigure local tracks, but the sprint must prove queued frames, encoder state, capture constraints, and keyframe/cache state actually switch immediately.
- Backend SFU has binary envelopes, direct fanout, live frame relay, broker-backed publisher/track state, and JSON media rejection.
- Therefore every active issue below is about finding, proving, and removing the real throughput ceiling.

## Top 20 Active Issues

1. [x] `[full-path-throughput-analysis]` Analyze and document the complete media path with measured timings and byte counts: camera capture, background processing, DOM/canvas readback, WLVC encode, selective tile planning, binary envelope build, outbound queue, browser `WebSocket.bufferedAmount`, network/proxy, King websocket receive, binary decode, SFU relay/fanout/broker, King websocket send, receiver decode, and render.
2. [x] `[stage-telemetry]` Add correlated per-frame diagnostics for every stage in the path, including `frame_sequence`, profile, payload bytes, wire bytes, queue age, buffered bytes, encode ms, send-drain ms, King receive latency, fanout latency, subscriber send latency, and receiver render latency.
3. [x] `[profile-byte-budget]` Define enforceable per-profile throughput budgets for `quality`, `balanced`, `realtime`, and `rescue`: max encoded bytes/frame, max wire bytes/sec, max encode ms, max queue age, max buffered bytes, and expected recovery behavior before `sfu_send_backpressure_critical`.
4. [x] `[capture-constraints]` Prove each outgoing quality profile actually applies lower camera constraints and lower publisher dimensions after a downgrade, including browser-reported track settings and no stale HD capture after switching to `realtime` or `rescue`.
5. [ ] `[source-readback]` Remove or bound DOM video/canvas readback pressure in the publisher pipeline by measuring `drawImage/getImageData` cost and moving to a faster supported source path where available, without weakening WLVC/SFU transport semantics.
6. [ ] `[wlvc-rate-control]` Make WLVC encode adapt before socket pressure: dynamic quality/resolution/fps decisions must target the byte budget from issue 3 instead of waiting for queue pressure after frames are already too large.
7. [ ] `[high-motion-payloads]` Fix high-motion payload spikes so movement cannot repeatedly create oversized delta/keyframe payloads; selective tiles, background snapshots, and full-frame fallback must stay under budget or drop early with a forced recoverable keyframe plan.
8. [ ] `[keyframe-cache-pacing]` Harden keyframe, cache-epoch, and selective-tile pacing so drops, profile switches, security sync, and reconnects do not cause repeated large full-frame bursts that refill the send buffer.
9. [ ] `[security-throughput-budget]` Enable protected SFU media only with measured overhead: encryption, protected envelope size, keyframe cadence, and receiver decrypt must fit the same throughput budget and must not reintroduce `wrong_key_id`/`malformed_protected_frame` recovery loops.
10. [ ] `[profile-switch-actuator]` Make automatic downgrade a real actuator: flush stale queued frames, reset/recreate the correct encoder, reapply capture constraints, force exactly one new-profile keyframe, and prove no old-profile frame is sent after the switch.
11. [ ] `[publisher-backpressure-controller]` Replace scattered skip/pause/downgrade/reconnect decisions with one publisher controller that consumes stage telemetry and decides encode pause, frame drop, profile downshift, keyframe request, and socket restart with bounded queues.
12. [ ] `[browser-ws-send-drain]` Fix browser websocket send pacing so large frames are never allowed to sit behind a 500ms drain timeout and refill `bufferedAmount`; enforce low-water resume, early drop, and audio/control priority.
13. [ ] `[binary-envelope-copy-audit]` Audit and reduce frontend binary envelope copies/base64-derived metrics so per-frame CPU and memory churn do not become the hidden bottleneck under motion.
14. [ ] `[king-receive-loop-fairness]` Profile and fix the King `/sfu` receive loop so broker polling, live relay polling, cleanup, presence touches, and websocket receive cannot starve each other or delay incoming media frames.
15. [ ] `[king-binary-decode-fanout]` Profile and optimize King binary decode and outbound binary envelope fanout so server-side validation, metadata handling, and re-encoding do not copy or serialize media payloads more than necessary.
16. [ ] `[slow-subscriber-isolation]` Prevent one slow receiver from backing up the publisher path: per-subscriber video send budgets must drop or skip video for that subscriber without blocking direct fanout to fast subscribers.
17. [ ] `[relay-broker-io-budget]` Audit live frame relay, broker DB, filesystem, and any persistence/spool path; media bytes must use a bounded, measured path and never create unbounded synchronous DB/file I/O in the hot frame path.
18. [ ] `[production-socket-proxy-budget]` Measure production TLS/proxy/websocket buffer behavior between browser and King, including frame sizes around continuation thresholds, server send buffers, close/error cases, and any config that caps throughput below the profile budget.
19. [ ] `[receiver-feedback-loop]` Add receiver-to-publisher feedback for decode/render lag, missed sequences, and subscriber pressure so the publisher downshifts from real receiver evidence before the sender socket reaches critical backpressure.
20. [ ] `[online-acceptance-no-critical-pressure]` Build the online acceptance gate: two-browser call with high motion, security enabled, profile changes, and slow-subscriber simulation must run without `sfu_send_backpressure_critical`, remote freeze, unbounded queue growth, or black video.

## Execution Order

1. Complete issue 1 first and do not guess the bottleneck.
2. Add issue 2 telemetry before changing thresholds so every later fix has proof.
3. Freeze issue 3 budgets and make all profile/controller changes obey them.
4. Fix publisher byte production first: capture, readback, WLVC rate control, high-motion payloads, keyframe/cache pacing, and security overhead.
5. Fix publisher send control next: profile switch actuation, unified backpressure controller, browser send/drain, and binary envelope copy pressure.
6. Fix King path next: receive-loop fairness, binary decode/fanout, slow subscriber isolation, relay/broker I/O, and production socket/proxy limits.
7. Add receiver feedback and run the online acceptance gate last.
8. Update `READYNESS_TRACKER.md` only after issue 20 passes online.

## Parking Rule

Move an item to `BACKLOG.md` if any of the following is true:
- it does not directly reduce SFU/WLVC encode pressure or media path throughput stalls
- it depends on unresolved proof from the full-path analysis
- it is exploratory rather than contract-critical
- it is already completed and only needs archival evidence
