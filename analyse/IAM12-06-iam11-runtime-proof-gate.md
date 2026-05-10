# IAM12-06 IAM11 Runtime Proof Gate

Date: 2026-05-10

Scope:
- IAM11 backend/runtime proof coverage in the Docker-capable IAM gates.
- No production deploy.
- No push, DNS changes, certbot calls, or remote mutation.
- No Background, Gossip, SFU, MediaSecurity, BTGF, or VCAP implementation work.

## Finding

The IAM11 closure notes referenced backend runtime proofs that were either only
run as one-off Docker commands or named in local deploy proof commands, but not
visible in the default `iam-call-access-sqlite-runtime-proof.sh` aggregate.

Missing or incomplete coverage found in this pass:

- IAM11-16 authority proofs:
  `system-admin-call-rights-contract.php`,
  `org-admin-call-rights-contract.php`, and
  `call-owner-transfer-contract.php`.
- IAM11-19 local deploy backend bundle:
  `call-access-privacy-contract.sh` and
  `call-access-session-fixation-contract.sh`.
- Current authorized-rejoin/decision runtime proof:
  `call-access-decision-contract.sh`.
- Edge/error matrix backend proof:
  `call-access-edge-error-matrix-contract.sh`.

The backend Docker discovery gate remains limited to `*docker-proof.sh` files.
No new Docker-discovery wrapper was needed because these are normal SQLite PHP
runtime contracts and the SQLite aggregate already provides Docker fallback.

## Change

- Added `system-admin-call-rights-contract.sh`.
- Converted these direct wrappers to the shared Docker-capable SQLite runner:
  - `call-owner-transfer-contract.sh`
  - `org-admin-call-rights-contract.sh`
  - `call-access-decision-contract.sh`
  - `call-access-privacy-contract.sh`
  - `call-access-session-fixation-contract.sh`
- Added the missing IAM11/current runtime proofs to
  `iam-call-access-sqlite-runtime-proof.sh`:
  - `system-admin-call-rights-contract.sh`
  - `org-admin-call-rights-contract.sh`
  - `call-owner-transfer-contract.sh`
  - `call-access-decision-contract.sh`
  - `call-access-edge-error-matrix-contract.sh`
  - `call-access-session-fixation-contract.sh`
  - `call-access-privacy-contract.sh`
- Updated static contracts and release-gate metadata so the package and matrix
  inventory expose the same runtime proof anchors.

## Verification

Focused direct wrappers:

```bash
demo/video-chat/backend-king-php/tests/system-admin-call-rights-contract.sh
demo/video-chat/backend-king-php/tests/org-admin-call-rights-contract.sh
demo/video-chat/backend-king-php/tests/call-owner-transfer-contract.sh
demo/video-chat/backend-king-php/tests/call-access-decision-contract.sh
demo/video-chat/backend-king-php/tests/call-access-privacy-contract.sh
demo/video-chat/backend-king-php/tests/call-access-session-fixation-contract.sh
```

Focused aggregate:

```bash
IAM_SQLITE_CONTRACTS="call-access-cross-org-contract.sh system-admin-call-rights-contract.sh org-admin-call-rights-contract.sh call-owner-transfer-contract.sh call-access-decision-contract.sh call-access-edge-error-matrix-contract.sh call-access-privacy-contract.sh call-access-session-fixation-contract.sh call-access-session-contract.sh call-access-strong-mismatch-privacy-contract.sh call-access-membership-removal-contract.sh call-access-stale-organization-role-contract.sh call-access-session-route-guard-contract.sh call-owner-transfer-lifecycle-contract.sh" demo/video-chat/backend-king-php/tests/iam-call-access-sqlite-runtime-proof.sh
```

Focused static contracts:

```bash
cd demo/video-chat/frontend-vue
node tests/contract/call-access-admission-boundaries-contract.mjs
node tests/contract/call-access-audit-redaction-contract.mjs
node tests/contract/call-access-forged-identifiers-contract.mjs
node tests/contract/call-access-authorized-rejoin-extract-contract.mjs
node tests/contract/call-access-edge-error-matrix-contract.mjs
node tests/contract/iam-call-access-ci-wire-contract.mjs
```

Canonical IAM gates:

```bash
cd demo/video-chat/frontend-vue
npm run test:ci:iam-call-access:sqlite
npm run test:ci:iam-call-access:static
npm run test:ci:iam-call-access:docker
```

Syntax and hygiene:

```bash
bash -n demo/video-chat/backend-king-php/tests/system-admin-call-rights-contract.sh demo/video-chat/backend-king-php/tests/org-admin-call-rights-contract.sh demo/video-chat/backend-king-php/tests/call-owner-transfer-contract.sh demo/video-chat/backend-king-php/tests/call-access-decision-contract.sh demo/video-chat/backend-king-php/tests/call-access-privacy-contract.sh demo/video-chat/backend-king-php/tests/call-access-session-fixation-contract.sh demo/video-chat/backend-king-php/tests/iam-call-access-sqlite-runtime-proof.sh
php -l demo/video-chat/backend-king-php/tests/system-admin-call-rights-contract.php
php -l demo/video-chat/backend-king-php/tests/org-admin-call-rights-contract.php
php -l demo/video-chat/backend-king-php/tests/call-owner-transfer-contract.php
php -l demo/video-chat/backend-king-php/tests/call-access-decision-contract.php
php -l demo/video-chat/backend-king-php/tests/call-access-privacy-contract.php
php -l demo/video-chat/backend-king-php/tests/call-access-session-fixation-contract.php
git diff --check
```

All commands above passed.

Note:
- `call-access-owner-transfer-remaining-extract-contract.mjs` was tried during
  exploration and failed on stale source-text expectations. It is not part of
  the current IAM package/static gate and was not used as IAM12-06 closure
  evidence.
