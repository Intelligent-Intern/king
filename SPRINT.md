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

## Sprint: IAM Branch Cleanup, Current Proof, And Stale-Diff Closure 10

Branch:
- `prod-kingrt-do-not-push-to-github`

Status:
- Active as of 2026-05-10 after Sprint 09 deploy and diagnostics.
- Local-only integration branch. Do not push to GitHub.
- Worker branches/worktrees must use short-lived non-`codex` names and merge
  back into the local no-push branch after proof.
- Background, Gossip, SFU, MediaSecurity, BTGF, and their tests remain parked
  unless the user explicitly reopens them.

User-facing problem:
- Sprint 09 closed the previous IAM proof batch, but many stale local IAM
  branch heads remain unmerged because they contain broad historical diffs.
- The next batch must extract only current proof/runtime value from 20 more
  local IAM branches, or prove that the current integration already contains
  the value and document the branch as cleanup-only.

Sprint goal:
- Close exactly 20 IAM proof/cleanup tickets from the next local-branch batch.
- For each historical branch, classify whether the current integration already
  contains the behavior; if not, extract the smallest current proof/runtime
  change needed.
- Keep stale branch artifacts out of the integration branch unless they are
  clean, contained, current, and proven.
- Clean worker worktrees/branches after they are merged, clean, and ancestor of
  the local no-push branch.
- Build/deploy locally only after the active sprint proof is green, without
  push, DNS automation, or certbot issuance.

Execution boundary:
- No pushes.
- No DNS or certbot automation.
- No Background/Gossip/SFU/MediaSecurity/BTGF implementation or tests.
- Do not merge whole historical IAM branches blindly; inspect and extract only
  current value.
- Do not discard dirty worktrees unless their changes are proven merged or the
  user explicitly approves removal.
- Use worker branches/worktrees that do not start with `codex/`.

Proof anchors:
- `BACKLOG.md`
- `READYNESS_TRACKER.md`
- `documentation/`
- `demo/video-chat/contracts/v1/iam-call-access-seeding.matrix.json`
- `demo/video-chat/frontend-vue/package.json`
- `demo/video-chat/frontend-vue/tests/contract/`
- `demo/video-chat/frontend-vue/tests/e2e/`
- `demo/video-chat/backend-king-php/domain/calls/`
- `demo/video-chat/backend-king-php/domain/realtime/`
- `demo/video-chat/backend-king-php/tests/`

Sprint Checkboxen:
- [ ] IAM10-01 Extract or prove delete/end terminal call states from
  `local/iam-e2e-delete-end-terminal-proof-2`.
- [ ] IAM10-02 Extract or prove anonymous-link org-admin rights from
  `local/iam-e2e-anonymous-link-org-admin-rights`.
- [ ] IAM10-03 Extract or prove authorized rejoin main journey from
  `local/iam-e2e-authorized-rejoin-main`.
- [ ] IAM10-04 Extract or prove owner-transfer permission audit from
  `local/iam-e2e-owner-transfer-permission-audit`.
- [ ] IAM10-05 Extract or prove review-abuse cross-browser behavior from
  `local/iam-e2e-review-abuse-cross-browser-proof-3`.
- [ ] IAM10-06 Extract or prove duplicate-abuse race behavior from
  `local/iam-e2e-abuse-duplicate-race`.
- [ ] IAM10-07 Extract or prove foreign personalized mismatch behavior from
  `local/iam-e2e-foreign-personalized-mismatch`.
- [ ] IAM10-08 Extract or prove guest-list management audit behavior from
  `local/iam-e2e-guest-list-management-audit-proof-2`.
- [ ] IAM10-09 Extract or prove invite invalidation behavior from
  `local/iam-e2e-invite-invalidation`.
- [ ] IAM10-10 Extract or prove owner-leave explicit-end behavior from
  `local/iam-e2e-owner-leave-explicit-end-proof`.
- [ ] IAM10-11 Extract or prove guest owner-transfer revocation behavior from
  `local/iam-e2e-guest-owner-transfer-revocation`.
- [ ] IAM10-12 Extract or prove parallel account tabs behavior from
  `local/iam-e2e-parallel-account-tabs`.
- [ ] IAM10-13 Extract or prove remaining lobby concurrency behavior from
  `local/iam-e2e-lobby-concurrency-remaining`.
- [ ] IAM10-14 Extract or prove owner-transfer lifecycle behavior from
  `local/iam-e2e-owner-transfer-lifecycle-proof-3`.
- [ ] IAM10-15 Extract or prove light mismatch logging behavior from
  `local/iam-e2e-light-mismatch-logging-proof-2`.
- [ ] IAM10-16 Extract or prove org-admin owner-transfer policy behavior from
  `local/iam-e2e-org-admin-owner-transfer-policy`.
- [ ] IAM10-17 Extract or prove system-admin edge cases from
  `local/iam-e2e-system-admin-edge-cases`.
- [ ] IAM10-18 Extract or prove temp-user kick/rejoin behavior from
  `local/iam-e2e-temp-user-kick-rejoin`.
- [ ] IAM10-19 Extract or prove reschedule stale-link safety from
  `local/iam-e2e-reschedule-stale-link-safety`.
- [ ] IAM10-20 Extract or prove disabled-user session revocation from
  `local/iam-e2e-disabled-user-session-revocation`.
