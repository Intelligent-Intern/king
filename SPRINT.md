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

## Sprint: IAM Cleanup, Proof Consolidation, And Browser Stability 04

Branch:
- `prod-kingrt-do-not-push-to-github`

Status:
- Active as of 2026-05-10 after Sprint 03 deploy.
- Local-only integration branch. Do not push to GitHub.
- Worker branches/worktrees must use short-lived non-`codex` names and merge
  back into the local no-push branch after proof.
- Background, Gossip, SFU, MediaSecurity, BTGF, and their tests remain parked
  unless the user explicitly reopens them.

User-facing problem:
- Sprint 03 closed the current IAM abuse/runtime proof batch and deployed it,
  but old IAM proof branches/worktrees still contain non-contained work, one
  dirty duplicate-cleanup branch has unresolved conflicts, and browser proof
  coverage needs consolidation without dragging media/background work back in.

Sprint goal:
- Extract only current, stronger IAM value from old proof branches.
- Resolve or park dirty IAM branch state without discarding user work.
- Keep the IAM proof gate deterministic and scoped to call-access behavior.
- Close exactly 20 tickets, then build/deploy locally without push/DNS/certbot.

Execution boundary:
- No pushes.
- No DNS or certbot automation.
- No Background/Gossip/SFU/MediaSecurity implementation work.
- Do not discard dirty worktrees unless their changes are proven merged or the
  user explicitly approves removal.
- Prefer focused contracts and narrow browser proofs over broad suite rewrites.

Proof anchors:
- `demo/video-chat/frontend-vue/package.json`
- `demo/video-chat/contracts/v1/ui-parity-acceptance.matrix.json`
- `demo/video-chat/frontend-vue/tests/contract/iam-call-access-ci-wire-contract.mjs`
- `demo/video-chat/frontend-vue/tests/e2e/call-access-join.spec.js`
- `demo/video-chat/frontend-vue/tests/e2e/call-access-seed-matrix.spec.js`
- `demo/video-chat/frontend-vue/tests/e2e/call-access-calendar-unregistered-invite.spec.js`
- `demo/video-chat/frontend-vue/tests/e2e/call-access-admin-join-boundaries.spec.js`
- `documentation/iam-sprint-03-dirty-worktree-classification.md`
- `documentation/iam-sprint-03-contained-head-cleanup-evidence.md`

Sprint Checkboxen:
- [x] IAM4-01 Reconcile dirty `codex/iam-call-access-e2e-foundation` with the
  deployed IAM gate and extract only unique current value, if any.
  - Merged worker branch `agent/iam-s4-01-foundation-reconcile`.
  - Added `documentation/iam-sprint-04-foundation-reconcile-evidence.md`.
  - Classified the dirty source worktree as fully superseded by the current
    deployed IAM gate and ported no source changes.
  - Proof: `node tests/contract/iam-call-access-e2e-foundation-contract.mjs`,
    `node tests/contract/iam-call-access-ci-wire-contract.mjs`,
    `bash -n demo/video-chat/scripts/smoke.sh`, and `git diff --check` passed.
  - Dirty source worktree `/home/jochen/projects/king.site/worktrees/king-domain-registry`
    was not reset, deleted, or modified.
- [x] IAM4-02 Resolve/classify dirty `codex/iam-duplicate-cleanup-reaudit-20260509`
  conflict state without losing user work or broad staged proof intent.
  - Merged worker branch `agent/iam-s4-02-duplicate-cleanup-classify`.
  - Added `documentation/iam-sprint-04-duplicate-cleanup-classification.md`
    and `iam-duplicate-cleanup-classification-contract.mjs`.
  - Classified the unresolved source worktree as `manual`; no conflict stages,
    staged proof edits, source files, or dirty worktree state were modified.
  - Proof: `node demo/video-chat/frontend-vue/tests/contract/iam-duplicate-cleanup-classification-contract.mjs`,
    `node demo/video-chat/frontend-vue/tests/contract/iam-call-access-ci-wire-contract.mjs`,
    and `git diff --check` passed.
- [x] IAM4-03 Inventory all non-contained `local/iam-e2e-*proof-3` worktrees and
  rank them by unique IAM proof value versus Sprint 03.
  - Merged worker branch `agent/iam-s4-03-proof3-inventory`.
  - Added `documentation/iam-sprint-04-proof3-inventory.md`.
  - Classified all 19 `local/iam-e2e-*proof-3` branches as non-contained and
    clean at scan time, ranked by unique proof value.
  - Highest unique follow-up: public-copy/not-found payload preservation; most
    other proof-3 branches are mined or evidence-only and should not be
    wholesale merged.
  - Proof: documentation-only evidence task; `git diff --check` and
    `git diff --cached --check` passed in the worker.
- [x] IAM4-04 Extract any stronger logout/login-switch and account-switch proof
  value from `local/iam-e2e-abuse-logout-login-switch-proof-3`.
  - Merged worker branch `agent/iam-s4-04-logout-switch-extract`.
  - Added `call-access-logout-switch-extract-contract.mjs`.
  - Extracted the unique same-browser logout/login-switch proof: frozen verified
    call-access context plus changed bearer token must fail closed with
    `409 call_access_conflict` / `session_context_changed` and must not rebind
    local session state or leak private invite/review data.
  - Proof: `node demo/video-chat/frontend-vue/tests/contract/call-access-logout-switch-extract-contract.mjs`,
    `call-access-logout-login-switch-contract.mjs`,
    `call-access-account-isolation-contract.mjs`,
    `call-access-duplicate-invite-replay-contract.mjs`, and `git diff --check`
    passed.
- [x] IAM4-05 Extract any stronger audit alias/event compatibility proof value
  from the audit `proof-3` branches.
  - Merged worker branch `agent/iam-s4-05-audit-proof-extract`.
  - Added `documentation/iam-sprint-04-audit-proof-extract-evidence.md`.
  - Confirmed current stable alias/redaction compatibility coverage is already
    present and green; broader lifecycle audit proof is unique but depends on
    missing current-prod backend helper/event surfaces and stays a follow-up
    implementation task instead of importing stale branch assumptions.
  - Proof: `node demo/video-chat/frontend-vue/tests/contract/call-access-audit-event-compatibility-contract.mjs`,
    `call-access-audit-redaction-contract.mjs`,
    `call-access-strong-mismatch-audit-redaction-contract.mjs`, and
    `git diff --check` passed.
- [x] IAM4-06 Extract deleted/ended/disabled terminal-state proof value from the
  deleted/disabled `proof-3` branches.
  - Merged worker branch `agent/iam-s4-06-deleted-disabled-extract`.
  - Added backend terminal join runtime proof
    `call-access-terminal-join-contract.php` / `.sh` and wired it into
    `iam-call-access-sqlite-runtime-proof.sh`.
  - Backend now rejects ended/disabled calls before owner, participant,
    system-admin, or free-for-all role grants, rejects inactive registered users
    before direct call role decisions, and keeps disabled personalized links as
    redacted `not_found`.
  - Proof: focused terminal SQLite proof, full
    `demo/video-chat/backend-king-php/tests/iam-call-access-sqlite-runtime-proof.sh`,
    frontend terminal contracts, PHP syntax checks, and `git diff --check`
    passed.
- [x] IAM4-07 Extract duplicate-abuse device/browser proof value from
  `local/iam-e2e-duplicate-abuse-device-browser-proof-3`.
  - Merged worker branch `agent/iam-s4-07-duplicate-abuse-extract`.
  - Added `documentation/iam-sprint-04-duplicate-abuse-device-browser-extract-evidence.md`.
  - Classified the stale source branch as fully superseded by current focused
    duplicate-device/browser, duplicate-abuse, invite-replay, verified-context,
    no-rebind, and redaction contracts; no source code was ported.
  - Proof: `call-access-duplicate-device-browser-contract.mjs`,
    `call-access-duplicate-abuse-contract.mjs`,
    `call-access-duplicate-invite-replay-contract.mjs`,
    `call-access-logout-login-switch-contract.mjs`,
    `iam-call-access-ci-wire-contract.mjs`, and `git diff --check` passed.
- [x] IAM4-08 Extract guest-list revocation proof value from
  `local/iam-e2e-guest-list-revocation-proof-3`.
  - Merged worker branch `agent/iam-s4-08-guest-list-revocation-extract`.
  - Added `documentation/iam-sprint-04-guest-list-revocation-extraction.md`
    and `iam-guest-list-revocation-extraction-contract.mjs`.
  - Classified the broad guest-list revocation source branch as
    `superseded/documentation-only`; current narrower proofs already cover
    removed/cancelled/declined guest-list access, stale personalized links,
    stale call-scoped sessions, lobby visibility, and Docker runtime wiring.
  - Proof: `iam-guest-list-revocation-extraction-contract.mjs`,
    `call-access-removed-members-contract.mjs`,
    `call-access-guest-list-membership-docker-proof-contract.mjs`,
    `iam-backend-docker-runtime-proof-wrapper.sh`, and `git diff --check`
    passed.
- [x] IAM4-09 Extract registered-invitee logged-in/logged-out/final proof value
  from the registered-invitee `proof-3` branches.
  - Merged worker branch `agent/iam-s4-09-registered-invitee-extract`.
  - Added `call-access-registered-invitee-extract-contract.mjs` and
    `documentation/iam-sprint-04-registered-invitee-extract-evidence.md`.
  - Extracted the current-prod coverage map for registered invitees: logged-in
    matching accounts, logged-out handoff, wrong-account/session-switch
    fail-closed behavior, call-scoped session binding, and realtime binding
    rejection for stale call/room/user contexts.
  - Proof: `call-access-registered-invitee-extract-contract.mjs`,
    `call-access-registered-logged-in-invitee-contract.mjs`,
    `call-access-registered-logged-out-handoff-contract.mjs`, and
    `git diff --check` passed.
- [x] IAM4-10 Extract owner-transfer lifecycle proof value from
  `local/iam-e2e-owner-transfer-lifecycle-proof-3`.
  - Merged worker branch `agent/iam-s4-10-owner-transfer-extract`.
  - Added `call-access-owner-transfer-temp-moderator-extract-contract.mjs`.
  - Extracted the temporary moderator/owner-transfer proof value: moderators may
    moderate their assigned call, but cannot transfer/demote ownership, cannot
    moderate other calls, lose rights immediately on revocation, and server-side
    lobby/role checks must ignore forged client role frames.
  - Proof: `call-access-owner-transfer-temp-moderator-extract-contract.mjs`,
    `owner-transfer-lifecycle-contract.mjs`,
    `call-access-owner-transfer-main-contract.mjs`, and `git diff --check`
    passed.
- [x] IAM4-11 Extract public-copy and seed-matrix proof value from their
  `proof-3` branches.
  - Merged worker branch `agent/iam-s4-11-public-copy-seed-matrix-extract`.
  - Updated public join resolution to preserve backend stable error payloads
    before localization instead of replacing every failed response with
    `call_access_validation_failed`.
  - Updated localization/privacy/terminal contracts and E2E expectation so
    missing/deleted personalized links render the safe no-data copy
    `This call link does not exist.` without leaking backend payload details.
  - Proof: `public-pages-localization-contract.mjs`,
    `call-access-link-privacy-contract.mjs`,
    `call-access-disabled-links-fail-closed-contract.mjs`,
    `call-access-forged-identifiers-contract.mjs`,
    `call-access-invite-invalidation-terminal-contract.mjs`,
    `call-access-direct-join-rights-contract.mjs`, and `git diff --check`
    passed.
  - Playwright target was skipped by worker because local `@playwright/test`
    was unavailable in the worktree.
- [x] IAM4-12 Extract remaining deleted-disabled and remaining-sprint-gap proof
  value from their `proof-3` branches.
  - Merged worker branch `agent/iam-s4-12-remaining-proof-extract`.
  - Added `documentation/iam-sprint-04-remaining-proof-extract-evidence.md`.
  - Classified the remaining deleted-disabled value as already extracted by
    IAM4-06, and the remaining sprint-gap guest-list value as superseded by the
    current stronger direct-join and SQLite runtime aggregate proofs.
  - Proof: terminal frontend contracts, focused terminal/guest-list SQLite
    runtime proof, and `git diff --check` passed.
- [x] IAM4-13 Extract review-abuse cross-browser and warning-modal policy proof
  value from their `proof-3` branches.
  - Merged worker branch `agent/iam-s4-13-review-abuse-extract`.
  - Added `documentation/iam-sprint-04-review-abuse-extraction.md` and
    `iam-review-abuse-extraction-contract.mjs`.
  - Classified duplicate-abuse session isolation as already covered by current
    stable proofs, and warning-modal/manual-review/account-confirmation as
    unique but `manual/deferred extraction` because its implementation files are
    source-only and broad.
  - Proof: `iam-review-abuse-extraction-contract.mjs`,
    duplicate-abuse/no-leak/strong-mismatch/audit-redaction contracts,
    `iam-call-access-ci-wire-contract.mjs`, and `git diff --check` passed.
- [x] IAM4-14 Extract system-admin deleted/ended proof value from
  `local/iam-e2e-system-admin-deleted-ended-proof-3`.
  - Merged worker branch `agent/iam-s4-14-system-admin-terminal-extract`.
  - Added `documentation/iam-sprint-04-system-admin-terminal-extract-evidence.md`
    and aligned the terminal-browser-flow contract with the current stronger
    personalized-link inactive-target predicate.
  - Confirmed the source branch's system-admin ended/deleted bypass concern is
    covered by IAM4-06 backend runtime proof and terminal browser-flow
    contracts; no stale source code was ported.
  - Proof: terminal-state/browser-flow/invite-invalidation/disabled-link
    frontend contracts, focused terminal SQLite runtime proof, and
    `git diff --check` passed.
- [x] IAM4-15 Convert accepted old-branch value into focused Sprint 04 contracts
  without broad package or suite-runner conflicts.
  - Merged worker branch `agent/iam-s4-15-focused-contracts-wire`.
  - Added `iam-sprint-04-focused-wire-contract.mjs`.
  - Wired accepted Sprint 04 focused proofs into `test:contract:iam-call-access`,
    `iam-call-access-ci-wire-contract.mjs`, and
    `ui-parity-acceptance.matrix.json`: logout-switch extract, registered
    invitee extract, terminal backend join proof, and the Sprint 04 wiring
    completeness proof.
  - Proof: focused wire contract, CI-wire contract, Sprint 03 inventory
    contract, terminal browser-flow contract, logout-switch extract,
    registered-invitee extract, full `npm run test:contract:iam-call-access`,
    and `git diff --check` passed.
- [x] IAM4-16 Run or repair the focused `npm run test:e2e:call-access` browser
  proof path without adding media/background/SFU/Gossip coverage.
  - Worker branch `agent/iam-s4-16-e2e-call-access-proof` produced no tracked
    source changes and no merge commit was needed.
  - `npm ci` was required because the worker worktree had no `node_modules`.
  - Proof: `npm run test:e2e:call-access` passed 12/12 focused IAM browser
    tests; `git diff --check` passed.
  - Generated artifacts were left only in the worker worktree:
    `playwright-report/iam-call-access/index.html` and
    `test-results/iam-call-access/.last-run.json`.
- [x] IAM4-17 Ensure IAM browser artifacts and failure output remain retained
  and redacted for call-access E2E diagnostics.
  - Merged worker branch `agent/iam-s4-17-browser-artifacts-redaction`.
  - Strengthened `call-access-ci-artifacts-contract.mjs` so focused IAM E2E
    specs do not write raw ad hoc artifacts or console diagnostics, while
    Playwright failure traces/screenshots remain retained under deterministic
    IAM artifact/report directories.
  - Pinned audit/redaction coverage for access/session ids, tokens, cookies,
    SDP, and ICE in the IAM artifact gate.
  - Proof: artifact contract, CI-wire contract, audit compatibility/redaction
    contracts, strong-mismatch audit-redaction contract, and `git diff --check`
    passed.
- [x] IAM4-18 Clean merged/superseded Sprint 04 worker branches/worktrees using
  contained-HEAD and clean-worktree rules only.
  - Merged worker branch `agent/iam-s4-18-cleanup-sprint-workers`.
  - Added `documentation/iam-sprint-04-worker-cleanup-evidence.md`.
  - No stale administrative worktree entries were found by
    `git worktree prune --dry-run --verbose`.
  - Cleanup deletion was deferred for protected active Sprint 04 lanes during
    the worker check; manager cleanup runs after IAM4-19 merge and before
    IAM4-20 deploy.
- [x] IAM4-19 Wire accepted Sprint 04 proofs into IAM package scripts,
  CI-wire contract, and release-gate metadata after integration.
  - Merged worker branch `agent/iam-s4-19-ci-wire-matrix-proof` with manual
    `package.json` conflict resolution preserving the union of IAM4-15 and
    IAM4-19 proof paths.
  - Added owner-transfer temp-moderator and guest-list revocation extraction
    proofs to `test:contract:iam-call-access`, `iam-call-access-ci-wire-contract.mjs`,
    and `ui-parity-acceptance.matrix.json`.
  - Proof: CI-wire contract, release-gate contract, direct newly wired proof
    contracts, full `npm run test:contract:iam-call-access`, and
    `git diff --check` passed.
- [ ] IAM4-20 Build, run Sprint 04 IAM proof set, deploy without
  push/DNS/certbot, and collect post-deploy diagnostics.

Loop policy:
- On `w`, keep up to six worker slots assigned where the remaining tickets can
  run independently, with worker branches not named `codex/*`.
- Merge completed worker branches into `prod-kingrt-do-not-push-to-github` only
  after their proof passes.
- If a worker finishes early, assign the next unchecked IAM ticket.
- When all 20 tickets are closed, move this sprint evidence to readiness history
  and open the next 20-ticket sprint if backlog remains.
