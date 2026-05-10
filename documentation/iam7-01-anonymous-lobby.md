# IAM7-01 Anonymous Open-Link Lobby Extraction

## Historical Source

- Branch inspected: `agent/iam-e2e-anonymous-lobby`
- Relevant tip: `9924866cd07459bbc9c7cebc639d85c34dbf8aa7`
- Historical commit: `test(videochat): prove anonymous open-link lobby split`

The historical branch bundled backend runtime edits, a backend contract, seed
matrix additions, and frontend E2E harness changes. It was not merged wholesale.

## Current Contract

Current prod keeps the account-safe open-link boundary that later sprint work
established:

- logged-in open-link use keeps the authenticated account and the existing
  account session; it does not mint or adopt a temporary guest identity;
- logged-in open-link sessions without their own direct call rights are put into
  `pending` and must wait in the King lobby;
- logged-out free-for-all open links issue isolated temporary guest identities
  and enter directly after session issuance;
- logged-out invite-only open-link sessions do not gain guest-list/direct-join
  rights at issuance and must explicitly queue in the King lobby.

That means the historical expectation that all open links wait for host
admission was not ported. Reintroducing it would conflict with current
maintained contracts such as
`call-access-session-route-guard-contract.php`,
`call-access-admin-prevention-contract.php`, and
`call-access-session-contract.php`.

## Extracted Proof

Added `demo/video-chat/backend-king-php/tests/call-access-anonymous-lobby-contract.php`
with a Docker-aware runner at
`demo/video-chat/backend-king-php/tests/call-access-anonymous-lobby-contract.sh`.

The proof covers the current split:

- logged-in free-for-all and invite-only open-link sessions keep the
  authenticated account and persist a `pending` participant row when the account
  has no direct call rights;
- logged-out free-for-all open links create an isolated guest and enter the
  bound room directly;
- logged-out invite-only open-link sessions create separate guest users without
  participant rows at issuance;
- queued open-link users resolve to `waiting-room` until lobby admission;
- a queued guest cannot self-admit;
- owner, same-organization admin, and system admin can admit queued open-link
  guests;
- admission persists the participant as `allowed`, so the guest can resolve into
  the bound call room after admission.

## Runtime Delta

Two minimal runtime fixes were required for the contract to pass honestly:

- `realtime_call_context.php` now creates a `pending` participant row only when
  an open-link call-access session explicitly queues. This preserves the current
  no-rights-at-issuance contract while making lobby admission durable.
- `realtime_lobby_state.php` now honors the DB-backed `can_moderate_call`
  connection flag, so org-admin moderation authority reaches the lower lobby
  state gate.

## Branch Disposition

`agent/iam-e2e-anonymous-lobby` is redundant for the current prod contract after
this extraction. Its remaining parked value is historical only: it documents an
older product direction where every open-link path waited for host admission.
Reintroducing that behavior for logged-out free-for-all links would be a product
contract change, not an IAM7 cleanup.
