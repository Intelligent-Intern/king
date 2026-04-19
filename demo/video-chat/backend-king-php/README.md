# Video Chat Backend (King PHP)

This directory is the active King-based backend track for video-chat.

## What this scaffold provides now

- starts with the King extension explicitly loaded (from `KING_EXTENSION_PATH` or `php.ini`)
- enables `king.security_allow_config_override=1` for this demo backend process so the runtime can initialize its local Object Store root explicitly
- boots an on-wire King HTTP/1 listener loop via `king_http1_server_listen_once()`
- exposes HTTP bootstrap and health endpoints
- exposes a WebSocket upgrade endpoint scaffold
- runs deterministic SQLite schema migrations on startup (idempotent across restarts)
- logs bound HTTP/WS addresses at startup
- keeps shutdown logging on process exit

## Route/Realtime modules

`server.php` now only wires runtime state + the deterministic dispatcher in
`http/router.php`.

The active route/realtime modules are registered in fixed order:

1. `runtime` (`http/module_runtime.php`)
2. `auth_session` (`http/module_auth_session.php`)
3. `users` (`http/module_users.php`)
4. `invites` (`http/module_invites.php`)
5. `calls` (`http/module_calls.php`)
6. `realtime` (`http/module_realtime.php`)

Contract check:

- `tests/router-module-order-contract.sh`

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
- `GET /api/auth/session-state` (soft session probe; never returns 401 for stale/missing tokens)
- `GET /api/auth/session` (requires session token)
- `POST /api/auth/refresh` (requires session token, rotates/replaces token)
- `POST /api/auth/logout` (requires session token)
- `GET /api/calls` (requires authenticated `admin`/`moderator`/`user` role)
- `GET /api/calls/resolve/{ref}` (soft route resolver for call/access ids; not-found is a `200` state)
- `POST /api/calls` (requires authenticated `admin`/`moderator`/`user` role)
- `PATCH /api/calls/{id}` (requires authenticated `admin`/`moderator`/`user` role, owner/admin/moderator policy)
- `DELETE /api/calls/{id}` (requires authenticated `admin`/`moderator`/`user` role, owner/admin/moderator policy)
- `POST /api/calls/{id}/cancel` (requires authenticated `admin`/`moderator`/`user` role, owner/admin/moderator policy)
- `POST /api/invite-codes` (requires authenticated `admin`/`moderator`/`user` role; call scope requires owner/admin/moderator policy)
- `POST /api/invite-codes/redeem` (requires authenticated `admin`/`moderator`/`user` role)
- `GET /api/admin/ping` (requires `admin` role)
- `GET /api/admin/users` (requires `admin` role)
- `POST /api/admin/users` (requires `admin` role)
- `PATCH /api/admin/users/{id}` (requires `admin` role)
- `POST /api/admin/users/{id}/deactivate` (requires `admin` role)
- `POST /api/admin/users/{id}/reactivate` (requires `admin` role)
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
- `VIDEOCHAT_KING_WORKERS` (optional shared fallback worker count, clamped `1..64`)
- `VIDEOCHAT_KING_HTTP_WORKERS` (default `VIDEOCHAT_KING_WORKERS`, otherwise `24`, clamped `1..64`)
- `VIDEOCHAT_KING_WS_WORKERS` (default `VIDEOCHAT_KING_WORKERS`, otherwise `8`, clamped `1..64`)
- `VIDEOCHAT_SESSION_TTL_SECONDS` (default `43200`, min `60`, max `2592000`)
- `VIDEOCHAT_DEMO_ADMIN_EMAIL` (default `admin@intelligent-intern.com`)
- `VIDEOCHAT_DEMO_ADMIN_PASSWORD` (default `admin123`)
- `VIDEOCHAT_DEMO_MODERATOR_EMAIL` (default `moderator@intelligent-intern.com`)
- `VIDEOCHAT_DEMO_MODERATOR_PASSWORD` (default `moderator123`)
- `VIDEOCHAT_DEMO_USER_EMAIL` (default `user@intelligent-intern.com`)
- `VIDEOCHAT_DEMO_USER_PASSWORD` (default `user123`)
- `VIDEOCHAT_DEMO_SEED_CALLS` (default `0`; `run-dev.sh` and `docker-compose.v1.yml` set default `1`)
- `VIDEOCHAT_AVATAR_STORAGE_ROOT` (default `dirname(VIDEOCHAT_KING_DB_PATH)/avatars`)
- `VIDEOCHAT_AVATAR_MAX_BYTES` (default `5242880`, clamped to `64KB..10MB`)
- `VIDEOCHAT_INVITE_CALL_TTL_SECONDS` (default `21600`, clamped to `300..2592000`)
- `VIDEOCHAT_INVITE_ROOM_TTL_SECONDS` (default `86400`, clamped to `300..2592000`)
- `VIDEOCHAT_WS_CHAT_MAX_CHARS` (default `2000`, clamped to `1..8000`)
- `VIDEOCHAT_WS_CHAT_MAX_BYTES` (default `8192`, clamped to `64..65536`)
- `VIDEOCHAT_WS_TYPING_DEBOUNCE_MS` (default `500`, clamped to `100..5000`)
- `VIDEOCHAT_WS_TYPING_EXPIRY_MS` (default `3000`, clamped to `500..15000`)
- `VIDEOCHAT_WS_REACTION_ALLOWED_EMOJIS` (default fixed tray emoji set; comma-separated override)
- `VIDEOCHAT_WS_REACTION_MAX_CHARS` (default `8`, clamped to `1..32`)
- `VIDEOCHAT_WS_REACTION_MAX_BYTES` (default `32`, clamped to `4..256`)
- `VIDEOCHAT_WS_REACTION_FLOOD_WINDOW_MS` (default `1000`, clamped to `250..60000`; fallback: `VIDEOCHAT_WS_REACTION_THROTTLE_WINDOW_MS`)
- `VIDEOCHAT_WS_REACTION_FLOOD_THRESHOLD_PER_WINDOW` (default `20`, clamped to `1..1000`; fallback: `VIDEOCHAT_WS_REACTION_THROTTLE_MAX_PER_WINDOW`)
- `VIDEOCHAT_WS_REACTION_FLOOD_BATCH_SIZE` (default `25`, clamped to `1..200`)
- `VIDEOCHAT_WS_REACTION_CLIENT_BATCH_MAX_COUNT` (default `25`, clamped to `1..200`)
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
ROTATED_TOKEN="$(curl -sS -X POST http://127.0.0.1:18080/api/auth/refresh -H "authorization: Bearer ${TOKEN}" | jq -r '.session.token')"
curl -sS http://127.0.0.1:18080/api/auth/session -H "authorization: Bearer ${ROTATED_TOKEN}"
curl -sS -X POST http://127.0.0.1:18080/api/auth/logout -H "authorization: Bearer ${ROTATED_TOKEN}"
```

## Integration Matrix Tests

Backend integration-matrix coverage for auth/session/rbac/calls/invites/realtime:

```bash
bash demo/video-chat/backend-king-php/tests/videochat-integration-matrix-http-contract.sh
bash demo/video-chat/backend-king-php/tests/videochat-integration-matrix-realtime-contract.sh
```

WLVC wire-envelope contract coverage (codec frame packaging/parsing contract):

```bash
bash demo/video-chat/backend-king-php/tests/wlvc-wire-contract.sh
```

Notes:

- the wrappers fail closed when contracts regress
- when `pdo_sqlite` is unavailable in the current PHP runtime, these wrappers report `SKIP` explicitly

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

`call_participants.invite_state` is the persistent call-admission state:
`invited` for assigned users, `pending` after the user clicks `Join call` in the
join modal, `allowed` after an owner/moderator admits the pending user, and
`declined`/`cancelled` for explicit non-join outcomes. Legacy `accepted` rows are
migrated as allowed admission.

`/health` and `/api/runtime` now include the database migration/runtime
snapshot (schema version, migration counts, table inventory, seeded demo users,
and seeded demo calls when enabled).

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
`GET /api/auth/session-state` is the browser recovery probe for stale local
tokens: it returns `200` with `result.state = authenticated|unauthenticated`
so route guards can clear invalid local state without producing console 401s.

`POST /api/auth/refresh` rotates the current session token atomically, returns a
replacement token, and invalidates the replaced token so stale replay attempts
fail closed with typed conflict semantics.

`POST /api/auth/logout` revokes the current session token and closes every
tracked active websocket connection that belongs to that session.

RBAC-protected endpoints fail closed with typed `403` errors:

- `code`: `rbac_forbidden` for REST
- `code`: `websocket_forbidden` for websocket upgrade
- details include `reason`, `rule_id`, `role`, `allowed_roles`, and `path`
- the permission matrix is explicit and transport-aware (`rest_auth_session`, `rest_admin_scope`, `rest_moderation_scope`, `rest_calls_collection`, `rest_calls_items`, `rest_invite_codes_collection`, `rest_invite_codes_items`, `rest_user_scope`, `websocket_gateway`)

`GET /api/admin/users` query contract:

- `query` (optional, aliases: `q`)
- `order` (`role_then_name_asc` or `role_then_name_desc`, default `role_then_name_asc`)
- `page` (integer, default `1`)
- `page_size` (integer `1..100`, default `10`)

Response includes:

- `users[]`
- `pagination` (`query`, `order`, `page`, `page_size`, `total`, `page_count`, `returned`, `has_prev`, `has_next`)
- deterministic `sort` metadata (`role_priority`, `secondary`, `tie_breaker`)

`POST /api/admin/users` + `PATCH /api/admin/users/{id}` mutation contract:

- create payload role is optional; omitted role defaults to `user`
- validation failures: `422 admin_user_validation_failed` with `error.details.fields`
- update payload rejects unsupported fields fail-closed (`field_not_updatable`)
- duplicate email: `409 admin_user_conflict` with `error.details.fields.email = already_exists`
- deactivate/reactivate are idempotent (`deactivated`/`already_disabled`, `reactivated`/`already_active`)
- reactivating a user does not reinstate previously revoked sessions
- missing target user (update/deactivate/reactivate): `404 admin_user_not_found`
- success: `result.user` with normalized role/status/profile fields

`GET /api/user/settings` + `PATCH /api/user/settings` contract:

- managed fields: `display_name`, `avatar_path`, `time_format`, `date_format`, `theme`
- unsupported fields fail closed with `field_not_updatable`
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
  - `room_id` (compatibility input only; create always allocates a private room with `room_id = call.id`)
  - `internal_participant_user_ids` (array of active user ids)
  - `external_participants` (`[{email, display_name}]`)
- owner is always included as internal participant mapping
- persisted calls never default to the shared `lobby`; each call workspace gets its own generated UUID room
- validation failures: `422 calls_create_validation_failed` with `error.details.fields`
- success: `201`, with `result.call` containing normalized owner + participants + totals

`GET /api/calls/resolve/{ref}` route resolution contract:

- requires an authenticated session
- resolves UUID-like refs as call id first, then access-link id
- returns `200` with `result.state = resolved|not_found|expired|forbidden`
- `result.resolved_as` is `call_id`, `access_id`, or empty for not found
- intended for browser route guards so normal stale-link navigation does not emit console 404s

Public call-access join/session contract:

- `GET /api/call-access/{access_id}/join` is public and resolves only valid, unexpired, joinable access links.
- `POST /api/call-access/{access_id}/session` is public and issues a normal session token for the linked user or a newly created guest user for open links.
- Open links require a valid `guest_name`; missing/invalid guest names fail with `422 call_access_validation_failed`.
- Every access-issued session is persisted in `call_access_sessions` with `session_id`, `access_id`, `call_id`, `room_id`, `user_id`, `link_kind`, and expiry metadata.
- `WS /ws` resolves access-issued sessions against that binding: no room query defaults to the bound call room, mismatched room/call queries stay fail-closed in the waiting room without a pending foreign-room admission target.
- Invited access-bound users enter the waiting room first; only `allowed` participants, owners, moderators, or admins can bypass into the bound call room.

`PATCH /api/calls/{id}` update contract:

- editable fields: `room_id`, `title`, `starts_at`, `ends_at`, `internal_participant_user_ids`, `external_participants`
- authorization: call owner, admin, or moderator
- immutable statuses: `cancelled` and `ended` reject edits (`status: immutable_for_edit`)
- global invite resend is not triggered by edit calls
- explicit invite resend request flags are rejected in update payload
- success returns `invite_dispatch.global_resend_triggered = false` and `invite_dispatch.explicit_action_required = true`

`DELETE /api/calls/{id}` delete contract:

- authorization: call owner or admin
- hard-delete semantics: removes the call record and cascades participant/access-link/invite-code bindings
- success: `200` with `result.state = deleted`

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
Optional query `?call_id=<call-id>` scopes admission/moderation resolution to a
specific call for legacy/non-dedicated room rows.

Presence channel contract on `WS /ws`:

- outbound events:
  - `system/welcome`
  - `room/snapshot`
  - `room/joined`
  - `room/left`
  - `reaction/event`
  - `reaction/batch`
  - `lobby/snapshot`
  - `system/error`
  - `system/pong`
- inbound commands:
  - `{"type":"room/join","room_id":"<active-room-id>"}`
  - `{"type":"room/leave"}`
  - `{"type":"room/snapshot/request"}`
  - `{"type":"chat/send","message":"...","attachments":[{"id":"att_..."}],"client_message_id":"..."}` (optional `attachments`, optional `client_message_id`)
  - `{"type":"typing/start"}`
  - `{"type":"typing/stop"}`
  - `{"type":"reaction/send","emoji":"👍","client_reaction_id":"..."}` (optional `client_reaction_id`)
  - `{"type":"reaction/send_batch","emojis":["👍","😂"],"client_reaction_id":"..."}` (optional `client_reaction_id`)
  - `{"type":"lobby/queue/request"}`
  - `{"type":"lobby/queue/join"}`
  - `{"type":"lobby/queue/cancel"}` (self-removes the current user from queued/admitted lobby state)
  - `{"type":"lobby/allow","target_user_id":123}` (`admin`/`moderator` only)
  - `{"type":"lobby/remove","target_user_id":123}` (`admin`/`moderator` only)
  - `{"type":"lobby/allow_all"}` (`admin`/`moderator` only)
  - `{"type":"call/offer","target_user_id":123,"payload":{...}}`
  - `{"type":"call/answer","target_user_id":123,"payload":{...}}`
  - `{"type":"call/ice","target_user_id":123,"payload":{...}}`
  - `{"type":"call/hangup","target_user_id":123,"payload":{...}}`
  - `{"type":"ping"}`
- behavior:
  - websocket gateway handshake is strict and fail-closed before upgrade:
    - method must be `GET`
    - `Upgrade` must be `websocket`
    - `Connection` must include `upgrade`
    - `Sec-WebSocket-Key` must be valid base64 for a 16-byte key
    - `Sec-WebSocket-Version` must be `13`
  - initial room snapshot is sent immediately after authenticated websocket attach
  - room changes stream join/leave deltas to room peers
  - chat fanout is room-scoped and server-authoritative (`chat/message` with stable server timestamps)
  - chat payload validation is bounded (`VIDEOCHAT_WS_CHAT_MAX_CHARS`, `VIDEOCHAT_WS_CHAT_MAX_BYTES`, defaults: 2,000 Unicode chars and 8 KiB UTF-8)
  - chat attachments are uploaded over `POST /api/calls/{call_id}/chat/attachments` into the King Object Store; websocket `chat/send` carries only attachment draft refs
  - attachment metadata fans out on `chat/message.message.attachments[]` as `id`, `name`, `content_type`, `size_bytes`, `kind`, `extension`, and `download_url`
  - attachment downloads use `GET /api/calls/{call_id}/chat/attachments/{attachment_id}` and are authorized against call ownership/participants/admin role; unsent drafts can be cancelled with `DELETE` on the same URL by the uploader
  - King Object Store object IDs stay flat and slash-free while embedding call/room scope hashes; SQLite stores only metadata, refs, status, and object IDs
  - accepted attachment types are images (`jpg`, `jpeg`, `png`, `webp`, `gif`), text (`txt`, `csv`, `md`), PDF, Office, and OpenDocument formats; executable/binary/archive types fail closed with structured error codes
  - attachment limits are bounded server-side: 10 attachments/message, 10 images/message, 8 MiB/image, 25 MiB document/PDF/Office, 100 MiB/message, call-level soft/hard quotas via `VIDEOCHAT_CHAT_ATTACHMENT_CALL_SOFT_QUOTA_BYTES` and `VIDEOCHAT_CHAT_ATTACHMENT_CALL_HARD_QUOTA_BYTES`
  - accepted chat publishes emit `chat/ack` to the sender with deterministic `ack_id`, stable `message_id`, and `sent_count`
  - typing indicators are room-scoped, debounced, expire automatically, never self-echo, and fail closed when sender room membership is invalid
  - reaction events are room-scoped, enforce emoji/client-id payload boundaries, accept both single and client-batched sends, and switch to server-side `reaction/batch` fanout once per-user reaction volume exceeds the configured flood threshold (`VIDEOCHAT_WS_REACTION_FLOOD_WINDOW_MS`, `VIDEOCHAT_WS_REACTION_FLOOD_THRESHOLD_PER_WINDOW`, `VIDEOCHAT_WS_REACTION_FLOOD_BATCH_SIZE`)
  - lobby queue updates are room-scoped snapshots (`lobby/snapshot`) driven by server-authoritative queue/admitted state
  - authenticated websocket attach never queues a waiting-room user by itself; invited users become `pending` only after the explicit `lobby/queue/join` command from the join-call flow, and the join-call modal remains open until an owner/moderator admits the user
  - waiting-room users can cancel their own queued/admitted handoff with `lobby/queue/cancel`; cancel or websocket disconnect resets a `pending` participant back to `invited`, while an admitted handoff is preserved long enough for the admitted browser to reconnect into the call
  - moderator actions (`lobby/allow`, `lobby/remove`, `lobby/allow_all`) are fail-closed for non-moderator roles and for senders not actively present in the room
  - queue/admitted entries are cleaned when a user cancels, joins the admitted room, disconnects from an active room, or changes rooms
  - signaling commands are target-routed (`call/offer`, `call/answer`, `call/ice`, `call/hangup`) and only delivered when sender+target room authorization is valid
  - invalid signaling authorization paths (invalid sender, sender-not-in-room, missing/invalid/self/not-in-room target) fail closed as `system/error` without cross-room leakage
  - accepted signaling publishes emit `call/ack` to the sender with `signal_id`, `signal_type`, and `sent_count`
  - reconnecting clients receive a fresh `room/snapshot` resync on attach
  - active websocket loops revalidate session liveness on receive; revoked/expired sessions get `system/error` (`websocket_session_invalidated`) with structured close metadata (`close_code`, `close_reason`, `close_category`) and are closed accordingly

## Contract checks

Canonical versioned API/WS catalog (single source of truth):

- `demo/video-chat/contracts/v1/api-ws-contract.catalog.json`

Run the catalog parity drift test (runtime payloads must match the versioned catalog):

```bash
demo/video-chat/backend-king-php/tests/contract-catalog-parity-contract.sh
```

Run the auth contract test (REST + websocket token validation coverage):

```bash
demo/video-chat/backend-king-php/tests/session-auth-contract.sh
```

Run the auth refresh/rotation contract test (token replacement + stale replay conflict):

```bash
demo/video-chat/backend-king-php/tests/session-refresh-contract.sh
```

Run the auth logout/revoke contract test (deterministic revoke response + persisted `revoked_at` metadata):

```bash
demo/video-chat/backend-king-php/tests/session-logout-contract.sh
```

Run the RBAC middleware contract test (explicit permission matrix + typed forbidden envelopes + allowed-role pass paths):

```bash
demo/video-chat/backend-king-php/tests/rbac-middleware-contract.sh
```

Run the realtime session-revocation contract test (revoked/expired token propagation into active websocket liveness checks):

```bash
demo/video-chat/backend-king-php/tests/realtime-session-revocation-contract.sh
```

Run the realtime websocket-gateway contract test (strict handshake validation + structured close descriptors for session invalidation):

```bash
demo/video-chat/backend-king-php/tests/realtime-websocket-gateway-contract.sh
```

Run the admin user list contract test (search + pagination + deterministic sorting):

```bash
demo/video-chat/backend-king-php/tests/admin-user-list-contract.sh
```

Run the admin user create endpoint contract test (validation + role defaults + duplicate-email conflict envelope):

```bash
demo/video-chat/backend-king-php/tests/admin-user-create-contract.sh
```

Run the admin user update endpoint contract test (normalization + fail-closed unsupported fields + not-found/conflict envelopes):

```bash
demo/video-chat/backend-king-php/tests/admin-user-update-contract.sh
```

Run the admin user status endpoint contract test (deactivate/reactivate idempotency + session invalidation policy):

```bash
demo/video-chat/backend-king-php/tests/admin-user-status-contract.sh
```

Run the admin user mutation contract test (create/update/deactivate/reactivate + validation/conflict semantics):

```bash
demo/video-chat/backend-king-php/tests/admin-user-mutation-contract.sh
```

Run the user settings contract test (settings persistence + reauth/session reload semantics):

```bash
demo/video-chat/backend-king-php/tests/user-settings-contract.sh
```

Run the user settings endpoint contract test (`GET/PATCH /api/user/settings` + session-check parity):

```bash
demo/video-chat/backend-king-php/tests/user-settings-endpoint-contract.sh
```

Run the avatar upload contract test (type/size validation + safe storage path handling):

```bash
demo/video-chat/backend-king-php/tests/avatar-upload-contract.sh
```

Run the avatar upload endpoint contract test (`POST /api/user/avatar` + `GET /api/user/avatar-files/{filename}` + session-check avatar-path parity):

```bash
demo/video-chat/backend-king-php/tests/avatar-upload-endpoint-contract.sh
```

Run the calls list contract test (my/all scope + search/status filters + deterministic pagination):

```bash
demo/video-chat/backend-king-php/tests/calls-list-contract.sh
```

Run the calls list endpoint contract test (`GET /api/calls` owner-bound scope behavior + deterministic paging envelope):

```bash
demo/video-chat/backend-king-php/tests/calls-list-endpoint-contract.sh
```

Run the call create contract test (create payload validation + participant persistence + normalized response):

```bash
demo/video-chat/backend-king-php/tests/call-create-contract.sh
```

Run the call create endpoint contract test (`POST /api/calls` with internal/external participants + atomic persistence expectations):

```bash
demo/video-chat/backend-king-php/tests/call-create-endpoint-contract.sh
```

Run the call update contract test (schedule/participant updates + no implicit invite resend):

```bash
demo/video-chat/backend-king-php/tests/call-update-contract.sh
```

Run the call update endpoint contract test (`PATCH /api/calls/{id}` participant-diff updates + explicit invite-dispatch semantics):

```bash
demo/video-chat/backend-king-php/tests/call-update-endpoint-contract.sh
```

Run the call cancel contract test (state transition + cancellation payload persistence + active-join exclusion):

```bash
demo/video-chat/backend-king-php/tests/call-cancel-contract.sh
```

Run the call cancel endpoint contract test (`POST /api/calls/{id}/cancel` state-transition validation + persisted cancellation payload semantics):

```bash
demo/video-chat/backend-king-php/tests/call-cancel-endpoint-contract.sh
```

Run the invite-code create contract test (UUID-backed uniqueness + scope binding + deterministic expiry policy):

```bash
demo/video-chat/backend-king-php/tests/invite-code-create-contract.sh
```

Run the invite-code create endpoint contract test (`POST /api/invite-codes` body/validation/authz semantics + UUID/policy-bound create envelope):

```bash
demo/video-chat/backend-king-php/tests/invite-code-create-endpoint-contract.sh
```

Run the invite-code redeem contract test (expiry + usage-limit enforcement + typed join context):

```bash
demo/video-chat/backend-king-php/tests/invite-code-redeem-contract.sh
```

Run the invite-code redeem endpoint contract test (`POST /api/invite-codes/redeem` validation + expiry/exhausted/conflict/not-found mapping + join-context envelope):

```bash
demo/video-chat/backend-king-php/tests/invite-code-redeem-endpoint-contract.sh
```

Run the call-access session contract test (`GET /api/call-access/{id}/join` + `POST /api/call-access/{id}/session` + access-bound WS room resolution):

```bash
demo/video-chat/backend-king-php/tests/call-access-session-contract.sh
```

Run the realtime presence contract test (room snapshots + join/leave deltas + reconnect resync):

```bash
demo/video-chat/backend-king-php/tests/realtime-presence-contract.sh
```

Run the realtime chat contract test (room-scoped fanout + payload bounds + stable dedupe/ack ids + attachment metadata refs):

```bash
demo/video-chat/backend-king-php/tests/realtime-chat-contract.sh
```

Run the chat attachment contract test (King Object Store metadata path, allowlist/blocklist/magic-byte validation, quotas, download ACL):

```bash
demo/video-chat/backend-king-php/tests/chat-attachment-contract.sh
```

Run the realtime typing contract test (debounce + expiry + no-self-echo semantics + sender room-membership guard):

```bash
demo/video-chat/backend-king-php/tests/realtime-typing-contract.sh
```

Run the realtime reaction contract test (room-scoped stream + payload boundaries + server-side throttling):

```bash
demo/video-chat/backend-king-php/tests/realtime-reaction-contract.sh
```

Run the realtime lobby contract test (queue snapshots + moderator actions + sender room-membership guards + disconnect/room-change cleanup):

```bash
demo/video-chat/backend-king-php/tests/realtime-lobby-contract.sh
```

Run the realtime signaling contract test (targeted offer/answer/ICE/hangup routing + sender/target membership guards):

```bash
demo/video-chat/backend-king-php/tests/realtime-signaling-contract.sh
```
