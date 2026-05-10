# IAM Sprint 05 Lobby State Cleanup Extraction

Sprint checkbox: IAM5-05, "Extract lobby state cleanup proof value from the
lobby cleanup branches, keeping live state websocket-driven and avoiding manual
refresh UI."

## Source Branches Inspected

| Branch | Tip inspected | Cleanup value |
| --- | --- | --- |
| `local/iam-e2e-lobby-state-cleanup-proof` | `46dd8f70` | Added focused backend and browser proof for admitted, rejected, and aborted lobby state cleanup. The branch also touched `SPRINT.md`, package scripts, matrix JSON, and CI wiring, so it is not safe to port wholesale for this lane. |
| `codex/iam-e2e-lobby-state-cleanup-proof-20260509` | `2b34babd` | Replayed the same cleanup proof with a narrower suite-helper shape: one backend cleanup contract, one Playwright spec, and one static proof contract. |
| `codex/iam-e2e-lobby-state-cleanup-script-gate-audit-20260509` | `50c15db2` | Merge/audit branch for the `2b34babd` proof. It does not add new cleanup semantics beyond the proof branch. |
| `codex/iam-lobby-timeout-consistency-followup-20260509` | `317757ce` | Adds useful timeout and abort consistency proof value: deferred admission publication, simulated persistence timeout, repair back to pending, and abort reset to invited. This branch is broader than IAM5-05 and is documented here rather than ported. |
| `codex/iam-lobby-audit-cleanup-followup-20260509` | `67a693b4` | Adds audit-event coverage for lobby entry, admission, rejection, and denied moderation. It is related evidence, but not required to extract cleanup state semantics. |

## Extracted Proof Value

The proof value is a current contract, not a UI workaround:

- Stale queue rows must be removed by authoritative lobby state changes.
- Accepted users must leave `queue` and appear in `admitted` only until the
  admitted browser consumes the handoff and joins the target room.
- Rejected or removed users must leave both `queue` and `admitted`.
- Waiting-room abort/cancel must clear pending lobby state and reset persisted
  invite state back to a non-pending state.
- Recovery must be websocket-driven through `lobby/snapshot`,
  `room/snapshot`, `room/snapshot/request`, and signed/synced snapshot backfill.
  The inspected branches did not contain a separate lobby delta protocol; the
  "delta recovery" value is represented by changed snapshot signatures and
  periodic/requested snapshot backfill.
- No manual refresh or reload control should be added for lobby state.

## Current Code Coverage

The current base already carries the runtime mechanics that the cleanup branches
were trying to prove:

- `demo/video-chat/backend-king-php/domain/realtime/realtime_lobby.php`
  - `lobby/queue/join` returns `already_admitted` when the user already has an
    admitted handoff, so a stale retry cannot recreate a queue row.
  - `lobby/queue/cancel` removes the user from both `queued_by_user` and
    `admitted_by_user`, broadcasts `cancelled`, and prunes empty room state.
  - `lobby/allow` removes the queued row before creating the admitted handoff.
  - `lobby/remove`, including normalized `lobby/reject` and `lobby/kick`,
    removes both queued and admitted state and prunes empty room state.
  - `videochat_lobby_clear_for_connection()` clears queued state on the final
    waiting connection leaving, while preserving an admitted waiting-room
    handoff long enough for the browser to reconnect into the call.
  - `videochat_lobby_remove_user_from_room()` clears both queued and admitted
    state when the admitted user joins and consumes the handoff.

- `demo/video-chat/backend-king-php/domain/realtime/realtime_lobby_sync.php`
  - DB sync reconstructs queue state from `pending` participants and admitted
    handoffs from allowed/accepted internal participants that have not joined.
  - Empty synced room state is pruned.
  - Snapshot signatures are used to suppress unchanged backfill sends.

- `demo/video-chat/backend-king-php/http/module_realtime_websocket.php`
  - Websocket attach sends a synced `lobby/snapshot` immediately.
  - The loop periodically sends synced lobby and room snapshots when signatures
    change.
  - `room/snapshot/request` returns both room and lobby snapshots, so reconnect
    and explicit backfill stay transport-native.
  - When an admitted waiting-room connection joins its pending room, the
    consumed handoff is removed and `admission_consumed` is broadcast.

- `demo/video-chat/backend-king-php/http/module_realtime_websocket_commands.php`
  - Successful queue/cancel/admit/remove commands persist their database state,
    then resync lobby state from the database.
  - Rejection/removal marks matching participants `cancelled`.
  - Admission marks matching pending participants `allowed` and then sends
    targeted admitted snapshots.

- `demo/video-chat/frontend-vue/src/domain/realtime/workspace/callWorkspace/roomState.ts`
  - `applyLobbySnapshot()` deduplicates queue and admitted entries by user.
  - Admitted entries win over stale queued rows for the same user.
  - Fresh snapshots clear stale per-row allow/remove pending UI state.
  - A snapshot for the desired pending room can trigger `sendRoomJoin()` for the
    admitted current user without a button-driven refresh.

- `demo/video-chat/frontend-vue/src/domain/realtime/workspace/callWorkspace/socketLifecycle.ts`
  - `lobby/snapshot` is applied directly.
  - Connect/open and recover paths request room snapshot backfill over the
    websocket.
  - Retryable websocket auth/backfill failures stay retryable instead of
    forcing reload/logout UI.

## Non-Ports

The source branches also modified `SPRINT.md`, package scripts, acceptance
matrix files, broad CI wiring, and Playwright E2E files. IAM5-05 explicitly
limits this lane to extraction documentation and an optional contract, so those
changes are intentionally not ported.

The proof branch backend contract is not copied into
`demo/video-chat/backend-king-php/tests/` because the write scope for this lane
does not include backend tests. Its assertions are extracted above and pinned
by the optional static contract in
`demo/video-chat/frontend-vue/tests/contract/call-access-lobby-state-cleanup-extract-contract.mjs`.

## Verification Intent

The relevant existing runtime proof remains:

- `demo/video-chat/backend-king-php/tests/realtime-lobby-contract.sh`
- `demo/video-chat/backend-king-php/tests/realtime-lobby-db-sync-contract.sh`
- `demo/video-chat/backend-king-php/tests/realtime-lobby-concurrency-contract.sh`
- `demo/video-chat/frontend-vue/tests/contract/call-access-lobby-concurrency-contract.mjs`
- `demo/video-chat/frontend-vue/tests/contract/call-access-realtime-scope-contract.mjs`
- `demo/video-chat/frontend-vue/tests/contract/realtime-reconnect-browser-contract.mjs`

IAM5-05 closes as a documentation extraction plus static guard. It does not add
a manual lobby refresh/reload control, and it does not weaken the websocket
snapshot/backfill contract.
