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

## Sprint: IAM Calendar, Edge States, And Call-App Boundary Proof 09

Branch:
- `prod-kingrt-do-not-push-to-github`

Status:
- Active as of 2026-05-10 after Sprint 08 deploy.
- Local-only integration branch. Do not push to GitHub.
- Worker branches/worktrees must use short-lived non-`codex` names and merge
  back into the local no-push branch after proof.
- Background, Gossip, SFU, MediaSecurity, BTGF, and their tests remain parked
  unless the user explicitly reopens them.

User-facing problem:
- Sprint 08 closed the previous active IAM batch, but many historical local IAM
  branches still remain unmerged because their branch heads carry broad stale
  diffs.
- The next batch must extract only the still-useful current proof/runtime value
  around audit completeness, calendar invitation edge states, Call App IAM
  boundaries, cross-org foreign joins, terminal joins, duplicate-device abuse,
  fail-closed edge errors, and email confirmation safety.

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
- [ ] IAM9-01 Extract or prove audit log completeness from
  `local/iam-e2e-audit-log-completeness`.
- [ ] IAM9-02 Extract or prove calendar invitation edge safe states from
  `local/iam-e2e-calendar-edge-safe-states`.
- [ ] IAM9-03 Extract or prove calendar invitation temporary account flow from
  `local/iam-e2e-calendar-invitation-flow`.
- [ ] IAM9-04 Extract or prove unregistered calendar main journey from
  `local/iam-e2e-calendar-unregistered-main-journey`.
- [x] IAM9-05 Extract or prove call-access safe-screen privacy from
  `local/iam-e2e-call-access-safe-screen-final`.
- [ ] IAM9-06 Extract or prove Call App entitlement revocation from
  `local/iam-e2e-call-app-entitlement-revocation`.
- [ ] IAM9-07 Extract or prove Call App launch-token reconnect validation from
  `local/iam-e2e-call-app-launch-token-reconnect`.
- [ ] IAM9-08 Extract or prove cross-org foreign join edges from
  `local/iam-e2e-cross-org-foreign-join-edges`.
- [ ] IAM9-09 Extract or prove remaining cross-org IAM rows from
  `local/iam-e2e-cross-org-remaining-proof-2`.
- [ ] IAM9-10 Extract or prove adjacent terminal join denials from
  `local/iam-e2e-deleted-ended-disabled-followup-proof-3`.
- [ ] IAM9-11 Extract or prove deleted/ended/disabled join denials from
  `local/iam-e2e-deleted-ended-disabled-join`.
- [ ] IAM9-12 Extract or prove deleted/ended join hardening from
  `local/iam-e2e-deleted-ended-join-hardening`.
- [ ] IAM9-13 Extract or prove duplicate link abuse across devices from
  `local/iam-e2e-duplicate-abuse-device-browser-proof-3`.
- [ ] IAM9-14 Extract or prove duplicate link abuse device/browser baseline from
  `local/iam-e2e-duplicate-link-abuse-device-browser`.
- [ ] IAM9-15 Extract or prove IAM edge error matrix fail-closed paths from
  `local/iam-e2e-edge-error-matrix-proof`.
- [ ] IAM9-16 Extract or prove IAM edge safe states from
  `local/iam-e2e-edge-safe-states-proof-2`.
- [ ] IAM9-17 Extract or prove email confirmation race hardening from
  `local/iam-e2e-email-confirmation-race-hardening`.
- [ ] IAM9-18 Extract or prove secure expiring account update confirmations from
  `local/iam-e2e-email-confirmation-secure-expiry`.
- [ ] IAM9-19 Extract or prove multiple pending account confirmations from
  `local/iam-e2e-email-multiple-pending-proof`.
- [ ] IAM9-20 Extract or prove email safe texts and dispatch audit from
  `local/iam-e2e-email-safe-texts-and-dispatch-audit`.
