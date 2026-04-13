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
- `GET /api/auth/session` (requires session token)
- `POST /api/auth/logout` (requires session token)
- `GET /api/calls` (requires authenticated `admin`/`moderator`/`user` role)
- `POST /api/calls` (requires authenticated `admin`/`moderator`/`user` role)
- `PATCH /api/calls/{id}` (requires authenticated `admin`/`moderator`/`user` role, owner/admin/moderator policy)
- `POST /api/calls/{id}/cancel` (requires authenticated `admin`/`moderator`/`user` role, owner/admin/moderator policy)
- `POST /api/invite-codes` (requires authenticated `admin`/`moderator`/`user` role; call scope requires owner/admin/moderator policy)
- `POST /api/invite-codes/redeem` (requires authenticated `admin`/`moderator`/`user` role)
- `GET /api/admin/ping` (requires `admin` role)
- `GET /api/admin/users` (requires `admin` role)
- `POST /api/admin/users` (requires `admin` role)
- `PATCH /api/admin/users/{id}` (requires `admin` role)
- `POST /api/admin/users/{id}/deactivate` (requires `admin` role)
- `GET /api/moderation/ping` (requires `admin` or `moderator` role)
- `GET /api/user/ping` (requires authenticated `admin`/`moderator`/`user` role)
- `GET /api/user/settings` (requires authenticated `admin`/`moderator`/`user` role)
- `PATCH /api/user/settings` (requires authenticated `admin`/`moderator`/`user` role)
- `POST /api/user/avatar` (requires authenticated `admin`/`moderator`/`user` role)
- `GET /api/user/avatar-files/{filename}` (requires authenticated `admin`/`moderator`/`user` role)
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
- `VIDEOCHAT_AVATAR_STORAGE_ROOT` (default `dirname(VIDEOCHAT_KING_DB_PATH)/avatars`)
- `VIDEOCHAT_AVATAR_MAX_BYTES` (default `5242880`, clamped to `64KB..10MB`)
- `VIDEOCHAT_INVITE_CALL_TTL_SECONDS` (default `21600`, clamped to `300..2592000`)
- `VIDEOCHAT_INVITE_ROOM_TTL_SECONDS` (default `86400`, clamped to `300..2592000`)
- `KING_EXTENSION_PATH` (default `extension/modules/king.so` from repo root)
- `PHP_BIN` (default `php`)

## Quick check

```bash
curl -s http://127.0.0.1:18080/health
```

Session check using login token:

```bash
TOKEN="$(curl -sS -X POST http://127.0.0.1:18080/api/auth/login \
  -H 'content-type: application/json' \
  -d '{"email":"admin@intelligent-intern.com","password":"admin123"}' | jq -r '.session.token')"
curl -sS http://127.0.0.1:18080/api/auth/session -H "authorization: Bearer ${TOKEN}"
curl -sS -X POST http://127.0.0.1:18080/api/auth/logout -H "authorization: Bearer ${TOKEN}"
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

`GET /api/auth/session` and every non-public `/api/*` path require a valid
session token (`Authorization: Bearer ...` or `X-Session-Id: ...`).

`POST /api/auth/logout` revokes the current session token and closes every
tracked active websocket connection that belongs to that session.

RBAC-protected endpoints fail closed with typed `403` errors:

- `code`: `rbac_forbidden` for REST
- `code`: `websocket_forbidden` for websocket upgrade
- details include `reason`, `role`, `allowed_roles`, and `path`

`GET /api/admin/users` query contract:

- `query` (optional, aliases: `q`)
- `page` (integer, default `1`)
- `page_size` (integer `1..100`, default `10`)

Response includes:

- `users[]`
- `pagination` (`query`, `page`, `page_size`, `total`, `page_count`, `returned`, `has_prev`, `has_next`)
- deterministic `sort` metadata

`POST /api/admin/users` + `PATCH /api/admin/users/{id}` mutation contract:

- validation failures: `422 admin_user_validation_failed` with `error.details.fields`
- duplicate email: `409 admin_user_conflict` with `error.details.fields.email = already_exists`
- missing target user (update/deactivate): `404 admin_user_not_found`
- success: `result.user` with normalized role/status/profile fields

`GET /api/user/settings` + `PATCH /api/user/settings` contract:

- managed fields: `display_name`, `avatar_path`, `time_format`, `theme`
- validation failures: `422 user_settings_validation_failed` with `error.details.fields`
- missing authenticated user row: `404 user_not_found`
- success: `settings` plus normalized `user` envelope

`POST /api/user/avatar` contract:

- accepted payload formats:
  - `{"content_type":"image/png|image/jpeg|image/webp","content_base64":"..."}`
  - `{"data_url":"data:image/png;base64,..."}`
- validation: strict base64 decode, binary signature check, max-size enforcement
- safe storage: generated file names only, fixed storage root, previous avatar cleanup
- failure envelope: `422 user_avatar_validation_failed` with `error.details.fields`
- success: `201`, with `result.avatar_path`, `result.content_type`, `result.bytes`

`GET /api/calls` query contract:

- `scope`: `my` or `all` (non-admin/moderator callers requesting `all` are safely downgraded to `my`)
- `status`: `all|scheduled|active|ended|cancelled`
- `query` (alias: `q`) for title search
- `page` / `page_size` (`1..100`)

Response includes:

- `calls[]` with owner envelope and participant totals
- `filters` (requested + effective scope)
- `pagination` (`page`, `page_size`, `total`, `page_count`, `returned`, `has_prev`, `has_next`)
- deterministic `sort` metadata

`POST /api/calls` create contract:

- required fields: `title`, `starts_at`, `ends_at`
- optional fields:
  - `room_id` (default `lobby`)
  - `internal_participant_user_ids` (array of active user ids)
  - `external_participants` (`[{email, display_name}]`)
- owner is always included as internal participant mapping
- validation failures: `422 calls_create_validation_failed` with `error.details.fields`
- success: `201`, with `result.call` containing normalized owner + participants + totals

`PATCH /api/calls/{id}` update contract:

- editable fields: `room_id`, `title`, `starts_at`, `ends_at`, `internal_participant_user_ids`, `external_participants`
- authorization: call owner, admin, or moderator
- immutable statuses: `cancelled` and `ended` reject edits (`status: immutable_for_edit`)
- global invite resend is not triggered by edit calls
- explicit invite resend request flags are rejected in update payload
- success returns `invite_dispatch.global_resend_triggered = false` and `invite_dispatch.explicit_action_required = true`

`POST /api/calls/{id}/cancel` cancel contract:

- required payload fields: `cancel_reason`, `cancel_message`
- explicit status transition support: `scheduled|active -> cancelled`
- disallowed transitions return `409 calls_cancel_state_conflict`
- cancellation payload fields are persisted on the call (`cancel_reason`, `cancel_message`, `cancelled_at`)
- cancellation updates participant join state to cancelled for downstream notification/reconciliation workflows
- cancelled calls are excluded from active-join semantics (`my_participation = false`)

`POST /api/invite-codes` create contract:

- required fields:
  - `scope`: `room` or `call`
  - `room` scope: `room_id`
  - `call` scope: `call_id`
- expiry policy is server-managed and deterministic by scope:
  - `call`: `VIDEOCHAT_INVITE_CALL_TTL_SECONDS` (default `21600`)
  - `room`: `VIDEOCHAT_INVITE_ROOM_TTL_SECONDS` (default `86400`)
- client-provided expiry overrides are rejected (`expires_at`, `expires_in_seconds`)
- code/id generation uses UUID-v4 values and retries on uniqueness collisions
- call-scope authorization: owner/admin/moderator only
- success: `201`, with `result.invite_code` containing context binding (`scope`, `room_id|call_id`), expiry metadata, and policy metadata

`POST /api/invite-codes/redeem` contract:

- required fields:
  - `code`: UUID-v4 invite code (case-insensitive)
- redeem policies:
  - invite must exist
  - invite must not be expired
  - invite must not exceed `max_redemptions`
  - resolved destination must still be joinable (room active / call not cancelled-or-ended)
- typed failures:
  - `422 invite_codes_redeem_validation_failed`
  - `404 invite_codes_redeem_not_found`
  - `410 invite_codes_redeem_expired`
  - `409 invite_codes_redeem_exhausted`
  - `409 invite_codes_redeem_conflict`
- success: `200`, with `result.redemption` containing:
  - `invite_code` (normalized persisted state + redemption counters)
  - `join_context` (`scope`, resolved room/call context, `request_user`)
  - `redeemed_at`

`WS /ws` also requires a valid session token (Bearer/X-Session-Id header or
query `?session=<token>`/`?token=<token>` for browser handshake compatibility).

## Contract checks

Run the auth contract test (REST + websocket token validation coverage):

```bash
demo/video-chat/backend-king-php/tests/session-auth-contract.sh
```

Run the admin user list contract test (search + pagination + deterministic sorting):

```bash
demo/video-chat/backend-king-php/tests/admin-user-list-contract.sh
```

Run the admin user mutation contract test (create/update/deactivate + validation/conflict semantics):

```bash
demo/video-chat/backend-king-php/tests/admin-user-mutation-contract.sh
```

Run the user settings contract test (settings persistence + reauth/session reload semantics):

```bash
demo/video-chat/backend-king-php/tests/user-settings-contract.sh
```

Run the avatar upload contract test (type/size validation + safe storage path handling):

```bash
demo/video-chat/backend-king-php/tests/avatar-upload-contract.sh
```

Run the calls list contract test (my/all scope + search/status filters + deterministic pagination):

```bash
demo/video-chat/backend-king-php/tests/calls-list-contract.sh
```

Run the call create contract test (create payload validation + participant persistence + normalized response):

```bash
demo/video-chat/backend-king-php/tests/call-create-contract.sh
```

Run the call update contract test (schedule/participant updates + no implicit invite resend):

```bash
demo/video-chat/backend-king-php/tests/call-update-contract.sh
```

Run the call cancel contract test (state transition + cancellation payload persistence + active-join exclusion):

```bash
demo/video-chat/backend-king-php/tests/call-cancel-contract.sh
```

Run the invite-code create contract test (UUID-backed uniqueness + scope binding + deterministic expiry policy):

```bash
demo/video-chat/backend-king-php/tests/invite-code-create-contract.sh
```

Run the invite-code redeem contract test (expiry + usage-limit enforcement + typed join context):

```bash
demo/video-chat/backend-king-php/tests/invite-code-redeem-contract.sh
```
