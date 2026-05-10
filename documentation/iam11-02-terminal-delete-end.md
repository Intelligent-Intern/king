# IAM11-02 Terminal Delete/End Proof

Source branch reviewed: `local/iam-e2e-delete-end-terminal-proof-2`

Current integration branch: `prod-kingrt-do-not-push-to-github`

Decision: redundant with current focused proof. No broad E2E, realtime, Gossip,
SFU, Background, or historical sprint diff was imported.

## Branch Value

The source branch carries a broad historical IAM/call-access diff. Its focused
top commit, `005bd408` (`Prove terminal IAM call lifecycle states`), touches
call lifecycle/cancel handling, `/api/calls/resolve`, owner-timeout/lifecycle
contracts, and a small realtime leave/rejoin assertion.

The useful current contract is:

- deleted calls are hidden as safe `not_found` states;
- ended/cancelled calls deny fresh joins and late sessions before issuing a new
  session id;
- system-admin, org-admin, owner, participant, stale call-access session, and
  cached realtime contexts cannot bypass terminal call state;
- stale personal/open call-access bindings are quarantined;
- terminal delete/end cleanup revokes call-access sessions, invalidates links,
  clears stored presence/lobby state, disables only scoped temporary guests,
  preserves registered accounts, and emits sanitized audit evidence.

## Current Proof

The current integration already proves the branch value through focused
contracts:

- `demo/video-chat/backend-king-php/tests/call-access-deleted-ended-disabled-join-contract.php`
  covers `ended`, `cancelled`, and `deleted` terminal loops, late session denial
  before issuer invocation, stale binding quarantine, direct-join denial, stale
  room reconnect denial, and cached owner-context realtime bypass denial.
- `demo/video-chat/backend-king-php/tests/call-access-deleted-ended-hardening-contract.php`
  covers org-admin terminal delete/end bypass denial, safe direct resolve
  envelopes, public personalized-link denial, and redaction.
- `demo/video-chat/backend-king-php/tests/call-access-terminal-join-contract.php`
  covers disabled registered users, deleted user-id direct decisions, ended
  personal/open links, and deleted personal/open links with redacted denial
  payloads.
- `demo/video-chat/backend-king-php/tests/call-lifecycle-contract.php` covers
  delete/end cleanup: session revocation, link invalidation, presence cleanup,
  temporary-guest invalidation, registered-account preservation, owner-leave
  explicit end, and sanitized lifecycle audit.
- `demo/video-chat/frontend-vue/tests/contract/call-access-terminal-states-contract.mjs`,
  `call-access-terminal-browser-flows-contract.mjs`,
  `iam9-10-terminal-followup-denials-contract.mjs`, and
  `iam9-11-terminal-join-denials-contract.mjs` pin the browser/static side of
  terminal state classification and CI wiring.

## Not Imported

No source-branch runtime diff was ported for IAM11-02. The branch still contains
large stale changes outside the focused terminal proof surface, including broad
E2E and realtime-adjacent edits. Those remain excluded from this leaf.
