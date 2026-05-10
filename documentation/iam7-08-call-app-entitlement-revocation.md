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
- New session start, stale session activation, and cached CRDT bootstrap/append
  continue to fail with `app_not_available`, so the parent runtime can close
  cached app state without leaking private CRDT data.
- Launch-token mint and validation use the stronger
  `videochat_call_app_launch_session_availability` path and return
  `entitlement_not_active` for revoked entitlements, `entitlement_expired` for
  expired entitlements, `installation_disabled` for disabled installations, and
  `token_stale_after_entitlement_change` for reconnect tokens issued before a
  later entitlement or installation change.

## Historical Branch Assessment

`local/iam-e2e-call-app-entitlement-revocation` is not safe to merge wholesale.
Its useful current value is the focused launch/validation revocation proof from
commit `dd21579f`; the branch also carries broad stale Sprint/UI/media churn and
deleted Call App assets outside IAM7-08.

## IAM9-06 Extraction

The current integration base already contains a stronger version of the
historical `dd21579f` runtime value:

- `demo/video-chat/backend-king-php/domain/call_apps/call_app_launch_tokens.php`
  revalidates the organization installation, entitlement status/expiry, catalog
  health, and reconnect staleness on both mint and validation.
- `demo/video-chat/backend-king-php/tests/call-app-marketplace-entitlement-contract.php`
  proves revoked entitlements remove call availability, block new sessions,
  block stale session activation, block launch-token mint/validation for
  existing sessions, and close cached CRDT bootstrap/append paths.
- `demo/video-chat/frontend-vue/tests/contract/iam9-06-call-app-entitlement-revocation-contract.mjs`
  keeps those exact revocation reasons under the Call App static gate without
  depending on `SPRINT.md`, `BACKLOG.md`, or `READYNESS_TRACKER.md` edits.
