# IAM7-19 Organization Removal Active Privilege Downgrade

Date: 2026-05-10

Historical branch:
- `local/iam-e2e-org-removal-active-privilege-downgrade`
- Head: `1cf74a3224c472788c18b316ad885425a50c6057`

Current extraction baseline:
- `prod-kingrt-do-not-push-to-github`
- Head: `e73009d1`

Assessment:
- The historical branch is not suitable for wholesale merge. Its diff includes
  broad stale IAM changes, file moves/deletions, and parked media/background
  test churn outside IAM7-19 scope.
- The current integration branch already had the stronger runtime shape:
  organization-admin and realtime call powers are recomputed from server-side
  call, participant, tenant, and organization membership rows.
- IAM7-19 extracted only the missing current proof and gate wiring for active
  organization/membership removal.

Runtime proof:
- `call-access-org-removal-active-privilege-downgrade-contract.php` proves a
  same-organization admin loses hidden-call access, call administration,
  realtime moderation, direct room bypass, and active call binding immediately
  after organization membership removal.
- The same proof keeps explicit call-scoped admission alive when an invited
  removed org admin still has allowed participant access, but downgrades the
  active viewer role to participant and removes moderation/owner controls.
- Tenant membership removal fails websocket liveness closed with
  `tenant_membership_inactive`, closes as policy violation, and rejects locally
  cached stale session data.

Gate wiring:
- The focused contract is included in
  `iam-call-access-sqlite-runtime-proof.sh`.
- `iam-org-removal-active-privilege-downgrade-contract.mjs` pins the contract
  path and server-state role derivation in the IAM frontend contract gate.
