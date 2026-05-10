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

## Sprint: IAM Call-Access Test Stabilization 01

Branch:
- `prod-kingrt-do-not-push-to-github`

Status:
- Active as of 2026-05-10.
- Local-only integration branch. Do not push to GitHub.
- Work happens in short-lived non-`codex` worker branches/worktrees and is
  merged back into the local no-push branch after proof.
- Background, Gossip, SFU, MediaSecurity, BTGF, and their tests remain parked
  unless the user explicitly reopens them.

User-facing problem:
- IAM/call-access coverage exists across many local worktrees and contracts, but
  the active sprint does not expose a clean 20-ticket execution queue.
- Guest/lobby/direct-join behavior, role boundaries, stale membership, duplicate
  invite/session abuse, and CI proof must be made deterministic before further
  deploys depend on them.
- Branch/worktree leftovers must be merged, parked, or removed intentionally so
  the local no-push branch remains the only deploy source.

Sprint goal:
- Stabilize IAM call-access tests and runtime contracts without shrinking the
  existing access model.
- Keep deterministic E2E seeding, frontend route behavior, backend admission,
  realtime scope, and deploy proof aligned.
- Close exactly 20 IAM/test checkboxes before opening the next IAM sprint.

Execution boundary:
- No pushes.
- No DNS or certbot automation.
- No Background/Gossip/SFU/MediaSecurity implementation work.
- Do not weaken admin, owner, moderator, guest-list, org-boundary, lobby, or
  audit semantics to make tests pass.
- Do not discard dirty worktrees unless their changes are proven merged or the
  user explicitly approves removal.

Proof anchors:
- `demo/video-chat/contracts/v1/iam-call-access-seeding.matrix.json`
- `demo/video-chat/frontend-vue/tests/contract/iam-call-access-e2e-foundation-contract.mjs`
- `demo/video-chat/frontend-vue/tests/e2e/call-access-join.spec.js`
- `demo/video-chat/frontend-vue/tests/e2e/call-access-seed-matrix.spec.js`
- `demo/video-chat/frontend-vue/tests/e2e/helpers/callAccessSeedMatrix.js`
- `demo/video-chat/frontend-vue/tests/e2e/helpers/videochatMatrixHarness.js`
- `demo/video-chat/backend-king-php/tests/call-guest-list-direct-join-contract.php`
- `demo/video-chat/backend-king-php/tests/call-access-membership-removal-contract.sh`
- `demo/video-chat/backend-king-php/tests/call-access-stale-organization-role-contract.sh`
- `demo/video-chat/frontend-vue/package.json`

Tickets:
- [x] IAM-01 Inventory IAM worktrees/branches and classify merge candidates for
  this sprint.
  - Removed 55 clean `codex/iam-lane-*` worktrees/branches whose HEAD was
    already contained in `prod-kingrt-do-not-push-to-github`.
  - Deleted the contained `codex/iam-lane-10-privacy-leak` branch; its worktree
    registration is gone, but a root-owned generated
    `demo/video-chat/backend-king-php/.local/email-outbox.log` directory remains
    on disk and needs manual/root cleanup if the empty path should disappear.
  - Kept six clean but not-contained IAM lane candidates for ticket-level review:
    `codex/iam-lane-54-organization-role-bootstrap-proof`,
    `codex/iam-lane-57-guest-list-owner-management-proof`,
    `codex/iam-lane-58-owner-transfer-rights-audit-proof`,
    `codex/iam-lane-59-admin-join-boundaries-proof`,
    `codex/iam-lane-60-calendar-invite-personalized-link-proof`, and
    `codex/iam-lane-61-temporary-call-link-account-proof`.
  - Primary larger merge candidate is the clean `iam-e2e-integration` worktree;
    do not wholesale merge it before per-ticket conflict and proof review.
  - Small clean worker candidates:
    `agent/iam-e2e-fixtures-foundation` and
    `agent/iam-e2e-rejoin-kick-membership`.
- [ ] IAM-02 Restore a clean deterministic IAM seed matrix covering system admin,
  tenant admins, owners, normal members, registered guests, temporary guests,
  deleted/ended/disabled calls, and cross-org calls.
- [ ] IAM-03 Make `iam-call-access-e2e-foundation-contract.mjs` pass against the
  seed matrix without fixture drift.
- [ ] IAM-04 Prove direct join permissions for platform admin, tenant admin,
  call owner, guest-list participant, and denied normal member.
- [ ] IAM-05 Prove external guest join links require display name, create a
  temporary guest identity, and wait in lobby until admitted.
- [ ] IAM-06 Prove backend guest-list direct-join behavior in the PHP contract.
- [ ] IAM-07 Prove cross-org denial and active-org switch behavior.
- [ ] IAM-08 Prove deleted, ended, disabled, and terminal call states do not leak
  private call data and cannot be joined.
- [ ] IAM-09 Prove membership removal and stale organization-role revalidation.
- [ ] IAM-10 Prove owner transfer, moderator, org-admin, and system-admin
  admission boundaries.
- [ ] IAM-11 Prove lobby queue idempotence, pagination/search stability, and
  concurrent admit/deny behavior.
- [ ] IAM-12 Prove duplicate invite/session/device/browser abuse is rejected or
  reconciled deterministically.
- [ ] IAM-13 Prove logout/login switch and parallel-tab account isolation.
- [ ] IAM-14 Prove call-access audit event compatibility and redaction.
- [ ] IAM-15 Prove Call App/whiteboard access revocation follows IAM call
  admission and removal decisions.
- [ ] IAM-16 Prove frontend route guards and verified-context UI for call-access
  sessions.
- [ ] IAM-17 Prove realtime websocket room scope and reconnect/backfill under IAM
  session changes.
- [ ] IAM-18 Wire the IAM contract/E2E subset into stable package scripts and CI
  release-gate metadata.
- [ ] IAM-19 Run backend/runtime proof in the strongest available local test
  environment; document any `pdo_sqlite` limitation instead of weakening tests.
- [ ] IAM-20 Build, run IAM proof set, deploy without push/DNS/certbot, and
  collect post-deploy diagnostics before opening the next 20-ticket sprint.

Loop policy:
- On `w`, keep up to six worker slots assigned, with worker branches not named
  `codex/*`.
- Merge completed worker branches into `prod-kingrt-do-not-push-to-github` only
  after their proof passes.
- If a worker finishes early, assign the next unchecked IAM ticket.
- When all 20 tickets are closed, move this sprint evidence to readiness/backlog
  history and open the next 20-ticket IAM sprint.
