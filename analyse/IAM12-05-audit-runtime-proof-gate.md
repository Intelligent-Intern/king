# IAM12-05 Audit Runtime Proof Gate

Date: 2026-05-10

Scope:
- IAM/call-access audit runtime proof wiring.
- No production deploy.
- No push, DNS changes, certbot calls, or remote mutation.
- No Background, Gossip, SFU, MediaSecurity, BTGF, or VCAP implementation work.

## Finding

`audit-call-access-events-contract.sh` was already listed in the
Docker-capable SQLite aggregate, but the direct wrapper could still host-skip
when local PHP lacked `pdo_sqlite`.

`audit-call-access-membership-contract.php` was a real runtime proof for audit
redaction and membership-removal call-scoped access behavior, but it had no
shell wrapper and was not included in `iam-call-access-sqlite-runtime-proof.sh`.
Because the aggregate executes contract entries as shell commands, adding the
raw PHP file directly would have been wrong.

The foreign-link review audit wrapper had the same host-skip shape and could
avoid the Docker-capable aggregate when called directly.

## Change

- Added `audit-call-access-membership-contract.sh` and wired it through
  `sqlite-contract-runner.sh`.
- Converted `audit-call-access-events-contract.sh` and
  `call-access-foreign-link-review-audit-contract.sh` to the shared
  Docker-capable SQLite runner.
- Added `audit-call-access-membership-contract.sh` to
  `iam-call-access-sqlite-runtime-proof.sh` next to the audit-events proof.
- Isolated `audit-call-access-events-contract.php` account-update confirmation
  email writes to a temp outbox instead of the repo-local default outbox.
- Updated frontend static contracts and release-gate matrix metadata so audit
  runtime proofs are visible in the IAM contract inventory.
- Updated the guest-list membership Docker proof contract to expect the shared
  runner wrappers instead of old host-skip text.

## Verification

Focused direct wrappers:

```bash
demo/video-chat/backend-king-php/tests/audit-call-access-events-contract.sh
demo/video-chat/backend-king-php/tests/audit-call-access-membership-contract.sh
demo/video-chat/backend-king-php/tests/call-access-foreign-link-review-audit-contract.sh
```

Focused aggregate:

```bash
IAM_SQLITE_CONTRACTS="call-access-foreign-link-review-audit-contract.sh audit-call-access-events-contract.sh audit-call-access-membership-contract.sh" demo/video-chat/backend-king-php/tests/iam-call-access-sqlite-runtime-proof.sh
```

Focused static contracts:

```bash
cd demo/video-chat/frontend-vue
node tests/contract/iam-call-access-audit-events-contract.mjs
node tests/contract/call-access-audit-redaction-contract.mjs
node tests/contract/call-access-foreign-link-review-audit-contract.mjs
node tests/contract/call-access-guest-list-membership-docker-proof-contract.mjs
node tests/contract/iam-call-access-ci-wire-contract.mjs
node tests/contract/call-access-audit-event-compatibility-contract.mjs
```

Canonical IAM gates:

```bash
cd demo/video-chat/frontend-vue
npm run test:ci:iam-call-access:sqlite
npm run test:ci:iam-call-access:static
```

Syntax and hygiene:

```bash
bash -n demo/video-chat/backend-king-php/tests/audit-call-access-events-contract.sh demo/video-chat/backend-king-php/tests/audit-call-access-membership-contract.sh demo/video-chat/backend-king-php/tests/call-access-foreign-link-review-audit-contract.sh demo/video-chat/backend-king-php/tests/iam-call-access-sqlite-runtime-proof.sh demo/video-chat/backend-king-php/tests/sqlite-contract-runner.sh
php -l demo/video-chat/backend-king-php/tests/audit-call-access-events-contract.php
php -l demo/video-chat/backend-king-php/tests/audit-call-access-membership-contract.php
php -l demo/video-chat/backend-king-php/tests/call-access-foreign-link-review-audit-contract.php
git diff --check
```

All commands above passed.
