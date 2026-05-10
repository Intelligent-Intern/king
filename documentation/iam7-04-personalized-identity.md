# IAM7-04 Personalized Identity Extraction

Date: 2026-05-10

Source branch inspected: `agent/iam-e2e-personalized-identity` at `36c5f4f7`.

## Current Integration State

The current integration already contains the core personalized call-access
identity behavior:

- `JoinView.vue` snapshots `verifiedAccessContext` after public link
  resolution.
- `callAccessSession.ts` sends `verified_user_id`,
  `verified_session_id`, and the current bearer token when a verified session
  exists.
- `call-access-join.spec.js` already proves the live logged-out personalized
  link path, verified-session switch fail-closed behavior, logout before
  session issuance, parallel account isolation, and strong personalized
  mismatch privacy.
- Backend route proof already covered matching authenticated users, wrong
  authenticated users, and session-switch conflict handling for personalized
  links.

## Extracted Value

The historical branch was not merged. Its reusable value was narrowed to:

- a dedicated browser proof for logged-out personalized links sending no bearer
  or verified identity body;
- a dedicated browser proof for same-account personalized links sending the
  current bearer plus the verified user/session snapshot;
- a backend session guard that rejects client-supplied verified context when no
  authenticated bearer session is present;
- route-guard coverage proving that rejected no-bearer verified context does
  not persist a call-access session;
- a static UI contract pinning the dedicated personalized identity proof and
  backend no-bearer guard.

## Redundancy And Parked Value

The historical branch diff is broad and stale. It deletes many current IAM
contracts and touches parked or unrelated areas, including Background, Gossip,
SFU, MediaSecurity, BTGF-adjacent contracts, call-app packages, governance,
deployment, and frontend runtime files. Those changes are not safe to import
for IAM7-04.

The useful value is now reduced to the focused proof above. The remaining
historical personalized identity tests are redundant with current
`call-access-join.spec.js` and existing mismatch/no-leak contracts. A future
cleanup could move more duplicated personalized-link cases out of the large
join spec, but that is refactor value, not missing runtime behavior for this
ticket.

## Verification

Focused verification for this commit:

- `npm ci --ignore-scripts`
- `node tests/contract/call-access-verified-context-ui-contract.mjs`
- `php -l ../backend-king-php/domain/calls/call_access_session.php`
- `php -l ../backend-king-php/tests/call-access-session-route-guard-contract.php`
- `../backend-king-php/tests/call-access-session-route-guard-contract.sh`
  skipped because host PHP lacks `pdo_sqlite`
- `IAM_SQLITE_CONTRACTS="call-access-session-route-guard-contract.sh"
  ../backend-king-php/tests/iam-call-access-sqlite-runtime-proof.sh`
- `PLAYWRIGHT_IAM_CALL_ACCESS_ARTIFACTS=1 npx playwright test tests/e2e/call-access-personalized-identity.spec.js --workers=1`
- `git diff --check`

All non-skipped commands passed. The SQLite route guard passed through the
container fallback.
