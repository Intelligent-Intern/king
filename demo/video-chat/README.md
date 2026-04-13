# King IIBIN WebSocket Demo

This demo ships as a responsive multi-user workspace for room chat and browser
video calls. The frontend and backend transport now bind to
`@intelligentintern/iibin` from `node_modules`.

Legacy baseline capabilities (historical reference only):

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
- typing indicators are room-scoped, exclude self display, and auto-expire on idle timeout windows
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

## Runtime Migration Status

The active development target is the new stack:

- `demo/video-chat/backend-king-php`
- `demo/video-chat/frontend-vue`

The older Node-based demo runtime stays in the repository only as historical
reference and is not the active development path.

Launch new scaffold:

```bash
cd demo/video-chat/backend-king-php
./run-dev.sh
```

```bash
cd demo/video-chat/frontend-vue
npm run dev
```

Legacy runtime boundaries (historical reference only):

- demo-local signaling backend (`backend/dev-backend.mjs`)
- login/user directory is persisted in SQLite (`KING_DEMO_DB_PATH`)
- websocket signaling identity is query-param based (`userId`, `name`, `color`, `room`) and not a hardened authn/authz model
- websocket identity now requires the login-issued session token; `userId` without a matching token is rejected
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

Scaffold sanity checks:

```bash
bash -n demo/video-chat/backend-king-php/run-dev.sh
php -l demo/video-chat/backend-king-php/public/index.php
node --check demo/video-chat/frontend-vue/scripts/dev-server.mjs
```

Current scaffold scope:

- new backend entrypoint boots with King extension loaded and starts an on-wire HTTP/1 + WebSocket scaffold listener
- new frontend entrypoint serves the bootstrap shell
- full API/WS parity is implemented in follow-up V1 issues

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

SQLite data is persisted in a mounted Docker volume:

- volume: `videochat-v1-sqlite`
- path in backend container: `/data/video-chat.sqlite`

## Runtime Notes

- backend scaffold endpoint: `GET http://127.0.0.1:18080/`
- backend health endpoint: `GET http://127.0.0.1:18080/health`
- backend websocket endpoint: `WS ws://127.0.0.1:18080/ws`
- frontend scaffold endpoint: `http://127.0.0.1:5174`
- the previous Node runtime remains in-repo only as historical reference, not as active dev path

## Scope

This directory is a demo application surface (frontend + backend), not the
source of truth for King v1 runtime guarantees. Repo-level runtime and
transport contracts stay in the extension tests and root documentation.
