# King Active Issues

Purpose:
- This file contains exactly 20 active sprint issues.
- Detailed history, parked ideas, and overflow items belong in `BACKLOG.md`.
- Completion evidence belongs in `READYNESS_TRACKER.md`.
- Branch-specific comparison notes live in `documentation/dev/video-chat/branch-compare-1.0.7-video-codec.md`.

Active GitHub issue:
- #148 Batch 3: Core Runtime, Experiment Intake, And Release Closure (`1.0.7-beta`)

Sprint rule:
- Keep only issues that are currently actionable and release-relevant.
- Do not keep completed work in this file.
- Do not weaken King v1 contracts to make a merge easier.
- Do not grow `CallWorkspaceView.vue`; new call-runtime behavior belongs in focused helpers/modules.
- Treat the current hotfix PR as the base for this sprint branch until it is merged into `development/1.0.7-beta`.

## Sprint: Video Call Hardening And Dual-Path Recovery

Sprint branch:
- `sprint/video-call-hardening`

Observed remaining risks:
- Browser minimize/background throttles DOM video, canvas reads, JavaScript timers, and sometimes workers; the current WLVC publisher can look frozen even when the user did not intentionally leave.
- Remote freeze recovery currently reacts after decoded/rendered frames stop, but it does not yet distinguish publisher-backgrounded, transport-failed, decoder-needs-keyframe, security-desynced, and true source-stalled states.
- Protected SFU media is currently not the production path; enabling it must not reintroduce `wrong_key_id` or `malformed_protected_frame` loops.
- Automatic quality reduction exists, but it still needs a full control loop: rapid downshift, secondary low-quality lane, stable-window climb-back, and explicit receiver/sender coordination.
- A second SFU path can help with transport failures, but it will not fix a minimized DOM/canvas source unless the publisher source/encode path is hardened as well.

## Top 20 Active Issues

1. [ ] `[visibility-state]` Define and implement a publisher background/minimize state over the call control channel so remote peers can show intentional browser-background pause instead of treating it as a broken SFU stream.
2. [ ] `[foreground-resume]` On `focus`, `pageshow`, and `visibility=visible`, reset the WLVC encoder, force a keyframe, reannounce SFU tracks, and clear receiver stall counters without requiring a manual refresh.
3. [ ] `[freeze-classifier]` Split remote video health states into publisher-backgrounded, decoder-waiting-keyframe, transport-receive-gap, security-desync, source-stalled, and true SFU outage.
4. [ ] `[source-pipeline]` Prototype and contract a WLVC publisher source path based on `MediaStreamTrackProcessor` and worker capture so minimized browsers are not dependent on DOM `<video>` playback and canvas timers.
5. [ ] `[background-heartbeat]` Add a low-rate video heartbeat/keyframe policy for hidden/minimized publishers where browsers permit it, with clear diagnostics when the browser fully throttles capture.
6. [ ] `[dual-sfu-path-schema]` Define the dual media path protocol: `path_id`, `stream_epoch`, `track_id`, publisher identity, active/standby role, sequence, keyframe, and retirement metadata.
7. [ ] `[secondary-channel]` Implement a secondary SFU media lane that can stay warm at lower resolution/quality without doubling full-quality bandwidth.
8. [ ] `[route-switch]` Add `call/media-route-switch` signaling so a sender or receiver can coordinate switching from primary to secondary path only after a decodable keyframe is available.
9. [ ] `[route-switch-receiver]` Keep standby decoders warm enough to switch without black frames, while rendering only the active path and retiring old epochs after a grace window.
10. [ ] `[quality-downshift]` Harden automatic quality downshift: two freezes/backpressure bursts trigger lower profile or secondary lane use without waiting for long reconnect loops.
11. [ ] `[quality-climbback]` Add stable-window climb-back: after a few seconds of clean receive/render stats, try one step up, validate it, and roll back immediately on renewed pressure.
12. [ ] `[per-peer-adaptation]` Make quality adaptation per publisher/peer path instead of a global one-way downgrade that punishes all participants equally.
13. [ ] `[security-enable]` Turn protected SFU media back on behind a contract gate and prove the happy path with current WLVC binary envelopes.
14. [ ] `[security-epoch-sync]` Bind media-security key id/session epoch to SFU path/stream epoch so route switches and reconnects cannot decrypt with stale keys.
15. [ ] `[security-recovery]` Add deterministic recovery for `wrong_key_id` and `malformed_protected_frame`: request handshake sync, drop only the affected epoch/path, and require a fresh keyframe.
16. [ ] `[backpressure-controller]` Unify sender bufferedAmount, payload cap, encode pause, frame drop, and quality switch decisions into one controller with bounded queues.
17. [ ] `[secondary-channel-budget]` Set explicit bandwidth/CPU budgets for the secondary lane and prove it cannot starve the primary path or audio/control traffic.
18. [ ] `[online-chaos-harness]` Extend online e2e to cover minimize/background, freeze injection, SFU socket recycle, secondary-path switch, protected-media resync, and quality climb-back.
19. [ ] `[diagnostics]` Add production diagnostics for every media recovery decision: freeze class, path id, epoch, profile, buffered bytes, key id, switch reason, and climb-back result.
20. [ ] `[acceptance-gate]` Define the release gate: two-browser online call survives minimize/restore, movement bursts, security enabled, automatic downshift, secondary path switch, and climb-back without remote black video.

## Execution Order

1. Freeze the control-plane schema for visibility state, route switching, path epochs, and security epochs.
2. Implement publisher source/visibility handling and receiver freeze classification before adding aggressive reconnect behavior.
3. Add secondary low-quality media lane and route-switch receiver logic.
4. Enable protected SFU media with path/epoch-aware security recovery.
5. Build automatic downshift/climb-back and prove it under online chaos.
6. Update `READYNESS_TRACKER.md` only after the online acceptance gate passes.

## Parking Rule

Move an item to `BACKLOG.md` if any of the following is true:
- it is not required for the current release bar
- it depends on unresolved work in one of the 20 issues above
- it is exploratory rather than contract-critical
- it is already completed and only needs archival evidence
