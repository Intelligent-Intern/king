# Video Chat Backend (King PHP)

This directory is the active King-based backend track for video-chat.

## What this scaffold provides now

- starts with the King extension explicitly loaded (from `KING_EXTENSION_PATH` or `php.ini`)
- boots an on-wire King HTTP/1 listener loop via `king_http1_server_listen_once()`
- exposes HTTP bootstrap and health endpoints
- exposes a WebSocket upgrade endpoint scaffold
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
- `WS /ws`

Default bind:

- host: `127.0.0.1`
- port: `18080`

Environment overrides:

- `VIDEOCHAT_KING_HOST` (default `127.0.0.1`)
- `VIDEOCHAT_KING_PORT` (default `18080`)
- `VIDEOCHAT_KING_WS_PATH` (default `/ws`)
- `VIDEOCHAT_KING_DB_PATH` (default `/data/video-chat.sqlite`)
- `KING_EXTENSION_PATH` (default `extension/modules/king.so` from repo root)
- `PHP_BIN` (default `php`)

## Quick check

```bash
curl -s http://127.0.0.1:18080/health
```

API and realtime contracts are expanded in subsequent V1 leaves.
