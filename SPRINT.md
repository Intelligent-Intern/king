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
- [x] IAM5-09 Extract owner absence, owner timeout, and owner-leave proof value
  from the owner-absence/timeout branches.
  - Merged worker branch `agent/iam-s5-09-owner-absence`.
  - Added `documentation/iam-sprint-05-owner-absence-extraction.md` and
    `call-access-owner-absence-extract-contract.mjs`.
  - Extracted owner absence, timeout, and owner-leave evidence as server-side
    presence tracking, 15 minute absence, final five minute countdown, terminal
    cleanup expectations, and explicit owner-end distinction. Owner-timeout
    runtime wiring remains backend follow-up evidence, not active Sprint 05
    scope.
  - Proof: owner-absence extract, terminal states, invite invalidation terminal,
    terminal browser flows, disabled-links fail-closed, owner-transfer lifecycle,
    owner-transfer main/temp-moderator, permission-change, audit
    compatibility/redaction contracts, owner-moderation backend wrapper
    (`pdo_sqlite` persistence skipped locally), and `git diff --check` passed.
- [x] IAM5-10 Extract owner-transfer main journey, rejoin, and permission-audit
  proof value from the remaining owner-transfer branches.
  - Merged worker branch `agent/iam-s5-10-owner-transfer`.
  - Added `documentation/iam-sprint-05-owner-transfer-extraction.md` and
    `call-access-owner-transfer-remaining-extract-contract.mjs`.
  - Extracted owner-transfer main journey, rejoin, and permission refresh proof
    against maintained owner-transfer contracts. Preserved owner-transfer audit
    mutation writes as backend follow-up and rejected broader org-admin
    owner-transfer authority because current policy keeps owner-management
    stricter than moderation.
  - Proof: owner-transfer remaining extract, owner-transfer main, lifecycle,
    temp-moderator, permission-change, audit compatibility contracts,
    owner-moderation/org-admin backend wrappers, and `git diff --check` passed.
- [x] IAM5-11 Extract guest-list management, owner management, and revocation
  proof value from the remaining guest-list branches.
  - Merged worker branch `agent/iam-s5-11-guest-list`.
  - Added `documentation/iam-sprint-05-guest-list-extraction.md` and
    `call-access-guest-list-remaining-extract-contract.mjs`.
  - Extracted maintained guest-list direct-join, revocation, membership
    removal, and owner-management proof while keeping source-only add/remove,
    merge/restore, audit helper, and granular cleanup behavior as backend
    follow-up evidence.
  - Proof: guest-list remaining extract, membership Docker proof contract,
    direct-join rights, removed-members, guest-list revocation extraction,
    guest-list membership Docker backend proof, and `git diff --check` passed.
    The adjacent cleanup audit assertion is documented as a follow-up, not
    merged into this ticket.
- [x] IAM5-12 Extract temporary guest, direct-join, temporary moderator, and
  kicked temporary-user proof value from the temp-access branches.
  - Merged worker branch `agent/iam-s5-12-temp-access`.
  - Added `documentation/iam-sprint-05-temp-access-extraction.md` and
    `call-access-temp-access-remaining-extract-contract.mjs`.
  - Extracted temporary guest link boundaries, personalized temp-link reuse,
    direct-join rights, kicked temporary-user denial, and temporary moderator
    owner-transfer proof while keeping source-only backend product changes as
    follow-up evidence outside this doc/contract extraction ticket.
  - Proof: temp-access remaining extract, temp call-link boundaries,
    personalized temp reuse, direct-join rights, kicked rejoin denial,
    owner-transfer temp-moderator, guest-list membership Docker backend proof,
    anonymous temp-rights Docker backend proof, and `git diff --check` passed.
- [x] IAM5-13 Extract email confirmation, account reconciliation, and safe
  dispatch/audit proof value from the account-confirmation branches.
  - Merged worker branch `agent/iam-s5-13-email-confirmation`.
  - Added `documentation/iam-sprint-05-email-confirmation-extraction.md` and
    `call-access-email-confirmation-extract-contract.mjs`.
  - Extracted account-confirmation invariants, account reconciliation, token
    expiry/supersession, safe dispatch, and audit-redaction requirements as
    deferred implementation evidence. The current base lacks the backend
    account-confirmation runtime, audit helper, PHP contract, frontend
    confirmation view, and safe dispatch/audit contract, so this ticket does
    not falsely claim runtime support.
  - Proof: email-confirmation extract, account isolation, logout/login switch,
    strong-mismatch privacy, link privacy, audit compatibility, audit
    redaction, strong-mismatch audit redaction contracts, and
    `git diff --check` passed.
- [x] IAM5-14 Extract calendar invite, unregistered invitee, and reschedule
  stale-link proof value from the calendar/invite branches.
  - Merged worker branch `agent/iam-s5-14-calendar-invite`.
  - Added `documentation/iam-sprint-05-calendar-invite-extraction.md` and
    `call-access-calendar-invite-extract-contract.mjs`.
  - Extracted calendar invite, registered invitee handoff, unregistered
    invitee/account-claim, terminal invalidation, stale-link privacy, and
    reschedule evidence while keeping the full backend reschedule lifecycle as
    source-only follow-up evidence outside this extraction ticket.
  - Proof: calendar invite extract, calendar invite join, registered logged-in
    invitee, registered logged-out handoff, registered invitee extract, invite
    invalidation terminal, terminal browser flows, terminal states, link
    privacy contracts, and `git diff --check` passed.
- [x] IAM5-15 Extract Call App IAM boundary proof value for entitlement
  revocation, launch-token reconnect validation, and whiteboard org install
  without touching Call App UI feature work.
  - Merged worker branch `agent/iam-s5-15-call-app-boundary`.
  - Added `documentation/iam-sprint-05-call-app-boundary-extraction.md` and
    `iam-s5-15-call-app-boundary-extraction-contract.mjs`.
  - Extracted Call App participant-grant revocation, launch-token reconnect
    validation, iframe no-primary-token boundary, and Whiteboard organization
    install proof while preserving stronger entitlement/session-staleness
    revalidation and production org-install command as focused follow-up
    evidence.
  - Repaired stale Call App diagnostics contracts to use current planning
    sources and to prove actual diagnostics redaction for tokens/secrets and
    raw media/frame payload fields.
  - Proof: IAM5-15 extraction, Call App revocation, permission revocation,
    Marketplace-to-call journey, Whiteboard org-install browser proof,
    iframe-launch, Call Diagnostics, and `git diff --check` passed.
- [x] IAM5-16 Extract system-admin, organization-role bootstrap, admin-join,
  and lane proof value from the remaining IAM lane branches.
  - Merged worker branch `agent/iam-s5-16-system-admin-lanes`.
  - Added `documentation/iam-sprint-05-system-admin-lanes-extraction.md` and
    `iam5-16-system-admin-lanes-extract-contract.mjs`.
  - Extracted system-admin, organization-role bootstrap, admin-join,
    owner-management, terminal-state, and lane evidence without broad lane
    merges.
  - Proof: IAM5-16 extraction, admission boundaries, direct-join rights,
    terminal states, terminal browser flows, owner-transfer main,
    admin-owner-rights contracts, owner-moderation backend wrapper
    (`pdo_sqlite` persistence skipped locally), SQLite-backed system-admin,
    org-admin, and call-creation wrappers skipped on this host due missing
    `pdo_sqlite`, and `git diff --check` passed.
- [x] IAM5-17 Consolidate seed data hygiene, asset cache busting, local run docs,
  and live-proof env audit value into the current IAM gate only if still useful.
  - Merged worker branch `agent/iam-s5-17-seed-cache-run-docs`.
  - Added `documentation/iam-sprint-05-seed-cache-run-docs-extraction.md` and
    `iam-s5-17-seed-cache-run-docs-contract.mjs`.
  - Consolidated useful seed data hygiene, asset cache busting, local run docs,
    and live-proof environment audit evidence into the current IAM gate while
    leaving superseded broad runbook churn out of the sprint.
  - Proof: IAM5-17 seed/cache/run-docs contract, IAM call-access E2E foundation,
    direct-join rights, asset cache busting, prod-debug observability
    contracts, and `git diff --check` passed.
- [x] IAM5-18 Run the focused IAM browser proof path or repair its local
  invocation without adding media/background/SFU/Gossip coverage.
  - Merged worker branch `agent/iam-s5-18-browser-proof-path`.
  - Added `documentation/iam-sprint-05-browser-proof-path.md`.
  - Confirmed the maintained focused IAM browser proof path runs without
    repair and without adding media, background, SFU, Gossip, product UI, or
    test-file changes.
  - Proof: `npm run test:e2e:call-access` from
    `demo/video-chat/frontend-vue` passed with 12 Playwright tests, and
    `git diff --check` passed.
- [x] IAM5-19 Clean merged/superseded Sprint 05 and old IAM worker
  branches/worktrees using contained-HEAD and clean-worktree rules only.
  - Removed clean, contained Sprint 05 worker worktrees and branches for
    IAM5-01 through IAM5-18 after their evidence was merged into
    `prod-kingrt-do-not-push-to-github`.
  - Used only contained-HEAD and clean-worktree checks before removal; dirty
    parked BGF/IAM worktrees were retained and not discarded.
  - Ran `git worktree prune`.
  - Proof: `git branch --list 'agent/iam-s5-*'` and
    `git worktree list --porcelain | rg 'iam-s5-'` return no remaining Sprint
    05 worker branches/worktrees, and `git status --short --branch` is clean.
- [x] IAM5-20 Build, run the Sprint 05 IAM proof set, deploy without
  push/DNS/certbot, and collect post-deploy diagnostics.
  - Ran `npm run test:contract:iam-call-access`; all Node contracts passed,
    Docker PHP `pdo_sqlite` fallbacks passed, and host-only SQLite checks
    skipped where local PHP lacks `pdo_sqlite`.
  - Ran `npm run test:contract:call-apps` because IAM5-15 touched Call App
    diagnostics contracts; frontend/static contracts passed and backend
    SQLite-only Call App checks skipped on this host due missing `pdo_sqlite`.
  - Ran `npm run build` and `npm run test:contract:build-size`.
  - Deployed from `prod-kingrt-do-not-push-to-github` with no push, no DNS
    writes, and no Certbot run by setting `VIDEOCHAT_DEPLOY_SKIP_CERTBOT=1`,
    `VIDEOCHAT_DEPLOY_REFRESH_DNS_ON_PREPARE=0`, and
    `VIDEOCHAT_DEPLOY_HCLOUD_DNS=0`.
  - Deploy result: production asset version `20260510041153`, backend, WS, and
    Edge containers rebuilt/restarted; TURN stayed running; SFU stayed disabled.
  - Diagnostics: `prod-debug.sh` passed read-only app/API/CDN/call-app/CSP and
    container checks. Authenticated `/api/calls/{id}/call-apps/available`
    returned HTTP 200 for current call
    `fdb60134-64b0-4a56-99ee-4126822e6122`; admin Call Diagnostics telemetry
    returned HTTP 200 with CPU, load, memory, and container fields. The old
    browser-log call id now returns HTTP 404 `calls_not_found`, not HTTP 500.
  - Residual diagnostics: unauthenticated marketplace/WS probes return expected
    auth failures; `https://sfu.kingrt.com/sfu` returns 404 because SFU remains
    disabled/manual; TURN logs contain external TCP reset noise from peers.

Loop policy:
- On `w`, keep up to six worker slots assigned where the remaining tickets can
  run independently, with worker branches not named `codex/*`.
- Merge completed worker branches into `prod-kingrt-do-not-push-to-github` only
  after their proof passes.
- If a worker finishes early, assign the next unchecked IAM ticket.
- When all 20 tickets are closed, move this sprint evidence to readiness history
  and open the next 20-ticket sprint if backlog remains.
