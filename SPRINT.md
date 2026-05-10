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
- [x] IAM2-01 Inventory remaining IAM browser/worktree candidates and map them
  to this sprint without merging stale branches wholesale.
  - Inventory found 146 clean not-contained IAM candidate worktrees/branches, to
    be used only as per-ticket reference material.
  - Dirty IAM candidates remain parked: `codex/iam-duplicate-cleanup-reaudit-20260509`
    has unresolved/conflict dirt, and `codex/iam-call-access-e2e-foundation`
    remains dirty in `king-domain-registry`.
  - No Sprint 01 `agent/iam-s1-*` worktrees remain registered.
  - Active Sprint 02 workers are isolated in `agent/iam-s2-02-calendar-invite`,
    `agent/iam-s2-03-unregistered-calendar-guest`,
    `agent/iam-s2-04-registered-logged-out`,
    `agent/iam-s2-05-registered-logged-in`,
    `agent/iam-s2-06-anonymous-temp-rights`, and
    `agent/iam-s2-07-personalized-temp-reuse`.
  - Proof: IAM worktree inventory and contained-HEAD scan completed locally.
- [x] IAM2-02 Prove calendar invite join links resolve to call-scoped sessions
  without leaking foreign calendar or call data.
  - Merged worker branch `agent/iam-s2-02-calendar-invite`.
  - Added `call-access-calendar-invite-join-contract.mjs` proving booking-created
    call/access-id binding, invite-only call scope, safe public booking payload,
    call-access session binding, least-privilege tenant fallback, invalidation
    fail-closed behavior, and frontend safe join errors.
  - Proof: `node demo/video-chat/frontend-vue/tests/contract/call-access-calendar-invite-join-contract.mjs`,
    `node demo/video-chat/frontend-vue/tests/contract/call-access-link-privacy-contract.mjs`,
    and `git diff --check` passed.
- [x] IAM2-03 Prove unregistered calendar invitees enter the guest-name/lobby
  flow and cannot bypass host admission.
  - Merged worker branch `agent/iam-s2-03-unregistered-calendar-guest`.
  - Added `call-access-calendar-unregistered-invite.spec.js` proving a calendar
    invite without account requires a guest name, issues only a guest session,
    keeps admin/lobby permissions false, posts lobby queue join, and does not
    navigate directly into the workspace before host admission.
  - Proof: `npx playwright test tests/e2e/call-access-calendar-unregistered-invite.spec.js --workers=1`
    and `git diff --check` passed.
- [x] IAM2-04 Prove registered invitees who are logged out get a safe login
  handoff and rebind only to the intended invite.
  - Merged worker branch `agent/iam-s2-04-registered-logged-out`.
  - Added `call-access-registered-logged-out-handoff-contract.mjs` proving
    authenticated route handoff, login redirect sanitization, backend returned
    access-link rebinding, verified context snapshotting, and fail-closed wrong
    account/session switch handling.
  - Proof: `node demo/video-chat/frontend-vue/tests/contract/call-access-registered-logged-out-handoff-contract.mjs`
    and `git diff --check` passed.
- [x] IAM2-05 Prove registered invitees who are already logged in can join only
  the invited call and keep active organization boundaries intact.
  - Merged worker branch `agent/iam-s2-05-registered-logged-in`.
  - Added `call-access-registered-logged-in-invitee-contract.mjs` proving
    personalized registered invitee binding, wrong-account denial, call/room/user
    session binding, realtime binding mismatch rejection, cross-org separation,
    and no private call data leak on denial.
  - Proof: `node tests/contract/call-access-registered-logged-in-invitee-contract.mjs`,
    `../backend-king-php/tests/call-access-session-route-guard-contract.sh`
    (SQLite phase skipped because host PHP lacks `pdo_sqlite`), and
    `git diff --check` passed.
- [x] IAM2-06 Prove anonymous call links and temporary call-link accounts honor
  org-admin restrictions and do not elevate direct-join rights.
  - Merged worker branch `agent/iam-s2-06-anonymous-temp-rights`.
  - Adjusted open invite-only session issuance so anonymous temporary guests can
    enter the lobby/admission path without being inserted as allowed direct-join
    participants.
  - Added `call-access-anonymous-temp-rights-contract.php` proving org-admin
    rights do not cross tenant/org boundaries, temporary accounts do not inherit
    org-admin powers, and anonymous sessions do not grant guest-list/direct-join
    rights.
  - Proof: PHP lint passed, `iam-call-access-sqlite-runtime-proof.sh` passed via
    container fallback, and Docker PHP 8.4 with `pdo_sqlite` ran
    `call-access-admin-prevention-contract.php`,
    `call-guest-list-direct-join-contract.php`, and
    `call-access-anonymous-temp-rights-contract.php`.
- [x] IAM2-07 Prove personalized temporary accounts cannot be reused across
  another account, browser, or organization.
  - Merged worker branch `agent/iam-s2-07-personalized-temp-reuse`.
  - Added `call-access-personalized-temp-reuse-contract.mjs` proving email-only
    personal links create tenant-bound guest identities, session validation binds
    user/call/room/link kind, wrong account/browser replay fails closed, tenant
    fallback stays bound to call-access session, and frontend verified-context
    issuance is required.
  - Proof: `node demo/video-chat/frontend-vue/tests/contract/call-access-personalized-temp-reuse-contract.mjs`
    and `git diff --check` passed.
- [x] IAM2-08 Prove invite invalidation after reschedule, delete, end, disable,
  or explicit revoke produces terminal safe states.
  - Merged worker branch `agent/iam-s2-08-invite-invalidation`.
  - Added `call-access-invite-invalidation-terminal-contract.mjs` proving
    revoked/expired/deleted/ended/disabled invite links fail closed, session
    issuance does not allocate identities or leak call/link data, backend routes
    map terminal states to safe HTTP errors, and join UI blocks session POSTs
    after terminal context errors.
  - Proof: `node demo/video-chat/frontend-vue/tests/contract/call-access-invite-invalidation-terminal-contract.mjs`
    and `git diff --check` passed.
- [x] IAM2-09 Prove duplicate invite redemption and stale verified-context
  replay are reconciled deterministically across devices.
  - Merged worker branch `agent/iam-s2-09-duplicate-invite-replay`.
  - Added `call-access-duplicate-invite-replay-contract.mjs` proving stale
    verified-context replay, parallel duplicate personalized-link use, atomic
    invite redemption caps, deterministic 409 conflict/exhausted responses, and
    redacted denial payloads.
  - Stabilized `invite-code-redeem-endpoint-contract.php` for current tenant
    scoped route execution and valid terminal room/call statuses.
  - Proof: Node contract passed; Docker PHP 8.4 with `pdo_sqlite` ran
    `invite-code-redeem-contract.php`, `invite-code-redeem-endpoint-contract.php`,
    and `call-access-session-route-guard-contract.php`; `git diff --check`
    passed.
- [x] IAM2-10 Prove owner-transfer main journey updates call-access authority
  without leaving old-owner moderator powers behind.
  - Merged worker branch `agent/iam-s2-10-owner-transfer-main`.
  - Added `call-access-owner-transfer-main-contract.mjs` proving transfer routes
    through the call-scoped role endpoint, previous owner demotion, canonical
    `calls.owner_user_id` authority, realtime/lobby role recomputation, and
    frontend owner-management gating.
  - Proof: Node contract passed; `call-owner-moderation-contract.php` passed on
    host with persistence skipped and passed fully in Docker PHP 8.4 with
    `pdo_sqlite`; `git diff --check` passed.
- [x] IAM2-11 Prove owner-transfer lifecycle and rejoin behavior for old owner,
  new owner, moderators, and guests.
  - Merged worker branch `agent/iam-s2-11-owner-transfer-lifecycle`.
  - Added `owner-transfer-lifecycle-contract.mjs` proving post-transfer rejoin
    roles for old owner, new owner, moderator, and guest; reconnect does not
    imply leave; room snapshots refresh viewer rights; and moderators do not gain
    owner-transfer rights.
  - Proof: `node demo/video-chat/frontend-vue/tests/contract/owner-transfer-lifecycle-contract.mjs`
    and `git diff --check` passed.
- [x] IAM2-12 Prove admin join boundaries in browser E2E for system admin,
  org-admin, foreign org-admin, moderator, owner, and member.
  - Merged worker branch `agent/iam-s2-12-admin-join-boundaries`.
  - Added `call-access-admin-join-boundaries.spec.js` proving direct-join
    boundaries for system admin, same-org admin, foreign org-admin, call-scoped
    moderator, call owner, and plain member; denied cases redact private call
    payloads.
  - Proof: `npx playwright test tests/e2e/call-access-admin-join-boundaries.spec.js --workers=1`
    and `git diff --check` passed.
- [x] IAM2-13 Prove removed members and invited users removed from the org lose
  call-access and lobby visibility without data leakage.
  - Merged worker branch `agent/iam-s2-13-removed-members`.
  - Added `call-access-removed-members-contract.mjs` covering removed org
    members, removed invitees, denied lobby visibility, redacted denial payloads,
    and preserved access for still-authorized members.
  - Proof: `node demo/video-chat/frontend-vue/tests/contract/call-access-removed-members-contract.mjs`
    and `git diff --check` passed.
- [x] IAM2-14 Prove disabled users, deleted users, deleted calls, ended calls,
  and disabled calls stay closed in browser flows.
  - Merged worker branch `agent/iam-s2-14-terminal-browser-flows`.
  - Added `call-access-terminal-browser-flows-contract.mjs` covering disabled
    users, deleted users, deleted calls, ended calls, disabled calls, browser
    recovery state, redacted terminal payloads, and route/session guards.
  - Proof: `node demo/video-chat/frontend-vue/tests/contract/call-access-terminal-browser-flows-contract.mjs`
    and `git diff --check` passed.
- [x] IAM2-15 Prove stale role and active-organization switch revalidation in
  browser flows, including no stale admin powers after switch.
  - Merged worker branch `agent/iam-s2-15-stale-role-org-switch`.
  - Added `call-access-stale-role-org-switch-contract.mjs` covering stale
    org-role revalidation, active-organization switch safety, old-admin
    demotion, refreshed realtime snapshots, denied admin actions, and redacted
    mismatch output.
  - Proof: `node tests/contract/call-access-stale-role-org-switch-contract.mjs`
    and `git diff --check` passed; backend shell proofs skipped on host because
    local PHP lacks `pdo_sqlite`.
- [x] IAM2-16 Prove audit event compatibility across legacy/current IAM event
  names and redacted artifact output.
  - Merged worker branch `agent/iam-s2-16-audit-artifacts`.
  - Added canonical IAM audit event aliases and artifact redaction helpers in
    `audit_events.php`, plus
    `call-access-audit-event-compatibility-contract.mjs`.
  - Proof: `node demo/video-chat/frontend-vue/tests/contract/call-access-audit-event-compatibility-contract.mjs`,
    `node demo/video-chat/frontend-vue/tests/contract/call-access-audit-redaction-contract.mjs`,
    `php -l demo/video-chat/backend-king-php/domain/audit/audit_events.php`,
    Docker PHP 8.4 `pdo_sqlite` `audit-call-access-membership-contract.php`,
    and `git diff --check` passed.
- [x] IAM2-17 Stabilize CI artifacts for IAM browser proof: traces, screenshots,
  report naming, and failure redaction.
  - Merged worker branch `agent/iam-s2-17-ci-artifacts`.
  - Added `call-access-ci-artifacts-contract.mjs`, enabled focused
    `PLAYWRIGHT_IAM_CALL_ACCESS_ARTIFACTS` artifact retention for call-access
    E2E, and made the IAM CI wire contract assert the artifact-enabled command.
  - Proof: `node tests/contract/call-access-ci-artifacts-contract.mjs`,
    `node tests/contract/iam-call-access-ci-wire-contract.mjs`, and
    `git diff --check` passed.
- [x] IAM2-18 Wire Sprint 02 proofs into stable package scripts and release-gate
  metadata after integration.
  - Merged worker branch `agent/iam-s2-18-release-gate-wiring`.
  - Wired Sprint 02 contract/E2E proofs into
    `test:contract:iam-call-access`, focused `test:e2e:call-access`, release
    gate command metadata, and the CI wire contract.
  - Proof: `node tests/contract/iam-call-access-ci-wire-contract.mjs`,
    `node tests/contract/call-access-ci-artifacts-contract.mjs`,
    `npm run test:contract:iam-call-access`, and `git diff --check` passed;
    host PHP SQLite branches skipped where `pdo_sqlite` was unavailable, while
    `iam-call-access-sqlite-runtime-proof.sh` used Docker PHP 8.4 and passed.
- [x] IAM2-19 Clean merged or superseded IAM Sprint 02 worktrees/branches using
  contained-HEAD and clean-worktree rules only.
  - Removed merged Sprint 02 worker worktrees/branches after each merge.
  - Final scan found no remaining `agent/iam-s2-*` branches, no registered
    Sprint 02 worktrees, and no merged Sprint 02 cleanup candidates.
  - Proof: `git branch --list 'agent/iam-s2-*'`,
    `git worktree list --porcelain | rg ...`, `git branch --merged ... | rg ...`,
    and `git worktree prune --verbose` produced no remaining Sprint 02 entries.
- [x] IAM2-20 Build, run Sprint 02 IAM proof set, deploy without push/DNS/certbot,
  and collect post-deploy diagnostics before opening the next sprint.
  - Ran `npm run test:contract:iam-call-access`; all Node contracts passed,
    host PHP SQLite checks skipped where `pdo_sqlite` is unavailable, and the
    Docker PHP 8.4 `iam-call-access-sqlite-runtime-proof.sh` fallback passed.
  - Ran `npm run test:e2e:call-access`; 12 focused Call Access E2E tests passed.
  - Ran `npm run build`; Vite production build passed.
  - Deployed locally from `prod-kingrt-do-not-push-to-github` with
    `VIDEOCHAT_DEPLOY_SKIP_CERTBOT=1`, `VIDEOCHAT_DEPLOY_HCLOUD_DNS=0`,
    `VIDEOCHAT_DEPLOY_REFRESH_DNS_ON_PREPARE=0`, and no push.
  - Post-deploy diagnostics: `prod-debug.sh` passed public runtime/assets/CSP
    checks; app/API/call-app asset probes returned 200; unauthenticated
    `/api/calls/{id}/call-apps/available` returned 401 instead of the previous
    500 shape; remote compose shows backend, websocket, edge, and TURN
    containers up; recent app-container error scan found no fatal/panic/500
    lines after deploy.

Loop policy:
- On `w`, keep up to six worker slots assigned, with worker branches not named
  `codex/*`.
- Merge completed worker branches into `prod-kingrt-do-not-push-to-github` only
  after their proof passes.
- If a worker finishes early, assign the next unchecked IAM ticket.
- When all 20 tickets are closed, move this sprint evidence to readiness/backlog
  history and open the next 20-ticket IAM sprint.
