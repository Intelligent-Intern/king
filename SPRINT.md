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
- [ ] IAM3-01 Inventory remaining IAM abuse/security-manipulation branches and
  map clean candidates to this sprint without wholesale merges.
- [ ] IAM3-02 Prove forged call-access IDs, invite IDs, and call IDs are rejected
  with redacted safe states across API and browser UI.
- [ ] IAM3-03 Prove tampered verified-context payloads cannot rebind a session,
  user, tenant, or call after frontend storage manipulation.
- [ ] IAM3-04 Prove duplicate device/browser personalized-link redemption stays
  deterministic under parallel tabs and does not leak private invite metadata.
- [ ] IAM3-05 Prove account logout/login switching across two browsers cannot
  reuse the previous viewer's call-access session.
- [ ] IAM3-06 Prove mismatch verification blocks wrong host/account identities
  with no foreign person, call, calendar, or organization data in UI payloads.
- [ ] IAM3-07 Prove anonymous guest display-name manipulation cannot escalate to
  registered-user, owner, moderator, or org-admin rights.
- [ ] IAM3-08 Prove temporary call-link users cannot persist outside the target
  call, tenant, expiration window, or admission state.
- [ ] IAM3-09 Prove disabled anonymous links and disabled call-access links fail
  closed before session creation and before lobby insertion.
- [ ] IAM3-10 Prove kicked or removed participants cannot rejoin via cached
  call-access sessions, stale tabs, or copied join URLs.
- [ ] IAM3-11 Prove active-call permission changes revoke stale UI actions and
  realtime room snapshots without forcing media/background regressions.
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
