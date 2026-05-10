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

## Sprint: IAM Call-Access Browser E2E Stabilization 02

Branch:
- `prod-kingrt-do-not-push-to-github`

Status:
- Active as of 2026-05-10.
- Local-only integration branch. Do not push to GitHub.
- Worker branches/worktrees must use short-lived non-`codex` names and merge
  back into the local no-push branch after proof.
- Background, Gossip, SFU, MediaSecurity, BTGF, and their tests remain parked
  unless the user explicitly reopens them.

User-facing problem:
- Sprint 01 made the deterministic IAM contract gate deployable, but the
  broader invite/browser journeys are still scattered across old local IAM
  branches and worktrees.
- Calendar invite links, registered invitees, anonymous temporary accounts,
  invite invalidation, owner transfer, admin boundaries, removed members, stale
  roles, disabled users, audit output, CI artifacts, and production smoke
  selection need a clean 20-ticket execution queue.

Sprint goal:
- Promote the next IAM backlog batch into focused browser/E2E and contract
  proofs without shrinking the access model.
- Keep all new proof files explicit, deterministic, and wired into stable
  package/release-gate commands only after they are merged.
- Close exactly 20 tickets, then build/deploy locally without push/DNS/certbot.

Execution boundary:
- No pushes.
- No DNS or certbot automation.
- No Background/Gossip/SFU/MediaSecurity implementation work.
- Do not discard dirty worktrees unless their changes are proven merged or the
  user explicitly approves removal.
- Prefer new focused contract/E2E proof files over broad edits that collide with
  existing joined call-access specs.

Proof anchors:
- `demo/video-chat/frontend-vue/package.json`
- `demo/video-chat/contracts/v1/ui-parity-acceptance.matrix.json`
- `demo/video-chat/frontend-vue/tests/e2e/call-access-join.spec.js`
- `demo/video-chat/frontend-vue/tests/e2e/call-access-seed-matrix.spec.js`
- `demo/video-chat/frontend-vue/tests/e2e/helpers/callAccessSeedMatrix.js`
- `demo/video-chat/frontend-vue/tests/e2e/helpers/videochatMatrixHarness.js`
- `demo/video-chat/backend-king-php/tests/iam-call-access-sqlite-runtime-proof.sh`

Tickets:
- [ ] IAM2-01 Inventory remaining IAM browser/worktree candidates and map them
  to this sprint without merging stale branches wholesale.
- [ ] IAM2-02 Prove calendar invite join links resolve to call-scoped sessions
  without leaking foreign calendar or call data.
- [ ] IAM2-03 Prove unregistered calendar invitees enter the guest-name/lobby
  flow and cannot bypass host admission.
- [ ] IAM2-04 Prove registered invitees who are logged out get a safe login
  handoff and rebind only to the intended invite.
- [ ] IAM2-05 Prove registered invitees who are already logged in can join only
  the invited call and keep active organization boundaries intact.
- [ ] IAM2-06 Prove anonymous call links and temporary call-link accounts honor
  org-admin restrictions and do not elevate direct-join rights.
- [ ] IAM2-07 Prove personalized temporary accounts cannot be reused across
  another account, browser, or organization.
- [ ] IAM2-08 Prove invite invalidation after reschedule, delete, end, disable,
  or explicit revoke produces terminal safe states.
- [ ] IAM2-09 Prove duplicate invite redemption and stale verified-context
  replay are reconciled deterministically across devices.
- [ ] IAM2-10 Prove owner-transfer main journey updates call-access authority
  without leaving old-owner moderator powers behind.
- [ ] IAM2-11 Prove owner-transfer lifecycle and rejoin behavior for old owner,
  new owner, moderators, and guests.
- [ ] IAM2-12 Prove admin join boundaries in browser E2E for system admin,
  org-admin, foreign org-admin, moderator, owner, and member.
- [ ] IAM2-13 Prove removed members and invited users removed from the org lose
  call-access and lobby visibility without data leakage.
- [ ] IAM2-14 Prove disabled users, deleted users, deleted calls, ended calls,
  and disabled calls stay closed in browser flows.
- [ ] IAM2-15 Prove stale role and active-organization switch revalidation in
  browser flows, including no stale admin powers after switch.
- [ ] IAM2-16 Prove audit event compatibility across legacy/current IAM event
  names and redacted artifact output.
- [ ] IAM2-17 Stabilize CI artifacts for IAM browser proof: traces, screenshots,
  report naming, and failure redaction.
- [ ] IAM2-18 Wire Sprint 02 proofs into stable package scripts and release-gate
  metadata after integration.
- [ ] IAM2-19 Clean merged or superseded IAM Sprint 02 worktrees/branches using
  contained-HEAD and clean-worktree rules only.
- [ ] IAM2-20 Build, run Sprint 02 IAM proof set, deploy without push/DNS/certbot,
  and collect post-deploy diagnostics before opening the next sprint.

Loop policy:
- On `w`, keep up to six worker slots assigned, with worker branches not named
  `codex/*`.
- Merge completed worker branches into `prod-kingrt-do-not-push-to-github` only
  after their proof passes.
- If a worker finishes early, assign the next unchecked IAM ticket.
- When all 20 tickets are closed, move this sprint evidence to readiness/backlog
  history and open the next 20-ticket IAM sprint.
