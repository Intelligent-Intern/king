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
- Root planning Markdown stays limited to `README.md`, `BACKLOG.md`, and
  `SPRINT.md`; do not add new root Markdown planning files.
- Do not grow `CallWorkspaceView.vue` or other oversized files; extract focused
  helpers/components when adding behavior.
- Use the local branch `prod-kingrt-do-not-push-to-github` for integration.
- Do not push.
- Do not run DNS or certbot automation unless a new domain is explicitly added.

## Sprint: IAM Call Access Proof Consolidation And Test Gate 12

Branch:
- `prod-kingrt-do-not-push-to-github`

Status:
- Active as of 2026-05-10 after completion of `IAM Call Access, Branch
  Hygiene, And Deploy Gate 11`.
- Local-only integration branch. Do not push to GitHub.
- IAM11 is closed as active work; its proof stays in the touched contracts,
  `analyse/`, `documentation/`, and local git history.
- Actual deploy, DNS, and certbot steps are out of scope for this sprint.

Sprint goal:
- Consolidate the IAM11 runtime changes into a stable, rerunnable
  call-access proof gate.
- Remove stale proof drift after the root Markdown cleanup and component
  extraction.
- Make host/Docker SQLite behavior explicit so local skips do not hide missing
  proof.
- Keep Call-App entitlement, launch-token, guest-list, audit, lobby, owner,
  org-admin, and session-state contracts explicit and current.

Execution boundary:
- No pushes.
- No DNS, certbot, or production deploy automation.
- Do not discard dirty worktrees or stale branch diffs unless they are proven
  merged or the user explicitly approves removal.
- Do not merge whole historical IAM branches blindly; inspect and extract only
  current runtime or proof value.
- Do not reopen Background, Gossip, SFU, MediaSecurity, BTGF, or VCAP work
  unless a focused regression directly blocks one of the tickets below.
- Keep `CallWorkspaceView.vue` and other oversized files trending down when
  runtime work becomes necessary.

Proof anchors:
- `BACKLOG.md`
- `analyse/`
- `documentation/`
- `demo/video-chat/contracts/v1/iam-call-access-seeding.matrix.json`
- `demo/video-chat/frontend-vue/tests/contract/`
- `demo/video-chat/frontend-vue/tests/e2e/`
- `demo/video-chat/backend-king-php/domain/calls/`
- `demo/video-chat/backend-king-php/domain/realtime/`
- `demo/video-chat/backend-king-php/tests/`
- local branch/worktree inventory
- local deploy gate command output

Sprint Checkboxen:
- [x] IAM12-01 Rebaseline the completed IAM11 integration diff and record the
  current no-push/no-deploy proof state.
- [x] IAM12-02 Remove stale IAM proof text that still says closed IAM11 items
  remain open, blocked, or unproven.
- [x] IAM12-03 Fix the `test:ci:iam-call-access:static` drift around
  `documentation/iam7-08-call-app-entitlement-revocation.md`.
- [x] IAM12-04 Standardize IAM PHP SQLite wrappers so each call-access runtime
  proof either runs through Docker fallback or documents an intentional host
  skip.
- [x] IAM12-05 Ensure audit call-access runtime proofs are included in the
  aggregate Docker-capable IAM gate.
- [x] IAM12-06 Ensure every IAM11 runtime proof is covered by
  `iam-call-access-sqlite-runtime-proof.sh` or the backend Docker gate.
- [ ] IAM12-07 Reconcile strong personalized-link mismatch UI proof after
  `CallAccessJoinFooter.vue` extraction.
- [ ] IAM12-08 Reconcile invalid/expired anonymous-link proof with archived
  readiness references.
- [ ] IAM12-09 Reconcile foreign-link review audit proof with archived readiness
  references and redaction markers.
- [ ] IAM12-10 Reconcile identity-mismatch review proof with audit marker
  preservation and redaction.
- [ ] IAM12-11 Reconcile lobby management moderator-rights proof with
  kick/reject/remove lifecycle semantics.
- [ ] IAM12-12 Reconcile org-removal active-privilege downgrade proof with
  database-backed admin authority.
- [ ] IAM12-13 Reconcile owner-absence realtime sync proof with current
  presence and room-snapshot behavior.
- [ ] IAM12-14 Reconcile Call-App entitlement revocation proof with package
  boundaries under `demo/call-app/<app-key>`.
- [ ] IAM12-15 Reconcile Call-App launch-token reconnect proof with current
  route/session behavior.
- [ ] IAM12-16 Reconcile registered invitee logged-in/logged-out handoff proof
  with current session route guards.
- [ ] IAM12-17 Reconcile guest-list lifecycle cleanup proof with current owner,
  moderator, and temporary-guest behavior.
- [ ] IAM12-18 Prepare a branch/worktree cleanup dry run for clean ancestors of
  `prod-kingrt-do-not-push-to-github` without deleting unproven dirty work.
- [ ] IAM12-19 Make the visible checkout/root Markdown state unambiguous without
  overwriting parked Background work.
- [ ] IAM12-20 Final sprint proof: IAM aggregate gate, frontend build, diff
  hygiene, branch-hygiene note, and no push/deploy/DNS/certbot action.

Current Loop Notes:
- IAM12 opened after IAM11 completed with all 20 checkboxes closed.
- IAM12-01 proof: `analyse/IAM12-01-rebaseline-and-scope-split.md` records the
  current integration state and the required IAM-only versus parked
  VCAP/media-plan split before any commit, deploy, or branch cleanup.
- IAM12-02 proof: `analyse/IAM12-02-stale-proof-text.md` records the stale
  IAM11-08 proof conflict and its correction. The IAM11-08 proof note now
  points at the current correct-host decline/update-confirm-email E2E and
  contract coverage instead of saying the item remains open.
- IAM12-03 proof: `analyse/IAM12-03-static-gate-drift.md` records the
  Call-App entitlement revocation documentation drift and the focused fix.
  `node tests/contract/iam9-06-call-app-entitlement-revocation-contract.mjs`
  and `npm run test:ci:iam-call-access:static` passed.
- IAM12-05 proof: `analyse/IAM12-05-audit-runtime-proof-gate.md` records the
  audit-events, audit-membership, and foreign-link-review audit runtime wiring
  into the Docker-capable SQLite aggregate. Direct audit wrappers, focused
  static contracts, `npm run test:ci:iam-call-access:sqlite`,
  `npm run test:ci:iam-call-access:static`, and `git diff --check` passed.
- IAM12-06 proof: `analyse/IAM12-06-iam11-runtime-proof-gate.md` records the
  IAM11/current runtime proof inventory cleanup. Authority, decision, privacy,
  session-fixation, and edge/error matrix runtime proofs are now covered by
  `iam-call-access-sqlite-runtime-proof.sh`; Docker discovery stayed scoped to
  `*docker-proof.sh`. Focused direct wrappers, focused aggregate/static checks,
  `npm run test:ci:iam-call-access:sqlite`,
  `npm run test:ci:iam-call-access:static`,
  `npm run test:ci:iam-call-access:docker`, and `git diff --check` passed.
- IAM12-19 partial proof: the visible main checkout is still on the dirty parked
  `codex/bgf-06-background-diagnostics` branch, but its root Markdown files were
  moved into `documentation/archive/root-md-2026-05-10/`. It now shows only
  `README.md`, `BACKLOG.md`, and `SPRINT.md` in the root. Parked Background
  implementation files were not touched.
- Current known drift to close first: stale IAM proof notes, static IAM gate
  documentation drift, and inconsistent host/Docker SQLite wrappers.
- No push, no deploy, no DNS, no certbot.
