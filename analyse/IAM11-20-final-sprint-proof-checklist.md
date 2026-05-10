# IAM11-20 Final Sprint Proof Checklist

Date: 2026-05-10

Scope:
- Analysis document only.
- No `SPRINT.md` edits.
- No Background, Gossip, SFU, MediaSecurity, or BTGF changes.
- Do not mark IAM complete unless all active IAM checks are green.

## Current State

IAM11-20 is not complete.

The active sprint checklist still has unchecked IAM and call-access coverage in
`SPRINT.md`, and the IAM11-16 analysis evidence contains active blockers. This
note is therefore a final proof checklist and gate proposal only.

Checklist inventory from the current `SPRINT.md`:

```text
checked items: 138
unchecked items: 890
```

High-density unchecked groups still include call creation and ownership, join
permissions, personalized and anonymous link flows, lobby/admission, rejoin and
kick, security manipulation, guest list, system-admin/org-admin edges,
membership removal, invite invalidation, guest lifecycle, reschedule, deletion,
explicit/implicit end, King containers, audit, and main E2E journeys.

## IAM11-16 Blockers

IAM11-13 parallel account tabs:
- No open blocker recorded in `analyse/IAM11-13-parallel-account-tabs-proof.md`.
- The proof is frontend E2E/contract scoped and establishes browser-context
  identity isolation for the same personalized link.

IAM11-14 lobby concurrency:
- Focused Playwright lobby concurrency passed.
- Backend lobby concurrency race proof did not execute locally because the host
  PHP runtime lacks `pdo_sqlite`; the wrapper returned its built-in skip.
- Blocker for final closure: rerun the backend race contract in a SQLite-enabled
  backend runtime and require a pass, not a skip.

IAM11-15 owner-transfer lifecycle:
- The analysis note records backend lifecycle proof for owner transfer,
  exactly-one-owner preservation, old-owner authority loss, new-owner authority,
  moderation after transfer, and cancelled/ended/deleted immutability.
- No open blocker is recorded for the focused lifecycle contract itself.
- Integration dependency remains on IAM11-16 org-admin/system-admin policy
  edges before the broader owner/admin sprint claim can close.

IAM11-16 admin edge policy:
- This is the main open blocker.
- Docker PHP proof passed `call-owner-transfer-contract.php`.
- Docker PHP proof passed `call-access-cross-org-contract.php`.
- Docker PHP proof failed `system-admin-call-rights-contract.php` at
  `system admin should manage foreign-tenant call participants`.
- Docker PHP proof failed `org-admin-call-rights-contract.php` at
  `own realtime context call id mismatch`.
- The org-admin owner-transfer policy is not exposed by current backend
  authority, so the org-admin owner-transfer claim must stay open.
- Tenantless-call direct join and review-flag system-admin edge coverage remain
  stronger-contract requirements, but the required backend review/domain routes
  are not present in this workspace.

## Integration Risks

- `SPRINT.md` is already dirty in this workspace; it must not be used as proof
  of completion until the final gate passes and the checklist is updated by the
  owning lane.
- The current workspace also contains dirty IAM runtime/test changes owned by
  other lanes. Final proof must run against the intended integration branch, not
  an accidental mix of local changes.
- Host PHP lacks `pdo_sqlite`, so host-only backend proof can falsely look
  incomplete through skips. The final backend gate must run in a SQLite-enabled
  runtime, such as the Docker PHP runtime used by IAM11-16.
- Admin edge failures can invalidate higher-level E2E claims for system-admin
  join, org-admin join, org-admin owner-transfer, and realtime moderation
  context even if narrower call-access checks pass.
- Branch hygiene remains a cleanup risk: IAM11-18 found dirty IAM worktrees and
  non-ancestor IAM branches that require manual owner review before deletion.
- The final deploy gate must remain local by default. Online smoke is optional
  and explicitly separated; it must not be treated as a deploy, push, DNS, or
  certificate operation.

## Exact Final Gate

Run this gate only on the chosen final IAM integration branch/worktree, after
all intended IAM11 lanes are merged and after unrelated dirty changes are out of
scope.

Backend syntax and deploy-script dry checks:

```bash
bash -n demo/video-chat/scripts/deploy.sh
bash -n demo/video-chat/scripts/lib/deploy-hetzner.sh
bash -n demo/video-chat/scripts/lib/deploy-remote-status.sh
bash -n demo/video-chat/scripts/deploy-smoke.sh
bash -n demo/video-chat/scripts/smoke.sh
bash -n demo/video-chat/scripts/local-deploy-gate.sh
demo/video-chat/scripts/check-deploy-idempotency.sh
```

Backend IAM contracts, in a SQLite-enabled PHP runtime:

```bash
docker run --rm -v "$PWD":/workspace -w /workspace php:8.4-cli-trixie php demo/video-chat/backend-king-php/tests/call-access-session-contract.php
docker run --rm -v "$PWD":/workspace -w /workspace php:8.4-cli-trixie php demo/video-chat/backend-king-php/tests/call-access-privacy-contract.php
docker run --rm -v "$PWD":/workspace -w /workspace php:8.4-cli-trixie php demo/video-chat/backend-king-php/tests/call-access-strong-mismatch-privacy-contract.php
docker run --rm -v "$PWD":/workspace -w /workspace php:8.4-cli-trixie php demo/video-chat/backend-king-php/tests/call-access-membership-removal-contract.php
docker run --rm -v "$PWD":/workspace -w /workspace php:8.4-cli-trixie php demo/video-chat/backend-king-php/tests/call-access-stale-organization-role-contract.php
docker run --rm -v "$PWD":/workspace -w /workspace php:8.4-cli-trixie php demo/video-chat/backend-king-php/tests/call-access-session-fixation-contract.php
docker run --rm -v "$PWD":/workspace -w /workspace php:8.4-cli-trixie php demo/video-chat/backend-king-php/tests/call-access-session-route-guard-contract.php
docker run --rm -v "$PWD":/workspace -w /workspace php:8.4-cli-trixie php demo/video-chat/backend-king-php/tests/call-owner-transfer-contract.php
docker run --rm -v "$PWD":/workspace -w /workspace php:8.4-cli-trixie php demo/video-chat/backend-king-php/tests/call-owner-transfer-lifecycle-contract.php
docker run --rm -v "$PWD":/workspace -w /workspace php:8.4-cli-trixie php demo/video-chat/backend-king-php/tests/realtime-lobby-concurrency-contract.php
docker run --rm -v "$PWD":/workspace -w /workspace php:8.4-cli-trixie php demo/video-chat/backend-king-php/tests/system-admin-call-rights-contract.php
docker run --rm -v "$PWD":/workspace -w /workspace php:8.4-cli-trixie php demo/video-chat/backend-king-php/tests/org-admin-call-rights-contract.php
docker run --rm -v "$PWD":/workspace -w /workspace php:8.4-cli-trixie php demo/video-chat/backend-king-php/tests/call-access-cross-org-contract.php
docker run --rm -v "$PWD":/workspace -w /workspace php:8.4-cli-trixie php demo/video-chat/backend-king-php/tests/iam11-17-call-access-edge-proof-contract.php
```

Frontend IAM and release proof:

```bash
cd demo/video-chat/frontend-vue
npm run test:contract:iam-call-access
npm run test:e2e:call-access
npm run test:e2e:lobby-concurrency
npm run test:e2e:release-gate
npm run build
```

Local aggregate gate:

```bash
demo/video-chat/scripts/local-deploy-gate.sh
```

Optional online smoke, separated from local proof and only after the local gate
is green:

```bash
demo/video-chat/scripts/local-deploy-gate.sh --print-online-smoke
VIDEOCHAT_LOCAL_DEPLOY_GATE_ALLOW_ONLINE_SMOKE=1 \
  demo/video-chat/scripts/local-deploy-gate.sh --online-smoke
```

## Completion Rule

IAM11-20 can only be marked complete when:

- every command in the local final gate exits 0;
- no backend IAM contract is skipped because of missing `pdo_sqlite`;
- `system-admin-call-rights-contract.php` and
  `org-admin-call-rights-contract.php` pass in the final integration runtime;
- `npm run test:contract:iam-call-access`,
  `npm run test:e2e:call-access`, `npm run test:e2e:lobby-concurrency`,
  `npm run test:e2e:release-gate`, and `npm run build` pass from the final
  frontend workspace;
- branch hygiene has no dirty or non-ancestor IAM worktree that is being treated
  as merged proof;
- `SPRINT.md` checklist changes are made only after the green proof is
  available and by the owning lane.
