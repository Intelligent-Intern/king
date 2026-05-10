# IAM7-06 Calendar Invitation Edge Safe States

Date: 2026-05-10

Branch inspected: `local/iam-e2e-calendar-edge-safe-states`

Worker branch: `agent/iam7-06-calendar-edge-safe-states`

## Historical Branch Assessment

The historical branch is not safe to merge wholesale. Its current diff against
the integration tip spans hundreds of files and includes stale IAM sprint
merges, unrelated call-access changes, deleted docs, and renamed tests.

Focused retained value:

- Calendar bookings should create a server-side temporary guest identity at
  booking time instead of leaving invite links bound only to an email address.
- Registered users who book while logged out must not have the calendar link
  silently bind to their existing account.
- Reopening the same calendar invitation link must reuse the same temporary
  guest identity instead of allocating another one.
- Cancelled or booking/call-mismatched appointment invitations must fail closed
  as `not_found` without returning access-link, call, target-user, session, or
  booking details.
- Existing call-access session bindings must become invalid when the underlying
  appointment booking no longer matches the access link.

Parked value:

- Broad shared call-access policy changes from the historical branch remain
  parked because newer sprint work has already integrated or superseded them in
  narrower tickets.
- Historical frontend/E2E branch artifacts remain parked; this extraction keeps
  the proof in backend PHP contracts.

## Current Extraction

`appointment_calendar_booking.php` now creates a synthetic guest user during
booking, removes tenant membership for that call-scoped identity, stores the
guest user id on `call_access_links.participant_user_id`, and keeps the booking
form email as contact metadata.

`call_access_calendar_guards.php` adds the calendar-specific invalidation rule:
if an `appointment_bookings` row for the access id is no longer `booked`, or if
the booking points at another call id, the access link is invalidated.

`call_access_contract.php` applies the invalidation rule to both public link
resolution and existing session binding validation.

`call-calendar-invitation-flow-contract.php` proves the safe states against a
SQLite backend fixture.

## Verification

- `php -l demo/video-chat/backend-king-php/domain/calls/appointment_calendar_booking.php`
- `php -l demo/video-chat/backend-king-php/domain/calls/call_access.php`
- `php -l demo/video-chat/backend-king-php/domain/calls/call_access_contract.php`
- `php -l demo/video-chat/backend-king-php/domain/calls/call_access_calendar_guards.php`
- `php -l demo/video-chat/backend-king-php/tests/call-calendar-invitation-flow-contract.php`
- `demo/video-chat/backend-king-php/tests/iam-call-access-sqlite-runtime-proof.sh`
- `IAM_SQLITE_CONTRACTS="appointment-calendar-contract.sh call-calendar-invitation-flow-contract.sh" demo/video-chat/backend-king-php/tests/iam-call-access-sqlite-runtime-proof.sh`

Host PHP did not provide `pdo_sqlite`, so the SQLite contracts used the
repository Docker PHP fallback. All listed verification commands passed.
