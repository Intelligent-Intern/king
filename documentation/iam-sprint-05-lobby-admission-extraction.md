# IAM Sprint 05 Lobby Admission Extraction

Date: 2026-05-10

Scope: IAM5-06 lobby admission timeout, concurrency, admission, audit, and
rejection-boundary proof extraction only. Background, Gossip, SFU,
MediaSecurity, and BTGF areas were not touched. Source proof worktrees were
inspected read-only; no source branch was deleted, reset, rebased, merged, or
edited.

Base checked: local `prod-kingrt-do-not-push-to-github` at
`a204a4a2c4a7989a5a0805ee58244a923c0ee920`.

## Source Branches Inspected

| Branch | Head | Status | Extracted value |
| --- | --- | --- | --- |
| `local/iam-e2e-lobby-timeout-consistency-proof-2` | `f3f3815531d1aabf1e7a803303e969ac10acf1a8` | Clean | Timeout consistency backend proof. |
| `codex/iam-lobby-timeout-consistency-followup` | `f57328d9d4376e3a57a232911d023e27fe7d0d83` | Clean | Same timeout proof files as `local/iam-e2e-lobby-timeout-consistency-proof-2`. |
| `codex/iam-lobby-timeout-consistency-followup-20260509` | `317757cee44b0820d052377ea1b42a042f1e3763` | Clean | Same timeout proof files as `local/iam-e2e-lobby-timeout-consistency-proof-2`. |
| `local/iam-e2e-lobby-concurrency-remaining` | `3c2f9cc9a2dacc3ea161131df196edceef41c4ee` | Clean | Remaining lobby concurrency static proof and focused browser IDs. |
| `local/iam-e2e-lobby-admission-main` | `f488d711f31fa9bafb39a8ec01086931e60f5331` | Clean | Main lobby admission Playwright matrix. |
| `local/iam-e2e-lobby-audit-entry-admission-rejection` | `6cc6f5f342784f4dbe4ecf90c886e33e33652995` | Clean | Lobby entry/admission/rejection audit helper proof. |
| `local/iam-e2e-lobby-audit-events` | `8a2911106a5061558b75cf6c8b8a7a86b1264c78` | Clean | Earlier broad lobby audit merge with mixed IAM changes. |
| `codex/iam-e2e-lobby-audit-ci-scan-20260509` | `7743e21fd54507a0079f7cdc47d712efac169f32` | Clean | Tip adds no unique lobby-audit code; history contains `46b6d963 Merge lobby audit event proof`. |
| `codex/iam-lobby-audit-cleanup-followup-20260509` | `67a693b4e737fb0bd57162568b4dc7e1873b5c7a` | Clean | Cleanest lobby audit follow-up proof. |

## Timeout Consistency

Unique source value is in the timeout branches:

```text
demo/video-chat/backend-king-php/http/module_realtime_lobby_persistence.php
demo/video-chat/backend-king-php/tests/realtime-lobby-timeout-consistency-contract.php
demo/video-chat/frontend-vue/tests/contract/iam-lobby-timeout-consistency-contract.mjs
```

The three timeout branches carry identical core timeout files. The backend
contract proves that `lobby/allow` and `lobby/allow_all` defer admission
publication until persistence, that admission writes use a pending-only
compare-and-set to `allowed`, and that a simulated database timeout after local
admission restores the local user to the queue while the database remains
`pending` with no unpersisted admitted handoff. It also proves aborting the
waiting connection clears the lobby and resets the participant to `invited`.

Current base does not contain
`demo/video-chat/backend-king-php/http/module_realtime_lobby_persistence.php`
or `demo/video-chat/backend-king-php/tests/realtime-lobby-timeout-consistency-contract.php`.
Current base still keeps lobby persistence helpers inside
`module_realtime_websocket_commands.php`. Because IAM5-06 write scope excludes
backend implementation files and package/CI wiring, the timeout implementation
was not ported here. The source proof is preserved as a backend follow-up item,
not copied as a stale static assertion against missing files.

## Concurrency Races

Current base already carries the stable concurrency proof:

```text
demo/video-chat/backend-king-php/tests/realtime-lobby-concurrency-contract.php
demo/video-chat/backend-king-php/tests/realtime-lobby-concurrency-contract.sh
demo/video-chat/frontend-vue/tests/contract/call-access-lobby-concurrency-contract.mjs
demo/video-chat/frontend-vue/tests/e2e/lobby-concurrency-ui.spec.js
```

The current backend contract proves:

- Two stale workers can admit the same pending participant and converge to one
  `allowed` participant state.
- The canonical snapshot after concurrent allow has an empty queue and one
  admitted handoff.
- A late duplicate allow is idempotent and returns `already_allowed`.
- In both admit-then-reject and reject-then-stale-admit races, rejection wins,
  the participant returns to `invited`, and the canonical lobby has no queued
  entry and no admitted handoff.

The current frontend static contract binds that backend contract to UI state
rules: lobby queue/admitted snapshots are deduped by user id, admitted rows win
over stale queued copies, stale allow/remove pending controls are cleared on
fresh snapshots, and the focused lobby-concurrency E2E remains a single queue
spec. The older `iam-lobby-concurrency-remaining-contract.mjs` branch value is
therefore superseded by the current `call-access-lobby-concurrency-contract.mjs`
plus `iam-call-access-ci-wire-contract.mjs`.

## Admission And Rejection Boundaries

The admission-main branch adds a broad Playwright matrix for:

- No-direct-access identities waiting for host admission.
- Host visibility of waiting participants and management controls.
- Organization admin visibility for own-organization calls.
- Normal participants having no lobby management controls.

Current base already has broader admission workflow coverage in
`demo/video-chat/frontend-vue/tests/e2e/lobby-admission.spec.js`, including
join-modal gating, pending reset on cancel/disconnect, unauthorized self-admit
failure, and a real admin admit path. That E2E is intentionally not imported or
rewired here because IAM5-06 asked to prefer static/backend contracts unless the
current harness is clearly compatible.

The extracted current proof is static/backend:

```text
demo/video-chat/frontend-vue/tests/contract/call-access-admission-boundaries-contract.mjs
demo/video-chat/backend-king-php/tests/realtime-lobby-security-contract.php
demo/video-chat/backend-king-php/tests/realtime-lobby-contract.php
demo/video-chat/backend-king-php/tests/realtime-lobby-db-sync-contract.php
```

Those contracts prove that:

- `lobby/allow`, `lobby/remove`, and `lobby/allow_all` require server-side
  moderation.
- Lobby moderation reloads the actor role from the database and binds authority
  to the target room/call context.
- Forged role, call role, call id, and cross-room owner authority fail closed.
- Non-moderators cannot allow lobby users.
- Invalid senders and senders outside the target room fail closed.
- Waiting-room users are queued only by explicit join, cancel/disconnect resets
  pending participants, and remove/reject resets the database participant state
  to `invited`.
- System admins and own-organization admins have admission authority only inside
  the intended boundary, without granting normal participants or temporary
  accounts moderation rights.

## Audit Entries

The cleanest audit source is
`codex/iam-lobby-audit-cleanup-followup-20260509`. Its unique files prove a
lobby-specific audit surface:

```text
demo/video-chat/backend-king-php/domain/audit/audit_lobby_events.php
demo/video-chat/backend-king-php/tests/audit-call-access-events-contract.php
demo/video-chat/frontend-vue/tests/contract/iam-call-access-audit-events-contract.mjs
```

The source audit proof adds `call_lobby_entry_created`,
`call_lobby_admission_granted`, `call_lobby_rejection_recorded`, and
`call_lobby_moderation_denied`. Payloads carry `audit_scope: iam_lobby`,
`previous_state`, `next_state`, `moderation_authorized`, actor role/call-role
context, and room/session/resource fingerprints while explicitly avoiding raw
room, credential, and guest identifiers. The backend proof drives queue join,
allow, reject, and unauthorized allow before fetching audit rows, and asserts
that unauthorized attempts create a moderation-denied audit entry without
creating an admission audit.

Current base does not contain
`demo/video-chat/backend-king-php/domain/audit/audit_lobby_events.php` or the
broader `audit-call-access-events-contract.php` lobby assertions. Current base
does contain stable call-access audit compatibility and redaction contracts:

```text
demo/video-chat/frontend-vue/tests/contract/call-access-audit-event-compatibility-contract.mjs
demo/video-chat/frontend-vue/tests/contract/call-access-audit-redaction-contract.mjs
demo/video-chat/frontend-vue/tests/contract/call-access-strong-mismatch-audit-redaction-contract.mjs
demo/video-chat/backend-king-php/tests/audit-call-access-membership-contract.php
```

Those current contracts cover IAM audit alias compatibility and redaction, but
not lobby-specific admission/rejection event rows. The lobby audit source value
was not ported because doing so correctly requires backend audit helpers and
runtime wiring outside this task's allowed write scope.

## Extraction Decision

Safe extraction performed in this branch:

- Documented the reusable current proof value for concurrency races and
  admission/rejection boundaries against existing static/backend contracts.
- Preserved timeout and lobby-audit branch value as precise backend follow-up
  evidence rather than copying stale assertions that would reference missing
  current-base files.
- Added a narrow static extraction contract that checks this evidence against
  current source files.

No product code, package scripts, shared CI wiring, `SPRINT.md`, or
`BACKLOG.md` were edited. No broad E2E source branch was imported.
