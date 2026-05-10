# IAM7-05 Audit Log Completeness Extraction

Scope: focused extraction from `local/iam-e2e-audit-log-completeness` into the
current Sprint 07 baseline. Background, Gossip, SFU, MediaSecurity, BTGF, and
their tests were not touched.

## Historical Branch Assessment

`local/iam-e2e-audit-log-completeness` is not mergeable as a whole. Its branch
delta includes stale broad IAM, Call App, frontend, realtime, Background,
Gossip, SFU, and MediaSecurity changes. The only focused tip value was commit
`1fdbd384` (`Complete IAM audit log coverage proof`), which added call/access
audit lifecycle helpers, runtime emission hooks, and backend proof.

Current HEAD already had the base audit table, canonical event recording,
payload sanitization/redaction, link-open audit emission,
call-scoped-access-continuation audit emission, and membership-removal audit
proof. It did not have current runtime coverage for call creation, invitation
creation, temporary call-access account creation, or account/link comparison.

## Extracted Current Value

Accepted into current code:

- `call_created` audit helper and emission from `videochat_create_call()`.
- `call_access_invitation_created` helper and emission only when a new access
  link is created.
- `temporary_account_created` helper and emission when the current call-access
  session path creates a guest account.
- `call_access_account_compared` helper and emission for matched personal
  account use and strong mismatch denial in the current session path.
- Backend SQLite proof
  `demo/video-chat/backend-king-php/tests/audit-call-access-events-contract.php`
  covering event presence, redaction, and required fingerprints.

The extracted hooks intentionally keep raw access IDs, session IDs, guest names,
host-name input, and call titles out of audit payloads. Access/session values
are retained only as fingerprints where needed for traceability.

## Parked Historical Value

Not extracted:

- `call_access_review.php` and
  `call_access_account_confirmation.php` from the historical branch, because
  those files are absent from current HEAD and belong to separate duplicate
  review/account-confirmation work.
- Historical host-name verification and account-update-confirmation audit
  assertions, because importing them would require that stale review surface.
- Historical participant join/leave/kick/owner-transfer audit helpers, because
  current Sprint 07 has separate owner-transfer/moderation tickets and importing
  them here would broaden this checkbox beyond audit-log completeness for the
  current call-access creation/session paths.

The historical branch is therefore partially redundant and partially parked:
its core audit completeness gap is now represented by current helpers, runtime
hooks, and backend proof; its broader stale branch surface remains source-only
reference for the later focused IAM tickets.
