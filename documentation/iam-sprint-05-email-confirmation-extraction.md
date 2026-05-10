# IAM Sprint 05 Email Confirmation Extraction

Date: 2026-05-10

Scope: IAM5-13 email confirmation, account reconciliation, confirmation race,
multiple pending, and safe dispatch/audit proof extraction only. Source
branches and source worktrees were inspected read-only; no source branch was
merged, cherry-picked, deleted, reset, rebased, cleaned, or edited.
Background, Gossip, SFU, MediaSecurity, and BTGF areas were not touched.

Base checked: local `prod-kingrt-do-not-push-to-github` at
`9f654b8345dcf7719ab68c7f15f535019d8b41a0`. The worker branch was
fast-forwarded to that current prod tip before evidence files were added.

## Source Branches Inspected

| Branch | Head | State | Branch diff against base | Tip proof value | Decision |
| --- | --- | --- | --- | --- | --- |
| `local/iam-e2e-account-reconciliation-email` | `393bef4219d953aed145cf023c9d7c05f8157e66` | Clean source worktree | 385 files, 15914 insertions, 40368 deletions | Account-confirmation reconciliation in PHP and browser proof: confirmation is sent to the current logged-in account, not the personalized-link target; pending account data does not update before confirmation; wrong-account confirmation fails; same-account browser confirmation does not rebind sessions. | Preserve as source evidence; not safe to merge because the branch carries broad unrelated history. |
| `local/iam-e2e-email-confirmation-secure-expiry` | `f2c702aa1aa96637faf044fba8d52bdf016b5feb` | Clean source worktree | 418 files, 23079 insertions, 40828 deletions | Secure expiring account-update tokens: high-entropy `cau_` tokens, HTTPS or loopback confirmation origins, TTL-bound expiry, expired session rejection, expired-token non-consumption, and raw access-id omission from confirmation links. | Preserve as source evidence; implementation files are absent from current base. |
| `local/iam-e2e-email-multiple-pending-proof` | `72c2c2922141ec4220c54cbc5cc3c9cb387adeff` | Clean source worktree | 442 files, 29072 insertions, 40836 deletions | Multiple pending confirmation proof: distinct tokens for concurrent pending requests, rate limiting, independent confirmation of pending payloads, replay conflicts, and no link-target account update. | Preserve as source evidence; not ported without the confirmation runtime. |
| `local/iam-e2e-email-safe-texts-and-dispatch-audit` | `29d94e72b63b6482c50094360d731d17e0a15932` | Clean source worktree | 492 files, 41135 insertions, 41066 deletions | Highest-value source: safe email text, dispatch acceptance/failure handling, failed-dispatch pending-row deletion, account-data-change audit, redacted dispatch/audit payloads, and static `call-access-email-safe-texts-dispatch-audit-contract.mjs`. | Preserve as strongest future implementation evidence. |
| `local/iam-e2e-email-confirmation-race-hardening` | `a87b0ba8144c4bddb2c34207c6d3a569217754a6` | Branch only, no source worktree listed | 472 files, 36779 insertions, 40965 deletions | Race hardening: optional older-pending invalidation, `superseded_at`, `superseded_by_id`, deterministic superseded-token conflict, and replay conflicts after successful confirmation. | Preserve as source evidence; no worktree was edited. |
| `local/iam-e2e-audit-confirmation-implicit` | `6c8b0734f0f06ff59da25cb56de95f3a2c7f34c5` | Clean source worktree | 414 files, 22134 insertions, 40606 deletions | Audit event expansion for account confirmation plus owner-absence logging; account-confirmation-specific value overlaps with later safe dispatch/audit source. | Preserve account-confirmation audit event names as source evidence only. |

## Current Base State

The current base does not contain the account-confirmation implementation
surface used by the source branches:

```text
demo/video-chat/backend-king-php/domain/calls/call_access_account_confirmation.php
demo/video-chat/backend-king-php/domain/calls/call_access_account_confirmation_audit.php
demo/video-chat/backend-king-php/tests/call-access-email-confirmation-contract.php
demo/video-chat/frontend-vue/src/domain/calls/access/AccountUpdateConfirmationView.vue
demo/video-chat/frontend-vue/tests/contract/call-access-email-safe-texts-dispatch-audit-contract.mjs
```

The base does contain unrelated workspace/user email-change confirmation code
in `demo/video-chat/backend-king-php/http/module_users.php`,
`demo/video-chat/backend-king-php/http/module_auth_session.php`, and matching
frontend auth/settings flows. That is not the call-access account-update
confirmation flow from the IAM5-13 branches and is not treated as extracted
runtime behavior here.

## Source Value Preserved

The strongest source contract to preserve for a future implementation is:

- Account reconciliation: confirmation requests must be authenticated to the
  current account, sent to the current logged-in account, and explicitly not
  sent to the personalized-link target account.
- Manual re-entry: pending account-update payloads must require manual
  display-name re-entry, and account data must remain unchanged until a valid
  confirmation consumes a token.
- Account binding: a token cannot be confirmed by another account; another
  browser session for the same account may confirm without rebinding either
  browser session.
- Link-target isolation: confirming an account update must not update the
  personalized-link target account and must not leak link-target email,
  display name, host data, raw access id, or confirmation token in responses.
- Expiry and replay: tokens are high-entropy `cau_` tokens, have bounded TTLs,
  use a secure HTTPS or loopback frontend origin, do not expose raw access ids
  in the URL, fail after expiry without consumption, and replay as conflict.
- Multiple pending: multiple pending confirmations use distinct tokens and can
  be confirmed independently unless older-pending invalidation is enabled.
- Race hardening: when older-pending invalidation is enabled, a newer request
  marks older pending rows with `superseded_at` and `superseded_by_id`; a
  superseded token returns deterministic conflict and cannot update data.
- Safe dispatch: confirmation email text contains only a recipient greeting,
  secure confirmation URL, and expiry metadata; it must not include access ids,
  call ids, session ids, pending account data, manual data, or foreign account
  data.
- Dispatch failure safety: mail/outbox dispatch must report actual accepted
  delivery, fail closed when not sent or queued, delete the pending
  confirmation row on dispatch failure, and leave account data unchanged.
- Audit safety: audit events include
  `call_access_account_update_confirmation_requested`,
  `call_access_account_update_confirmation_email_dispatched`,
  `call_access_account_update_confirmation_email_dispatch_failed`,
  `call_access_account_update_confirmed`,
  `call_access_account_data_changed`,
  `call_access_account_update_confirmation_failed`,
  `call_access_account_update_confirmation_rate_limited`, and
  `call_access_account_update_confirmation_superseded`.
- Audit minimization: persisted confirmation storage and audit payloads keep
  fingerprints, not raw access ids, confirmation tokens, recipient emails,
  session ids, host emails, link-target names, or pending mail-failure names.
  Source audit payloads pin `confirmation_identifier_logged=false`,
  `raw_link_identifier_logged=false`, and `recipient_email_logged=false`.

## Current Extracted Proof Value

Current maintained contracts already support adjacent account, privacy, and
audit safety, but not the account-confirmation runtime itself:

- `demo/video-chat/frontend-vue/tests/contract/call-access-account-isolation-contract.mjs`
  proves browser storage replaces account tokens on login switch, parallel
  contexts keep separate account tokens, and call-access session issuance fails
  closed when verified login context disappears.
- `demo/video-chat/frontend-vue/tests/contract/call-access-logout-login-switch-contract.mjs`
  combines registered invitee wrong-account denial with account storage
  replacement and parallel-context isolation.
- `demo/video-chat/frontend-vue/tests/contract/call-access-strong-mismatch-privacy-contract.mjs`
  proves wrong-host personalized-link denial grants no access and does not bind
  a foreign session.
- `demo/video-chat/frontend-vue/tests/contract/call-access-link-privacy-contract.mjs`
  proves invalid link states hide foreign call titles and emails.
- `demo/video-chat/frontend-vue/tests/contract/call-access-audit-event-compatibility-contract.mjs`
  keeps denied call-access aliases canonicalized.
- `demo/video-chat/frontend-vue/tests/contract/call-access-audit-redaction-contract.mjs`
  keeps raw call-access ids, session ids, tokens, person fields, call fields,
  and media/signaling artifacts out of persisted audit payloads.
- `demo/video-chat/frontend-vue/tests/contract/call-access-strong-mismatch-audit-redaction-contract.mjs`
  keeps strong mismatch denial audit fields canonical and redacted.

Those current contracts extract the safe value that the base can honestly
prove today: account/session isolation, denied-state privacy, and audit
minimization. The account-update confirmation workflow itself remains deferred
implementation evidence.

## Extraction Decision

No backend runtime, frontend route/view, package script, CI wiring, `SPRINT.md`,
or `BACKLOG.md` change was made for IAM5-13. The source branches require
backend confirmation domain files, audit helper, HTTP route wiring, frontend
confirmation UI, PHP runtime contract, optional static contract, package
script wiring, and CI gate updates. They also carry broad unrelated history
against the current prod base, including files outside this ticket's write
scope. Importing only static assertions or documentation as if the runtime were
present would falsely claim support for missing contracts.

Safe extraction performed in this branch:

- documented the strongest email confirmation, account reconciliation,
  multiple-pending, race-hardening, and safe dispatch/audit proof value from
  the source branches;
- recorded the exact current base implementation gap;
- added a narrow static extraction contract that checks this evidence against
  the current repo surface;
- left product code, package scripts, shared CI wiring, sprint/backlog files,
  and forbidden Background/Gossip/SFU/MediaSecurity/BTGF areas untouched.

## IAM7-02 Current Extraction Update

Date: 2026-05-10

Worker `agent/iam7-02-duplicate-review-email` extracted the focused
call-access account-confirmation backend subset from
`agent/iam-e2e-duplicate-review-email` after the Sprint 07 baseline advanced
through `9c3744e4` and `b413f7de`.

Current implementation now includes:

- `demo/video-chat/backend-king-php/domain/calls/call_access_account_confirmation.php`
  for account-bound, expiring, one-time account-update confirmations.
- `demo/video-chat/backend-king-php/tests/call-access-email-confirmation-contract.php`
  for current-account recipient targeting, manual display-name re-entry,
  wrong-account denial, replay/expiry denial, no pre-confirm account update,
  no session rebinding, link-target account isolation, and token fingerprint
  storage.
- HTTP route wiring in `demo/video-chat/backend-king-php/http/module_calls_access.php`
  for account-update confirmation request and confirm endpoints. Development
  responses may expose a debug token; production responses return `null`.

The safe-dispatch/outbox, superseded-token race hardening, confirmation-specific
audit helper, frontend confirmation view, and browser E2E journey remain parked
source value. This extraction does not claim email dispatch acceptance or
frontend modal coverage; it proves the backend account-confirmation contract
now exists and keeps raw access ids, confirmation tokens, link-target account
data, and session ids out of persisted confirmation rows and audit payloads.
