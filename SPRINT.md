# King Active Issues

Purpose:
- This file contains exactly 20 active sprint issues.
- Detailed history, parked ideas, and overflow items belong in `BACKLOG.md`.
- Completion evidence belongs in `READYNESS_TRACKER.md`.
- Branch-specific comparison notes live in this file until the cherry-pick intake is complete.

Active GitHub issue:
- #148 Batch 3: Core Runtime, Experiment Intake, And Release Closure (`1.0.7-beta`)

Sprint rule:
- Keep only issues that directly harden the current SFU/WLVC online video call path.
- Do not keep completed work in this file.
- Do not weaken King v1 contracts to make a merge easier.
- Do not grow `CallWorkspaceView.vue`; new call-runtime behavior belongs in focused helpers/modules.
- Cherry-pick useful commits from `more-payload-1.0.7-online-video-call` with `git cherry-pick -x` where possible, then resolve conflicts by preserving the strongest current contract.
- If a source commit is only partially usable, keep provenance in the local commit message with the source SHA and the rejected hunks.

## Sprint: More Payload Branch Cherry-Pick Intake

Sprint branch:
- `sprint/video-call-hardening`

Candidate branch:
- `origin/more-payload-1.0.7-online-video-call`
- current baseline: `327640f`
- candidate head: `e0b4e2a`
- merge base: `5605b23`

Branch analysis:
- Direct merge is rejected. `HEAD..origin/more-payload-1.0.7-online-video-call` would delete current hardening files including `publisherBackpressureController.js`, `outboundFrameBudget.ts`, `sendFailureDetails.ts`, `sfuMessageHandler.ts`, multiple SFU throughput contracts, `online-sfu-pressure-acceptance.mjs`, and `production-socket-proxy-budget.mjs`.
- The candidate branch contains useful layout/test-harness fixes and recovery ideas, but also older SFU client code that predates the current measured throughput budget, binary envelope path, receiver feedback, large-frame receive fix, and online pressure acceptance gate.
- The intake must therefore cherry-pick or manually port only the useful deltas while preserving current SFU byte budgets, binary media-only contracts, media-security recovery, and online acceptance coverage.

Useful candidate commits:
- `694c2d9` mini participant strip / active user display: useful, but must preserve current stricter layout contracts.
- `376426f` mini fallback and connected participant filtering: useful, but connectedAt must be normalized strictly instead of treating any non-null value as connected.
- `fd0d4a5` fixture `connected_at`: mostly already present; keep only missing fixture coverage.
- `2a505fc` harness server-socket flow pieces: useful only for `system/welcome`, realtime room sync, and explicit main selection; reject weakened assertions.
- `cbc40e5` reconnect snapshot assertion: useful only if it adds coverage beyond the current media-security/control-state reconnect test.
- `4ff8e1b` shared `stringField` helper: small refactor candidate if it does not fight the current `sfuMessageHandler.ts` extraction.
- `1147f44` recovery idea: keyframe-first / less restart churn is useful; buffer increases and looser downgrade thresholds are rejected.
- `76c356b` publisher-scoped stall tracker: useful only if adapted to the current binary frame path and current diagnostics; do not replace runtime health.

Rejected or superseded candidate commits:
- `c8621be`, `c2cf73d`, `bc19f56`: reject the runtimeHealth TypeScript conversion because it removes stronger current stall/recovery behavior.
- `da90906`: only compare PHPT/contract patterns; do not weaken or delete current SFU hardening contracts.
- `e6e8d04`: reject reconnect test simplification; only consider the fake socket reconnect helper if it proves app reconnect logic instead of bypassing it.
- Merge commits `ccce8ad`, `b554ec1`, `0005817`, and `e0b4e2a` are not direct cherry-pick candidates.

## Top 20 Active Issues

1. [x] `[branch-diff-baseline]` Re-run the branch diff before the first cherry-pick and record the exact current SHA, candidate SHA, merge base, and deleted-current-contract list so no stale comparison drives the intake.
2. [x] `[patch-equivalence-audit]` For every candidate commit, check whether the behavior is already implemented or superseded in the current branch before editing code.
3. [x] `[direct-merge-guard]` Add a local guard note or contract check that rejects wholesale merge outcomes that delete current SFU hardening modules, online acceptance gates, or throughput contracts.
4. [ ] `[mini-main-selection]` Port the useful part of `694c2d9`: when `main_mini` has no pinned user and the server selected the local user as main, prefer `selection.mini_user_ids` before falling back to arbitrary visible users.
5. [ ] `[mini-fallback-non-main]` Port the useful part of `376426f`: mini participant fallback must prefer connected visible users other than the main user and must never duplicate the main tile.
6. [ ] `[roster-shape-normalization]` Port the useful roster normalization from `694c2d9`: accept snake_case and camelCase room/user/call-role fields, but keep current role ordering and live media peer aggregation.
7. [ ] `[connected-at-normalization]` Harden connected participant filtering so `connected_at` / `connectedAt` counts only when it is a non-empty normalized timestamp, and connection counts still win when present.
8. [ ] `[layout-fixture-coverage]` Compare `fd0d4a5` and current fixtures; add only missing `connected_at` fixture coverage for participant rows without weakening any existing E2E assertions.
9. [ ] `[harness-welcome-flow]` Port the useful part of `2a505fc`: fake server sockets should emit the same welcome/snapshot flow as KingRT, while preserving current media-security and control-state checks.
10. [ ] `[harness-realtime-room-sync]` Port explicit realtime room sync and main-user selection setup only where tests otherwise depend on incidental defaults.
11. [ ] `[reconnect-snapshot-assertion]` Compare `cbc40e5` against the current reconnect E2E; add missing snapshot-after-reconnect assertions only if they strengthen the existing media-security/control-state test.
12. [ ] `[reject-reconnect-weakening]` Ensure no cherry-pick from `2a505fc` or `e6e8d04` removes sidebar, grid, mini-strip, media-security, control-state, or snapshot assertions.
13. [ ] `[shared-string-field-helper]` Evaluate `4ff8e1b`; export/reuse `stringField` only if it reduces duplicate parsing without undoing `sfuMessageHandler.ts` or binary frame validation.
14. [ ] `[keyframe-before-restart-review]` Compare `1147f44` with current receiver feedback; port only missing keyframe-first recovery behavior and keep current profile byte budgets and downgrade thresholds.
15. [ ] `[reject-buffer-threshold-regression]` Explicitly reject `1147f44` buffer increases and looser downgrade windows unless measurements prove they improve throughput without reintroducing critical backpressure.
16. [ ] `[publisher-stall-tracker]` Adapt the useful idea from `76c356b` into the current SFU client only if publisher last-frame tracking is updated from the binary envelope path, not only JSON `sfu/frame` messages.
17. [ ] `[stall-recovery-layering]` Ensure any publisher-scoped stall tracker complements, not replaces, current `runtimeHealth.js` diagnostics, targeted quality-pressure signaling, auto-resubscribe, and bounded SFU restart logic.
18. [ ] `[runtime-health-ts-reject]` Document and enforce rejection of `c8621be`/`c2cf73d`/`bc19f56` unless a later change preserves every current runtimeHealth behavior and contract.
19. [ ] `[contract-pattern-audit]` Compare `da90906` against current PHPT/frontend contracts; port only stale-path corrections and never delete tests that protect SFU throughput, binary media, or online pressure acceptance.
20. [ ] `[cherry-pick-provenance-gate]` After each accepted port, commit locally with source SHA provenance, run the focused contract/E2E tests for the touched area, update this sprint checkbox, and do not push to GitHub.

## Execution Order

1. Finish issues 1-3 before any source commit is cherry-picked.
2. Port layout and roster fixes first because they are product-visible and lower-risk.
3. Port only strengthening test-harness pieces next; never accept test simplifications as merge-conflict fixes.
4. Evaluate SFU recovery commits last, because they touch the hottest path and must preserve the current measured throughput budget.
5. Run focused tests after each accepted port, then update `READYNESS_TRACKER.md` only after the cherry-pick intake is complete.

## Parking Rule

Move an item to `BACKLOG.md` if any of the following is true:
- it does not directly improve the current SFU/WLVC online video call path
- it depends on unresolved proof from a still-open intake issue
- it is exploratory rather than contract-critical
- it is already completed and only needs archival evidence
