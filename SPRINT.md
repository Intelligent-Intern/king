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

## Sprint: IAM Abuse, Runtime Proof, And Cleanup Stabilization 03

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
- Sprint 02 wired the broad IAM Call Access proof set into stable scripts and
  deployed it, but the remaining IAM backlog still has abuse/manipulation gaps,
  backend SQLite proof skips on local hosts without `pdo_sqlite`, and many old
  IAM branches/worktrees that must be classified without discarding user work.

Sprint goal:
- Close the next 20 IAM tickets around security-manipulation cases, duplicate
  device/browser abuse, mismatch verification, no-data-leak UI states, Docker
  runtime proof, and clean VCS hygiene.
- Keep tests deterministic and scoped to IAM Call Access.
- Close exactly 20 tickets, then build/deploy locally without push/DNS/certbot.

Execution boundary:
- No pushes.
- No DNS or certbot automation.
- No Background/Gossip/SFU/MediaSecurity implementation work.
- Do not discard dirty worktrees unless their changes are proven merged or the
  user explicitly approves removal.
- Prefer focused contract/E2E proof files over broad edits that collide with
  stable call-access specs.

Proof anchors:
- `demo/video-chat/frontend-vue/package.json`
- `demo/video-chat/contracts/v1/ui-parity-acceptance.matrix.json`
- `demo/video-chat/frontend-vue/tests/e2e/call-access-join.spec.js`
- `demo/video-chat/frontend-vue/tests/e2e/call-access-seed-matrix.spec.js`
- `demo/video-chat/frontend-vue/tests/e2e/helpers/callAccessSeedMatrix.js`
- `demo/video-chat/frontend-vue/tests/e2e/helpers/videochatMatrixHarness.js`
- `demo/video-chat/backend-king-php/tests/iam-call-access-sqlite-runtime-proof.sh`
- `demo/video-chat/backend-king-php/domain/calls/call_access_session.php`
- `demo/video-chat/backend-king-php/http/module_call_apps.php`

Tickets:
- [x] IAM3-01 Inventory remaining IAM abuse/security-manipulation branches and
  map clean candidates to this sprint without wholesale merges.
  - Merged worker branch `agent/iam-s3-01-inventory`.
  - Added `iam-sprint-03-inventory-contract.mjs` pinning 22 clean,
    not-contained IAM candidate worktrees for IAM3-02..IAM3-12, seven
    runtime/wiring/cleanup planning sources for IAM3-13..IAM3-20, and two
    dirty/manual IAM3-18 candidates.
  - Dirty/manual candidates remain parked: `codex/iam-call-access-e2e-foundation`
    is contained but dirty; `codex/iam-duplicate-cleanup-reaudit-20260509` is
    dirty, not-contained, and conflict-marked.
  - Proof: `node demo/video-chat/frontend-vue/tests/contract/iam-sprint-03-inventory-contract.mjs`
    and `git diff --check` passed.
- [x] IAM3-02 Prove forged call-access IDs, invite IDs, and call IDs are rejected
  with redacted safe states across API and browser UI.
  - Merged worker branch `agent/iam-s3-02-forged-identifiers`.
  - Removed guessed `access_id` echoing from call-access route error details,
    updated terminal invalidation assertions to the redacted shape, and added
    `call-access-forged-identifiers-contract.mjs`.
  - Kept package/release-gate wiring out of this ticket; Sprint 03 proof wiring
    remains IAM3-17.
  - Proof: `node tests/contract/call-access-forged-identifiers-contract.mjs`,
    `node tests/contract/call-access-link-privacy-contract.mjs`,
    `node tests/contract/call-access-terminal-states-contract.mjs`,
    `node tests/contract/call-access-terminal-browser-flows-contract.mjs`,
    `node tests/contract/call-access-invite-invalidation-terminal-contract.mjs`,
    `node tests/contract/iam-call-access-ci-wire-contract.mjs`,
    `php -l demo/video-chat/backend-king-php/http/module_calls_access.php`, and
    `git diff --check` passed; direct host PHP privacy runtime skipped where
    `pdo_sqlite` is unavailable.
- [x] IAM3-03 Prove tampered verified-context payloads cannot rebind a session,
  user, tenant, or call after frontend storage manipulation.
  - Merged worker branch `agent/iam-s3-03-tampered-verified-context`.
  - Added `call-access-tampered-verified-context-contract.mjs` proving frontend
    storage cannot inject trusted user/tenant/call bindings, verified context
    carries only user/session identity, backend derives tenant/call/access from
    bearer-authenticated server rows, and tampered verified user/session data
    fails closed without persisting a call-access session.
  - Proof: `node demo/video-chat/frontend-vue/tests/contract/call-access-tampered-verified-context-contract.mjs`,
    `node demo/video-chat/frontend-vue/tests/contract/call-access-verified-context-ui-contract.mjs`,
    and `git diff --check` passed; local backend route-guard shell skipped
    because host PHP lacks `pdo_sqlite`.
- [x] IAM3-04 Prove duplicate device/browser personalized-link redemption stays
  deterministic under parallel tabs and does not leak private invite metadata.
  - Merged worker branch `agent/iam-s3-04-duplicate-device-browser`.
  - Added `call-access-duplicate-device-browser-contract.mjs` proving one
    deterministic success plus one 409 conflict for parallel personalized-link
    redemption, no session adoption by the rejected browser, no retry loop, and
    no private invite/call/session metadata in the conflict state.
  - Proof: `node tests/contract/call-access-duplicate-device-browser-contract.mjs`,
    `node tests/contract/call-access-duplicate-invite-replay-contract.mjs`,
    `node tests/contract/call-access-duplicate-abuse-contract.mjs`, and
    `git diff --check` passed.
- [x] IAM3-05 Prove account logout/login switching across two browsers cannot
  reuse the previous viewer's call-access session.
  - Merged worker branch `agent/iam-s3-05-logout-login-switch`.
  - Added `call-access-logout-login-switch-contract.mjs` proving logout clears
    local viewer state, a later login cannot reuse the previous verified
    call-access context, and parallel browser contexts keep bearer tokens
    isolated.
  - Proof: `node tests/contract/call-access-logout-login-switch-contract.mjs`,
    `node tests/contract/call-access-account-isolation-contract.mjs`,
    `node tests/contract/call-access-registered-logged-in-invitee-contract.mjs`,
    `node tests/contract/call-access-registered-logged-out-handoff-contract.mjs`,
    and `git diff --check` passed.
- [x] IAM3-06 Prove mismatch verification blocks wrong host/account identities
  with no foreign person, call, calendar, or organization data in UI payloads.
  - Merged worker branch `agent/iam-s3-06-mismatch-no-leak`.
  - Added `call-access-mismatch-no-leak-states-contract.mjs` proving
    wrong-account, logout, duplicate-browser, and strong personalized-link
    mismatch states render only stable safe messages, do not enter lobby or
    workspace, do not bind rejected sessions, and do not expose foreign person,
    call, calendar, tenant, organization, or session data.
  - Proof: `node demo/video-chat/frontend-vue/tests/contract/call-access-mismatch-no-leak-states-contract.mjs`,
    `node demo/video-chat/frontend-vue/tests/contract/call-access-strong-mismatch-privacy-contract.mjs`,
    and `git diff --check` passed.
- [x] IAM3-07 Prove anonymous guest display-name manipulation cannot escalate to
  registered-user, owner, moderator, or org-admin rights.
  - Merged worker branch `agent/iam-s3-07-anonymous-guest-manipulation`.
  - Added `call-access-anonymous-guest-manipulation-contract.mjs` proving
    guest-controlled display-name/body manipulation survives only as display
    text, while user id, role, call role, tenant-admin, platform-admin, lobby
    moderation, owner-transfer, and direct-join authority remain server-derived
    and denied.
  - Proof: `node tests/contract/call-access-anonymous-guest-manipulation-contract.mjs`,
    `node tests/contract/call-access-admission-boundaries-contract.mjs`,
    `node tests/contract/call-access-direct-join-rights-contract.mjs`,
    `node tests/contract/call-access-account-isolation-contract.mjs`, and
    `git diff --check` passed.
- [x] IAM3-08 Prove temporary call-link users cannot persist outside the target
  call, tenant, expiration window, or admission state.
  - Merged worker branch `agent/iam-s3-08-temp-call-link-boundaries`.
  - Added `call-access-temp-call-link-boundaries-contract.mjs` proving temporary
    call-link sessions are bound to the issued call, organization context,
    expiration, and active admission state rather than becoming portable or
    persistent access.
  - Proof: `node tests/contract/call-access-temp-call-link-boundaries-contract.mjs`,
    `node tests/contract/call-access-personalized-temp-reuse-contract.mjs`, and
    `git diff --check` passed. Docker PHP proof for anonymous temporary rights
    passed in the worker; direct host PHP remains skipped when `pdo_sqlite` is
    unavailable.
- [x] IAM3-09 Prove disabled anonymous links and disabled call-access links fail
  closed before session creation and before lobby insertion.
  - Merged worker branch `agent/iam-s3-09-disabled-links-fail-closed`.
  - Added `call-access-disabled-links-fail-closed-contract.mjs` proving disabled
    anonymous and call-access links stop at the access boundary before session
    creation, lobby insertion, or reusable state can be emitted.
  - Proof: `node tests/contract/call-access-disabled-links-fail-closed-contract.mjs`,
    `node tests/contract/call-access-invite-invalidation-terminal-contract.mjs`,
    `node tests/contract/call-access-terminal-states-contract.mjs`, and
    `git diff --check` passed.
- [ ] IAM3-10 Prove kicked or removed participants cannot rejoin via cached
  call-access sessions, stale tabs, or copied join URLs.
- [x] IAM3-11 Prove active-call permission changes revoke stale UI actions and
  realtime room snapshots without forcing media/background regressions.
  - Merged worker branch `agent/iam-s3-11-permission-change-active-call`.
  - Added `call-access-permission-change-active-call-contract.mjs` proving role
    and permission updates revoke stale active-call actions through IAM/realtime
    state without relying on media/background lifecycle changes.
  - Proof: `node demo/video-chat/frontend-vue/tests/contract/call-access-permission-change-active-call-contract.mjs`,
    `node demo/video-chat/frontend-vue/tests/contract/call-access-owner-transfer-main-contract.mjs`,
    `node demo/video-chat/frontend-vue/tests/contract/call-access-admission-boundaries-contract.mjs`,
    `node demo/video-chat/frontend-vue/tests/contract/call-access-realtime-scope-contract.mjs`,
    and `git diff --check` passed.
- [ ] IAM3-12 Prove strong mismatch logging records only canonical, redacted IAM
  audit fields and never raw access links, cookies, SDP, ICE, or tokens.
- [ ] IAM3-13 Convert direct host-PHP SQLite skips into deterministic Docker PHP
  runtime proof for anonymous temporary rights.
- [ ] IAM3-14 Convert direct host-PHP SQLite skips into deterministic Docker PHP
  runtime proof for guest-list direct join and membership removal.
- [ ] IAM3-15 Convert direct host-PHP SQLite skips into deterministic Docker PHP
  runtime proof for cross-org and stale-organization-role checks.
- [ ] IAM3-16 Add a single stable IAM backend runtime proof wrapper that runs the
  Docker fallback contracts and exposes concise failure output.
- [ ] IAM3-17 Wire Sprint 03 contract/E2E/runtime proofs into package scripts and
  release-gate metadata after integration.
- [ ] IAM3-18 Classify dirty IAM worktrees (`codex/iam-call-access-e2e-foundation`,
  `codex/iam-duplicate-cleanup-reaudit-20260509`) as keep, superseded, or
  manual without discarding unmerged user work.
- [ ] IAM3-19 Clean merged/superseded IAM Sprint 03 worker branches/worktrees
  using contained-HEAD and clean-worktree rules only.
- [ ] IAM3-20 Build, run Sprint 03 IAM proof set, deploy without push/DNS/certbot,
  and collect post-deploy diagnostics before opening the next sprint.

Loop policy:
- On `w`, keep up to six worker slots assigned where the remaining tickets can
  run independently, with worker branches not named `codex/*`.
- Merge completed worker branches into `prod-kingrt-do-not-push-to-github` only
  after their proof passes.
- If a worker finishes early, assign the next unchecked IAM ticket.
- When all 20 tickets are closed, move this sprint evidence to readiness history
  and open the next 20-ticket IAM sprint if backlog remains.
