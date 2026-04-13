# King Video Call Demo (Active Replatform Track)

This directory is the active build-out of a production-grade video-call
workspace on top of King runtime primitives.

What we are building here:

- a Vue workspace UI that matches the approved mock layout/interaction model
- a King PHP backend (`backend-king-php`) as the authoritative API + WS layer
- contract-first REST + realtime behavior with deterministic error envelopes
- role-aware collaboration surfaces (admin/moderator/user)
- invite-driven call orchestration, room presence, chat, and signaling

Target architecture:

- frontend: `demo/video-chat/frontend-vue`
- backend: `demo/video-chat/backend-king-php`
- protocol catalog: `demo/video-chat/contracts/v1/api-ws-contract.catalog.json`
- transport payload format: `@intelligentintern/iibin` from `node_modules`

Documentation policy for this README:

- this is a living spec for the active video-call stack
- update it on every commit that changes behavior, contracts, runtime paths, or UX flow
- keep it aligned with `ISSUES.md`, `READYNESS_TRACKER.md`, and the active contract tests

Latest commit-level progress:

- 2026-04-13: closed `#47` with explicit `POST /api/invite-codes` endpoint contract coverage (`backend-king-php/tests/invite-code-create-endpoint-contract.sh`)
- 2026-04-13: closed `#48` with explicit `POST /api/invite-codes/redeem` endpoint contract coverage (`backend-king-php/tests/invite-code-redeem-endpoint-contract.sh`)
- 2026-04-13: closed `#49` with strict `WS /ws` handshake validation + structured close-descriptor contract coverage (`backend-king-php/tests/realtime-websocket-gateway-contract.sh`)
- 2026-04-13: closed `#50` with server-authoritative room presence snapshot + join/leave delta coverage (including room-change/reconnect phantom-user guards) in `backend-king-php/tests/realtime-presence-contract.sh`
- 2026-04-13: closed `#51` with room-scoped chat fanout hardening (sender-in-room enforcement, bounded malformed-payload rejection, stable message ids for dedupe, deterministic `chat/ack` ids) in `backend-king-php/tests/realtime-chat-contract.sh` + `backend-king-php/tests/contract-catalog-parity-contract.sh`
- 2026-04-13: closed `#52` with typing indicator hardening (room-scoped debounce + expiry, no self-echo, sender-in-room fail-closed validation, explicit `typing/stop` catalog parity) in `backend-king-php/tests/realtime-typing-contract.sh` + `backend-king-php/tests/contract-catalog-parity-contract.sh`

Current new-stack baseline capabilities:

- required login surface (display name) with persisted local session identity across reloads
- explicit sign-out lifecycle that tears down websocket reconnect loops, call peers, and local media tracks
- authenticated workspace gating so room/chat/call actions and local media init only run with a valid signed-in session
- room directory is fetched from backend API and normalized to deterministic ordering with live member counters
- room directory with create/join/switch behavior
- room creation submits to backend with optimistic list updates and automatic room-id conflict retries
- room switching applies explicit room-scoped reset boundaries for typing/call/ui draft state
- invite-code generation roundtrip for active room with context-panel code display
- invite-code redeem/join flow resolves target room id and switches active room on success
- invite-code copy action uses clipboard API first with a legacy copy fallback for non-secure contexts
- participant roster is sourced from live `room/snapshot` events with normalized ordering and live snapshot timestamp
- chat timeline is server-fanout driven with message normalization and room-local dedupe by message id
- chat sender acks now carry deterministic `ack_id` + stable `message_id` so client retries can dedupe without local echo drift
- typing indicators are room-scoped, exclude self display, auto-expire on idle timeout windows, and fail closed when sender room membership is invalid
- chat composer enforces bounded draft length and rejects empty/whitespace payloads before websocket send
- chat and roster timestamps are rendered in deterministic locale-independent UTC `HH:MM UTC` format
- pre-call local media preview is an explicit gate; join is enabled only after preview succeeds, with visible permission/device errors
- call join/leave lifecycle updates participant call presence immediately from room-scoped signaling and snapshot reconciliation
- peer connection ownership is managed by a dedicated remote-user-id keyed manager with deterministic release cleanup
- offer/answer negotiation is enforced as targeted peer-to-peer signaling (no broadcast fallback) for multi-peer rooms
- ICE candidate forwarding is targeted per peer and candidate payloads are normalized before safe client-side apply
- remote track tiles now attach/detach safely via lifecycle watchers and participant-based pruning to avoid stale remnants
- microphone toggles now mutate only local audio-track enabled state in place (no renegotiation churn)
- camera toggles now mutate only local video-track enabled state in place while preserving active peer connections
- room-switch, sign-out, and component-unmount boundaries now enforce full call teardown (peers + local media tracks)
- websocket reconnect now uses bounded exponential backoff and re-syncs room/call state on recovery
- multi-user room chat over websocket fanout
- browser video call signaling (`offer`/`answer`/`ice`) with peer tiles
- responsive shell layout with reduced-motion-safe slide transitions for chat/call stage switching
- shared UI token layer in `frontend/src/style.css` for color, spacing, border, radius, and elevation
- normalized typography and control sizing (inputs/buttons/headlines/body text) from one baseline scale

## Runtime Boundaries (Active vs Historical)

Active development and runtime path:

- `demo/video-chat/backend-king-php`
- `demo/video-chat/frontend-vue`

Launch active stack:

```bash
cd demo/video-chat/backend-king-php
./run-dev.sh
```

```bash
cd demo/video-chat/frontend-vue
npm run dev
```

Current demo caveats:

- login/user directory is persisted in SQLite (`KING_DEMO_DB_PATH`)
- no durable room/message persistence across backend restart
- no TURN relay setup (STUN-only by default)
- no production moderation/audit policy
- frontend runtime proxy may emit Node deprecation warnings from transitive proxy dependencies; behavior remains functional

## Repeated + Nested Frame Example

The same `/ws` path can carry repeated+nested control frames, not only one flat
chat record per message. A typical server-side IIBIN shape is:

```php
<?php

king_proto_define_schema('PeerState', [
    'peer_id' => ['tag' => 1, 'type' => 'string', 'required' => true],
    'tracks' => ['tag' => 2, 'type' => 'repeated_string'],
]);

king_proto_define_schema('RoomSyncEnvelope', [
    'room' => ['tag' => 1, 'type' => 'string', 'required' => true],
    'peers' => ['tag' => 2, 'type' => 'repeated_PeerState', 'required' => true],
    'ack_ids' => ['tag' => 3, 'type' => 'repeated_string'],
]);

$payload = king_proto_encode('RoomSyncEnvelope', [
    'room' => 'general',
    'peers' => [
        ['peer_id' => 'ada', 'tracks' => ['cam', 'mic']],
        ['peer_id' => 'lin', 'tracks' => ['mic']],
    ],
    'ack_ids' => ['req-42', 'req-43'],
]);

king_websocket_send($socket, $payload, true);
```

This is the same repeated+nested compatibility model documented in
[`documentation/iibin.md`](/home/jochen/projects/king.site/king/documentation/iibin.md):
older readers keep shared fields and ignore newly added fields.

## Commands

Run backend and frontend in separate terminals:

```bash
cd demo/video-chat/backend-king-php
./run-dev.sh
```

```bash
cd demo/video-chat/frontend-vue
npm run dev
```

Useful commands:

- `cd demo/video-chat/backend-king-php && ./run-dev.sh`
- `cd demo/video-chat/frontend-vue && npm run dev`
- `curl -s http://127.0.0.1:18080/`

## Verification Closure

Automated parity and smoke checks:

```bash
bash demo/video-chat/scripts/smoke.sh
```

`demo/video-chat/scripts/smoke.sh` now verifies:

- backend and frontend launchers plus syntax checks
- backend boot and live `/health` probe
- API/WS catalog drift gate against the canonical versioned contract fixture (`contract-catalog-parity-contract`)
- login route handshake (`/api/auth/login`), authenticated session read, and logout revoke path
- dedicated logout revoke contract (`session-logout-contract`) with persisted revocation metadata assertions
- room join/presence contract (`realtime-presence-contract`)
- room chat fanout contract (`realtime-chat-contract`)
- invite redeem contract (`invite-code-redeem-contract`)
- call signaling bootstrap contract (`realtime-signaling-contract`)
- frontend dev-server boot probe

Release-bound runtime honesty:

- active path is `backend-king-php` + `frontend-vue`
- historical Node runtime remains in-repo as reference only and is outside the active parity gate

## Docker Compose (Frontend + Backend)

New-stack compose file:

- `demo/video-chat/docker-compose.v1.yml`

Run from `demo/video-chat`:

```bash
docker compose -f docker-compose.v1.yml up --build
```

Default host ports:

- frontend: `http://127.0.0.1:5174`
- backend: `http://127.0.0.1:18080`

Override host ports when needed:

```bash
VIDEOCHAT_V1_FRONTEND_PORT=35174 VIDEOCHAT_V1_BACKEND_PORT=38080 docker compose -f docker-compose.v1.yml up --build
```

Optional frontend preflight origin override for non-default routing:

```bash
VIDEOCHAT_V1_BACKEND_ORIGIN=http://127.0.0.1:38080 docker compose -f docker-compose.v1.yml up --build
```

SQLite data is persisted in a mounted Docker volume:

- volume: `videochat-v1-sqlite`
- path in backend container: `/data/video-chat.sqlite`

## Runtime Notes

- backend scaffold endpoint: `GET http://127.0.0.1:18080/`
- backend health endpoint: `GET http://127.0.0.1:18080/health`
- backend runtime preflight endpoint: `GET http://127.0.0.1:18080/api/runtime`
- backend version endpoint: `GET http://127.0.0.1:18080/api/version`
- backend login endpoint: `POST http://127.0.0.1:18080/api/auth/login`
- backend session endpoint: `GET http://127.0.0.1:18080/api/auth/session` (requires token)
- backend logout endpoint: `POST http://127.0.0.1:18080/api/auth/logout` (requires token, revokes session + closes session websocket connections)
- backend RBAC admin probe: `GET http://127.0.0.1:18080/api/admin/ping` (admin only)
- backend admin users list: `GET http://127.0.0.1:18080/api/admin/users?query=&page=1&page_size=10` (admin only)
- backend admin user create: `POST http://127.0.0.1:18080/api/admin/users` (admin only)
- backend admin user update: `PATCH http://127.0.0.1:18080/api/admin/users/{id}` (admin only)
- backend admin user deactivate: `POST http://127.0.0.1:18080/api/admin/users/{id}/deactivate` (admin only)
- backend RBAC moderation probe: `GET http://127.0.0.1:18080/api/moderation/ping` (admin/moderator)
- backend RBAC user probe: `GET http://127.0.0.1:18080/api/user/ping` (authenticated admin/moderator/user)
- backend user settings read: `GET http://127.0.0.1:18080/api/user/settings` (authenticated admin/moderator/user)
- backend user settings update: `PATCH http://127.0.0.1:18080/api/user/settings` (authenticated admin/moderator/user)
- backend avatar upload: `POST http://127.0.0.1:18080/api/user/avatar` (authenticated admin/moderator/user)
- backend avatar file read: `GET http://127.0.0.1:18080/api/user/avatar-files/{filename}` (authenticated admin/moderator/user)
- backend calls list: `GET http://127.0.0.1:18080/api/calls?scope=my&status=all&query=&page=1&page_size=10` (authenticated admin/moderator/user)
- backend call create: `POST http://127.0.0.1:18080/api/calls` (authenticated admin/moderator/user)
- backend call update: `PATCH http://127.0.0.1:18080/api/calls/{id}` (authenticated admin/moderator/user; owner/admin/moderator policy)
- backend call cancel: `POST http://127.0.0.1:18080/api/calls/{id}/cancel` (authenticated admin/moderator/user; owner/admin/moderator policy)
- backend websocket endpoint: `WS ws://127.0.0.1:18080/ws`
- backend startup applies ordered sqlite migrations (`schema_migrations`) and exposes migration state in runtime/health responses
- frontend scaffold endpoint: `http://127.0.0.1:5174`
- frontend consumes backend preflight metadata on startup (`app/version + runtime health`)
- the previous Node runtime remains in-repo only as historical reference, not as active dev path

## Scope

This directory is a demo application surface (frontend + backend), not the
source of truth for King v1 runtime guarantees. Repo-level runtime and
transport contracts stay in the extension tests and root documentation.
