# IAM Sprint 05 Owner Absence Extraction

Date: 2026-05-10

Scope: IAM5-09 owner absence, owner timeout, and owner-leave proof extraction
only. Background, Gossip, SFU, MediaSecurity, BTGF, package scripts, shared CI
wiring, `SPRINT.md`, and `BACKLOG.md` were not edited.

Base checked: local `prod-kingrt-do-not-push-to-github` at
`49e5cdae858a3b572654ade6040f4b50d037632e`.

## Source Branches Inspected

| Branch | Head | Extracted value | Decision |
| --- | --- | --- | --- |
| `local/iam-e2e-king-participants-owner-timeout` | `4e5a6f9c61aa71e4daa4db220c3c8b9de6973f3d` | First focused King participant proof for owner absence, owner-present detection, monitoring, countdown, owner return, and persisted implicit end. | Superseded by later timeout branches for countdown semantics, but useful as the initial runtime proof source. |
| `local/iam-e2e-owner-absence-browser` | `927289e720b49e5125e861b07f1a93af3fd45281` | Browser proof for participant-visible owner absence countdown and owner return cancellation. | Source evidence only; it adds Vue UI and Playwright files outside this lane's write scope. |
| `local/iam-e2e-owner-absence-countdown-proof` | `f2f82371b3c6d0ad3938016358177bed8bbb1df6` | Corrects the timeout shape to a 15-minute total owner-absence deadline with the final 5 minutes visible as countdown. | Preferred timer semantics for this extraction. |
| `local/iam-e2e-owner-absence-realtime-sync` | `72dd4d81d1e8431e940808890f232b78cf6f8a70` | Adds stale owner heartbeat handling, server-side `left_at` materialization, participant refresh survival, and synchronized countdown snapshots. | Preferred realtime source value. |
| `local/iam-e2e-owner-leave-explicit-end-proof` | `24f4204096a8ea9b18c4ce5a497ad97f8d5c94da` | Carries the owner-leave and explicit owner-end browser proof: owner tab close/context kill/network disconnect enter monitoring, while explicit end publishes an ended lifecycle state. | Source evidence only; current prod has terminal-state contracts but not the branch lifecycle payload implementation. |
| `local/iam-e2e-owner-timeout-open-link-proof` | `755da3df0949402f9ac3115c94e134d72730270f` | Extends owner-timeout end to anonymous/open link invalidation, session revocation, and temporary guest disablement. | Source evidence only; depends on unported backend lifecycle files. |
| `local/iam-owner-timeout-anonymous-link-proof` | `c446d28a5611fc06d340d6ff9a5135dbe428a855` | Alternate anonymous/open-link timeout proof with the same invalidation intent. | Superseded by the broader `755da3df` proof, but confirms the anonymous-link branch value. |

No separate local branch matching `*owner*timeout*audit*` was found. The
owner-timeout audit value is carried inside the owner-timeout contract branches:
the source proof asserts that implicit owner-timeout end preserves a
`call_ended` audit row while the terminal lifecycle revokes links, sessions,
lobby rows, and temporary guests.

## Extracted Owner Absence Value

The strongest branch contract is:

- owner absence is evaluated from server-side realtime presence and persisted
  call/participant state, not from client timers;
- `VIDEOCHAT_OWNER_ABSENCE_TIMER_MS` is `15 * 60 * 1000`;
- `VIDEOCHAT_OWNER_ABSENCE_COUNTDOWN_MS` is `5 * 60 * 1000`;
- the call ends after 15 minutes total owner absence, and the visible countdown
  occupies the final five minutes of that same deadline;
- owner absence states are `owner_present`, `no_participants`, `monitoring`,
  `countdown`, and `ended`;
- owner return before the deadline cancels monitoring/countdown and clears the
  owner's `left_at` marker;
- owner tab close, browser/context crash, and network disconnect do not
  immediately end the call. They start monitoring from the server leave time or
  stale-heartbeat cutoff;
- stale owner heartbeat expiry is materialized into the owner participant
  `left_at` value so refreshes and multiple participants share the same
  `absent_since` and `ends_at` values;
- room snapshots include `call_lifecycle.owner_absence`, and the room snapshot
  signature includes that lifecycle payload so realtime participants receive
  changes without manual refresh UI.

Current prod does not contain the branch implementation files
`demo/video-chat/backend-king-php/domain/realtime/realtime_owner_absence.php`,
`demo/video-chat/backend-king-php/tests/call-access-owner-timeout-contract.php`,
`demo/video-chat/frontend-vue/src/domain/realtime/OwnerAbsenceCountdownBanner.vue`,
or
`demo/video-chat/frontend-vue/src/domain/realtime/workspace/callWorkspace/ownerAbsenceState.js`.
Those files were not ported because IAM5-09 is limited to evidence and an
optional contract.

## Extracted Owner Timeout Value

The timeout branches route the implicit ended transition through the same
terminal cleanup surface expected for explicit call ending:

- persist `calls.status = 'ended'`;
- mark joined participants with `left_at`;
- return `ended_reason: owner_absent_timeout` and `transitioned: true`;
- block fresh direct joins with `call_not_joinable_from_status`;
- revoke stale call-access sessions so websocket rejoin fails;
- delete or invalidate personalized, anonymous, and open call-access links;
- prevent late personalized or anonymous link session issuance;
- cancel pending/admitted lobby participants and clear lobby rows;
- disable pending/admitted temporary guests created for call access;
- preserve a terminal `call_ended` audit event without exposing raw invite,
  call, session, or temporary guest identifiers.

Current prod already has maintained terminal fail-closed proof for ended,
disabled, deleted, invalidated, and expired call-access paths, but it does not
have the owner-timeout trigger that would call that cleanup path automatically.
The source owner-timeout backend contract therefore remains backend follow-up
evidence rather than a copied static assertion against missing files.

## Extracted Owner-Leave Value

The owner-leave branches distinguish three cases:

- explicit owner leave or browser loss should not immediately end the call while
  non-owner participants remain; it starts owner-absence monitoring;
- owner rejoin before the deadline cancels monitoring/countdown and preserves
  the active call;
- explicit owner call end is terminal immediately and broadcasts an ended
  lifecycle payload with `ended_reason: owner_explicit_end` to active
  participants.

Current prod has related maintained proof that reconnect and explicit leave are
separate websocket actions, and that terminal ended calls deny new joins and
redact private call payloads. Current prod does not yet have the
branch-specific lifecycle payload fields for `owner_explicit_end`.

## Current Maintained Coverage

The current base preserves adjacent proof value through narrower contracts:

- `demo/video-chat/frontend-vue/tests/contract/call-access-terminal-states-contract.mjs`
  pins ended, disabled, and deleted direct-join denial, safe not-found/forbidden
  responses, and private payload redaction.
- `demo/video-chat/frontend-vue/tests/contract/call-access-invite-invalidation-terminal-contract.mjs`
  pins invalidated/expired/ended public join and session routes so terminal
  states do not issue sessions or leak invite data.
- `demo/video-chat/backend-king-php/tests/call-access-terminal-join-contract.php`
  exercises ended personal/open links, disabled/deleted users, and terminal
  session denial in the backend SQLite harness when `pdo_sqlite` is available.
- `demo/video-chat/frontend-vue/tests/contract/owner-transfer-lifecycle-contract.mjs`
  pins reconnect versus explicit `room/leave` separation and fresh owner-role
  recomputation after users leave and rejoin.
- `demo/video-chat/frontend-vue/tests/contract/call-access-owner-transfer-main-contract.mjs`
  pins persisted owner-role recomputation, old-owner demotion, and stricter
  owner-management authority.
- `demo/video-chat/frontend-vue/tests/contract/call-access-permission-change-active-call-contract.mjs`
  pins active websocket snapshots refreshing owner/moderator/admin authority
  without browser reload.
- `demo/video-chat/frontend-vue/tests/contract/call-access-audit-event-compatibility-contract.mjs`
  and
  `demo/video-chat/frontend-vue/tests/contract/call-access-audit-redaction-contract.mjs`
  pin compatible audit event aliases and sanitized audit payloads for
  call-access identifiers, sessions, tokens, SDP/ICE, and private call data.

## Non-Ports

The owner source branches also modify backend runtime files, Vue workspace UI,
Playwright E2E suites, seed matrices, package scripts, CI wiring, smoke scripts,
and sprint/planning files. Those changes were not imported. Porting the runtime
correctly requires backend realtime owner-absence implementation, room snapshot
lifecycle payloads, terminal lifecycle cleanup, and UI/browser proof in a lane
that permits those files.

IAM5-09 closes as focused extraction evidence plus a static guard. It does not
weaken the stronger branch contract, and it does not mark the owner-timeout
runtime itself as implemented on current prod.
