# GSP02 Collaboration Persistence Inventory

Date: 2026-05-10
Branch: `kingrt/prod-ready`

## Scope

This inventory closes GSP02-02. It maps the current chat-history and Image
Planning reload paths before implementation work.

The target behavior for the sprint is:

- call chat history survives reload for admitted call participants;
- Image Planning images, selected image and thumbnails survive reload;
- Image Planning assets are participant-only, not public data URLs;
- live call chat is the operating channel when a valid session is available;
- diagnostics/logs are collected every loop.

## Online Diagnostics Snapshot

`demo/video-chat/scripts/prod-debug.sh` was run read-only with no deploy, no
restart, no DB write, no DNS and no certbot.

Current production surface:

- API runtime/version: 200
- app shell/CDN/call-app host/registry: 200
- marketplace without session: 401 expected
- lobby websocket without session: 401 expected
- SFU websocket: 404 expected for the parked SFU surface
- containers: backend, websocket backend, edge and TURN are up
- schema: v57, all 57 migrations applied

Relevant recent log findings for the active call
`39c5b3ea-855b-40fd-b030-c8af1d512605`:

- `realtime_websocket_retryable_error` with `socket_unreachable` occurred for
  users 5 and 19 before/around the last deploy assets.
- `media_session_plan_missing` still appears after online room snapshots.
- `gossip_primary_publish_failed_no_sfu_fallback` repeats heavily while SFU is
  intentionally not used as fallback.
- Background init diagnostics still appear in logs even though Background is
  not part of this sprint.
- No PHP fatal or auth SQL regression appeared in this read-only snapshot.

Live browser/chat state:

- The current Playwright page is at the login screen for
  `https://app.kingrt.com/workspace/call/39c5b3ea-855b-40fd-b030-c8af1d512605`.
- Chat read/post reporting cannot continue until a valid session is recovered
  or an admitted guest/browser session is established.

## Call Chat Current Path

Backend write path:

- Websocket command `chat/send` is handled in
  `demo/video-chat/backend-king-php/http/module_realtime_chat_commands.php`.
- The runtime publishes the message with `videochat_chat_publish()`.
- After publish, `videochat_chat_archive_append_message()` stores the message
  in `call_chat_messages` and object storage/local attachment storage fallback.
- The archive row contains `message_id`, `call_id`, `room_id`,
  `sender_user_id`, `sender_display_name`, `sender_role`, text, JSON payload,
  object key, server time and sequence.
- `INSERT OR IGNORE` makes message append idempotent by `message_id`.

Backend read path:

- `GET /api/calls/{call_id}/chat-archive` is routed in
  `demo/video-chat/backend-king-php/http/module_calls.php`.
- `videochat_chat_archive_fetch()` lives in
  `demo/video-chat/backend-king-php/domain/realtime/chat_archive.php`.
- The API supports `cursor`, `limit`, `query`, `q`, `sender_user_id` and
  `file_kind`.
- Access requires a valid authenticated user and call ownership/admin/internal
  participant membership.
- Guest accounts are currently rejected by
  `videochat_user_is_guest_account()`.
- Response includes messages, attachment groups, read-only access metadata,
  pagination and filters.

Frontend live path:

- `chatByRoom` is an in-memory reactive bucket in
  `demo/video-chat/frontend-vue/src/domain/realtime/CallWorkspaceView.vue`.
- `demo/video-chat/frontend-vue/src/domain/realtime/workspace/callWorkspace/chatRuntime.ts`
  appends local optimistic messages and normalizes websocket `chat/message`
  frames.
- `socketLifecycle.ts` forwards websocket `chat/message` into
  `appendChatMessage()`.
- The bucket dedupes by message id or client message id and caps the room at
  240 messages.

Existing archive UI:

- `demo/video-chat/frontend-vue/src/domain/calls/components/ChatArchiveModal.vue`
  can open an archive from dashboard/admin call lists.
- `demo/video-chat/frontend-vue/src/domain/calls/chat/archive.ts` normalizes the
  archive payload.
- The call workspace does not use this archive API for reload bootstrap.

Chat gaps:

1. The active call chat starts empty after reload unless live websocket replay
   happens. It does not bootstrap from `/chat-archive`.
2. The archive API returns `seq > cursor ORDER BY seq ASC`, so `cursor=0`
   returns the oldest messages, not the latest room tail needed for reload.
3. The archive API does not filter by requested room id in the route contract;
   it returns call-wide messages while the UI buckets by room.
4. Guest accounts are currently denied archive access. For "admitted call
   participants" this is too narrow if guest join-link participants must see
   chat after reload.
5. Chat archive failures are sent as websocket `system/error`, but the frontend
   reload bootstrap has no visible diagnostic for archive unavailable.

## Image Planning Current Path

App source:

- Image Planning lives under `demo/call-app/planning-image/`, which matches the
  call-app source package boundary.
- `call-app.manifest.json` advertises a CRDT collaboration app with default
  participant access `allowed_by_default`.
- `crdt.schema.json` declares operations:
  `planning_image.add`, `planning_image.replace`, `planning_image.select`,
  `planning_image.delete`, `planning_image.clear`, `planning_image.viewport`.

Iframe behavior:

- `public/planning-image.js` requests `call_app.crdt.bootstrap.request` after
  `call_app.launch` if read access is granted.
- It polls `call_app.crdt.ops.request` every 2 seconds.
- It already has a thumbnail picker, multi-image map, selected image id, zoom,
  pan, delete button and Delete-key handling.
- Upload reads the file as `data:image/...` via `FileReader`, creates an image
  UUID, writes `planning_image.add`, then writes `planning_image.select`.
- Delete writes `planning_image.delete` and applies it locally.

Parent bridge:

- `useCallAppCrdtBridge.js` handles:
  - `call_app.crdt.bootstrap.request`
  - `call_app.crdt.ops.request`
  - `call_app.crdt.op.append`
  - `call_app.crdt.snapshot.request`
- API calls are made to:
  - `/api/call-app-sessions/{id}/crdt/bootstrap`
  - `/api/call-app-sessions/{id}/crdt/ops`
  - `/api/call-app-sessions/{id}/crdt/snapshots`
- Bridge errors are posted back as `call_app.crdt.error`.

Backend CRDT:

- `call_app_crdt_documents` persists one document per session.
- `call_app_crdt_ops` persists idempotent operations by
  `(document_row_id, operation_id)`.
- Bootstrap returns stored document snapshot plus operations after
  `max(snapshot_clock, after_clock)`.
- App grants enforce `read`, `write` and `delete` actions.
- For `planning_image.delete`, the backend can bypass missing `delete`
  permission when the actor owns the image based on the latest add/replace op.

Image Planning gaps:

1. Image bytes are stored inside CRDT payloads as `data_url`. That makes every
   operation carry the whole image, bypasses participant-only asset URLs and can
   bloat CRDT replay.
2. Direct asset ACL does not exist because there is no Image Planning asset
   route yet.
3. Snapshot compaction currently writes a generic
   `king.call_app.crdt.checkpoint.v1` with only counts and clocks. The
   Image Planning iframe only applies `planning_image.snapshot.v1`, so after
   compaction it would ignore the snapshot and old compacted image ops would
   not replay.
4. Metadata is present but not split from bytes. There is no durable thumbnail
   versus full-size asset separation.
5. The current delete permission model is close but must be proven with
   contracts: uploader bypass, admin/moderator delete, denied participant
   rejection and iframe disabled state.
6. The app can reload from operations today only while all image ops remain in
   the replay window and the stored data URLs are accepted. That is not a
   durable v1 contract.

## Next Implementation Order

1. Fix chat reload first because it is the team's operating channel.
2. Add latest-tail chat archive bootstrap with room filter and frontend
   integration.
3. Add Image Planning asset storage/ACL and metadata-only CRDT operations.
4. Add app-specific snapshot rebuild for Image Planning before enabling or
   relying on snapshot compaction.
5. Add contracts for admitted participants, denied outsiders, delete rights and
   hard reload restoration.
6. Extend diagnostics scripts to group chat archive, CRDT and image asset
   failures each loop.
