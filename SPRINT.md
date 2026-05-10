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
- [ ] GSP01-03 Make `client/capabilities.v1` a hard orchestrator input:
  persistence failure must not silently ACK success; the ACK must report stored
  state, epoch and redacted public projection.
- [ ] GSP01-04 Add durable `media_session_plan.v1` ownership: monotonic plan
  epoch, participant state, `waiting_for_gossip`, `streaming_720p30`,
  `throttled_50`, `throttled_25`, and `stuck_not_sending`.
- [ ] GSP01-05 Collapse room media truth to one authoritative snapshot shape:
  capabilities, media plan, participant media state and diagnostics counters
  must come from the orchestrator, not parallel legacy producers.
- [ ] GSP01-06 Frontend capability bridge: send capabilities after admitted
  websocket join, retry only when capabilities change, and wait for a matching
  plan epoch before starting local video publication.
- [ ] GSP01-07 Frontend media state application: local capture/publish/receive
  state must be driven by `media_session_plan.v1`, not local runtime switching
  or remote peer counts.
- [ ] GSP01-08 Park SFU from the active stream path: no `sfu_first`, no SFU
  fallback, no SFU socket restart as media recovery while the plan transport is
  gossip. Plan/deploy config must make `gossip_primary` and
  `VITE_VIDEOCHAT_GOSSIP_DATA_LANE=active` explicit.
- [ ] GSP01-09 Build the gossip readiness barrier: a call may wait up to five
  minutes for planned gossip peers before streaming or marking a participant
  `stuck_not_sending` with a reason.
- [ ] GSP01-10 Publisher dispatch: encode 720p30 keyframes/deltas and send only
  `gossip.media.frame.v1` for planned gossip transport; do not emit `sfu/frame`
  on the active gossip path. Decide whether the sprint uses WebCodecs VP8,
  WLVC, or protected-frame payloads and pin that choice in tests.
- [ ] GSP01-11 Receiver path: consume `gossip.media.frame.v1`, decode or hand
  off to the existing renderer, and show remote participant video tiles without
  relying on SFU peer state as the source of truth. Proof must show decoded
  pixels and `frameCount > 0`, not just a created peer or canvas.
- [ ] GSP01-12 Screenshare parity: screenshare is represented as a participant
  media stream in the same gossip envelope/state model and can enter fullscreen
  without special SFU assumptions.
- [ ] GSP01-13 Backpressure ladder: replace active reconnect/restart recovery
  with pause, 50 percent cadence, 25 percent cadence, then
  `stuck_not_sending` plus diagnostics reason. Gossip DataChannel
  `bufferedAmount`, queue depth and dropped delta/keyframe counters must feed
  the ladder.
- [ ] GSP01-14 MediaSecurity parking for this path: sender-key mismatch,
  participant transcript recovery and key-wrap delay must not block planned
  gossip frame send/receive in this sprint; log the condition instead.
- [ ] GSP01-15 Focus/UI stability: focus loss, tab visibility changes and UI
  clicks must not start reconnect loops; open sockets may request snapshot
  backfill, closed sockets reconnect only for real network closure.
- [ ] GSP01-16 Strict 720p30 active profile: remove active auto-quality,
  rescue, downgrade and regression-improvement decisions from the gossip v1
  stream path; current `realtime`/`quality` profiles are not sufficient because
  they do not define 1280x720@30 as the active target. Incompatible clients
  become explicit non-sending states.
- [x] GSP01-17 Diagnostics surface: Call Diagnostics must show live
  capabilities, plan epoch, gossip readiness, sender/receiver frame counters,
  dropped frames, backpressure state, stuck reasons and raw redacted events.
- [ ] GSP01-18 Test gate: add/convert focused contract and E2E proof for two
  and three participants seeing video through gossip with SFU disabled and no
  background regression harness in the release path.
- [ ] GSP01-19 Predeploy gate: run local build/contracts, `prod-debug.sh`
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
- GSP01-17 proof: Call Diagnostics now includes a Gossip stage and summary
  metrics for capabilities, plan epoch, gossip readiness, sender/receiver frame
  counters, drops, backpressure state, and stuck reason. `node --check` passed
  for the runtime and contract, and
  `node demo/video-chat/frontend-vue/tests/contract/call-app-call-diagnostics-contract.mjs`
  passed.
- Existing capability and media-plan code is a starting point, not yet the hard
  orchestration contract.
- Existing gossip tests may be useful as source material, but old SFU/Gossip
  fallback and regression assumptions must not define the release gate.
