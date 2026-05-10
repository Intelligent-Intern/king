# IAM Sprint 05 Remaining Branch And Worktree Inventory

Date: 2026-05-10

Worker: IAM5-01

Inventory branch: `agent/iam-s5-01-inventory`

Inventory worktree:
`/home/jochen/projects/king.site/worktrees/iam-s5-01-inventory`

Base used for containment checks:
`prod-kingrt-do-not-push-to-github` at `49e5cdae`.

Scope: evidence and ranking only. No Background, Gossip, SFU,
MediaSecurity, BTGF, shared CI wiring, sprint checklist, package manifest, or
source file cleanup was performed.

## Method

- Enumerated local branches whose names contain `iam` or `call-access` with
  `git for-each-ref refs/heads`.
- Enumerated worktrees whose branch or path contains `iam` or `call-access`
  with `git worktree list --porcelain`.
- Classified containment with
  `git merge-base --is-ancestor <branch-or-head> prod-kingrt-do-not-push-to-github`.
- Classified worktree dirtiness with
  `git status --porcelain=v1 -uall`; unmerged index entries are `conflicted`,
  other status entries are `dirty`, and no status entries are `clean`.
- Ranked follow-up value from branch tip subjects, representative tip diffs,
  existing Sprint 03/04 extraction evidence, and the active Sprint 05 ticket
  boundaries.

## Totals

| Set | Total | Clean | Dirty | Conflicted | Contained | Not contained |
| --- | ---: | ---: | ---: | ---: | ---: | ---: |
| Matching local branches | 192 | n/a | n/a | n/a | 8 | 184 |
| Matching worktrees | 155 | 153 | 1 | 1 | 8 | 147 |

Additional inventory facts:

- 38 matching branches have no registered worktree.
- 1 matching worktree is detached:
  `/home/jochen/projects/king.site/worktrees/deploy-iam-e2e-snapshot`
  at `59165273`; it is clean and contained.
- The Sprint 05 worker branches `agent/iam-s5-01-inventory`,
  `agent/iam-s5-02-integration-classify`,
  `agent/iam-s5-03-duplicate-family`,
  `agent/iam-s5-04-authorized-rejoin`,
  `agent/iam-s5-05-lobby-cleanup`, and
  `agent/iam-s5-06-lobby-admission` are contained at scan time and must be
  preserved as active/recent Sprint 05 worker lanes, not cleanup targets.

## Manual Preservation

These worktrees are user-owned/manual-risk and must not be reset, removed, or
force-cleaned by branch cleanup:

| Branch | Worktree | State | Containment | Evidence | Rule |
| --- | --- | --- | --- | --- | --- |
| `codex/iam-call-access-e2e-foundation` | `/home/jochen/projects/king.site/worktrees/king-domain-registry` | dirty, 4 files | contained | Dirty files are `demo/video-chat/contracts/v1/ui-parity-acceptance.matrix.json`, `demo/video-chat/frontend-vue/package.json`, `demo/video-chat/frontend-vue/tests/contract/iam-call-access-e2e-foundation-contract.mjs`, and `demo/video-chat/scripts/smoke.sh`. | Preserve. It is a dirty contained foundation worktree called out in `BACKLOG.md`; classify only if deploy smoke stability requires it. |
| `codex/iam-duplicate-cleanup-reaudit-20260509` | `/home/jochen/projects/king.site/worktrees/codex-iam-duplicate-cleanup-reaudit-20260509` | conflicted, 24 status entries | not contained | `demo/video-chat/frontend-vue/package.json` is unmerged, with broad staged IAM contract-suite edits and `call-access-e2e-suite.mjs` added/modified. | Preserve. Resolve or extract only under IAM5-03; no cleanup until conflict value is proven redundant and user work is safe. |

Cleanup rules:

- Clean contained branches can only be deleted after confirming they are not
  active Sprint 05 worker lanes and their worktrees are clean at cleanup time.
- Dirty or conflicted worktrees are preservation blockers. Do not use
  `git reset`, `git checkout --`, forced removal, or branch deletion.
- Clean non-contained branches are not cleanup candidates until their unique
  proof value is either extracted or explicitly classified as superseded.
- Broad historical branches touching package scripts, suite runners, or CI
  gates should not be merged wholesale. Port focused contracts or behavior
  into current prod only under the relevant IAM5 ticket.

## Ranked Follow-Up Candidates

Rank is based on unique proof value first, then dirtiness/containment cleanup
risk. `manual-risk` means cleanup is unsafe without focused reconciliation.

| Rank | Ticket | Branch family | Dirtiness | Containment | Category | Unique proof value and extraction note |
| ---: | --- | --- | --- | --- | --- | --- |
| 1 | IAM5-03 | `codex/iam-duplicate-cleanup*` | mixed: 3 clean, 1 conflicted | not contained | completed/manual-risk | Highest cleanup risk because one worktree is conflicted and the family carries package-suite refactor value: `iamCallAccessSuiteCoverage.mjs`, `iam-call-access-contract-suite.mjs`, and broad contract-suite wiring. Base prod now contains IAM5-03 evidence; keep the conflicted source preserved for any later cleanup proof. |
| 2 | IAM5-02 | `iam-e2e-integration` plus same-head audit/rescan branches | clean | not contained | completed/cleanup-anchor | Broad integration anchor, 251 branch-side commits against prod. Base prod now classifies it as a cleanup anchor, not a wholesale merge candidate; it remains useful source evidence for focused IAM5 lanes. |
| 3 | IAM5-04 | `local/iam-e2e-authorized-rejoin-main`, `codex/iam-e2e-authorized-rejoin-*` | clean | not contained | completed/extracted | Focused backend and browser value: `call-access-authorized-rejoin-contract.php`, wrapper, seeding matrix changes, and `call-access-authorized-rejoin.spec.js`. Base prod now contains IAM5-04 extraction evidence; clean source branches still need later cleanup classification. |
| 4 | IAM5-06 | Lobby timeout, concurrency, admission, and audit branches | clean | not contained | completed/extracted | Highest product-logic branch set after duplicate cleanup. Tip diffs include realtime lobby persistence/commands, timeout consistency contracts, lobby concurrency contracts, audit event domain/module changes, and live fixture updates. Base prod now contains IAM5-06 extraction evidence; avoid manual refresh UI in any follow-up. |
| 5 | IAM5-05 | `local/iam-e2e-lobby-state-cleanup-proof`, `codex/iam-e2e-lobby-state-cleanup-*` | clean | not contained | completed/extracted | Adds realtime lobby cleanup backend contracts, frontend contract, E2E spec, and gate entries. Base prod now contains IAM5-05 extraction evidence, preserving websocket snapshot/delta state rather than manual refresh UI. |
| 6 | IAM5-07 | Duplicate review and abuse branches, including `codex/iam-e2e-duplicate-review-abuse-integration` | clean | not contained | current-value | Adds duplicate-review backend/session changes and E2E coverage for duplicate device/browser and logout/login switch cases. Some lower-level duplicate-device value was mined in Sprint 04, so extract only missing review/email/modal/audit behavior. |
| 7 | IAM5-08 | Cross-org remaining branches, including `local/iam-e2e-cross-org-remaining-proof-2` and `codex/iam-e2e-cross-org-remaining-proof-2-test-only-20260509` | clean plus no-worktree siblings | not contained | current-value | Adds remaining cross-org contract rows, seed matrix updates, and `iam-cross-org-remaining-proof-contract.mjs`. Keep tenant isolation stronger than old branch behavior. |
| 8 | IAM5-09 | Owner absence, owner timeout, owner-leave, and owner-realtime branches | mixed clean/no-worktree | not contained | current-value/manual-risk | Contains backend owner absence/timeout logic, frontend countdown/join behavior, link privacy assertions, and owner-timeout anonymous link invalidation. Moderate cleanup risk because some branches touch UI and realtime state. |
| 9 | IAM5-16 | IAM lane branches plus system-admin/admin boundary branches | clean plus no-worktree siblings | not contained | current-value/manual-risk | Lane branches are small, focused, and often one-commit. Highest value appears in organization role bootstrap and admin join boundaries, including backend governance/call-management/realtime room resolution contracts. Manual-risk because this touches authority semantics. |
| 10 | IAM5-10 | Owner-transfer main, rejoin, lifecycle, permission-audit, and temp-mod branches | mixed clean/no-worktree | not contained | current-value | Adds owner-transfer main journey E2E and permission audit/rejoin proof value. Some lifecycle value was mined in Sprint 04; compare before porting. |
| 11 | IAM5-11 | Guest-list management, owner management, revocation, and audit branches | clean | not contained | current-value | Adds guest-list management/audit contract value and direct-join backend refinements. Guest-list revocation proof-3 branches are likely superseded by Sprint 04 extraction, but owner-management and audit matrix still need focused comparison. |
| 12 | IAM5-12 | Temporary guest, direct-join, temporary moderator, temp admission, and kicked temp-user branches | mixed clean/no-worktree | not contained | current-value | Adds temporary guest-list direct-join E2E, temporary moderator rights, kicked temp-user rejoin, and temp admission context proof value. Extract focused access-boundary proofs only. |
| 13 | IAM5-13 | Email confirmation, account reconciliation, confirmation race, multiple pending, and safe dispatch branches | mixed clean/no-worktree | not contained | current-value | Highest value branch is `local/iam-e2e-email-safe-texts-and-dispatch-audit`, with confirmation domain/audit hardening and safe text contract. Account reconciliation and race-hardening branches need comparison for non-overlap. |
| 14 | IAM5-14 | Calendar invitation, unregistered invitee, reschedule stale-link, edge-safe-state branches | mixed clean/no-worktree | not contained | current-value | Adds calendar/unregistered journey and reschedule stale-link backend contracts. Some registered invitee flow proof was mined in Sprint 04; focus on calendar and stale-link paths. |
| 15 | IAM5-15 | Call App IAM boundary branches: entitlement revocation, launch-token reconnect, whiteboard org install | mixed clean/no-worktree | not contained | current-value/manual-risk | Contains call-app launch-token entitlement/session checks and whiteboard org install proof. Keep scope to IAM boundary contracts; do not pick up Call App UI feature work. |
| 16 | IAM5-17 | Seed data hygiene, asset cache busting, local docs, live proof env audit, cleanup-report branches | mixed clean/no-worktree | not contained | likely-superseded/current-value | Mostly housekeeping and broad historical merge branches. Extract only if a focused contract or local proof command is still missing from the current IAM gate. |
| 17 | IAM5-18 | Browser proof invocation and final harness branches | clean | not contained | current-value/low-extraction | Lowest branch-extraction value but useful proof value. `local/iam-e2e-final-contract-harness-scan-3` only modifies `rbac-middleware-contract.php`; browser proof should run from current prod invocation first, then repair only the local invocation if needed. |

## Family Inventory

Current-value families to assign before cleanup:

For IAM5-02, IAM5-03, IAM5-04, IAM5-05, and IAM5-06, base prod already
contains the Sprint 05 extraction/classification evidence. Their source
branches still appear in the inventory because cleanup must wait for explicit
contained-HEAD and clean-worktree checks.

| Ticket | Branches/worktrees to inspect first |
| --- | --- |
| IAM5-02 | `iam-e2e-integration`; same-head/no-worktree anchors `local/iam-e2e-invalid-anonymous-link-proof-20260509`; related audit/rescan branches under `codex/iam-e2e-merge-candidate-audit`, `codex/iam-e2e-next-3-candidate-triage`, `codex/iam-e2e-prod-call-audit-20260509`, and `codex/iam-e2e-branch-cleanup-audit`. |
| IAM5-03 | `codex/iam-duplicate-cleanup`, `codex/iam-duplicate-cleanup-current-reaudit-20260509`, `codex/iam-duplicate-cleanup-latest-reaudit-20260509`, and conflicted `codex/iam-duplicate-cleanup-reaudit-20260509`. |
| IAM5-04 | `local/iam-e2e-authorized-rejoin-main`, `codex/iam-e2e-authorized-rejoin-browser-proof-20260509`, `codex/iam-e2e-authorized-rejoin-doc-gate-scan-20260509`, `codex/iam-e2e-authorized-rejoin-main-integration-20260509`, `codex/iam-e2e-authorized-rejoin-main-integration-current-20260509`. |
| IAM5-05 | `local/iam-e2e-lobby-state-cleanup-proof`, `codex/iam-e2e-lobby-state-cleanup-proof-20260509`, `codex/iam-e2e-lobby-state-cleanup-script-gate-audit-20260509`. |
| IAM5-06 | `local/iam-e2e-lobby-admission-main`, `local/iam-e2e-lobby-concurrency-remaining`, `local/iam-e2e-lobby-audit-events`, `local/iam-e2e-lobby-audit-entry-admission-rejection`, `local/iam-e2e-lobby-timeout-consistency-proof-2`, `codex/iam-lobby-timeout-consistency-followup`, `codex/iam-lobby-timeout-consistency-followup-20260509`, `codex/iam-lobby-audit-cleanup-followup-20260509`, `local/iam-e2e-lobby-management-moderator-rights`, `agent/iam-e2e-anonymous-lobby`, `local/iam-e2e-temp-admission-context-proof`. |
| IAM5-07 | `codex/iam-e2e-duplicate-review-abuse-integration`, `agent/iam-e2e-duplicate-review-email`, `local/iam-e2e-abuse-duplicate-race`, `local/iam-e2e-duplicate-link-abuse-device-browser`, `local/iam-e2e-abuse-logout-login-switch-proof-3`, `local/iam-e2e-review-warning-modal-policy-proof-3`, `local/iam-e2e-review-abuse-cross-browser-proof-3`, `local/iam-e2e-foreign-link-review-audit`, `local/iam-e2e-identity-mismatch-review-flow`. |
| IAM5-08 | `local/iam-e2e-cross-org-active-org-switch`, `local/iam-e2e-cross-org-remaining-proof-2`, `codex/iam-e2e-cross-org-remaining-proof-2-test-only-20260509`, `codex/iam-e2e-cross-org-post-proof-audit-20260509`, `local/iam-e2e-cross-org-foreign-join-edges`, `local/iam-e2e-foreign-personalized-mismatch`, `local/iam-e2e-privacy-foreign-data`, `local/iam-e2e-org-removal-active-privilege-downgrade`, `local/iam-e2e-invited-user-org-removal`. |
| IAM5-09 | `local/iam-e2e-owner-absence-browser`, `local/iam-e2e-owner-absence-countdown-proof`, `local/iam-e2e-king-participants-owner-timeout`, `local/iam-e2e-owner-absence-realtime-sync`, `local/iam-e2e-owner-timeout-open-link-proof`, `local/iam-owner-timeout-anonymous-link-proof`, `local/iam-e2e-owner-leave-explicit-end-proof`, `codex/iam-e2e-audit-log-completeness-proof-20260509`, `local/iam-e2e-audit-log-completeness`. |
| IAM5-10 | `codex/iam-owner-transfer-main-journey-followup`, `local/iam-e2e-owner-transfer-main-journey-proof-2`, `local/iam-e2e-owner-transfer-rejoin-main`, `local/iam-e2e-owner-transfer-lifecycle-proof-3`, `local/iam-e2e-owner-transfer-permission-audit`, `local/iam-e2e-permission-change-active-call`, `local/iam-e2e-reconnect-after-permission-change`, `local/iam-e2e-permission-downgrade-audit-proof`, `agent/iam-e2e-owner-transfer-temp-mods`, `local/iam-e2e-org-admin-owner-transfer-policy`, `local/iam-e2e-guest-owner-transfer-revocation`. |
| IAM5-11 | `local/iam-e2e-guest-list-management-proof`, `local/iam-e2e-guest-list-management-audit-proof-2`, `local/iam-e2e-guest-list-harness-followup-3`, `local/iam-e2e-guest-list-revocation-proof-3`, `local/iam-e2e-admin-guestlist-main-journeys`, `local/iam-e2e-guest-cleanup`, `local/iam-e2e-guest-cleanup-remaining`, `local/iam-e2e-guest-lifecycle-temp-cleanup-remaining`, `codex/iam-lane-57-guest-list-owner-management-proof`. |
| IAM5-12 | `agent/iam-e2e-direct-join-roles`, `agent/iam-e2e-rejoin-kick-membership`, `local/iam-e2e-temp-guest-list-direct-join`, `local/iam-e2e-temp-moderator-remaining`, `local/iam-e2e-temp-user-kick-rejoin`, `local/iam-e2e-anonymous-temp-rights-proof-2`, `local/iam-e2e-anonymous-link-org-admin-rights`, `local/iam-e2e-rejoin-refresh-session-safety`, `codex/iam-lane-61-temporary-call-link-account-proof`. |
| IAM5-13 | `local/iam-e2e-account-reconciliation-email`, `local/iam-e2e-email-confirmation-secure-expiry`, `local/iam-e2e-email-multiple-pending-proof`, `local/iam-e2e-email-safe-texts-and-dispatch-audit`, `local/iam-e2e-email-confirmation-race-hardening`, `local/iam-e2e-audit-confirmation-implicit`. |
| IAM5-14 | `local/iam-e2e-calendar-invitation-flow`, `local/iam-e2e-calendar-unregistered-main-journey`, `codex/iam-e2e-calendar-unregistered-followup-20260509`, `local/iam-e2e-calendar-edge-safe-states`, `local/iam-e2e-reschedule-stale-link-safety`, `local/iam-e2e-invite-reschedule-delete-end-main-journeys`, `local/iam-e2e-invite-invalidation`, `local/iam-e2e-invite-invalidation-audit`, `local/iam-e2e-invite-registered-flow-proof-2`, `local/iam-e2e-invite-registered-logged-out-proof-3`, `codex/iam-lane-60-calendar-invite-personalized-link-proof`. |
| IAM5-15 | `local/iam-e2e-call-app-entitlement-revocation`, `local/iam-e2e-call-app-launch-token-reconnect`, `local/iam-e2e-whiteboard-org-install-final`, `local/iam-e2e-disabled-user-session-revocation`. |
| IAM5-16 | `local/iam-e2e-system-admin-edge-cases`, `local/iam-e2e-system-admin-deleted-ended-proof-3`, `codex/iam-e2e-anon-system-admin-proof-20260509`, `codex/iam-lane-54-organization-role-bootstrap-proof`, `codex/iam-lane-58-owner-transfer-rights-audit-proof`, `codex/iam-lane-59-admin-join-boundaries-proof`, `local/iam-e2e-call-owner-creation-rights`, `local/iam-e2e-core-org-session-journey`. |
| IAM5-17 | `codex/iam-e2e-asset-cache-busting-contract-20260509`, `local/iam-e2e-local-run-docs-proof-20260509`, `local/iam-seed-data-hygiene-20260509`, `codex/iam-seed-data-hygiene-20260509`, `codex/iam-e2e-live-proof-env-audit-20260509`, `codex/iam-e2e-deploy-readiness-20260509`, `iam-e2e-deploy-readiness-rescan-codex-20260509`, `codex/iam-e2e-script-helper-docs-cleanup-20260509`, `codex/iam-e2e-cleanup-report-20260509`, `codex/iam-next-5-candidate-rank-20260509`, `codex/iam-sprint-proof-audit-20260509`, `codex/iam-sprint-arendt-proof-checkboxes-20260509`. |
| IAM5-18 | `local/iam-e2e-final-contract-harness-scan-3`, `local/iam-e2e-main-journey-smoke`, `local/iam-e2e-call-lifecycle`, `local/iam-e2e-ci-docs-gate`, `local/iam-e2e-ci-artifacts-proof-2`, `codex/iam-e2e-ci-artifacts-proof-current-20260509`, `local/iam-e2e-final-branch-risk-review-3`, `local/iam-e2e-final-low-risk-checkbox-3`, `local/iam-e2e-final-proof-cleanup-3`, `local/iam-e2e-final-static-gate-proof-3`. |

Likely superseded or cleanup-after-classification families:

| Family | Branches | Reason |
| --- | --- | --- |
| Sprint 03/04 proof-3 evidence already mined | `local/iam-e2e-audit-alias-followup-proof-3`, `local/iam-e2e-audit-event-compat-proof-3`, `local/iam-e2e-duplicate-abuse-device-browser-proof-3`, `local/iam-e2e-delete-end-terminal-proof-2`, `local/iam-e2e-deleted-ended-disabled-followup-proof-3`, `local/iam-e2e-deleted-ended-disabled-join`, `local/iam-e2e-deleted-ended-join-hardening`, `local/iam-e2e-edge-safe-states-proof-2`, `local/iam-e2e-remaining-deleted-disabled-user-proof-3`, `local/iam-e2e-remaining-sprint-gaps-proof-3`, `local/iam-e2e-registered-invitee-final-proof-3`, `local/iam-e2e-registered-invitee-logged-in-proof-3`, `local/iam-e2e-registered-logged-out-personalized`, `codex/iam-e2e-registered-invitee-followup`, `local/iam-e2e-registered-invitee-sprint-scan-20260509`. | Current Sprint 03/04 docs and contracts already record the extracted value. Keep only until IAM5 classification confirms no missing current proof. |
| Public-copy and safe-error leftovers | `local/iam-e2e-public-copy-followup-proof-3`, `local/iam-e2e-seed-matrix-copy-proof-3`, `local/iam-e2e-personalized-invalid-safe-error-proof-2`, `local/iam-e2e-call-access-safe-screen-final`, `local/iam-e2e-edge-error-matrix-proof`, `agent/iam-e2e-personalized-identity`, `local/iam-e2e-link-invalidation-durability-proof`, `local/iam-e2e-link-invalidation-active-state`, `local/iam-e2e-disabled-anonymous-links`, `local/iam-e2e-explicit-call-end-join-paths`, `local/iam-e2e-light-mismatch-logging-proof-2`, `codex/iam-e2e-light-mismatch-audit-proof-20260509`, `local/iam-e2e-security-manipulation-remaining`, `local/iam-e2e-strong-mismatch-host-verification-flow`, `local/iam-e2e-multi-session-device-safety`, `local/iam-e2e-parallel-account-tabs`. | Mostly covered by Sprint 03/04 fail-closed, mismatch, duplicate, link invalidation, and safe-copy contracts. Reopen only if an IAM5 ticket finds a precise missing assertion. |
| Final sprint/status docs | `local/iam-e2e-final-sprint-checkbox-proof-3`, `local/iam-e2e-final-sprint-format-scan-3`, `local/iam-e2e-sprint-final-unchecked-scan-3`, `local/iam-e2e-sprint-proof-followup-3`, `local/iam-e2e-sprint-proof-index-3`, `codex/iam-e2e-final-risk-scan-20260509`, `codex/iam-e2e-final-risk-scan-current-20260509`, `codex/iam-e2e-final-gate-risk-scan`, `codex/iam-e2e-registered-invitee-branch-audit-20260509`. | Mostly sprint bookkeeping or audit notes against older bases. Treat as likely superseded after proof extraction tickets finish. |
| Contained/non-IAM cleanup candidates | `codex/iam-e2e-foundation`; detached `deploy-iam-e2e-snapshot`. | Clean and contained, but `codex/iam-e2e-foundation` has an optional SFU-related subject and must not be touched in IAM5. The detached deploy snapshot should be preserved until deploy owners approve cleanup. |

## No-Worktree Branches

The 38 matching branches without registered worktrees are not dirty in the
worktree sense, but most are still non-contained and must be classified before
branch deletion:

`agent/iam-e2e-anonymous-lobby`,
`agent/iam-e2e-duplicate-review-email`,
`agent/iam-e2e-owner-transfer-temp-mods`,
`agent/iam-e2e-personalized-identity`,
`codex/iam-e2e-anon-system-admin-proof-20260509`,
`codex/iam-e2e-audit-log-completeness-proof-20260509`,
`codex/iam-e2e-calendar-unregistered-followup-20260509`,
`codex/iam-e2e-ci-artifacts-proof-current-20260509`,
`codex/iam-e2e-cleanup-report-20260509`,
`codex/iam-e2e-cross-org-post-proof-audit-20260509`,
`codex/iam-e2e-foundation`,
`codex/iam-e2e-light-mismatch-audit-proof-20260509`,
`codex/iam-seed-data-hygiene-20260509`,
`local/iam-e2e-audit-log-completeness`,
`local/iam-e2e-calendar-edge-safe-states`,
`local/iam-e2e-call-access-safe-screen-final`,
`local/iam-e2e-call-app-entitlement-revocation`,
`local/iam-e2e-call-app-launch-token-reconnect`,
`local/iam-e2e-cross-org-foreign-join-edges`,
`local/iam-e2e-deleted-ended-join-hardening`,
`local/iam-e2e-email-confirmation-race-hardening`,
`local/iam-e2e-foreign-link-review-audit`,
`local/iam-e2e-identity-mismatch-review-flow`,
`local/iam-e2e-invalid-anonymous-link-proof-20260509`,
`local/iam-e2e-link-invalidation-active-state`,
`local/iam-e2e-lobby-management-moderator-rights`,
`local/iam-e2e-local-run-docs-proof-20260509`,
`local/iam-e2e-org-removal-active-privilege-downgrade`,
`local/iam-e2e-owner-absence-realtime-sync`,
`local/iam-e2e-owner-leave-explicit-end-proof`,
`local/iam-e2e-owner-timeout-open-link-proof`,
`local/iam-e2e-owner-transfer-permission-audit`,
`local/iam-e2e-permission-downgrade-audit-proof`,
`local/iam-e2e-privacy-foreign-data`,
`local/iam-e2e-reschedule-stale-link-safety`,
`local/iam-e2e-temp-admission-context-proof`,
`local/iam-owner-timeout-anonymous-link-proof`,
`local/iam-seed-data-hygiene-20260509`.
