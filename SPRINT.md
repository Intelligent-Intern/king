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

## Sprint: IAM Session, Audit, Guestlist, And Terminal Proof 08

Branch:
- `prod-kingrt-do-not-push-to-github`

Status:
- Active as of 2026-05-10 after Sprint 07 deploy.
- Local-only integration branch. Do not push to GitHub.
- Worker branches/worktrees must use short-lived non-`codex` names and merge
  back into the local no-push branch after proof.
- Background, Gossip, SFU, MediaSecurity, BTGF, and their tests remain parked
  unless the user explicitly reopens them.

User-facing problem:
- Sprint 07 closed the last active IAM batch, but many historical local IAM
  branches still remain unmerged because their branch heads carry broad stale
  diffs.
- The next batch must extract only the still-useful current proof/runtime value
  around session switching, audit compatibility, guest-list journeys, terminal
  call states, and disabled-user/link behavior.

Sprint goal:
- Close exactly 20 IAM proof tickets from the next local-branch batch.
- For each historical branch, classify whether the current integration already
  contains the behavior; if not, extract the smallest current proof/runtime
  change needed.
- Keep stale branch artifacts out of the integration branch unless they are
  clean, contained, current, and proven.
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
- [x] IAM8-01 Extract or prove duplicate personalized-link race detection from
  `local/iam-e2e-abuse-duplicate-race`.
- [x] IAM8-02 Extract or prove duplicate abuse after logout/login user switch
  from `local/iam-e2e-abuse-logout-login-switch-proof-3`.
- [x] IAM8-03 Extract or prove account reconciliation email confirmation from
  `local/iam-e2e-account-reconciliation-email`.
- [x] IAM8-04 Extract or prove admin guest-list main journeys from
  `local/iam-e2e-admin-guestlist-main-journeys`.
- [x] IAM8-05 Extract or prove logged-in anonymous-link organization-admin
  rights from `local/iam-e2e-anonymous-link-org-admin-rights`.
- [x] IAM8-06 Extract or prove anonymous temporary access rights from
  `local/iam-e2e-anonymous-temp-rights-proof-2`.
- [x] IAM8-07 Extract or prove IAM audit event alias follow-up compatibility
  from `local/iam-e2e-audit-alias-followup-proof-3`.
- [x] IAM8-08 Extract or prove audit confirmation and owner-absence implicit
  logging from `local/iam-e2e-audit-confirmation-implicit`.
- [x] IAM8-09 Extract or prove host-verification audit event aliases from
  `local/iam-e2e-audit-event-compat-proof-3`.
- [ ] IAM8-10 Extract or prove IAM audit event contract coverage from
  `local/iam-e2e-audit-events`.
- [ ] IAM8-11 Extract or prove authorized call rejoin from
  `local/iam-e2e-authorized-rejoin-main`.
- [ ] IAM8-12 Extract or prove IAM call lifecycle proof value from
  `local/iam-e2e-call-lifecycle`.
- [ ] IAM8-13 Extract or prove call creation owner/moderation rights from
  `local/iam-e2e-call-owner-creation-rights`.
- [ ] IAM8-14 Extract or prove IAM E2E CI failure artifact handling from
  `local/iam-e2e-ci-artifacts-proof-2`.
- [ ] IAM8-15 Extract or prove IAM call-access CI gate split/docs from
  `local/iam-e2e-ci-docs-gate`.
- [ ] IAM8-16 Extract or prove core organization session journeys from
  `local/iam-e2e-core-org-session-journey`.
- [ ] IAM8-17 Extract or prove cross-org active organization switching from
  `local/iam-e2e-cross-org-active-org-switch`.
- [ ] IAM8-18 Extract or prove terminal call lifecycle states from
  `local/iam-e2e-delete-end-terminal-proof-2`.
- [ ] IAM8-19 Extract or prove disabled anonymous call-access links from
  `local/iam-e2e-disabled-anonymous-links`.
- [ ] IAM8-20 Extract or prove disabled user session revocation / Call App token
  binding from `local/iam-e2e-disabled-user-session-revocation`.
