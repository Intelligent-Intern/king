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
- [x] GSP02-01 Replace the completed GSP01 checklist with this active sprint,
  update `EPIC.md` with the Sprint 02 target, and commit the planning reset on
  `kingrt/prod-ready`.
- [x] GSP02-02 Inventory chat history and Image Planning reload paths: document
  current request/response events, persistence tables, bridge events, access
  decisions and known gaps before code changes.
- [x] GSP02-03 Chat DB persistence hardening: prove every `chat/send` from an
  admitted call participant is stored durably with room, call, sender, role,
  attachments metadata, monotonic sequence and redacted payload.
- [x] GSP02-04 Chat DB tail API: return the latest room chat state for admitted
  call participants with cursor pagination, redaction and no guest leak.
- [x] GSP02-05 Frontend chat DB sync bootstrap: on call load and websocket
  reconnect, sync the DB tail into live chat state, dedupe by message id,
  preserve unread state and never require a manual refresh button.
- [x] GSP02-06 Live-call reporting channel: recover or establish a valid session
  for the target call chat, post concise progress reports there, and read the
  chat transcript into the sprint loop.
- [x] GSP02-07 Sprint reporting contract: every completed checkbox requires a
  live chat status message, a fresh DB/chat read, and any user reply folded into
  the next issue before work continues.
- [x] GSP02-08 Chat poll cadence: during active work, check the live call chat at
  least every 60 seconds after a status post and answer direct instructions in
  the room through the normal WebSocket `chat/send` path.
- [x] GSP02-09 Supervised chat monitor: add a local monitor runner with PID, log
  file, dry-run mode and stop command; it reads chat safely and does not expose
  tokens, cookies, sessions, SDP, ICE or media payloads.
- [x] GSP02-10 Monitor watchdog: document and implement the manager-side check
  that verifies the chat monitor is alive at least every 15 minutes and restarts
  it only with an explicit reason in the log.
- [ ] GSP02-11 DB sync terminology cleanup: remove misleading "archive only"
  language from active call-chat sync paths; DB is the persistent source for
  frontend chat state, while archive UI is only one read-only view.
- [x] GSP02-12 Initial chat sync count: make the call workspace request and
  render the latest 50 DB messages by default after load, reload and reconnect.
- [x] GSP02-13 Older chat demand load: add a top-of-chat control that loads older
  DB messages using the cursor without disturbing scroll position or unread
  state.
- [x] GSP02-14 Chat ordering: keep live and DB-loaded messages sorted by server
  sequence/time so older-page loads appear above newer messages and live appends
  remain stable.
- [ ] GSP02-15 Chat dedupe: prove duplicate DB sync, reconnect replay and sender
  optimistic messages collapse by message id and client message id.
- [ ] GSP02-16 Chat redaction contract: prove DB sync payloads never expose
  tokens, cookies, sessions, secrets, raw media payloads, SDP or ICE candidates.
- [ ] GSP02-17 Chat participant ACL: prove only admitted call participants and
  allowed admins can sync call chat state; removed users lose access.
- [ ] GSP02-18 Chat reload browser proof: online or compose proof that two
  participants still see at least the latest 50 messages after hard reload.
- [ ] GSP02-19 Operator checkbox removal: remove the operator feedback checkbox
  from call chat UI and keep backend feedback storage dormant unless a future
  explicit control reintroduces it.
- [ ] GSP02-20 Call audio talk path: diagnose and fix why participants cannot
  hear each other, while keeping any capture/analysis consent-gated and never
  storing raw audio or secrets; after talk audio works, evaluate TTS output and
  consent-gated STT chat notes as explicit follow-up work.
- [ ] GSP02-21 Image Planning CRDT audit: prove which operations already replay
  after reload and why uploaded image bytes, metadata or selected state may be
  missing across sessions.
- [ ] GSP02-22 Image Planning durable asset storage: move uploaded image bytes
  out of volatile iframe memory into a participant-only backend asset path with
  UUID, content hash, mime type, size and uploader identity.
- [ ] GSP02-23 Image Planning metadata operations: make add/select/delete
  idempotent CRDT operations carrying UUID, name, dimensions, thumbnail,
  storage reference, uploader and logical clock.
- [ ] GSP02-24 Image Planning reload bootstrap: hard reload restores image list,
  selected image, thumbnails and canvas render from durable CRDT snapshot plus
  replayed operations.
- [ ] GSP02-25 Image picker UX: support multiple uploaded images with a compact
  overlay thumbnail picker instead of a plain select, stable responsive layout
  and no canvas overlap.
- [ ] GSP02-26 Image deletion: uploader, admin and moderator can delete through
  the delete button and Del key; other participants see disabled controls and
  receive a clear denial event.
- [ ] GSP02-27 Call-app permission grants: expose per-user read/upload/delete
  grants for Image Planning through the existing call-app permissions model and
  enforce the same decision in backend and iframe UI.
- [ ] GSP02-28 Participant-only image access: direct image asset URLs reject
  unauthenticated users, non-participants, removed/kicked users and foreign-call
  access.
- [ ] GSP02-29 Call-app bridge replay hardening: bootstrap, ops replay and
  snapshot compaction survive parent reload, iframe reload and active session
  removal/re-add without duplicate visible images.
- [ ] GSP02-30 Call-app persistence proof: Image Planning images and selected
  state remain visible to other admitted participants after reload.
- [ ] GSP02-31 Chat plus call-app diagnostics grouping: extend diagnostics to
  group recent chat DB sync, call-app CRDT, image asset, websocket reconnect and
  Gossip media errors for every loop.
- [ ] GSP02-32 Guest join crash deploy blocker: deploy the local
  `JoinView` account-confirmation import fix before relying on guest join links
  in online proof.
- [ ] GSP02-33 Guest lobby flow proof: guest join link opens name entry, queues in
  lobby, shows badge/notification to moderator, and admits without reconnect
  loops.
- [ ] GSP02-34 Call-app remove session proof: participants with permission can
  remove active call-app sessions from a call and the removed app stays gone
  after reload.
- [ ] GSP02-35 Gossip mesh reference audit: inspect `gossip/1.0.8-build-mesh`
  and document the exact topology structure that must be ported for real users.
- [ ] GSP02-36 Gossip no-SFU active path: remove SFU from active publish/receive
  decisions for this v1 path; SFU can remain parked code but not an active gate.
- [ ] GSP02-37 Gossip topology state: server head performs peer discovery,
  topology assignment and state maintenance without relaying media.
- [ ] GSP02-38 Gossip peer degree: each peer connects bidirectionally to up to 5
  randomized peers, with deterministic logs for topology changes.
- [ ] GSP02-39 Gossip frame envelope: keyframes and deltas move over the mesh
  without MediaSecurity, SFU fallback or quality experiment gates in the active
  v1 path.
- [ ] GSP02-40 Gossip backpressure rule: on send pressure, pause briefly, retry
  at 50 percent frames, then 25 percent frames, then stop sending and log the
  reason without reconnect loops.
- [ ] GSP02-41 Gossip receive/render proof: decoded remote frames render visibly
  for at least two real participants or controlled browser sessions.
- [ ] GSP02-42 Avatar/video stream proof: synthetic avatar or canvas video uses
  the same Gossip path as a normal participant stream and can prove pixels move.
- [ ] GSP02-43 SFU diagnostics cleanup: errors like
  `gossip_primary_publish_failed_no_sfu_fallback` must be replaced by clear
  Gossip-only failure reasons.
- [ ] GSP02-44 Root Markdown hygiene: keep root planning Markdown limited to
  `README.md`, `BACKLOG.md`, `EPIC.md` and `SPRINT.md`; detailed analysis stays
  under `analyse/`.
- [ ] GSP02-45 Branch hygiene: keep local visible branches limited to `main`,
  `develop` and `kingrt/prod-ready` where possible; merged local branches and
  clean worktrees are removed without pushing.
- [ ] GSP02-46 Contract test batch: add focused backend/frontend contracts for
  chat DB sync, older-load pagination, Image Planning asset ACL, CRDT
  idempotency, delete rights and bridge replay.
- [ ] GSP02-47 Browser proof batch: multi-participant proof that chat history and
  Image Planning images survive reload while unauthorized users cannot fetch
  image assets.
- [x] GSP02-48 Predeploy gate: run lint/build/contracts/smoke relevant to the
  touched paths and post a live chat summary before deployment.
- [x] GSP02-49 Deploy: deploy from local `kingrt/prod-ready` without push, DNS
  automation or certbot; include asset version and deployment commit in chat.
- [ ] GSP02-50 Postdeploy debug loop: run 5 to 10 diagnostics loops, collect all
  unique errors before any second deploy, post loop summaries in chat, update
  `EPIC.md`, then refill `SPRINT.md` with the next 50 issues.

Current Loop Notes:
- Sprint 01 completed on 2026-05-10 and is summarized in `EPIC.md`.
- The current Playwright session is at the login screen for the target call;
  live chat reporting starts once a valid session is recovered.
- Audio analysis remains consent-gated and must be requested/confirmed in the
  visible call chat before capture.
- Active reporting rule: after every checkbox closure, post the result in the
  live call chat, wait/read for replies, and update the next issue if the room
  gives new instructions.
- Chat state rule: production `call_chat_messages` is not a passive archive for
  this sprint. It is the persistent DB source that must be synchronized into
  the frontend call chat state, starting with the latest 50 messages and older
  pages on demand.
- GSP02-02 proof: `analyse/gsp02-collaboration-persistence-inventory.md`
  documents the chat archive write/read path, frontend live-chat gap,
  Image Planning CRDT reload path, missing participant-only asset route,
  snapshot compaction risk, and the current online diagnostic snapshot.
- GSP02-03 proof: `chat_archive.php` now redacts token/session/cookie/secret
  keys and data/media payloads before storing archive JSON snapshots, exposes
  `client_message_id` with fetched messages for reload de-dupe, and the
  `chat-archive-contract.sh` runner proves idempotent append, monotonic `seq`,
  sender role, attachment metadata and redaction via the Docker SQLite fallback
  when host PHP lacks `pdo_sqlite`.
- GSP02-04 proof: `videochat_chat_archive_fetch()` now supports room-scoped
  reload tails via `room_id` plus `tail=1`/`direction=latest`, returns the
  latest room page in ascending UI order, exposes an older-page cursor, and
  keeps the old forward cursor mode for archive screens. The chat archive
  contract proves latest-tail, older-tail and cross-room exclusion.
- GSP02-05 proof: `chatArchiveBootstrap.js` loads `/chat-archive` with
  `room_id`, `tail=1` and `limit`, appends history backfill as normal
  `chat/message` payloads, and suppresses unread noise through
  `chatRuntime.ts`. `socketLifecycle.ts` triggers the helper on websocket
  open/reconnect and room snapshots. Verified with
  `node tests/contract/chat-archive-bootstrap-contract.mjs` and
  `npm run build`.
- GSP02-06 blocker note: the public join link currently crashes online before
  chat reporting can resume because deployed `JoinView` references
  `requestCallAccessAccountUpdateConfirmation` without importing it. Local fix
  `fa4d34ac` imports the function and adds
  `join-view-account-confirmation-import-contract.mjs`; this still needs deploy
  before the online room can be used again through that join path.
- GSP02-06 proof: recovered the target call transcript directly from production
  `call_chat_messages`, read entries through sequence `258`, then posted a live
  WebSocket `chat/send` status reply acknowledged by `chat/ack`. The archive
  confirmed the reply as sequence `259` at `2026-05-10T15:48:31+00:00`.
  Follow-up items from chat: online reload still does not show chat history to
  participants, SFU must be removed from the active path, and
  `gossip/1.0.8-build-mesh` is the requested mesh-structure reference.
- GSP02-07 proof: expanded the sprint to 50 checkbox issues, added the
  per-checkbox chat-report/read rule and the DB-as-sync-state rule, read the
  latest chat reply about the wrong reporter account, switched reporting back
  to the existing `Codex Reporter` user session, and posted a live `chat/send`
  update acknowledged as DB sequence `261` at `2026-05-10T15:56:16+00:00`.
- Chat monitor implementation note: keep `prod-debug.sh` read-only. GSP02-09
  must be a separate supervised runner with `start|stop|status|once|post|dry-run`,
  PID, heartbeat, redacted log, HTTP DB-tail reads and normal WebSocket
  `chat/send` writes. Never log websocket URLs, raw frames, session storage,
  Authorization headers, SDP/ICE, media payloads or full `message_json`.
- GSP02-12 through GSP02-14 proof: `chatArchiveBootstrap.js` now exposes the
  DB-backed chat-history sync helper with an initial `limit=50`, cursor-based
  older-page loading and diagnostics named `chat_history_db_sync_*`. The call
  chat UI exposes a top-of-chat older-message action, and `chatRuntime.ts`
  retains DB `seq` in normalized message state and sorts DB/live messages
  stably. Verified with `node tests/contract/chat-archive-bootstrap-contract.mjs`
  and `npm run build`; live report posted by `Codex Reporter` as DB sequence
  `263` at `2026-05-10T15:58:25+00:00`.
- Latest live-chat instruction: Jendrik replied `deploy it` at DB sequence
  `262`; next action is local commit and production deploy from
  `kingrt/prod-ready` without push, DNS automation or certbot.
- GSP02-48/GSP02-49 proof: committed local `6ccf0c0d` on `kingrt/prod-ready`,
  deployed with `VIDEOCHAT_DEPLOY_SKIP_CERTBOT=1`,
  `VIDEOCHAT_DEPLOY_HCLOUD_DNS=0` and
  `VIDEOCHAT_DEPLOY_REFRESH_DNS_ON_PREPARE=0`, no push. Production reports
  asset version `20260510160000`, app/API/cdn/call-app hosts are reachable,
  containers are up, and the authenticated DB-tail API returned HTTP 200 with
  44 messages, first seq `221`, last seq `264`, `direction=latest`,
  `limit=50`.
- Latest live-chat instruction after deploy: Jendrik confirmed `ok chat history
  loads.. now fix sound so we can talk` at DB sequence `264`. Reply posted from
  `Codex Reporter`; active next issue is GSP02-20 audio talk path.
- GSP02-08 proof: `live-chat-monitor.sh once` read the production DB-backed chat
  tail through sequence `268`, the room instructions were folded into this
  sprint, and `Codex Reporter` replied through normal websocket `chat/send`
  with `chat/ack` for message `chat_04f61f244d42153327825e07`.
- GSP02-09 proof: `demo/video-chat/scripts/live-chat-monitor.sh` now supports
  `start|stop|status|once|post|dry-run|run`, stores PID, heartbeat and logs
  under `demo/video-chat/.local/live-chat-monitor/`, reads only `seq`, time,
  sender, role and text from `call_chat_messages`, and posts status through the
  existing websocket chat path without logging tokens or full websocket URLs.
- GSP02-10 proof: the monitor watchdog command checks process and heartbeat
  staleness with a 900 second default, restarts only with a logged reason, and
  `start` detaches via `setsid` when available so the tool shell cannot reap the
  process group. A live status check showed PID `2384508` running with
  heartbeat `2026-05-10T16:11:53Z`.
- Latest live-chat instruction: Alexander asked at DB sequence `270` why sound
  is not using Gossip like video. Next active work remains GSP02-20: inspect and
  unblock the current audio talk path first, then fold TTS/STT follow-ups from
  Jendrik sequence `266` through `268` into the audio lane.
