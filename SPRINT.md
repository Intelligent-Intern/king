# King Active Sprint

Purpose:
- `SPRINT.md` contains only the active top-priority sprint.
- `EPIC.md` defines the longer running product goal and sprint loop.
- Parked or deferred work lives in `BACKLOG.md`.
- Completion evidence belongs in commit history, contracts, diagnostics and
  readiness docs.

Rules:
- Work one checkbox at a time unless the user explicitly expands scope.
- A checkbox is only closed after implementation and proof.
- Do not weaken King v1 contracts to make the sprint smaller.
- Do not grow `CallWorkspaceView.vue`; extract focused helpers/modules when
  adding behavior.
- Use local branch `kingrt/prod-ready`.
- Do not push.
- Do not run DNS or certbot automation unless a new domain is explicitly added.
- Deploy only from the integrated local branch.
- The live call chat is the preferred reporting channel once a logged-in or
  admitted session is available; local sprint docs remain the source of truth.

## Sprint: Collaboration Persistence + Live Debug Loop 02

Branch:
- `kingrt/prod-ready`

Status:
- Active as of 2026-05-10.
- Follows completed GSP01 Gossip Video Call v1 Streaming 01.
- Goal: make collaboration state survive reloads and make the running call
  debuggable from the live chat/log loop without weakening the Gossip media
  target.

Sprint goal:
- Call chat history is available after reload for admitted participants.
- Image Planning call-app images, selected image, thumbnails and permissions
  survive reload through the durable call-app CRDT/session path.
- Image assets are only visible to admitted call participants with explicit
  read/upload/delete grants.
- The live call chat can be used as the operational reporting channel after
  session recovery.
- Diagnostics and logs are collected every loop and feed the next issue before
  deploy.
- The sprint ends with deploy plus 5 to 10 diagnostics loops, then `EPIC.md`
  is revised and the next 20-issue sprint is generated.

Subagent lanes:
- Agent 1: backend chat archive and participant access.
- Agent 2: Image Planning call-app CRDT, assets and permissions.
- Agent 3: frontend chat reload and call-app bridge replay.
- Agent 4: live chat reporting, consent-gated audio notes and diagnostics UI.
- Agent 5: tests, contracts and browser proof.
- Agent 6: deploy, diagnostics, branch hygiene and sprint bookkeeping.

Execution boundary:
- No push to GitHub or any remote.
- No DNS changes.
- No certbot unless a new domain was explicitly requested.
- No root planning Markdown other than `README.md`, `BACKLOG.md`, `EPIC.md`,
  and `SPRINT.md`.
- Do not restore background regression tests or old regression harnesses as
  release gates.
- Do not reintroduce SFU fallback, MediaSecurity gates or auto-quality rescue as
  active stream-control dependencies for Gossip v1.

Proof anchors:
- `EPIC.md`
- `demo/call-app/planning-image/`
- `demo/video-chat/backend-king-php/domain/call_apps/call_app_crdt.php`
- `demo/video-chat/backend-king-php/http/module_call_apps.php`
- `demo/video-chat/backend-king-php/domain/realtime/chat_archive.php`
- `demo/video-chat/backend-king-php/http/module_realtime_chat_commands.php`
- `demo/video-chat/backend-king-php/http/module_calls.php`
- `demo/video-chat/frontend-vue/src/domain/realtime/workspace/callWorkspace/chatRuntime.ts`
- `demo/video-chat/frontend-vue/src/domain/realtime/callApps/useCallAppCrdtBridge.js`
- `demo/video-chat/frontend-vue/src/domain/realtime/callApps/callAppDiagnosticTailBridge.js`
- `demo/video-chat/scripts/prod-debug.sh`

Sprint Checkboxen:
- [x] GSP02-01 Replace the completed GSP01 checklist with this active 20-item
  sprint, update `EPIC.md` with the Sprint 02 target, and commit the planning
  reset on `kingrt/prod-ready`.
- [x] GSP02-02 Inventory chat history and Image Planning reload paths: document
  current request/response events, persistence tables, bridge events, access
  decisions and known gaps before code changes.
- [ ] GSP02-03 Chat archive backend hardening: prove every `chat/send` from an
  admitted call participant is stored durably with room, call, sender, role,
  attachments metadata, monotonic sequence and redacted payload.
- [ ] GSP02-04 Chat history reload API: return the latest room chat history for
  admitted call participants after reload with cursor pagination, redaction and
  no guest/archive leak outside the call.
- [ ] GSP02-05 Frontend chat bootstrap: on call load and websocket reconnect,
  backfill archive history before live append, dedupe by message id, preserve
  unread state and never require a manual refresh button.
- [ ] GSP02-06 Live-call reporting channel: recover or establish a valid session
  for the target call chat, post concise progress reports there, and read the
  visible chat transcript into the sprint loop.
- [ ] GSP02-07 Consent-gated audio loop contract: only capture/analyze call
  audio when visible participants consent in chat; record transcript notes in
  diagnostics without storing raw audio or secrets.
- [ ] GSP02-08 Image Planning CRDT audit: prove which operations already replay
  after reload and why uploaded image bytes, metadata or selected state may be
  missing across sessions.
- [ ] GSP02-09 Image Planning durable asset storage: move uploaded image bytes
  out of volatile iframe memory into a participant-only backend asset path with
  UUID, content hash, mime type, size and uploader identity.
- [ ] GSP02-10 Image Planning metadata operations: make add/select/delete
  idempotent CRDT operations carrying UUID, name, dimensions, thumbnail,
  storage reference, uploader and logical clock.
- [ ] GSP02-11 Image Planning reload bootstrap: hard reload restores image list,
  selected image, thumbnails and canvas render from durable CRDT snapshot plus
  replayed operations.
- [ ] GSP02-12 Image picker UX: support multiple uploaded images with a compact
  overlay thumbnail picker instead of a plain select, stable responsive layout
  and no canvas overlap.
- [ ] GSP02-13 Image deletion: uploader, admin and moderator can delete through
  the delete button and Del key; other participants see disabled controls and
  receive a clear denial event.
- [ ] GSP02-14 Call-app permission grants: expose per-user read/upload/delete
  grants for Image Planning through the existing call-app permissions model and
  enforce the same decision in backend and iframe UI.
- [ ] GSP02-15 Participant-only asset access: direct image asset URLs must
  reject unauthenticated users, non-participants, removed/kicked users and
  ended/foreign-call access where the call contract disallows it.
- [ ] GSP02-16 Call-app bridge replay hardening: bootstrap, ops replay and
  snapshot compaction must survive parent reload, iframe reload and active
  session removal/re-add without duplicate visible images.
- [ ] GSP02-17 Diagnostics/log loop: extend `prod-debug.sh` or a focused helper
  to group recent chat archive, call-app CRDT, image asset, websocket reconnect
  and Gossip media errors for every `w` loop.
- [ ] GSP02-18 Contract tests: add focused backend/frontend contracts for chat
  archive reload, Image Planning asset ACL, CRDT idempotency, delete rights and
  bridge replay.
- [ ] GSP02-19 Browser proof: multi-participant proof that chat history and
  Image Planning images remain visible after reload while unauthorized users
  cannot fetch image assets.
- [ ] GSP02-20 Deploy and debug loop: deploy without push/DNS/certbot, run 5 to
  10 diagnostics loops, collect all unique errors, prepare a second deploy only
  after grouped fixes, update `EPIC.md`, then refill `SPRINT.md` with the next
  20 issues.

Current Loop Notes:
- Sprint 01 completed on 2026-05-10 and is summarized in `EPIC.md`.
- The current Playwright session is at the login screen for the target call;
  live chat reporting starts once a valid session is recovered.
- Audio analysis remains consent-gated and must be requested/confirmed in the
  visible call chat before capture.
- GSP02-02 proof: `analyse/gsp02-collaboration-persistence-inventory.md`
  documents the chat archive write/read path, frontend live-chat gap,
  Image Planning CRDT reload path, missing participant-only asset route,
  snapshot compaction risk, and the current online diagnostic snapshot.
