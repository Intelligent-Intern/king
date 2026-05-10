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
- [x] IAM5-01 Build a fresh remaining IAM branch/worktree inventory and rank
  branches by unique proof value, dirtiness, containment, and cleanup risk.
  - Merged worker branch `agent/iam-s5-01-inventory`.
  - Added `documentation/iam-sprint-05-remaining-inventory.md`.
  - Inventoried 192 matching local IAM/call-access branches and 155 matching
    worktrees, classified dirty/conflicted preservation blockers, ranked
    IAM5-02..IAM5-18 extraction families, and recorded cleanup rules for clean
    contained branches only.
  - Proof: `git diff --check HEAD~1..HEAD` passed in the worker and
    `git diff --check HEAD^..HEAD` passed after merge.
- [x] IAM5-02 Classify `iam-e2e-integration` as merge candidate, superseded
  evidence, or cleanup anchor against `prod-kingrt-do-not-push-to-github`.
  - Merged worker branch `agent/iam-s5-02-integration-classify`.
  - Added `documentation/iam-sprint-05-integration-classification.md` and
    `iam-sprint-05-integration-classification-contract.mjs`.
  - Classified `iam-e2e-integration` as a cleanup anchor, not a wholesale merge
    candidate; it remains useful source evidence for focused IAM5 lanes but is
    not contained by the production integration branch.
  - Proof: `node demo/video-chat/frontend-vue/tests/contract/iam-sprint-05-integration-classification-contract.mjs`
    and `git diff --check` passed.
- [x] IAM5-03 Reconcile the `codex/iam-duplicate-cleanup*` dirty/conflicted
  family without losing user work; extract only current package-suite value.
  - Merged worker branch `agent/iam-s5-03-duplicate-family`.
  - Added `documentation/iam-sprint-05-duplicate-cleanup-family.md` and
    `iam-duplicate-cleanup-family-contract.mjs`.
  - Preserved the dirty/conflicted source worktree untouched, documented the
    unresolved `package.json` conflict and staged suite files, and extracted
    only the separable package-suite redirection value as evidence for a future
    focused runner lane.
  - Proof: `node demo/video-chat/frontend-vue/tests/contract/iam-duplicate-cleanup-family-contract.mjs`
    and `git diff --check` passed.
- [x] IAM5-04 Extract authorized rejoin proof value from
  `local/iam-e2e-authorized-rejoin-main` and the 20260509 authorized-rejoin
  branches.
  - Merged worker branch `agent/iam-s5-04-authorized-rejoin`.
  - Added `documentation/iam-sprint-05-authorized-rejoin-extraction.md` and
    `call-access-authorized-rejoin-extract-contract.mjs`.
  - Extracted the durable authorized-rejoin rule: currently authorized
    participants/admin paths may rejoin after normal leave, while stale, kicked,
    removed, and role-invalidated paths remain fail-closed.
  - Proof: authorized-rejoin extract, direct-join, kicked-rejoin,
    removed-members, stale-role, owner-transfer temp-moderator, realtime-scope
    contracts, and `git diff --check` passed.
- [x] IAM5-05 Extract lobby state cleanup proof value from the lobby cleanup
  branches, keeping live state websocket-driven and avoiding manual refresh UI.
  - Merged worker branch `agent/iam-s5-05-lobby-cleanup`.
  - Added `documentation/iam-sprint-05-lobby-state-cleanup-extraction.md` and
    `call-access-lobby-state-cleanup-extract-contract.mjs`.
  - Extracted lobby cleanup proof as websocket snapshot/delta and admission
    lifecycle evidence, explicitly rejecting manual refresh UI for realtime
    state.
  - Proof: `node demo/video-chat/frontend-vue/tests/contract/call-access-lobby-state-cleanup-extract-contract.mjs`,
    existing lobby/call-access contracts in the worker, and `git diff --check`
    passed. Host `pdo_sqlite` lobby DB wrappers skipped in the worker as an
    environment limitation.
- [x] IAM5-06 Extract lobby admission timeout/concurrency/audit proof value
  from the lobby timeout, concurrency, admission, and audit branches.
  - Merged worker branch `agent/iam-s5-06-lobby-admission`.
  - Added `documentation/iam-sprint-05-lobby-admission-extraction.md` and
    `call-access-lobby-admission-extract-contract.mjs`.
  - Extracted current concurrency and admission/rejection boundary proof against
    existing static/backend contracts. Timeout consistency and lobby-specific
    audit event implementation value remain documented backend follow-up work,
    because porting those runtime/audit files was outside this ticket's write
    scope.
  - Proof: lobby-admission extract, lobby-concurrency,
    admission-boundaries, audit compatibility/redaction contracts,
    `realtime-lobby-contract.sh`, and `git diff --check` passed.
- [x] IAM5-07 Extract duplicate review/abuse proof value from the duplicate
  review branches, including email/review safety only where current contracts
  support it.
  - Merged worker branch `agent/iam-s5-07-duplicate-review`.
  - Added `documentation/iam-sprint-05-duplicate-review-extraction.md` and
    `call-access-duplicate-review-extract-contract.mjs`.
  - Extracted current supported duplicate-abuse, denied-state privacy, and
    audit-redaction proof. Manual-review, host-verification,
    light-mismatch, and account-update email-confirmation behavior remains
    deferred implementation evidence because the required runtime/UI files are
    absent from the current base.
  - Proof: duplicate-review extract, duplicate device/browser, duplicate abuse,
    duplicate invite replay, mismatch no-leak, strong mismatch privacy/audit,
    link privacy, audit compatibility/redaction, review-abuse extraction
    contracts, and `git diff --check` passed.
- [x] IAM5-08 Extract cross-organization remaining proof value from the
  cross-org proof branches without weakening tenant isolation.
  - Merged worker branch `agent/iam-s5-08-cross-org`.
  - Added `documentation/iam-sprint-05-cross-org-extraction.md` and
    `call-access-cross-org-extract-contract.mjs`.
  - Extracted cross-organization proof as target-tenant authorization,
    active-org least privilege, stale-role revalidation, no-leak denied
    payloads, and call-scoped invite boundaries while preserving unported
    positive foreign-link journeys as follow-up evidence.
  - Proof: cross-org extract, cross-org, stale-role org switch, direct-join,
    link privacy, mismatch no-leak, strong mismatch privacy, removed-members
    contracts, backend cross-org/stale-role Docker fallbacks, and
    `git diff --check` passed.
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
