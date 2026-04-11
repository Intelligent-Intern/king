# King IIBIN WebSocket Demo

This demo ships as a responsive multi-user workspace for room chat and browser
video calls. The frontend and backend transport now bind to
`@intelligentintern/iibin` from `node_modules`.

What is wired today:

- login surface with persisted local session identity
- room directory with create/join/switch behavior
- invite-code create/redeem flow per room
- multi-user room chat over websocket fanout
- browser video call signaling (`offer`/`answer`/`ice`) with peer tiles
- responsive shell layout with slide transitions for chat/call stage switching

Current boundaries:

- demo-local signaling backend (`dev-backend.mjs`)
- login/user directory is persisted in SQLite (`KING_DEMO_DB_PATH`)
- no durable room/message persistence across backend restart
- no TURN relay setup (STUN-only by default)
- no production moderation/audit policy

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
cd demo/video-chat
npm install
npm run dev:backend
```

```bash
cd demo/video-chat
npm run dev
```

Useful commands:

- `npm run build`
- `npm run preview`
- `npm run type-check`
- `npm run test`

## Docker Compose (Frontend + Backend)

Run from `demo/video-chat`:

```bash
docker compose up --build
```

Default host ports:

- frontend: `http://127.0.0.1:5173`
- backend: `http://127.0.0.1:8080`

The backend persists user login records in a Docker volume:

- volume: `videochat-sqlite`
- database path in container: `/data/video-chat.sqlite`

Override versions/ports when needed:

```bash
IIBIN_SOURCE='1.0.5-beta' VIDEOCHAT_FRONTEND_PORT=3000 VIDEOCHAT_BACKEND_PORT=18080 docker compose up --build
```

## Runtime Notes

- the local backend listens on `http://127.0.0.1:8080` by default
- Vite proxies `/api` and `/ws` to that backend
- health endpoint: `GET /health`
- auth endpoint: `POST /api/auth/login`
- user directory endpoint: `GET /api/users`
- signaling endpoint: `WS /ws?userId=<id>&name=<display>&color=<hex>&room=<roomId>`

## Scope

This directory is a frontend demo surface, not the source of truth for King v1
runtime guarantees. Repo-level runtime and transport contracts stay in the
extension tests and root documentation.
