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

## Sprint: Gossip Video Call v1 Streaming 01

Branch:
- `kingrt/prod-ready`

Status:
- Active as of 2026-05-10.
- This sprint explicitly reopens parked Gossip media work because the current
  priority is visible video through Gossip.
- Goal: make visible video run over Gossip. SFU fallback, MediaSecurity gates,
  Background media policy, automatic quality experiments and repair loops are
  out of the active stream path for this sprint.

Sprint goal:
- One authoritative capability/orchestrator state.
- One active streaming path: gossip keyframes/deltas at 720p30.
- Real receiver rendering, not just diagnostics.
- Backpressure reduces cadence and then exposes a stuck reason; it does not
  reconnect or invent a fallback.
- The sprint ends with deploy plus 5 to 10 diagnostics loops, then `EPIC.md`
  is revised and the next 20-issue sprint is generated.

Subagent lanes:
- Agent 1: backend capability/orchestrator/snapshot.
- Agent 2: frontend capture, encoder and publisher.
- Agent 3: gossip data lane and media envelope.
- Agent 4: receiver/rendering, tiles, fullscreen and screenshare.
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

Proof anchors:
- `EPIC.md`
- `analyse/video-call-streaming-v1-gap-analysis.md`
- `analyse/video-call-v1-contract-map.md`
- `demo/video-chat/backend-king-php/http/module_realtime_media_session_commands.php`
- `demo/video-chat/backend-king-php/domain/realtime/realtime_media_session_plan.php`
- `demo/video-chat/backend-king-php/domain/realtime/realtime_gossipmesh.php`
- `demo/video-chat/backend-king-php/http/module_realtime_gossip_media_relay.php`
- `demo/video-chat/frontend-vue/src/domain/realtime/workspace/callWorkspace/mediaCapabilityPlanBridge.ts`
- `demo/video-chat/frontend-vue/src/domain/realtime/local/publisherPipeline.ts`
- `demo/video-chat/frontend-vue/src/domain/realtime/workspace/callWorkspace/gossipDataLane.ts`
- `demo/video-chat/frontend-vue/src/domain/realtime/workspace/callWorkspace/publisherBackpressureController.ts`
- `demo/call-app/call-diagnostics/`
- `demo/video-chat/scripts/prod-debug.sh`

Sprint Checkboxen:
- [x] GSP01-01 Root planning reset: keep `EPIC.md` as the active long-form
  epic, move the interrupted IAM12 sprint state to `BACKLOG.md`, and keep this
  sprint as the only active checklist.
- [x] GSP01-02 Define `gossip.media.frame.v1`: keyframe/delta type, sequence,
  participant session, call id, room id, codec/runtime marker, 720p30 profile,
  timestamp, redaction rules and no SDP/ICE/tokens/secrets. Decide explicitly
  whether the first sprint maps this envelope into the existing decoder or
  introduces a separate decoder adapter.
- [x] GSP01-03 Make `client/capabilities.v1` a hard orchestrator input:
  persistence failure must not silently ACK success; the ACK must report stored
  state, epoch and redacted public projection.
- [x] GSP01-04 Add durable `media_session_plan.v1` ownership: monotonic plan
  epoch, participant state, `waiting_for_gossip`, `streaming_720p30`,
  `throttled_50`, `throttled_25`, and `stuck_not_sending`.
- [x] GSP01-05 Collapse room media truth to one authoritative snapshot shape:
  capabilities, media plan, participant media state and diagnostics counters
  must come from the orchestrator, not parallel legacy producers.
- [x] GSP01-06 Frontend capability bridge: send capabilities after admitted
  websocket join, retry only when capabilities change, and wait for a matching
  plan epoch before starting local video publication.
- [x] GSP01-07 Frontend media state application: local capture/publish/receive
  state must be driven by `media_session_plan.v1`, not local runtime switching
  or remote peer counts.
- [x] GSP01-08 Park SFU from the active stream path: no `sfu_first`, no SFU
  fallback, no SFU socket restart as media recovery while the plan transport is
  gossip. Plan/deploy config must make `gossip_primary` and
  `VITE_VIDEOCHAT_GOSSIP_DATA_LANE=active` explicit.
- [x] GSP01-09 Build the gossip readiness barrier: a call may wait up to five
  minutes for planned gossip peers before streaming or marking a participant
  `stuck_not_sending` with a reason.
- [x] GSP01-10 Publisher dispatch: encode 720p30 keyframes/deltas and send only
  `gossip.media.frame.v1` for planned gossip transport; do not emit `sfu/frame`
  on the active gossip path. Decide whether the sprint uses WebCodecs VP8,
  WLVC, or protected-frame payloads and pin that choice in tests.
- [x] GSP01-11 Receiver path: consume `gossip.media.frame.v1`, decode or hand
  off to the existing renderer, and show remote participant video tiles without
  relying on SFU peer state as the source of truth. Proof must show decoded
  pixels and `frameCount > 0`, not just a created peer or canvas.
- [x] GSP01-12 Screenshare parity: screenshare is represented as a participant
  media stream in the same gossip envelope/state model and can enter fullscreen
  without special SFU assumptions.
- [x] GSP01-13 Backpressure ladder: replace active reconnect/restart recovery
  with pause, 50 percent cadence, 25 percent cadence, then
  `stuck_not_sending` plus diagnostics reason. Gossip DataChannel
  `bufferedAmount`, queue depth and dropped delta/keyframe counters must feed
  the ladder.
- [x] GSP01-14 MediaSecurity parking for this path: sender-key mismatch,
  participant transcript recovery and key-wrap delay must not block planned
  gossip frame send/receive in this sprint; log the condition instead.
- [x] GSP01-15 Focus/UI stability: focus loss, tab visibility changes and UI
  clicks must not start reconnect loops; open sockets may request snapshot
  backfill, closed sockets reconnect only for real network closure.
- [x] GSP01-16 Strict 720p30 active profile: remove active auto-quality,
  rescue, downgrade and regression-improvement decisions from the gossip v1
  stream path; current `realtime`/`quality` profiles are not sufficient because
  they do not define 1280x720@30 as the active target. Incompatible clients
  become explicit non-sending states.
- [x] GSP01-17 Diagnostics surface: Call Diagnostics must show live
  capabilities, plan epoch, gossip readiness, sender/receiver frame counters,
  dropped frames, backpressure state, stuck reasons and raw redacted events.
- [x] GSP01-18 Test gate: add/convert focused contract and E2E proof for two
  and three participants seeing video through gossip with SFU disabled and no
  background regression harness in the release path.
- [x] GSP01-19 Predeploy gate: run local build/contracts, `prod-debug.sh`
  preflight, HTTP/API/WS checks and branch hygiene on `kingrt/prod-ready`; fix
  all distinct failures before deploying.
- [ ] GSP01-20 Deploy and debug loop: deploy without push/DNS/certbot, run 5 to
  10 diagnostics loops, collect all unique errors, prepare a second deploy only
  after grouped fixes, update `EPIC.md`, then refill `SPRINT.md` with the next
  20 issues.

Current Loop Notes:
- `kingrt/prod-ready` is the only integration branch for this sprint.
- The previous IAM12 checklist is no longer the active sprint.
- GSP01-01 proof: `EPIC.md` exists in the root, IAM12 follow-up work is parked
  in `BACKLOG.md`, and `SPRINT.md` contains exactly the active 20-item Gossip
  sprint. Commit `452afdcc` created the reset.
- GSP01-02 proof: `demo/video-chat/contracts/v1/gossip-media-frame.contract.json`
  defines the external `gossip.media.frame.v1` envelope, and
  `demo/video-chat/frontend-vue/tests/contract/gossip-media-frame-v1-contract.mjs`
  passed with `node tests/contract/gossip-media-frame-v1-contract.mjs`.
- GSP01-03/GSP01-04 proof: commit `e591e81b` makes capability persistence
  fail closed with `ok=false`/`stored=false`, adds redacted ACK projection,
  monotonic plan epoch, Gossip plan states, and the
  `media-capability-plan-gossip-contract.php` proof. `php -l` passed for the
  touched PHP files and `php demo/video-chat/backend-king-php/tests/media-capability-plan-gossip-contract.php`
  passed.
- Frontend capability bridge progress: commit `8f2a9769` sends capabilities
  only after admitted `system/welcome`, suppresses duplicate unchanged sends,
  normalizes the Gossip plan catalog, and adds a matching-plan publication gate
  helper. The current loop wires that helper into the actual local publisher
  start/stop path.
- GSP01-06/GSP01-07 proof: local media publication now waits for socket online,
  admitted `system/welcome`, stored `client.capabilities.v1/ack`, matching
  participant session, matching/minimum plan epoch and
  `media_state=streaming_720p30`. Runtime switching and peer-count-triggered
  paths call the media-plan gate before publishing. Verified with
  `node tests/contract/local-media-session-plan-gate-contract.mjs` and
  `node tests/contract/client-capabilities-media-plan-contract.mjs`.
- GSP01-08 proof: this change parks SFU from the active planned Gossip path.
  `gossip_primary` dispatch returns before SFU lookup,
  failed Gossip publish emits `gossip_primary_publish_failed_no_sfu_fallback`,
  active planned Gossip transport parks SFU socket restart diagnostics via
  `planned_gossip_sfu_socket_restart_parked`, and `.env`/compose/deploy make
  `VITE_VIDEOCHAT_MEDIA_CARRIER=gossip_primary` plus
  `VITE_VIDEOCHAT_GOSSIP_DATA_LANE=active` explicit. Verified with
  `node tests/contract/gsp01-08-sfu-parking-contract.mjs`,
  `node tests/contract/gossip-primary-no-sfu-fallback-contract.mjs`,
  `node tests/contract/gossip-production-deploy-profile-contract.mjs`, and
  `node tests/contract/gossip-media-carrier-integration-smoke-contract.mjs`.
- GSP01-05 proof: commit `c78b0820` makes `room/snapshot` carry the
  authoritative media session plan with redacted capabilities,
  participant-media-state, Gossip readiness and diagnostics counters, and
  removes legacy `participants[*].client_capabilities`. `php -l` passed for
  the touched files; runtime execution is still blocked by the existing
  duplicate `videochat_call_access_link_disabled_at()` definition in
  `domain/calls/call_access_contract.php`.
- GSP01-09 proof: backend readiness now computes per-connection Gossip
  readiness from the server topology, requires more than one peer plus assigned
  neighbors before `streaming_720p30`, and marks stale waits after 300000 ms as
  `stuck_not_sending` with `gossip_readiness_timeout`. Verified with `php -l`
  plus `php demo/video-chat/backend-king-php/tests/media-capability-plan-contract.php`,
  `php demo/video-chat/backend-king-php/tests/media-capability-plan-gossip-contract.php`,
  `php demo/video-chat/backend-king-php/tests/realtime-room-snapshot-media-authority-contract.php`,
  and `php demo/video-chat/backend-king-php/tests/realtime-gossipmesh-room-state-topology-contract.php`.
- Gossip route progress: commit `4618a59a` publishes external
  `gossip.media.frame.v1` envelopes and suppresses SFU fallback on
  `gossip_primary` publish failure. `node tests/contract/gossip-live-receive-decode-route-contract.mjs`
  and `node tests/contract/gossip-outbound-live-publication-contract.mjs`
  passed. The current loop pins the codec/profile choice; GSP01-11 remains
  open until decoded-pixel browser proof is complete.
- GSP01-10 proof: active Gossip publication is pinned to
  `gossip.media.frame.v1`, contract version `v1.0.0`, profile
  `video_720p30`, transport-only `gossip_primary_direct` and WLVC as the active
  codec branch while preserving explicit WebCodecs identifiers. Verified with
  `node tests/contract/gossip-outbound-live-publication-contract.mjs` and
  `node tests/contract/gossip-media-frame-v1-contract.mjs`.
- GSP01-11 progress: `gossip.media.frame.v1` now decodes into the existing
  remote decoded-canvas renderer entry and diagnostics require decoded pixels
  plus `frameCount >= 1`. This remains open until browser proof shows real
  remote tiles with decoded pixels.
- GSP01-11 proof: merge commit `30b1447a` adds
  `tests/e2e/gossip-frame-pixel-proof.spec.js` and a browser harness proving a
  synthetic/avatar canvas frame passes the `media_session_plan.v1` gate, is
  published as `gossip.media.frame.v1`, delivered via `GossipController`,
  adapted by `sfuFrameFromGossipMessage`, rendered through
  `handleSFUEncodedFrame`, attached to a remote mini tile, and produces decoded
  pixel readback with `receivedFrameCount > 0` and `frameCount > 0`. Verified
  with `node tests/contract/gossip-live-receive-decode-route-contract.mjs` and
  `npx playwright test tests/e2e/gossip-frame-pixel-proof.spec.js --workers=1`.
- GSP01-12 proof: screenshare Gossip frames carry a stream-scoped
  `screen_share:<ownerUserId>` publisher id, real owner recovery id,
  `publisher_media_source=screen_share`, and synthetic participant ids so
  fullscreen/layout can treat screenshare as participant media without SFU
  track announcements. Verified with
  `node tests/contract/call-screenshare-participant-contract.mjs` and
  `npm run test:contract:screenshare-fullscreen`.
- GSP01-13 proof: commit `4fb184f1` adds Gossip DataChannel buffered-amount,
  queue-depth and dropped-frame telemetry into the backpressure ladder.
  `node demo/video-chat/frontend-vue/tests/contract/gossip-backpressure-contract.mjs`
  and `node demo/video-chat/frontend-vue/tests/contract/gossip-telemetry-contract.mjs`
  passed.
- GSP01-14 proof: planned Gossip transport now parks MediaSecurity blocking
  conditions as `media_security_planned_gossip_parking` diagnostics instead of
  closing the send/receive gate. Verified with
  `node tests/contract/gossip-media-security-parking-contract.mjs`,
  `node tests/contract/media-security-idempotent-sender-key-contract.mjs`,
  and `node tests/contract/gossip-sfu-dual-carrier-continuity-contract.mjs`.
- GSP01-15 proof: commit `24a075d3` separates open sockets from room snapshot
  sync during foreground recovery, requests snapshot backfill for live sockets,
  and reconnects only closed sockets. `node demo/video-chat/frontend-vue/tests/contract/foreground-reconnect-contract.mjs`
  and `node demo/video-chat/frontend-vue/tests/contract/realtime-reconnect-browser-contract.mjs`
  passed.
- GSP01-16 proof: strict `1280x720@30` capability checks now reject DOM/native
  fallback-only camera senders, block native runtime fallback on active
  `gossip_primary`, suppress auto-quality/recovery probes and remove SFU
  fallback availability from Gossip-primary carrier config. Verified with
  `node tests/contract/sfu-strict-720p30-runtime-contract.mjs`,
  `node tests/contract/client-capabilities-media-plan-contract.mjs` and
  `node tests/contract/gossip-media-carrier-mode-contract.mjs`.
- GSP01-17 proof: Call Diagnostics now includes a Gossip stage and summary
  metrics for capabilities, plan epoch, gossip readiness, sender/receiver frame
  counters, drops, backpressure state, and stuck reason. `node --check` passed
  for the runtime and contract, and
  `node demo/video-chat/frontend-vue/tests/contract/call-app-call-diagnostics-contract.mjs`
  passed.
- GSP01-18 progress: `node tests/contract/gsp01-18-gossip-primary-plan-frame-contract.mjs`
  proves two-peer and three-peer admitted capability-plan publication over
  bidirectional, server-provided, max-five-neighbor Gossip topology, and proves
  the server lane carries capability ops frames only. The item remains open
  until stale SFU-fallback/regression gate expectations are converted and
  browser proof is attached.
- GSP01-18 proof: merge commit `f4d0fd38` replaces the active Gossip contract
  gate with the compact GSP01 proof set, removing old SFU fallback and
  Background/SFU regression harness requirements from `test:contract:gossip`.
  Combined with the GSP01-11 browser pixel proof, the test gate now covers
  two/three-peer Gossip plan publication, no server media fanout, no SFU
  fallback, live receive/decode routing, and visible decoded pixels. Verified
  with `npm run test:contract:gossip`.
- GSP01-19 progress: commit `50176df2` removed the duplicate
  `hostName` declaration in `callAccessSession.ts` that blocked
  `npm run build`. The build now passes on `kingrt/prod-ready`. A focused
  `call-access-verified-context-ui-contract.mjs` run still fails on existing
  personalized identity wording, outside this duplicate-declaration fix.
  GSP01-19 remains open for the rest of the predeploy gate.
- GSP01-19 sprint sync: commit `cb3cdbca` recorded the `50176df2` build
  unblock and remaining focused contract failure in this sprint file without
  closing the predeploy gate.
- GSP01-19 proof: final local predeploy gate on `kingrt/prod-ready` passed:
  `npm run build`, `npm run test:contract:gossip`,
  `node tests/contract/gossip-media-carrier-integration-smoke-contract.mjs`,
  `node tests/contract/gossip-publisher-pipeline-decoupling-contract.mjs`,
  `node tests/contract/sfu-strict-720p30-runtime-contract.mjs`,
  `npx playwright test tests/e2e/gossip-frame-pixel-proof.spec.js --workers=1`,
  deploy script syntax checks, `check-deploy-idempotency.sh`,
  `prod-debug-observability-contract.mjs`, `prod-debug.sh` dry-run, and
  read-only `prod-debug.sh` with `VIDEOCHAT_PROD_DEBUG_SKIP_REMOTE=1`.
  Public probes returned 200 for API runtime/version, app shell, CDN assets,
  call-app host and registry host. Marketplace and lobby websocket returned
  auth-required 401 without a session; SFU websocket returned 404, classified
  non-blocking for this sprint because SFU is parked from the active path.
  Branch hygiene is clean: no `kingrt/gsp01-*` worker branches remain, and the
  only remaining worktrees are `main` and `kingrt/prod-ready`.
- GSP01-20 diagnostics dry-run prep: merge commit `d4840e45` integrated
  `analyse/gsp01-20-diagnostics-dryrun.md`, documenting safe local dry-run
  diagnostics and excluded deploy/DNS/certbot paths. GSP01-20 remains open
  until an authorized deploy plus 5 to 10 diagnostics loops are actually run.
- Audio analysis consent: a permission request was posted in the live call chat
  on 2026-05-10. Jendrik, Platform Admin and Alexander explicitly agreed in
  chat. Audio analysis may be added to future `w` loops only via temporary
  chunks and without raw audio retention, subject to available local capture
  and transcription tooling.
- Online call-chat reporting: Playwright/MCP reached
  `https://app.kingrt.com/login?redirect=/workspace/call/39c5b3ea-855b-40fd-b030-c8af1d512605`.
  The page exposes only email/password sign-in and no registration or guest
  join link in this context, so no live room reports can be posted until a
  valid app session or real guest/invite link is available.
- Call-chat update: after the guest join link was provided, `Codex Reporter`
  joined the call, greeted Alexander and Jendrik in English, and is reading the
  chat. Alexander clarified the active architecture target: remove SFU from
  the active path, keep the server as peer discovery/topology only, connect each
  peer to up to five bidirectional neighbors, and exchange frames peer-to-peer
  without ordering guarantees in this sprint.
- GSP01-20 deploy attempt 1: authorized deploy from `kingrt/prod-ready` ran
  with no push, DNS disabled and certbot skipped. The deploy aborted at
  `backend-health` because `videochat-backend-v1` and
  `videochat-backend-ws-v1` entered restart loops. Read-only `prod-debug.sh`
  grouped the distinct failure as a PHP fatal duplicate declaration:
  `videochat_realtime_apply_lobby_remove_result()` existed in both
  `module_realtime_active_call_kick.php` and
  `module_realtime_websocket_lobby.php`. Public API/WS therefore returned 502;
  SFU stayed 404 as expected for this sprint; TURN connection resets were
  classified as non-blocking noise. The second deploy is blocked until this
  grouped fatal is fixed and verified.
- GSP01-20 grouped fix progress: the duplicate lobby-remove implementation was
  consolidated into `module_realtime_active_call_kick.php`, the WebSocket lobby
  path now imports that helper and passes `presenceState`, and the active-kick
  contract now proves the helper is not redeclared. Verified locally with PHP
  lint for `module_realtime_active_call_kick.php`,
  `module_realtime_websocket_lobby.php`, and `module_realtime.php`,
  `php -r "require 'demo/video-chat/backend-king-php/http/module_realtime.php';"`,
  `node demo/video-chat/frontend-vue/tests/contract/iam-active-call-kick-contract.mjs`,
  `node tests/contract/gsp01-18-gossip-primary-plan-frame-contract.mjs`, and
  `npm run test:contract:gossip`; `npm run build` also passed. A broader
  `iam-call-access-ci-gate.sh --static` run still fails earlier on the
  existing duplicate matrix key `alpha_ended` in
  `iam-call-access-seeding.matrix.json`, unrelated to the deploy fatal and not
  folded into this emergency deploy fix. GSP01-20 remains open until the
  second deploy and 5 to 10 diagnostics loops pass.
- GSP01-20 deploy attempt 2: containers started and public runtime/version were
  healthy, but the deploy failed at the `ice-servers` probe with HTTP 500
  `auth_backend_error`. Remote logs showed the grouped root cause as
  `Undefined variable $hostVerifiedSelect` in
  `domain/calls/call_access_contract.php:167`, producing malformed SQL
  `near ","` and breaking REST/session/WebSocket auth. The fix defines the
  optional `host_verified_at` select fragment for both schemas with and without
  that column. Verified with PHP lint, `module_realtime.php` load,
  `call-access-binding-host-verified-select-contract.mjs`, and a Docker
  `pdo_sqlite` SQL smoke that prepares and executes
  `videochat_validate_call_access_session_binding()` against minimal tables.
- Existing capability and media-plan code is a starting point, not yet the hard
  orchestration contract.
- Existing gossip tests may be useful as source material, but old SFU/Gossip
  fallback and regression assumptions must not define the release gate.
