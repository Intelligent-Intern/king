# King Active Issues

Purpose:
- `SPRINT.md` contains only the active top-priority sprint.
- Completed sprint detail is intentionally removed from this file.
- Parked or deferred work lives in `BACKLOG.md`.
- Completion evidence belongs in commit history, contracts, and readiness docs.

Rules:
- Work one checkbox at a time unless the user explicitly expands scope.
- A checkbox is only closed after implementation and proof.
- Do not weaken King v1 contracts to make the sprint smaller.
- Do not grow `CallWorkspaceView.vue` or other oversized files; extract focused
  helpers/components when adding behavior.
- Use the local branch `prod-kingrt-do-not-push-to-github` for integration.
- Do not push. Deploy only when the active sprint proof is green.
- Do not run DNS or certbot automation unless a new domain is explicitly added.

## Sprint: IAM Cleanup, Proof Consolidation, And Browser Stability 04

Branch:
- `prod-kingrt-do-not-push-to-github`

Status:
- Active as of 2026-05-10 after Sprint 03 deploy.
- Local-only integration branch. Do not push to GitHub.
- Worker branches/worktrees must use short-lived non-`codex` names and merge
  back into the local no-push branch after proof.
- Background, Gossip, SFU, MediaSecurity, BTGF, and their tests remain parked
  unless the user explicitly reopens them.

User-facing problem:
- Sprint 03 closed the current IAM abuse/runtime proof batch and deployed it,
  but old IAM proof branches/worktrees still contain non-contained work, one
  dirty duplicate-cleanup branch has unresolved conflicts, and browser proof
  coverage needs consolidation without dragging media/background work back in.

Sprint goal:
- Extract only current, stronger IAM value from old proof branches.
- Resolve or park dirty IAM branch state without discarding user work.
- Keep the IAM proof gate deterministic and scoped to call-access behavior.
- Close exactly 20 tickets, then build/deploy locally without push/DNS/certbot.

Execution boundary:
- No pushes.
- No DNS or certbot automation.
- No Background/Gossip/SFU/MediaSecurity implementation work.
- Do not discard dirty worktrees unless their changes are proven merged or the
  user explicitly approves removal.
- Prefer focused contracts and narrow browser proofs over broad suite rewrites.

Proof anchors:
- `demo/video-chat/frontend-vue/package.json`
- `demo/video-chat/contracts/v1/ui-parity-acceptance.matrix.json`
- `demo/video-chat/frontend-vue/tests/contract/iam-call-access-ci-wire-contract.mjs`
- `demo/video-chat/frontend-vue/tests/e2e/call-access-join.spec.js`
- `demo/video-chat/frontend-vue/tests/e2e/call-access-seed-matrix.spec.js`
- `demo/video-chat/frontend-vue/tests/e2e/call-access-calendar-unregistered-invite.spec.js`
- `demo/video-chat/frontend-vue/tests/e2e/call-access-admin-join-boundaries.spec.js`
- `documentation/iam-sprint-03-dirty-worktree-classification.md`
- `documentation/iam-sprint-03-contained-head-cleanup-evidence.md`

Sprint Checkboxen:
- [x] IAM4-01 Reconcile dirty `codex/iam-call-access-e2e-foundation` with the
  deployed IAM gate and extract only unique current value, if any.
  - Merged worker branch `agent/iam-s4-01-foundation-reconcile`.
  - Added `documentation/iam-sprint-04-foundation-reconcile-evidence.md`.
  - Classified the dirty source worktree as fully superseded by the current
    deployed IAM gate and ported no source changes.
  - Proof: `node tests/contract/iam-call-access-e2e-foundation-contract.mjs`,
    `node tests/contract/iam-call-access-ci-wire-contract.mjs`,
    `bash -n demo/video-chat/scripts/smoke.sh`, and `git diff --check` passed.
  - Dirty source worktree `/home/jochen/projects/king.site/worktrees/king-domain-registry`
    was not reset, deleted, or modified.
- [ ] IAM4-02 Resolve/classify dirty `codex/iam-duplicate-cleanup-reaudit-20260509`
  conflict state without losing user work or broad staged proof intent.
- [ ] IAM4-03 Inventory all non-contained `local/iam-e2e-*proof-3` worktrees and
  rank them by unique IAM proof value versus Sprint 03.
- [ ] IAM4-04 Extract any stronger logout/login-switch and account-switch proof
  value from `local/iam-e2e-abuse-logout-login-switch-proof-3`.
- [ ] IAM4-05 Extract any stronger audit alias/event compatibility proof value
  from the audit `proof-3` branches.
- [ ] IAM4-06 Extract deleted/ended/disabled terminal-state proof value from the
  deleted/disabled `proof-3` branches.
- [ ] IAM4-07 Extract duplicate-abuse device/browser proof value from
  `local/iam-e2e-duplicate-abuse-device-browser-proof-3`.
- [ ] IAM4-08 Extract guest-list revocation proof value from
  `local/iam-e2e-guest-list-revocation-proof-3`.
- [ ] IAM4-09 Extract registered-invitee logged-in/logged-out/final proof value
  from the registered-invitee `proof-3` branches.
- [ ] IAM4-10 Extract owner-transfer lifecycle proof value from
  `local/iam-e2e-owner-transfer-lifecycle-proof-3`.
- [ ] IAM4-11 Extract public-copy and seed-matrix proof value from their
  `proof-3` branches.
- [ ] IAM4-12 Extract remaining deleted-disabled and remaining-sprint-gap proof
  value from their `proof-3` branches.
- [ ] IAM4-13 Extract review-abuse cross-browser and warning-modal policy proof
  value from their `proof-3` branches.
- [ ] IAM4-14 Extract system-admin deleted/ended proof value from
  `local/iam-e2e-system-admin-deleted-ended-proof-3`.
- [ ] IAM4-15 Convert accepted old-branch value into focused Sprint 04 contracts
  without broad package or suite-runner conflicts.
- [ ] IAM4-16 Run or repair the focused `npm run test:e2e:call-access` browser
  proof path without adding media/background/SFU/Gossip coverage.
- [ ] IAM4-17 Ensure IAM browser artifacts and failure output remain retained
  and redacted for call-access E2E diagnostics.
- [ ] IAM4-18 Clean merged/superseded Sprint 04 worker branches/worktrees using
  contained-HEAD and clean-worktree rules only.
- [ ] IAM4-19 Wire accepted Sprint 04 proofs into IAM package scripts,
  CI-wire contract, and release-gate metadata after integration.
- [ ] IAM4-20 Build, run Sprint 04 IAM proof set, deploy without
  push/DNS/certbot, and collect post-deploy diagnostics.

Loop policy:
- On `w`, keep up to six worker slots assigned where the remaining tickets can
  run independently, with worker branches not named `codex/*`.
- Merge completed worker branches into `prod-kingrt-do-not-push-to-github` only
  after their proof passes.
- If a worker finishes early, assign the next unchecked IAM ticket.
- When all 20 tickets are closed, move this sprint evidence to readiness history
  and open the next 20-ticket sprint if backlog remains.
