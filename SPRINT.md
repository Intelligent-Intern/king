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

## Sprint: IAM Backlog Sweep, Proof Extraction, And Branch Cleanup 05

Branch:
- `prod-kingrt-do-not-push-to-github`

Status:
- Active as of 2026-05-10 after Sprint 04 deploy.
- Local-only integration branch. Do not push to GitHub.
- Worker branches/worktrees must use short-lived non-`codex` names and merge
  back into the local no-push branch after proof.
- Background, Gossip, SFU, MediaSecurity, BTGF, and their tests remain parked
  unless the user explicitly reopens them.

User-facing problem:
- Many old IAM and call-access proof branches/worktrees still exist. Some are
  clean and likely superseded, some are dirty or conflicted, and some may still
  contain useful focused IAM proof value that should be extracted before branch
  cleanup.

Sprint goal:
- Mine only current, stronger IAM/call-access proof value from the remaining
  local branches.
- Keep changes focused on IAM tests, backend call-access contracts, docs, and
  branch cleanup evidence.
- Delete only clean contained branches/worktrees whose HEAD is already merged
  into `prod-kingrt-do-not-push-to-github`.
- Leave Background/Gossip/SFU/MediaSecurity work untouched.
- Close exactly 20 tickets, then build/deploy locally without push/DNS/certbot.

Execution boundary:
- No pushes.
- No DNS or certbot automation.
- No Background/Gossip/SFU/MediaSecurity implementation work.
- Do not discard dirty worktrees unless their changes are proven merged or the
  user explicitly approves removal.
- Prefer focused contracts and classification docs over wholesale old-branch
  merges.

Proof anchors:
- `demo/video-chat/frontend-vue/package.json`
- `demo/video-chat/contracts/v1/ui-parity-acceptance.matrix.json`
- `demo/video-chat/frontend-vue/tests/contract/iam-call-access-ci-wire-contract.mjs`
- `demo/video-chat/frontend-vue/tests/e2e/call-access-join.spec.js`
- `demo/video-chat/frontend-vue/tests/e2e/call-access-seed-matrix.spec.js`
- `demo/video-chat/frontend-vue/tests/e2e/call-access-calendar-unregistered-invite.spec.js`
- `demo/video-chat/frontend-vue/tests/e2e/call-access-admin-join-boundaries.spec.js`
- `demo/video-chat/backend-king-php/tests/iam-call-access-sqlite-runtime-proof.sh`
- `documentation/iam-sprint-04-worker-cleanup-evidence.md`

Sprint Checkboxen:
- [ ] IAM5-01 Build a fresh remaining IAM branch/worktree inventory and rank
  branches by unique proof value, dirtiness, containment, and cleanup risk.
- [ ] IAM5-02 Classify `iam-e2e-integration` as merge candidate, superseded
  evidence, or cleanup anchor against `prod-kingrt-do-not-push-to-github`.
- [ ] IAM5-03 Reconcile the `codex/iam-duplicate-cleanup*` dirty/conflicted
  family without losing user work; extract only current package-suite value.
- [ ] IAM5-04 Extract authorized rejoin proof value from
  `local/iam-e2e-authorized-rejoin-main` and the 20260509 authorized-rejoin
  branches.
- [ ] IAM5-05 Extract lobby state cleanup proof value from the lobby cleanup
  branches, keeping live state websocket-driven and avoiding manual refresh UI.
- [ ] IAM5-06 Extract lobby admission timeout/concurrency/audit proof value
  from the lobby timeout, concurrency, admission, and audit branches.
- [ ] IAM5-07 Extract duplicate review/abuse proof value from the duplicate
  review branches, including email/review safety only where current contracts
  support it.
- [ ] IAM5-08 Extract cross-organization remaining proof value from the
  cross-org proof branches without weakening tenant isolation.
- [ ] IAM5-09 Extract owner absence, owner timeout, and owner-leave proof value
  from the owner-absence/timeout branches.
- [ ] IAM5-10 Extract owner-transfer main journey, rejoin, and permission-audit
  proof value from the remaining owner-transfer branches.
- [ ] IAM5-11 Extract guest-list management, owner management, and revocation
  proof value from the remaining guest-list branches.
- [ ] IAM5-12 Extract temporary guest, direct-join, temporary moderator, and
  kicked temporary-user proof value from the temp-access branches.
- [ ] IAM5-13 Extract email confirmation, account reconciliation, and safe
  dispatch/audit proof value from the account-confirmation branches.
- [ ] IAM5-14 Extract calendar invite, unregistered invitee, and reschedule
  stale-link proof value from the calendar/invite branches.
- [ ] IAM5-15 Extract Call App IAM boundary proof value for entitlement
  revocation, launch-token reconnect validation, and whiteboard org install
  without touching Call App UI feature work.
- [ ] IAM5-16 Extract system-admin, organization-role bootstrap, admin-join,
  and lane proof value from the remaining IAM lane branches.
- [ ] IAM5-17 Consolidate seed data hygiene, asset cache busting, local run docs,
  and live-proof env audit value into the current IAM gate only if still useful.
- [ ] IAM5-18 Run the focused IAM browser proof path or repair its local
  invocation without adding media/background/SFU/Gossip coverage.
- [ ] IAM5-19 Clean merged/superseded Sprint 05 and old IAM worker
  branches/worktrees using contained-HEAD and clean-worktree rules only.
- [ ] IAM5-20 Build, run the Sprint 05 IAM proof set, deploy without
  push/DNS/certbot, and collect post-deploy diagnostics.

Loop policy:
- On `w`, keep up to six worker slots assigned where the remaining tickets can
  run independently, with worker branches not named `codex/*`.
- Merge completed worker branches into `prod-kingrt-do-not-push-to-github` only
  after their proof passes.
- If a worker finishes early, assign the next unchecked IAM ticket.
- When all 20 tickets are closed, move this sprint evidence to readiness history
  and open the next 20-ticket sprint if backlog remains.
