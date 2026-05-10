# IAM11-05/09 Backend Audit Integration Shape

Date: 2026-05-10

Scope: documentation-only review of the current integration worktree
`/home/jochen/projects/king.site/worktrees/bgf-sprint-integration`.
No PHP runtime or test files were edited. Background, Gossip, SFU,
MediaSecurity, and BTGF areas were not touched.

## Findings

1. IAM11-05 owner-transfer audit is now present in runtime, but the extraction
   evidence is stale.

   `documentation/iam-sprint-05-owner-transfer-extraction.md` still says the
   current base does not contain `videochat_audit_record_call_owner_transferred`
   and that the source mutation audit write was not ported. The integration
   worktree now has the helper in
   `demo/video-chat/backend-king-php/domain/audit/audit_events.php` and calls it
   from the owner-transfer mutation path in
   `demo/video-chat/backend-king-php/domain/calls/call_management_owner_transfer.php`.

2. Function names are mostly coherent, but the audit helper canonicalizes away
   its own requested event name.

   `videochat_audit_record_call_owner_transferred` records
   `event_type => 'call_owner_transferred'`. The shared alias map resolves
   `call_owner_transferred` to `call_access_role_changed` before insert. That
   is consistent with the existing compatibility floor, but it means tests that
   expect a persisted `call_owner_transferred` row will fail unless they assert
   the canonical event plus owner-transfer payload metadata.

3. IAM11-05 transaction boundary is strong.

   The owner-transfer path starts a transaction before mutating
   `calls.owner_user_id` and `call_participants`, checks the exactly-one-owner
   invariant, writes the audit row, and commits only after the audit write
   succeeds. On any exception it rolls back the mutation and audit write
   together. This matches the expected integration shape for a successful
   transfer audit.

4. IAM11-09 guest-list audit is partially integrated, but not equivalent to the
   source proof named in `SPRINT.md`.

   The integration worktree has `videochat_guest_list_audit_*` helpers and
   writes guest-list audit rows inside the create, update, and non-owner role
   update transactions. The event taxonomy currently emits
   `guest_list_entry_added`, `guest_list_entry_removed`,
   `guest_list_permission_changed`, or `guest_list_entry_updated`. It does not
   emit the sprint-listed `guest_list_entry_merged` or
   `guest_list_entry_restored` events.

5. IAM11-09 transaction boundaries are strong where wired.

   Call create writes initial guest-list audit changes before commit and rolls
   back on `guest_list_audit_write_failed`. Call update computes the diff before
   the transaction, replaces participants, writes audit changes, and commits
   only after audit success. Non-owner participant role changes also write the
   guest-list permission audit inside the role-update transaction.

6. Existing static extraction contracts are stale relative to this integration
   tree.

   `call-access-owner-transfer-remaining-extract-contract.mjs` fails before it
   reaches the current audit-path question because its expected text no longer
   matches `call-access-owner-transfer-main-contract.mjs`.
   `call-access-guest-list-remaining-extract-contract.mjs` also fails on an
   active-permission proof text expectation. The contracts therefore are not
   reliable closure gates for IAM11-05/09 in this worktree without refresh.

## Test Commands

Run from `demo/video-chat/frontend-vue` unless noted:

- `node tests/contract/call-access-audit-event-compatibility-contract.mjs`
  - Result: PASS.
- `node tests/contract/call-access-owner-transfer-remaining-extract-contract.mjs`
  - Result: FAIL on stale owner-transfer main contract text expectation.
- `node tests/contract/call-access-guest-list-remaining-extract-contract.mjs`
  - Result: FAIL on stale active-permission proof text expectation.

Run from the repo root:

- `php -l demo/video-chat/backend-king-php/domain/audit/audit_events.php`
  - Result: PASS.
- `php -l demo/video-chat/backend-king-php/domain/calls/call_management_owner_transfer.php`
  - Result: PASS.
- `php -l demo/video-chat/backend-king-php/domain/calls/call_guest_list_audit.php`
  - Result: PASS.

Broader package gates remain the documented IAM gates:

- `cd demo/video-chat/frontend-vue && npm run test:ci:iam-call-access:static`
- `cd demo/video-chat/frontend-vue && npm run test:ci:iam-call-access:sqlite`
- `cd demo/video-chat/frontend-vue && npm run test:ci:iam-call-access:docker`
- `cd demo/video-chat/frontend-vue && npm run test:ci:iam-call-access`

Based on the targeted failures above, the remaining extraction contracts should
be refreshed before using the broader gates as IAM11-05/09 closure evidence.
