# IAM7-20 Owner Absence Realtime Sync

Date: 2026-05-10

Scope: focused extraction from `local/iam-e2e-owner-absence-realtime-sync` at
`72dd4d81d1e8431e940808890f232b78cf6f8a70`. The historical branch was not
merged wholesale; its broad stale IAM/UI/script changes were rejected.

## Extracted Runtime Value

- Realtime presence rows now retain stale heartbeat evidence long enough for
  the 15-minute owner-absence window.
- Room snapshots publish `call_lifecycle.owner_absence` from current server
  state and include it in the snapshot signature.
- Stale owner heartbeat expiry materializes the owner's `left_at` server-side,
  drops stale local owner participants from snapshots, and downgrades stale
  owner viewer controls fail-closed.
- Lobby snapshots and lobby moderation commands revalidate against current DB
  role/presence state before exposing moderator queue data or accepting owner
  commands.
- Owner return through active server presence cancels monitoring and restores
  owner authority from current state.
- Owner absence timeout persists `calls.status = ended`, marks joined
  participants left, disables call-access links, revokes call-access sessions,
  clears realtime presence, and returns only redacted counts.

## Proof

- Backend contract:
  `demo/video-chat/backend-king-php/tests/call-access-owner-absence-realtime-sync-contract.php`
- Contract wrapper:
  `demo/video-chat/backend-king-php/tests/call-access-owner-absence-realtime-sync-contract.sh`
- Frontend/static gate:
  `demo/video-chat/frontend-vue/tests/contract/iam-owner-absence-realtime-sync-contract.mjs`
