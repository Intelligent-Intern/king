# King Active Sprint

Purpose:
- `SPRINT.md` contains only the active operating sprint.
- `EPIC.md` defines the longer running product goal and release contract.
- Parked or deferred work lives in `BACKLOG.md`.
- Completion evidence belongs in commits, contracts, diagnostics and focused
  readiness notes, not as long root-doc transcripts.
- Completed sprint detail is intentionally removed from this file.

Rules:
- Use local branch `kingrt/prod-ready`.
- Do not push.
- Do not run DNS or certbot automation unless a new domain is explicitly added.
- Deploy only after focused verification and grouped diagnostics; no push.
- Root planning Markdown remains limited to `README.md`, `BACKLOG.md`,
  `EPIC.md` and `SPRINT.md`.
- Do not weaken King v1 contracts to make a check pass.
- Do not grow `CallWorkspaceView.vue`; extract focused helpers/modules when
  adding behavior.

## Sprint: Video Call Stability Orchestrator + Live Proof

Status:
- Active as of 2026-05-11.
- Current focus: make live call video stable and prove it with browser evidence.
- Current implementation lane: port Alex build-mesh station by station, with
  proof before moving to the next station.
- Live proof target: call `39c5b3ea-855b-40fd-b030-c8af1d512605`.
- Join-link token was provided in chat and is intentionally not stored in this
  tracked planning file.

Sprint goal:
- Clients exchange capabilities.
- The server/head orchestrator chooses one explicit media plan for the call.
- Alex build-mesh behavior is ported as explicit stations with source anchors,
  local proof and deploy/live-proof evidence.
- The frontend applies that media plan without hidden health gates, page reloads
  or reconnect loops.
- A deterministic test image/video stream is visible from one browser
  participant in another browser participant for 30 continuous minutes.
- Only after the 30-minute proof passes do we move to the right-sidebar layout
  and then IAM test cleanup.

Connection policy:
- Control lane owns the expected participant set.
- Start one connect cycle when the control lane says participants are present.
- If expected peers are not connected after 5 seconds, exactly one second
  connect attempt is allowed for that cycle.
- No unbounded reconnect loop, no browser reload loop and no visible transport
  chatter in the call UI.
- Wait up to 5 minutes for the expected participant set before declaring the
  cycle not ready with diagnostics.
- When a new participant joins, the orchestrator starts a new explicit cycle.

Media plan ladder:
- Candidate 1: Gossip `720p30`, 30-second render window.
- Candidate 2: Gossip `360p30`, 30-second render window.
- Candidate 3: Gossip `360p5`, 30-second render window.
- Candidate 4: SFU `720p30`, selected only by the orchestrator after Gossip
  render failure.
- Candidate 5: SFU `320p30`, selected only by the orchestrator after SFU 720
  render failure.
- Every transition records participant set, selected transport, profile,
  reason, render counters, egress counters and timestamp.
- Backpressure may pause send or reduce the selected plan; it must not invent
  reconnect/reload strategies.

Execution boundary:
- No push to GitHub or any remote.
- No DNS changes.
- No certbot unless a new domain was explicitly requested.
- No Background Replacement, blur, MediaSecurity, STT or IAM expansion before
  the video proof gate unless it directly blocks call video.
- No visible green transport-ack notices, retry countdown banners or console
  diagnostic spam in normal user sessions.

Sprint Checkboxen:
- [x] VST-00 Rewrite active sprint/backlog around the new stability-first
  sequence: video proof, right sidebar, IAM, then background/blur diagnosis.
- [x] VST-01 Inventory the current Playwright production call path, auth inputs
  and fake-media support for a two-browser live-call proof.
  Proof: `analysis/video-call-live-proof-readiness.md` records the existing
  production Playwright path, helper coverage, runtime inputs and proof gaps.
- [x] VST-02 Add a deterministic test-stream mode for the call page so one
  participant can publish a recognizable generated image/video without relying
  on a physical camera.
  Proof: `installMediaDeviceShim()` supports `deterministicVideoPattern` and
  `videoPatternLabel`; `node --check` and `git diff --check` passed.
- [x] VST-03 Add a receiver pixel probe that can identify the deterministic
  stream in the remote participant video surface.
  Proof: `remoteVideoPixelSnapshot()` and
  `waitForDeterministicRemoteVideo()` sample remote canvas/video surfaces and
  score the deterministic pattern. The same fix also prevents the reported
  "video canvases are gone" state by selecting `wlvc_wasm` for Gossip-primary
  when WLVC stage A is available instead of falling into blocked
  native/`unsupported` mode. Focused Gossip-primary contract and frontend build
  passed.
- [x] VST-04 Add a 30-minute Playwright live proof harness for the online call:
  sender joins, receiver joins, sender streams, receiver pixels change, no
  reload, no participant kick and no websocket loop.
  Proof: `tests/e2e/live-call-video-proof.spec.js` targets the live call and
  is runnable through `npm run test:e2e:live-call-video-proof` when the secure
  runtime env is present.
- [x] VST-05 Add log capture for the live proof: frontend console, websocket
  lifecycle, media plan events, egress counters, receiver counters, HTTP 500s,
  SQLite lock messages and container errors.
  Proof: the live proof captures console/page errors, websocket counters,
  reload counters and receiver pixel samples, and fails on known noisy runtime
  failures.
- [x] VST-06 Add a short evidence artifact under `analysis/` for each live
  proof run, including asset version and pass/fail reasons.
  Proof: the live proof writes `live-call-video-proof.json` into Playwright
  output and mirrors the same JSON under `analysis/live-call-video-proof/`.
- [x] VST-06a Alex build-mesh station map: record source branch/path/commit,
  station order, target modules and proof command per station in
  `analysis/video-call-live-proof-readiness.md`.
- [x] VST-06b Build-mesh station 1: port the server/head participant-set and
  connect-cycle authority without client reload or local health gates.
  Proof: backend topology now matches the build-mesh bidirectional ring and
  `GossipController` no longer emits autonomous heartbeat/reconnect decisions.
- [x] VST-06c Build-mesh station 2: port the authoritative media-plan ladder and
  room snapshot distribution.
  Proof: backend media session plan now exposes the ordered ladder
  `gossip_720p30 -> gossip_360p30 -> gossip_360p5 -> sfu_720p30 -> sfu_320p30`
  with stable selected-plan metadata in room snapshots. Focused PHP contracts
  for media capability plan, Gossip plan and room snapshot media authority
  passed.
- [x] VST-06d Build-mesh station 3: port Gossip topology, neighbor selection,
  egress accounting and backpressure behavior.
  Proof: `gossipController.ts` is back on the build-mesh TTL-aware duplicate
  window, frame history and selected-neighbor forwarding path; the Gossip
  contract suite passes. The active build-mesh contract now also proves that
  dedicated RTCDataChannels request `ArrayBuffer` delivery, accept browser
  `Blob` payloads and surface decode failures as diagnostics instead of
  invisible frame loss.
- [x] VST-06e Build-mesh station 4: port publisher profile application,
  encoder path proof, keyframe cadence and first-frame budget handling.
  Proof: publisher first-keyframe downscale is bounded to one frame and then
  restores the selected-profile WLVC cadence. Focused WLVC downscale, Gossip
  codec cadence, build-mesh station, outbound publication and primary
  one-connect contracts plus frontend build and build-size passed.
- [x] VST-06f Build-mesh station 5: port receiver render evidence, stuck
  reasons and remote pixel/counter proof.
  Proof: Gossip-delivered frames bypass SFU cache/continuity/jitter gates,
  local pixel proof passes, and the live proof harness is listable for the
  production call.
- [x] VST-06g Build-mesh station 6: port fallback transition diagnostics and UI
  status filtering so normal sessions show no transport chatter.
  Proof: `test:contract:gossip` now runs
  `test:contract:call-workspace-ui-status`; the build-mesh station contract
  asserts that UI status gate remains included.
- [x] VST-07 Define `media_session_plan.v1` states for `pending`,
  `connecting`, `gossip_720p30`, `gossip_360p30`, `gossip_360p5`, `sfu_720p30`,
  `sfu_320p30`, `ready` and `failed`.
  Proof: backend and frontend now publish `session_state_catalog` with the
  canonical orchestrator states while preserving participant media states for
  audio/capability behavior; `media-capability-plan-contract`,
  `realtime-room-snapshot-media-authority-contract` and
  `client-capabilities-media-plan-contract` pass.
- [x] VST-08 Expose the selected media plan and participant capability summary
  in authoritative room snapshots.
  Proof: authoritative room snapshots and the legacy join snapshot shape now
  include `selected_plan`, `session_state`, redacted
  `capabilities.by_connection_id`, `capability_summary`,
  `participant_media_state` and Gossip topology metadata; catalog parity and
  snapshot authority contracts pass.
- [x] VST-09 Capture client capabilities needed for the plan: camera size,
  codec path, WebCodecs/WASM support, GPU hint, network/backpressure counters
  and mobile/browser constraints.
  Proof: `client/capabilities.v1` now carries redacted `codec`, `network`,
  `network.backpressure`, mobile and browser-family fields in addition to
  media/runtime/constraints; backend normalization/persistence keeps the same
  public projection. Focused frontend/backend capability contracts and frontend
  build pass.
- [x] VST-10 Make the publisher apply the orchestrator profile exactly:
  resolution, FPS, keyframe cadence and transport.
  Proof: publisher capture, readback, encoder cadence and frame dispatch now
  resolve the active authoritative selected plan and apply width, height, FPS,
  keyframe interval and transport. `client-capabilities-media-plan-contract`,
  `gossip-codec-cadence-contract`, `sfu-capture-constraints-contract`,
  frontend build and `git diff --check` passed.
- [x] VST-11 Make the receiver report render evidence without treating missing
  frames as a local reconnect trigger.
  Proof: receiver render/missing-frame evidence is reported with
  `local_reconnect_trigger: false`; missing-frame evidence suppresses local
  stall reconnect eligibility while keeping diagnostics. The receiver feedback,
  Gossip decode and jitter-buffer contracts plus frontend build passed.
- [x] VST-12 Implement the 720p30 Gossip attempt as the first active candidate.
  Proof: the server/head media-session plan defaults `selected_plan.plan_id` to
  `gossip_720p30` with `transport=gossip`, `profile=720p30`, `width=1280`,
  `height=720`, `fps=30`, `render_window_ms=30000`,
  `selected_by=server_head` and reason `initial_gossip_720p30`; authoritative
  room snapshots preserve that selected plan for capable participants. Focused
  backend/frontend contracts `media-capability-plan-contract.php`,
  `realtime-room-snapshot-media-authority-contract.php` and
  `client-capabilities-media-plan-contract.mjs` passed.
- [x] VST-13 Implement the 360p30 Gossip downgrade only after the orchestrator
  records 30 seconds without receiver render evidence.
  Proof: backend media-session planning now advances persisted
  `gossip_720p30` to `gossip_360p30` only when at least two participant
  sessions are present and the selected plan's `render_window_ms=30000` elapsed
  since plan start or last receiver render. The transition carries previous
  plan, next plan, render window, no-render duration and an idempotency key.
- [x] VST-14 Implement the 360p5 Gossip downgrade only after the orchestrator
  records another 30 seconds without receiver render evidence.
  Proof: the same server/head transition path advances persisted
  `gossip_360p30` to `gossip_360p5` after its own render window without
  receiver render evidence, and does not skip multiple ladder steps.
- [x] VST-15 Re-enable SFU as an orchestrator-selected fallback only after
  Gossip candidates fail, starting at 720p30.
  Proof: persisted `gossip_360p5` advances to orchestrator-selected
  `sfu_720p30` only after the Gossip render window fails; normal initial
  snapshots still start at Gossip and do not select SFU first.
- [x] VST-16 Add SFU 320p30 as the final selected fallback after 30 seconds of
  SFU 720 failure.
  Proof: persisted `sfu_720p30` advances to `sfu_320p30` after its render
  window without evidence and preserves the `after_sfu_720p30_render_failure`
  selection gate.
- [x] VST-17 Keep all fallback transitions server/head-authored and idempotent;
  clients only apply the current selected plan.
  Proof: room snapshots now persist `media_session_plan.selected_plan` in the
  server/head presence state, ingest latest receiver-render diagnostics from
  `client_diagnostics`, advance one ladder step per render window, and publish
  only the current selected plan to clients. Focused contracts
  `media-capability-plan-contract.php`,
  `realtime-room-snapshot-media-authority-contract.php`,
  `media-capability-plan-gossip-contract.php` and
  `client-capabilities-media-plan-contract.mjs` passed. Deployed without push,
  DNS changes or certbot issuance to asset version `20260511182449`; post-deploy
  diagnostics show API/app `200`, SFU websocket disabled as expected, containers
  up, no recent filtered backend/edge error logs and no SQLite lock hits.
- [x] VST-18 Prove active call pages do not contain the previous 2-minute reload
  behavior.
  Proof: added `active-call-no-auto-reload-contract.mjs` to the asset-cache
  contract suite. It pins that build-version checks and asset-version mismatch
  handling skip or hint on `/workspace/call`, allow deferred reload only after
  leaving the call, and contain no 120s active-call reload loop. Focused
  asset-cache, foreground-reconnect and realtime-reconnect browser contracts
  passed.
- [x] VST-19 Prove focus loss, tab switch, UI clicks and sidebar toggles do not
  create a reconnect loop.
  Proof: foreground admission recovery in public join and dashboard enter-call
  now requests only `lobby/queue/request` over an already-open socket and never
  constructs a new admission websocket on focus/pageshow. The focused
  `foreground-reconnect-contract`, `realtime-reconnect-browser-contract`,
  frontend build and `git diff --check` passed.
- [x] VST-20 Prove the 5-second second-connect path does not remove a
  participant from the roster or mark the call presence as left.
  Proof: frontend lifecycle contract now asserts second-connect transport
  replacement is not semantic `room/leave`; backend room-leave snapshot contract
  now proves passive transport disconnect keeps DB-backed membership visible and
  does not mark `left_at`. Focused reconnect, roster-stability,
  room-leave-cleanup and containerized PHP SQLite contracts passed. Online
  follow-up found stale `socket_closed` second-connect scheduling with already
  complete rosters; the close handler now only allows socket-close second
  connect before open and while the expected roster is incomplete. Deployed
  without push/DNS/certbot changes to asset version `20260511155401`; immediate
  post-deploy logs show no grouped errors or `second_connect` hits.
- [x] VST-21 Prove browser `VideoFrame` objects are closed or avoided in the
  active media path.
  Proof: the active Gossip-primary contract now asserts the protected browser
  WebCodecs publisher is skipped, DOM-canvas readback keeps the
  `MediaStreamTrackProcessor` VideoFrame source behind an explicit kill switch,
  and a runtime guard proves no processor is constructed when Gossip-primary
  readback is active. Existing SFU/WebCodecs and Wavelet contracts prove late,
  source, scaled, decoded and generated `VideoFrame` objects are closed in
  `finally` paths. Focused contracts
  `gossip-primary-one-connect-media-runtime-contract.mjs`,
  `sfu-video-frame-primary-path-contract.mjs`,
  `sfu-protected-browser-encoder-contract.mjs` and
  `wavelet-codec-header-contract.mjs` passed; no deploy was required because
  only contract/sprint documentation changed.
- [x] VST-22 Remove normal-session console spam from Call-App diagnostics while
  preserving admin diagnostics.
  Proof: Call-App diagnostics no longer write direct `[CallAppDiagnostics]`
  console output; focused observability, client diagnostics and call-diagnostics
  contracts plus frontend build passed.
- [x] VST-22a Remove Chat Archive pseudo-polling from the active call:
  `/chat-archive?tail=1&limit=50` is only an idempotent initial DB backfill
  after WebSocket open, not a `room/snapshot` loop; live chat stays on
  WebSocket and older history loads only on user request.
  Proof: `chat-archive-bootstrap-contract.mjs`, frontend build and
  `git diff --check` passed. Deployed without push/DNS/certbot changes to
  asset version `20260511134800`; post-deploy source grep shows no
  `bootstrapChatArchive('room_snapshot')`, keeps
  `bootstrapChatArchive('websocket_open')`, and recent backend logs show no
  `chat-archive`, `chat_history`, `database is locked` or `500` hits.
- [x] VST-23 Remove or isolate the Whiteboard iframe unsafe-navigation console
  warning without weakening iframe isolation.
  Proof: Call-App iframe URLs now use same-origin `/call-app/...` edge paths
  while retaining the opaque sandbox without `allow-same-origin`. Focused
  CSP/PostMessage and production deploy contracts, frontend build and
  `git diff --check` passed. Deployed without push/DNS/certbot changes to
  asset version `20260511142914`; post-deploy checks show app and same-origin
  `/call-app/whiteboard/public/index.html` return `200`, all production
  containers are up, and recent backend/WS logs show no grouped errors.
- [x] VST-24 Run focused local contracts for each completed build-mesh station
  and final integration: orchestration, reconnect/reload, publisher profile
  application, receiver render evidence and UI status.
  Proof: `npm run test:contract:gossip`, `npm run build`, and
  `npx playwright test tests/e2e/gossip-frame-pixel-proof.spec.js --workers=1`
  passed after the DataChannel receive/admission fixes.
- [x] VST-25 Deploy the station-complete integration without push/DNS/certbot
  after focused checks pass.
  Proof: deployed locally without push using `VIDEOCHAT_DEPLOY_SKIP_CERTBOT=1`,
  `VIDEOCHAT_DEPLOY_HCLOUD_DNS=0`,
  `VIDEOCHAT_DEPLOY_REFRESH_DNS_ON_PREPARE=0`; asset version
  `20260510231613`.
- [x] VST-26 Run post-deploy diagnostics for the build-mesh port and group all
  failures before a second deploy attempt.
  Proof: post-deploy diagnostics showed app/API `200`, call-app availability
  `200 apps=6`, chat archive `200 messages=50`, and the 60-second production
  live proof reported no noisy failures.
- [x] VST-27 Run the online two-browser live proof against the target call and
  keep it stable for 30 minutes before moving to layout work.
  Proof: production Chromium live proof against call
  `39c5b3ea-855b-40fd-b030-c8af1d512605` passed for `1800000ms`
  from `2026-05-10T23:55:54.446Z` to `2026-05-11T00:26:31.536Z`
  with `60` samples, max pattern score `9`, admin load baseline `1`,
  sender load baseline `2`, websocket close/error counts `0/0`, no console
  errors and no noisy failures.
  Post-deploy proof: deployed locally without push/DNS/certbot changes to asset
  version `20260511003234`; 60-second production live proof passed from
  `2026-05-11T00:33:59.799Z` to `2026-05-11T00:35:36.811Z` with scores
  `9, 9, 10, 9`, stable load baselines `1/2`, websocket close/error counts
  `0/0`, no console events and no noisy failures.
- [x] VST-28 Right sidebar: split the user tab into Lobby and Present Users with
  a clear divider, independent pagination and responsive heights.
  Proof: `right-roster-lobby-users-contract` verifies Lobby above Present Users
  with `.roster-section-divider`, independent pages and responsive section
  grid; `test:contract:participant-roster` is green.
- [x] VST-29 Right sidebar: show search for Lobby/Users only when the section
  has more than one page.
  Proof: `participantUi.ts` gates `showLobbySearch` and `showUsersSearch` on
  more than one unfiltered page or an active query; roster contract is green.
- [x] VST-30 Right sidebar: make user action icons larger, keep kick separated,
  and add an options gear above the table.
  Proof: `CallWorkspacePanels.css` keeps roster actions at `38px`, separates
  `.roster-kick-btn`, and `RightRosterPanel.vue` exposes the options gear.
- [x] VST-31 Right sidebar: gear view lists which user/call-app actions are
  visible and grantable, including read/write/delete-style permissions.
  Proof: `call-app-participant-grants-contract`, `right-roster-lobby-users-contract`
  and `call-access-lobby-concurrency-contract` are green.
- [x] VST-32 Right sidebar: mobile proof that Lobby/Users remain reachable and
  actions are tappable.
  Proof: `npm run test:e2e:lobby-concurrency` passes the desktop lobby
  concurrency flow and the `390x844` mobile right-roster proof with Lobby,
  Present Users, visible divider metrics, options gear and tappable allow/remove
  actions.
  Deploy proof: deployed locally without push/DNS/certbot changes to asset
  version `20260511071207`; post-deploy production live proof against call
  `39c5b3ea-855b-40fd-b030-c8af1d512605` passed from
  `2026-05-11T07:15:06.535Z` to `2026-05-11T07:16:43.795Z` with four samples,
  remote canvas `patternScore=9` throughout, websocket close/error counts
  `0/0`, no console events and no noisy failures.
- [x] VST-33 Background/blur diagnosis: run the current compositor and browser
  contracts after video is stable, and identify whether failure is assets,
  model/wasm load, capture gating, mask/compositor or UI state.
  Proof: background runtime diagnostics, aspect preservation, foreground and
  realtime reconnect contracts passed. The full fallback suite was blocked by
  sandbox `EPERM` when its Vite/Chromium warmup opened localhost, but the
  isolated warmup contract passed outside the sandbox. The only code-level
  blocker found was proof-harness drift: the production background smoke and
  native audio harness still wrote outgoing profile version `5` while runtime
  preferences require version `6`.
- [x] VST-34 Background/blur fix: repair only the identified blocker, without
  reintroducing regression loops or background-tab media policy as a hidden
  call-video behavior.
  Proof: updated the production background smoke and native audio harness media
  preferences to profile version `6`; `test:contract:background-runtime`,
  `test:contract:background-aspect`, isolated
  `background-compositor-warmup-contract.mjs`,
  `foreground-reconnect-contract.mjs` and
  `realtime-reconnect-browser-contract.mjs` pass. No Background runtime,
  Gossip, SFU, regression loop or background-tab policy code was changed.
- [ ] VST-35 Background/blur proof: browser smoke proves blur and replacement
  render on the active call preview without destabilizing media send.
- [ ] VST-36 IAM: split the remaining call-access/IAM test work into subagent
  batches after video and sidebar gates pass.
- [x] VST-37 IAM worker batch 1: guest join/lobby/admission/rejoin tests.
  Proof: Worker 1 completed focused guest/lobby/admission/rejoin coverage;
  Docker SQLite direct-join and lobby cleanup contracts plus selected IAM
  SQLite contracts passed. Broader IAM aggregate blockers remain separate.
- [x] VST-38 IAM worker batch 2: owner/mod/admin rights, kick and role updates.
  Proof: Worker 2 completed focused owner/mod/admin rights, active kick and
  role-update work; Docker SQLite owner/mod/admin contracts,
  `call-access-rejoin-kick-contract.sh`,
  `call-access-anonymous-temp-rights-contract.sh`, frontend admin-owner rights,
  kicked rejoin denial and owner-transfer contracts passed. Broad
  `call-access-session-contract.sh` still has a separate pre-existing
  personalized-join fixture failure and remains outside this checkbox.
- [ ] VST-39 IAM worker batch 3: cross-org, duplicate link and stale identity
  tests.
- [x] VST-40 Primary-admin call rescue: user `#1` can reactivate terminal calls
  from the admin call list, and the live target call is owner-absence immune.
  Proof: added `POST /api/calls/{id}/reactivate`, primary-admin-only UI action
  and redacted `call_reactivated` audit event. Deployed production asset
  `20260511082002` without push/DNS/certbot issuance. Remote
  `call-reactivate-endpoint-contract.sh` passed inside the backend container;
  `/health` and `/join/0117ab06-d292-4811-85aa-6fa1ce16f75d` returned `200`.
  DB proof: call `39c5b3ea-855b-40fd-b030-c8af1d512605` is `active`, owner
  `#1` is `allowed`, the join link expires `2099-12-31T23:59:59+00:00`, and
  owner-absence snapshot is disabled with `permanent_call_immune`.
- [x] VST-41 Camera capture restore: the call page asks for camera permission
  and opens a real video track immediately after joining when camera is enabled
  and deterministic test stream mode is not active. The camera toggle must not
  leave duplicate/stale capture pipelines behind, and a later local camera
  freeze must be recoverable without call reconnect or page reload.
  Proof: restored immediate capture-only local camera acquisition, guarded
  camera reconfigure against stale streams and added local-track watchdog
  recovery without page reload or call reconnect. Focused contracts
  `local-camera-capture-start-contract.mjs` and
  `vst-41-camera-proof-contract.mjs` passed on 2026-05-11.
- [ ] VST-41a Sputnik live swarm proof: adapt Alex'
  `origin/gossip/1.0.8-build-mesh` Sputnik station for the online call so about
  20 simulated video participants join the target call with distinct generated
  video tiles and synthetic beep audio over the Gossip path.
- [x] VST-41a.1 Sputnik sidebar control: user `#1` can start/stop 10 synthetic
  Sputnik participants from the call left sidebar without popups and without
  exposing the control to other users.
  Proof: replaced popup windows with `POST/GET/DELETE
  /api/calls/{id}/sputnik-swarm`, added the primary-user-only backend gate,
  `videochat-sputnik-runner-v1` Headless-Chromium service and normal join-flow
  Sputnik media shim. `sputnik-user-one-sidebar-contract.mjs`, PHP syntax
  checks, compose profile config and frontend build passed. Deployed without
  push/DNS/certbot changes to asset version `20260511121144`. Post-deploy
  diagnostics: API and app returned `200`, runner `/health` returned `ok`,
  backend reached the runner internally, one online start/stop smoke completed,
  and no Chromium child process remained after stop. Hotfix proof: online
  Sputniks were stuck at join-page "Resolving call access..." because the media
  shim inherited native `MediaDevices.addEventListener` with the wrong `this`
  binding; the shim now binds native MediaDevices methods to the original
  object. `sputnik-user-one-sidebar-contract.mjs` and frontend build passed.
  Hotfix deployed without push/DNS/certbot changes to asset version
  `20260511134133`; post-deploy probe reached
  `/api/call-access/{id}/join` and `/session` with `200`, no `Illegal
  invocation`, and stopped in lobby state `Waiting for host...` as expected for
  manual admission.
- [x] VST-41b Mobile login timeout unblock: login must not wait behind stale
  stored-session recovery or unrelated queued backend requests on smartphones.
  Proof: `/api/auth/login` responds in production in under 100ms for an invalid
  login probe, `loginWithPassword` and stored-session recovery now bypass the
  global request queue with bounded request counts/timeouts, `/login` no longer
  blocks on stale-token recovery before rendering, `mobile-login-timeout-contract.mjs`,
  `call-access-terminal-browser-flows-contract.mjs`, frontend build and build
  size contract passed. Deployed without push/DNS/certbot changes to asset
  version `20260511190129`; an iPhone 13 Playwright smoke with a deliberately
  stale stored session rendered the login form in `1634ms`. Two older static
  call-access contracts still fail on pre-existing text-matcher drift outside
  this change.
- [ ] VST-42 Final sprint deploy: run 5-10 debug loops, update `EPIC.md` and
  refill the next `SPRINT.md` from remaining backlog.
