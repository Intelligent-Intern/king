# IAM Sprint 04 Review-Abuse Extraction

Date: 2026-05-10

Scope: read-only extraction review of
`local/iam-e2e-review-abuse-cross-browser-proof-3` and
`local/iam-e2e-review-warning-modal-policy-proof-3`. The source worktrees were
not deleted, reset, rebased, checked out, cleaned, or modified. Background,
Gossip, SFU, MediaSecurity, and BTGF areas were not touched.

## Source Worktrees

Branch: `local/iam-e2e-review-abuse-cross-browser-proof-3`

Worktree:
`/home/jochen/projects/king.site/worktrees/iam-e2e-review-abuse-cross-browser-proof-3`

Observed HEAD: `0e02e60542734f5221b1bd85fee7154e002e5077`

Observed status:

```text
## local/iam-e2e-review-abuse-cross-browser-proof-3
```

Branch: `local/iam-e2e-review-warning-modal-policy-proof-3`

Worktree:
`/home/jochen/projects/king.site/worktrees/iam-e2e-review-warning-modal-policy-proof-3`

Observed HEAD: `bdd29ffd2bc2a7ba9cbec4711dfc043931639044`

Observed status:

```text
## local/iam-e2e-review-warning-modal-policy-proof-3
```

Both source branches are clean, but each diff against current
`prod-kingrt-do-not-push-to-github` is broad:

- review-abuse cross-browser: 210 files changed, 41117 insertions, 3084
  deletions,
- warning-modal policy: 210 files changed, 41174 insertions, 3083 deletions.

Those branches include backend call-access review/account-confirmation helpers,
identity mismatch helpers, frontend join-view modal changes, account-update
confirmation UI, package wiring, and E2E fixtures/specs. They are not safe
narrow cherry-picks.

## Review-Abuse Value Found

The cross-browser source proof value has two parts.

First, duplicate personalized-link abuse must keep browser/account sessions
isolated. Concurrent use of the same personalized link by separate browsers
must reconcile deterministically, allow at most the bound account to receive a
call-scoped session, reject the other browser without rebinding it, and avoid
leaking the accepted browser session, access token, linked account, or private
call data.

Second, suspicious personalized-link mismatch should enter a manual-review
warning policy instead of silently adopting the link target account. The source
proofs classify the review signal as `duplicate_personalized_link` with
`manual_review_required`, expose only safe warning-modal fields such as
`mismatch=strong_personalized_link`, `auth=not_bound_to_current_user`, and
`host_name=not_verified|wrong_host_name`, and avoid raw access IDs, cookies,
session tokens, linked-account email/name, private call data, and private host
data.

The warning-modal source also proves an account-update email confirmation
journey: confirmation requests are rate-limited, sent to the currently logged-in
account instead of the link account, require manual account-data re-entry, use
secure expiring account-bound tokens, confirm safely across browser contexts,
reject expired, replayed, or wrong-account tokens, and never rebind the current
browser session before confirmation.

## Current Coverage

Current Sprint 03/04 integration already covers the duplicate-abuse/session
isolation portion with narrower stable proofs:

- `demo/video-chat/frontend-vue/tests/contract/call-access-duplicate-abuse-contract.mjs`
  pins the parallel browser-context duplicate-abuse E2E path, one accepted
  session plus one 409 conflict, current-account verified context on both
  requests, no rejected-browser session overwrite, and no cross-device token
  bleed.
- `demo/video-chat/frontend-vue/tests/contract/call-access-mismatch-no-leak-states-contract.mjs`
  pins generic conflict/forbidden denial UI, no lobby/workspace entry, no
  session envelope application on denied responses, and no foreign person,
  calendar, call, tenant, or session data in UI state.
- `demo/video-chat/frontend-vue/tests/contract/call-access-strong-mismatch-privacy-contract.mjs`
  pins the wrong-host strong personalized-link mismatch E2E path: current
  logged-in session remains authoritative, verified context is sent, no
  workspace or lobby access is granted, and foreign person/session data is not
  rendered.
- `demo/video-chat/frontend-vue/tests/contract/call-access-strong-mismatch-audit-redaction-contract.mjs`
  pins canonical/redacted audit behavior for strong mismatch categories.

Current integration does not contain the source-only manual-review and
account-confirmation implementation files:

- `demo/video-chat/backend-king-php/domain/calls/call_access_review.php`,
- `demo/video-chat/backend-king-php/domain/calls/call_access_account_confirmation.php`,
- `demo/video-chat/backend-king-php/domain/calls/call_access_account_confirmation_audit.php`,
- `demo/video-chat/backend-king-php/domain/calls/call_access_identity.php`,
- `demo/video-chat/frontend-vue/src/domain/calls/access/AccountUpdateConfirmationView.vue`,
- `demo/video-chat/frontend-vue/tests/e2e/call-access-duplicate-review-email.spec.js`,
- `demo/video-chat/frontend-vue/tests/e2e/call-access-duplicate-race.spec.js`,
- `demo/video-chat/backend-king-php/tests/call-access-duplicate-review-contract.php`,
- `demo/video-chat/backend-king-php/tests/call-access-identity-mismatch-review-flow-contract.php`.

## Classification

Recommendation: `manual/deferred extraction`.

The duplicate-abuse cross-browser session-isolation proof value is already
covered by current stable IAM proofs. The warning-modal manual-review and
account-update email confirmation value is unique, but it is not currently
implemented or wired in this integration branch and cannot be extracted safely
without importing a broad review/account-confirmation feature set. Keep both
source worktrees untouched for a manager-owned feature extraction decision.

## IAM7-02 Update

Date: 2026-05-10

The backend manual-review and account-confirmation subset is now extracted by
`agent/iam7-02-duplicate-review-email` from the narrower
`agent/iam-e2e-duplicate-review-email` source branch. The current backend now
has `call_access_review.php`, `call_access_account_confirmation.php`,
`call-access-duplicate-review-contract.php`, and
`call-access-email-confirmation-contract.php`.

The older review-abuse and warning-modal worktrees remain parked for frontend
warning-modal policy, duplicate-race browser proof, identity mismatch flow,
confirmation dispatch/audit hardening, and broader E2E coverage. Their broad
source diffs are still not safe narrow cherry-picks.
