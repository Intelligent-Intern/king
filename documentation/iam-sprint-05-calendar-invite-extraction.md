# IAM Sprint 05 Calendar Invite Extraction

Date: 2026-05-10

Scope: IAM5-14 calendar invite, unregistered invitee, registered invitee
handoff, invite invalidation, terminal-link, and reschedule stale-link proof
extraction only. Source branches and source worktrees were inspected read-only;
no source branch was deleted, reset, rebased, merged, checked out, cleaned, or
edited. Background, Gossip, SFU, MediaSecurity, and BTGF areas were not
touched.

Base checked: local `prod-kingrt-do-not-push-to-github` at
`a55c38fcfd4631e6daa9c99a27d39503b1225666`.

## Source Branches Inspected

| Branch | Head | State | Diff against base | Calendar/invite value | Decision |
| --- | --- | --- | --- | --- | --- |
| `local/iam-e2e-calendar-invitation-flow` | `b1b4b002c49c` | Clean source worktree | 66 files, 10138 insertions, 1538 deletions | Backend calendar invitation flow contract for distinct personalized links, separate appointment calls, guest identity binding, wrong-account rejection, manipulated-link denial, and reopen behavior. | Evidence only. Current maintained contracts cover the safe invite/link boundary; source booking-time temporary-account binding is not imported in this doc-only lane. |
| `local/iam-e2e-calendar-unregistered-main-journey` | `b739305a9d34` | Clean source worktree | 106 files, 17932 insertions, 2120 deletions | Broad unregistered calendar journey, invite invalidation, stale-link browser journey, terminal call handling, and unrelated owner/lifecycle work. | Evidence only; too broad for a narrow extraction merge. |
| `codex/iam-e2e-calendar-unregistered-followup-20260509` | `8b2a7fb7d1dc` | Branch only, no source worktree listed | 240 files, 48437 insertions, 3313 deletions | Follow-up bundle with unregistered journey testcase, reschedule stale-link backend proof, registered invitee wrappers, terminal join, and link-invalidation durability. | Evidence only; current proof is extracted through focused maintained contracts. |
| `local/iam-e2e-calendar-edge-safe-states` | `8160f8d91076` | Branch only, no source worktree listed | 164 files, 30329 insertions, 2700 deletions | Calendar edge safe states for invalid, expired, terminal, and no-leak invite screens. | Superseded by current link privacy and terminal/invalidation contracts. |
| `local/iam-e2e-reschedule-stale-link-safety` | `4f8ebbad9ab1` | Branch only, no source worktree listed | 193 files, 35889 insertions, 2934 deletions | Backend proof that rescheduling removes old personal/open links, clears pending lobby entries, disables old temporary guests, revokes old open-link sessions, preserves registered accounts, and admits only newly issued links. | Source-only reschedule lifecycle evidence. Current maintained proof keeps stale links expired or redacted, but the branch's full reschedule lifecycle implementation is not ported in this evidence-only lane. |
| `local/iam-e2e-invite-reschedule-delete-end-main-journeys` | `79319d339654` | Clean source worktree | 199 files, 38175 insertions, 3028 deletions | Browser main journeys for admitted personalized links plus reschedule, delete, and end terminal handling. | Delete/end terminal value is covered by current terminal contracts; reschedule lifecycle remains source evidence. |
| `local/iam-e2e-invite-invalidation` | `0dece51b1660` | Clean source worktree | 28 files, 4160 insertions, 1213 deletions | Focused invalidated personalized-link proof: cancelled invite resolves as safe missing link, no session is minted, and UI renders no private invite data. | Extracted into current invalidation, terminal, and link privacy contracts. |
| `local/iam-e2e-invite-invalidation-audit` | `d7331f9f55db` | Clean source worktree | 88 files, 13499 insertions, 1920 deletions | Invalidation plus audit/redaction expansion. | Invalidation value is current; broader audit proof is already represented by maintained audit compatibility/redaction contracts. |
| `local/iam-e2e-invite-registered-flow-proof-2` | `a03dd751a370` | Clean source worktree | 211 files, 41667 insertions, 3102 deletions | Registered logged-in invitee browser proof: authenticated account remains bound, no temporary guest adoption, lobby admission is required, and no elevated tenant/admin rights are granted. | Superseded by Sprint 04/current registered-invitee contracts. |
| `local/iam-e2e-invite-registered-logged-out-proof-3` | `f1601b97cb99` | Clean source worktree | 212 files, 41302 insertions, 3083 deletions | Registered logged-out handoff and backend wrapper proof. | Already mined in Sprint 04; retained as source evidence only. |
| `codex/iam-lane-60-calendar-invite-personalized-link-proof` | `689b8ec38d37` | Clean source worktree; upstream gone | 9 files, 423 insertions, 30 deletions | Narrow calendar personalized-link proof: UUID link ids, registered logged-in account binding, logged-out/unregistered temporary handling, unrelated availability changes preserving existing links, cancellation denial, and expired-link denial. | Current contracts cover the safe link, account, invalidation, and expiry value. Booking-time temporary-account behavior is not claimed by current code. |

## Current Extracted Value

The maintained value in current prod is the call-access contract around calendar
booking and invite links, not a wholesale import of the stale branches:

- Calendar booking mints a fresh call id and fresh call-access UUID, creates an
  invite-only appointment call, stores an external invited participant, stores
  the access link against the generated call and invitee email, and ties the
  appointment booking to that same call/access pair.
- Calendar booking and public join responses expose only the public call shape
  and `/join/{access_id}` path needed by the invitee. They do not expose owner
  ids, tenant authority, calendar internals, booking internals, participant
  email in terminal states, media/signaling secrets, or stale session tokens.
- An unregistered calendar invitee opens a personal link without a target user,
  must provide a guest display name, receives a call-access session only after
  that POST, is represented as a guest with least-privilege tenant permissions,
  and remains in the lobby/join path until host admission. The browser proof
  does not fetch the direct call route or enter `/workspace/call` before
  admission.
- Registered invitee value remains covered by the current registered-invitee
  contracts: logged-in invitees keep the authenticated account, wrong logged-in
  accounts fail closed without persisting a session, logged-out registered
  invitees go through a safe login handoff, and realtime room resolution rejects
  stale bindings for another room, call, or user.
- Cancelled or declined participant rows invalidate personal links as safe
  not-found states. Fresh session issuance does not allocate a user, issue a
  session id, persist a call-access binding, or return call/access/user data for
  invalidated links.
- Expired links, including stale invite links once their old `expires_at` is
  reached, stay terminal: public resolve/session routes map them to
  `call_access_expired`, return no access link, call, target user, or
  participant email, and existing call-access session bindings fail once the
  link expiry is stale.
- Deleted calls resolve as safe not-found states; ended or disabled calls
  resolve as terminal conflict states with no call payload. The browser terminal
  contracts prove the safe state renders before join controls and blocks session
  POSTs.

## Source-Only Reschedule Lifecycle Value

The branch `local/iam-e2e-reschedule-stale-link-safety` carries stronger
backend lifecycle behavior than current prod currently claims in maintained
contracts: reschedule deletes old personal/open links, resets or clears pending
lobby entries, disables stale temporary guests, revokes old anonymous sessions,
and requires newly issued links after the schedule change.

That full lifecycle implementation is not ported here because IAM5-14's write
scope is documentation plus an optional static contract. The extracted current
proof is therefore limited to stale-link safety at the call-access boundary:
stale, expired, invalidated, deleted, ended, and disabled links fail closed and
redact private data. The source branch should remain available as future
implementation evidence if a backend reschedule lifecycle lane is opened.

## Extraction Decision

Safe extraction performed in this branch:

- documented each inspected calendar/invite source branch and its current
  decision;
- pinned the current calendar booking/invite, unregistered invitee, registered
  invitee, invite invalidation, terminal state, and link privacy proof surface
  with a narrow static contract;
- classified the reschedule lifecycle backend contract as source-only evidence
  while preserving the current stale-link terminal proof.

No product code, package scripts, shared CI wiring, `SPRINT.md`, or
`BACKLOG.md` were edited. No source branch was merged or cherry-picked.

## Current Proof Commands

```bash
cd demo/video-chat/frontend-vue
node tests/contract/call-access-calendar-invite-extract-contract.mjs
node tests/contract/call-access-calendar-invite-join-contract.mjs
node tests/contract/call-access-registered-logged-in-invitee-contract.mjs
node tests/contract/call-access-registered-logged-out-handoff-contract.mjs
node tests/contract/call-access-registered-invitee-extract-contract.mjs
node tests/contract/call-access-invite-invalidation-terminal-contract.mjs
node tests/contract/call-access-terminal-browser-flows-contract.mjs
node tests/contract/call-access-terminal-states-contract.mjs
node tests/contract/call-access-link-privacy-contract.mjs
cd ../../..
git diff --check
```
