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

## Sprint: IAM Remaining Proof And Branch Cleanup 07

Branch:
- `prod-kingrt-do-not-push-to-github`

Status:
- Active as of 2026-05-10 after Sprint 06 deploy.
- Local-only integration branch. Do not push to GitHub.
- Worker branches/worktrees must use short-lived non-`codex` names and merge
  back into the local no-push branch after proof.
- Background, Gossip, SFU, MediaSecurity, BTGF, and their tests remain parked
  unless the user explicitly reopens them.

User-facing problem:
- Several remaining IAM/call-access proof branches still exist locally with
  useful test ideas, but their historical diffs are broad and stale.
- The active branch must absorb only current missing IAM/runtime proof value,
  keep stale branch artifacts out, and remove branch/worktree clutter only when
  containment and clean state are proven.

Sprint goal:
- Restore the next 20 IAM call-access test/proof candidates as focused active
  tickets.
- For each historical branch, classify whether the current integration already
  contains the behavior; if not, extract the smallest current proof/runtime
  change needed.
- Keep video media internals and parked background work untouched.
- Close exactly 20 tickets, then build/deploy locally without push/DNS/certbot.

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
- [x] IAM7-01 Extract or prove anonymous open-link lobby split from
  `agent/iam-e2e-anonymous-lobby`.
- [x] IAM7-02 Extract or prove duplicate review email/account confirmation from
  `agent/iam-e2e-duplicate-review-email`.
- [x] IAM7-03 Extract or prove owner-transfer and temporary-moderator call
  management from `agent/iam-e2e-owner-transfer-temp-mods`.
- [x] IAM7-04 Extract or prove personalized call-access identity handling from
  `agent/iam-e2e-personalized-identity`.
- [x] IAM7-05 Extract or prove IAM audit-log completeness from
  `local/iam-e2e-audit-log-completeness`.
- [x] IAM7-06 Extract or prove calendar invitation edge safe states from
  `local/iam-e2e-calendar-edge-safe-states`.
- [x] IAM7-07 Extract or prove call-access safe-screen privacy from
  `local/iam-e2e-call-access-safe-screen-final`.
- [x] IAM7-08 Extract or prove Call App entitlement revocation from
  `local/iam-e2e-call-app-entitlement-revocation`.
- [x] IAM7-09 Extract or prove Call App launch-token reconnect validation from
  `local/iam-e2e-call-app-launch-token-reconnect`.
- [x] IAM7-10 Extract or prove cross-organization foreign join edges from
  `local/iam-e2e-cross-org-foreign-join-edges`.
- [x] IAM7-11 Extract or prove deleted/ended call join hardening from
  `local/iam-e2e-deleted-ended-join-hardening`.
- [x] IAM7-12 Extract or prove account update/email confirmation race hardening
  from `local/iam-e2e-email-confirmation-race-hardening`.
- [ ] IAM7-13 Extract or prove foreign link review audit scoping from
  `local/iam-e2e-foreign-link-review-audit`.
- [ ] IAM7-14 Extract or prove identity mismatch review flow from
  `local/iam-e2e-identity-mismatch-review-flow`.
- [ ] IAM7-15 Extract or prove invalid/expired anonymous-link handling from
  `local/iam-e2e-invalid-anonymous-link-proof-20260509`.
- [ ] IAM7-16 Extract or prove link invalidation active states from
  `local/iam-e2e-link-invalidation-active-state`.
- [ ] IAM7-17 Extract or prove lobby management moderator rights from
  `local/iam-e2e-lobby-management-moderator-rights`.
- [ ] IAM7-18 Extract or prove local IAM E2E run docs and CI command hygiene
  from `local/iam-e2e-local-run-docs-proof-20260509`.
- [ ] IAM7-19 Extract or prove organization-removal active privilege downgrade
  from `local/iam-e2e-org-removal-active-privilege-downgrade`.
- [ ] IAM7-20 Extract or prove owner-absence realtime sync from
  `local/iam-e2e-owner-absence-realtime-sync`.
