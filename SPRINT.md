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
- [x] IAM-02 Restore a clean deterministic IAM seed matrix covering system admin,
  tenant admins, owners, normal members, registered guests, temporary guests,
  deleted/ended/disabled calls, and cross-org calls.
  - Merged worker branch `agent/iam-s1-02-seed-matrix`.
  - Seed matrix now includes beta normal member denial plus ended, disabled, and
    deleted call cases; seed routes keep denied/terminal payloads free of private
    call objects and call identifiers.
  - Integrated with IAM-03/IAM-07 by allowing terminal direct-join denials to
    use terminal status reasons instead of masquerading as `calls_forbidden`.
  - Proof: `node tests/contract/iam-call-access-e2e-foundation-contract.mjs`,
    `node tests/contract/call-access-direct-join-rights-contract.mjs`,
    `node tests/contract/call-access-cross-org-contract.mjs`, and
    `npx playwright test tests/e2e/call-access-seed-matrix.spec.js --workers=1
    --reporter=list` passed.
- [x] IAM-03 Make `iam-call-access-e2e-foundation-contract.mjs` pass against the
  seed matrix without fixture drift.
  - Merged worker branch `agent/iam-s1-03-foundation-contract`.
  - Contract now derives Direct Join Permissions scenarios from
    `iam-call-access-seeding.matrix.json`, asserts exact spec/matrix parity, and
    derives denied direct-join checks from the matrix.
  - Proof: `node tests/contract/iam-call-access-e2e-foundation-contract.mjs` and
    `npm run test:contract:iam-call-access` passed; backend SQLite shell proofs
    skipped locally because `pdo_sqlite` is unavailable.
- [x] IAM-04 Prove direct join permissions for platform admin, tenant admin,
  call owner, guest-list participant, and denied normal member.
  - Merged worker branch `agent/iam-s1-04-direct-join-rights`.
  - Added `call-access-direct-join-rights-contract.mjs` proving direct-join
    authorization is limited to platform admin, tenant admin, call owner, or
    guest-list participant, with normal tenant members denied.
  - Proof: `node tests/contract/call-access-direct-join-rights-contract.mjs`,
    `node tests/contract/iam-call-access-e2e-foundation-contract.mjs`, and
    `node tests/contract/call-access-cross-org-contract.mjs` passed.
- [x] IAM-05 Prove external guest join links require display name, create a
  temporary guest identity, and wait in lobby until admitted.
  - Merged worker branch `agent/iam-s1-05-guest-link-lobby`.
  - Added an external open guest-link E2E case proving name-required behavior,
    `guest_name` session creation, temporary guest identity, lobby wait, owner
    admission transition, and no media/control secret exposure in payloads.
  - Proof: `npx playwright test tests/e2e/call-access-join.spec.js --grep
    "external guest join link" --workers=1 --reporter=list` and
    `npm run test:contract:iam-call-access` passed; backend SQLite shell proofs
    skipped locally because `pdo_sqlite` is unavailable.
- [x] IAM-06 Prove backend guest-list direct-join behavior in the PHP contract.
  - Merged worker branch `agent/iam-s1-06-guest-list-php`.
  - PHP contract now covers active internal guest-list access, declined entries,
    external participant rows not counting as guest-list access, and tenant-scoped
    lookup denial.
  - Proof: PHP lint passed for the contract and guest-list domain file. Host
    shell proof skipped because `pdo_sqlite` is unavailable; worker ran the same
    PHP contract successfully in a disposable PHP container with `pdo_sqlite`.
- [x] IAM-07 Prove cross-org denial and active-org switch behavior.
  - Merged worker branch `agent/iam-s1-07-cross-org`.
  - Added `call-access-cross-org-contract.mjs` for alpha-admin to beta-call
    denial, active-org switch least-privilege behavior, and no private call-data
    leak on denied resolve/fetch payloads.
  - Removed private `call_id` echo from denied seed-route call-fetch details.
  - Proof: `node tests/contract/call-access-cross-org-contract.mjs` and
    `node tests/contract/iam-call-access-e2e-foundation-contract.mjs` passed.
- [x] IAM-08 Prove deleted, ended, disabled, and terminal call states do not leak
  private call data and cannot be joined.
  - Merged worker branch `agent/iam-s1-08-terminal-states`.
  - Added `call-access-terminal-states-contract.mjs` proving ended/disabled
    calls deny direct join, deleted calls are hidden as not found, and terminal
    resolve/fetch payloads do not include private call objects or identifiers.
  - Proof: `node tests/contract/call-access-terminal-states-contract.mjs`
    passed with the existing IAM access contracts.
- [x] IAM-09 Prove membership removal and stale organization-role revalidation.
  - Merged worker branch `agent/iam-s1-09-membership-stale-role`.
  - Membership-removal and stale-role shell proofs now run PHP syntax validation
    before the `pdo_sqlite` gate, so non-SQLite hosts still catch contract syntax
    regressions instead of skipping all proof.
  - Proof: PHP lint passed for both contracts; shell wrappers executed and
    skipped only the SQLite-backed runtime phase because host PHP lacks
    `pdo_sqlite`.
- [x] IAM-10 Prove owner transfer, moderator, org-admin, and system-admin
  admission boundaries.
  - Merged worker branch `agent/iam-s1-10-admission-boundaries`.
  - Added `call-access-admission-boundaries-contract.mjs` covering system-admin,
    org-admin, owner/moderator, owner-transfer, stale role, forged role, and
    foreign-call moderation boundaries.
  - Proof: `node tests/contract/call-access-admission-boundaries-contract.mjs`
    passed with the existing IAM access contracts.
- [x] IAM-11 Prove lobby queue idempotence, pagination/search stability, and
  concurrent admit/deny behavior.
  - Merged worker branch `agent/iam-s1-11-lobby-concurrency`.
  - Added `call-access-lobby-concurrency-contract.mjs` and wired it into
    `npm run test:contract:iam-call-access`.
  - Proof covers lobby snapshot dedupe, admitted-over-stale-queue behavior,
    action-state cleanup, search/page clamping, and concurrent admit/deny
    convergence.
  - Proof: `npm run test:contract:iam-call-access` passed; backend SQLite shell
    proofs skipped locally because `pdo_sqlite` is unavailable.
- [x] IAM-12 Prove duplicate invite/session/device/browser abuse is rejected or
  reconciled deterministically.
  - Merged worker branch `agent/iam-s1-12-duplicate-abuse`.
  - Added `call-access-duplicate-abuse-contract.mjs` proving stale verified
    context conflicts, parallel browser/device isolation, duplicate session-id
    rejection, invite redemption caps, and redacted conflict output.
  - Proof: `node tests/contract/call-access-duplicate-abuse-contract.mjs` passed.
- [x] IAM-13 Prove logout/login switch and parallel-tab account isolation.
  - Merged worker branch `agent/iam-s1-13-account-isolation`.
  - Added `call-access-account-isolation-contract.mjs` proving logout clears
    local session state, login replaces account tokens, call-access issuance
    binds to the current token, and parallel tab contexts stay isolated.
  - Proof: `node tests/contract/call-access-account-isolation-contract.mjs`
    passed with the existing IAM access contracts.
- [x] IAM-14 Prove call-access audit event compatibility and redaction.
  - Merged worker branch `agent/iam-s1-14-audit-redaction`.
  - Added `call-access-audit-redaction-contract.mjs` proving audit payloads use
    shared sanitization, raw access/session IDs are fingerprinted, denied/auth
    mismatch routes do not hand-roll unsafe audit payloads, and backend privacy
    proofs cover sanitizer redaction.
  - Proof: `node tests/contract/call-access-audit-redaction-contract.mjs` passed;
    referenced backend SQLite runtime proofs remain gated by local `pdo_sqlite`.
- [ ] IAM-15 Prove Call App/whiteboard access revocation follows IAM call
  admission and removal decisions.
- [x] IAM-16 Prove frontend route guards and verified-context UI for call-access
  sessions.
  - Merged worker branch `agent/iam-s1-16-route-guards-ui`.
  - Added `call-access-route-guard-ui-contract.mjs` proving public join route,
    authenticated workspace guard, post-login join-modal routing, verified
    context session issuance, admission wait UI, safe logout/switch failures,
    and invite-entry workspace transition.
  - Proof: `node tests/contract/call-access-route-guard-ui-contract.mjs` and
    `node tests/contract/call-access-verified-context-ui-contract.mjs` passed.
- [x] IAM-17 Prove realtime websocket room scope and reconnect/backfill under IAM
  session changes.
  - Merged worker branch `agent/iam-s1-17-realtime-scope`.
  - Added `call-access-realtime-scope-contract.mjs` proving websocket room/call
    query binding, current-session reconnect, fail-closed missing session,
    welcome/snapshot backfill, backend binding mismatch handling, and retryable
    reconnect backfill failures.
  - Proof: `node tests/contract/call-access-realtime-scope-contract.mjs` passed.
    Backend reconnect shell proof skipped the SQLite runtime phase locally after
    non-SQLite assertions because `pdo_sqlite` is unavailable.
- [ ] IAM-18 Wire the IAM contract/E2E subset into stable package scripts and CI
  release-gate metadata.
- [x] IAM-19 Run backend/runtime proof in the strongest available local test
  environment; document any `pdo_sqlite` limitation instead of weakening tests.
  - Merged worker branch `agent/iam-s1-19-runtime-proof`.
  - Added `iam-call-access-sqlite-runtime-proof.sh`, which runs the stable IAM
    SQLite backend contract set directly when host PHP has `pdo_sqlite`, or via
    a disposable PHP CLI container when the host extension is missing.
  - Proof: wrapper passed locally through Docker `php:8.4-cli-trixie` with
    `pdo_sqlite`, covering admin prevention, cross-org, membership removal,
    session route guard, stale org role, strong mismatch privacy, and guest-list
    direct join.
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
