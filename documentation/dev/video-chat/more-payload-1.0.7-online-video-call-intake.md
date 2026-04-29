# More Payload 1.0.7 Online Video Call Intake

Date: 2026-04-29
Active branch: `sprint/video-call-hardening`
Candidate branch: `origin/more-payload-1.0.7-online-video-call`

## Baseline

- Current baseline: `327640f` before intake planning, `f5c678a` after sprint planning.
- Candidate head: `e0b4e2a`.
- Merge base: `5605b23`.
- Intake rule: do not merge the branch wholesale. Port only useful deltas with source-SHA provenance and preserve the stronger current King v1 contracts.

## Direct Merge Rejection

`git diff --name-status HEAD..origin/more-payload-1.0.7-online-video-call` shows a direct merge would remove or downgrade current SFU hardening:

- `demo/video-chat/frontend-vue/src/domain/realtime/workspace/callWorkspace/publisherBackpressureController.js`
- `demo/video-chat/frontend-vue/src/lib/sfu/outboundFrameBudget.ts`
- `demo/video-chat/frontend-vue/src/lib/sfu/sendFailureDetails.ts`
- `demo/video-chat/frontend-vue/src/lib/sfu/sfuMessageHandler.ts`
- `demo/video-chat/frontend-vue/src/domain/realtime/workspace/callWorkspace/runtimeHealth.js`
- `demo/video-chat/frontend-vue/tests/e2e/online-sfu-pressure-acceptance.mjs`
- `demo/video-chat/frontend-vue/tests/e2e/production-socket-proxy-budget.mjs`
- the SFU throughput, profile-budget, send-drain, source-readback, relay/broker, receive-loop, slow-subscriber, receiver-feedback, security-throughput, and online-acceptance contracts.

That is a regression relative to the current online-pressure closure and is not an acceptable merge-fix shortcut.

## Patch Equivalence Summary

Useful or partially useful:

- `694c2d9`: mini-strip and roster shape fixes. Port only the layout/roster semantics that strengthen current tests.
- `376426f`: mini fallback and connected participant filtering. Port with stricter connectedAt normalization than the candidate branch used.
- `fd0d4a5`: fixture connected_at coverage. Current fixtures already contain most of it; add only missing coverage.
- `2a505fc`: fake server welcome/snapshot and realtime sync setup. Port only harness realism; reject assertion weakening.
- `cbc40e5`: reconnect snapshot assertion. Current reconnect test is already stronger for media-security/control-state; add only missing snapshot proof if needed.
- `4ff8e1b`: shared `stringField` helper. Port if it reduces duplicated parsing without undoing current message-handler extraction.
- `1147f44`: keyframe-first/lower restart churn idea. Current branch already has targeted quality-pressure full-keyframe recovery; reject buffer increases and looser thresholds.
- `76c356b`: publisher-scoped stall tracking idea. Port only as a complementary binary-frame-aware SFU-client tracker; do not replace runtime health.

Rejected or superseded:

- `c8621be`, `c2cf73d`, `bc19f56`: reject runtimeHealth TypeScript conversion because it removes current recovery behavior.
- `da90906`: compare only for stale contract path fixes; do not delete current SFU hardening contracts.
- `e6e8d04`: reject reconnect test simplification; keep only helper behavior if it proves real app reconnect.
- Merge commits are not cherry-pick targets.

## Accepted Local Ports

- Layout/roster/harness deltas from `694c2d9`, `376426f`, `fd0d4a5`, `2a505fc`, and `cbc40e5` were manually ported into the current extracted layout/workspace modules and E2E harness without duplicating main/mini tiles or weakening reconnect assertions.
- SFU recovery deltas from `4ff8e1b`, `1147f44`, and `76c356b` were manually ported as a shared `stringField` export, existing keyframe-first recovery proof, and a binary-frame-aware SFU-client tracker that resubscribes a stalled publisher before UI-level restart recovery.
- Contract-pattern deltas from `da90906` were limited to stale-path corrections in PHPT/frontend contracts so current extracted SFU modules are checked directly; throughput, binary media, protected-frame, live-relay, and online-pressure gates remain intact.

## Guard

The local frontend contract `tests/contract/more-payload-intake-contract.mjs` pins this intake decision by checking that the current hardening files and acceptance gates still exist, the risky `runtimeHealth.ts` replacement is absent, and the intake document records the direct-merge rejection and source commit classifications.
