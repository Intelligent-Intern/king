# IAM7-08 Call App Entitlement Revocation

## Scope

Call App organization entitlement and installation revocation must fail closed
for active Call App sessions, not only for new catalog availability.

## Runtime Proof

- Availability still hides disabled installations, revoked entitlements, expired
  entitlements, and unhealthy catalog entries.
- Existing Call App sessions re-check their installation and entitlement before
  launch-token mint, launch-token validation, stale active-session activation,
  and CRDT bootstrap/replay/append.
- `app_not_available` is preserved as the backend reason so the parent runtime
  can close cached app state without leaking private CRDT data.

## Historical Branch Assessment

`local/iam-e2e-call-app-entitlement-revocation` is not safe to merge wholesale.
Its useful current value is the focused launch/validation revocation proof from
commit `dd21579f`; the branch also carries broad stale Sprint/UI/media churn and
deleted Call App assets outside IAM7-08.
