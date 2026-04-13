# Video Chat Backend (King PHP)

This directory is the active King-based backend track for video-chat.

## What this scaffold provides now

- starts with the King extension explicitly loaded (from `KING_EXTENSION_PATH` or `php.ini`)
- boots an on-wire King HTTP/1 listener loop via `king_http1_server_listen_once()`
- exposes HTTP bootstrap and health endpoints
- exposes a WebSocket upgrade endpoint scaffold
- runs deterministic SQLite schema migrations on startup (idempotent across restarts)
- logs bound HTTP/WS addresses at startup
- keeps shutdown logging on process exit

## Run locally

```bash
cd demo/video-chat/backend-king-php
./run-dev.sh
```

## Endpoints

- `GET /` or `GET /api/bootstrap`
- `GET /health`
- `GET /api/runtime`
- `GET /api/version`
- `POST /api/auth/login`
- `WS /ws`

Default bind:

- host: `127.0.0.1`
- port: `18080`

Environment overrides:

- `VIDEOCHAT_KING_HOST` (default `127.0.0.1`)
- `VIDEOCHAT_KING_PORT` (default `18080`)
- `VIDEOCHAT_KING_WS_PATH` (default `/ws`)
- `VIDEOCHAT_KING_DB_PATH` (default local run: `demo/video-chat/backend-king-php/.local/video-chat.sqlite`; docker compose sets `/data/video-chat.sqlite`)
- `VIDEOCHAT_KING_BACKEND_VERSION` (default `1.0.6-beta`)
- `VIDEOCHAT_KING_ENV` (default `development`)
- `VIDEOCHAT_SESSION_TTL_SECONDS` (default `43200`, min `60`, max `2592000`)
- `VIDEOCHAT_DEMO_ADMIN_EMAIL` (default `admin@intelligent-intern.com`)
- `VIDEOCHAT_DEMO_ADMIN_PASSWORD` (default `admin123`)
- `VIDEOCHAT_DEMO_USER_EMAIL` (default `user@intelligent-intern.com`)
- `VIDEOCHAT_DEMO_USER_PASSWORD` (default `user123`)
- `KING_EXTENSION_PATH` (default `extension/modules/king.so` from repo root)
- `PHP_BIN` (default `php`)

## Quick check

```bash
curl -s http://127.0.0.1:18080/health
```

## Schema bootstrap

Startup applies ordered SQLite migrations via `database.php` and records them in
`schema_migrations`.

Current schema coverage includes:

- `roles`
- `users`
- `sessions`
- `rooms`
- `room_memberships`
- `calls`
- `call_participants`
- `invite_codes`

`/health` and `/api/runtime` now include the database migration/runtime
snapshot (schema version, migration counts, and table inventory).

API and realtime contracts are expanded in subsequent V1 leaves.

## Login contract

`POST /api/auth/login` expects JSON:

```json
{
  "email": "admin@intelligent-intern.com",
  "password": "admin123"
}
```

Success response returns:

- `status: "ok"`
- `session` envelope (`id`, `token`, `issued_at`, `expires_at`, `expires_in_seconds`)
- `user` envelope (`id`, `email`, `display_name`, `role`, `status`, prefs)

Failures use the same error envelope shape:

```json
{
  "status": "error",
  "error": {
    "code": "auth_invalid_credentials",
    "message": "Invalid email or password."
  },
  "time": "..."
}
```
