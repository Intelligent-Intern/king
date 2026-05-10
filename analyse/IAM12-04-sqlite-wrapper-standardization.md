# IAM12-04 SQLite Wrapper Standardization

Date: 2026-05-10

Scope:
- IAM/call-access PHP SQLite runtime proof execution.
- No production deploy.
- No push, DNS changes, certbot calls, or remote mutation.
- No Background, Gossip, SFU, MediaSecurity, BTGF, or VCAP implementation work.

## Finding

Host PHP in this workspace does not expose `pdo_sqlite`. Several call-access
runtime wrappers exited successfully as local skips, which made direct package,
smoke, or focused wrapper calls weaker than the canonical Docker-capable IAM
SQLite proof.

The canonical `--sqlite` gate also exposed two runtime issues once the wrappers
were forced through Docker:

- Email-bound external personal links must stay usable for true external
  invitees, but deleted registered or temporary users must not degrade into an
  email-only guest link.
- Free-for-all open-link guests should enter directly before a kick, but after
  a kick the persisted `invited` state must require renewed lobby approval.

## Change

- Added a shared backend test runner:
  `demo/video-chat/backend-king-php/tests/sqlite-contract-runner.sh`.
- Converted the focused call-access wrappers that were direct skip risks to
  delegate to `iam-call-access-sqlite-runtime-proof.sh` when host PHP lacks
  `pdo_sqlite`.
- Expanded `iam-call-access-sqlite-runtime-proof.sh` to include session,
  anonymous temp rights, realtime lobby concurrency, IAM11-17, and owner
  transfer lifecycle coverage.
- Updated the frontend IAM package gate to call the anonymous-temp-rights
  Docker proof instead of invoking the raw PHP contract directly.
- Preserved true external email invitee joins while returning safe not-found
  responses for deleted or disabled personalized targets.
- Persisted free-for-all open guests as `allowed` on first join and made kicked
  free-for-all participants require renewed admission.
- Updated static contracts to assert the shared Docker-capable runner path and
  the external-invitee guard.

## Verification

Focused direct wrapper delegation:

```bash
demo/video-chat/backend-king-php/tests/call-access-membership-removal-contract.sh
demo/video-chat/backend-king-php/tests/realtime-lobby-concurrency-contract.sh
demo/video-chat/backend-king-php/tests/iam11-17-call-access-edge-proof-contract.sh
```

Canonical gates:

```bash
cd demo/video-chat/frontend-vue
npm run test:ci:iam-call-access:sqlite
npm run test:ci:iam-call-access:static
```

Focused frontend/static checks:

```bash
cd demo/video-chat/frontend-vue
node tests/contract/call-access-terminal-browser-flows-contract.mjs
node tests/contract/call-access-forged-identifiers-contract.mjs
node tests/contract/call-access-calendar-invite-extract-contract.mjs
node tests/contract/call-access-registered-invitee-extract-contract.mjs
node tests/contract/call-access-kicked-rejoin-denial-contract.mjs
node tests/contract/call-access-lobby-concurrency-contract.mjs
node tests/contract/call-access-edge-error-matrix-contract.mjs
```

All commands above passed. `git diff --check` and `bash -n` on the touched shell
wrappers passed.
