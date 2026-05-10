# IAM7-09 Call App Launch Token Reconnect

Status: extracted into the current Sprint 07 integration baseline.

Scope:
- Call App launch-token minting now rechecks active installation, entitlement,
  entitlement expiry, and catalog health before issuing a token.
- Launch-token reconnect validation rejects expired, revoked, cross-session,
  cross-call, entitlement-revoked, inactive-session, reactivated-session, and
  grant-changed tokens.
- Participant grant changes rotate existing user launch tokens when the grant
  state or allowed permission actions change.
- Legitimate reconnect remains valid while the app session, grant, participant,
  installation, and entitlement are unchanged.

Proof:
- `call-app-session-lifecycle-contract.php` covers valid reconnect, cross-call
  replay, expiry, entitlement revocation, status-only token replay after rights
  change, inactive session validation, and stale token rejection after session
  reactivation.
- `call-app-permission-revocation-contract.mjs` pins the reconnect checks and
  focused lifecycle assertions.

Historical branch assessment:
- `local/iam-e2e-call-app-launch-token-reconnect` contains broad stale sprint
  and frontend churn and must not be merged wholesale.
- The focused current value has been extracted into the active branch.
