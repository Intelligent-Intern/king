# SFU And GossipMesh Planning

Last updated: 2026-05-01

## Goal

Build a decentralized GossipMesh media runtime that stays simple enough to reason about under live video pressure.

The codec quality can remain separate. The current problem is signalling and transport coordination: frames, buffering, recovery, and reconnect are being mixed together. The target is a two-lane design:

- Ops lane: low-bandwidth operational signal, membership, liveness, topology, pressure, recovery intent, carrier health.
- Data lane: high-bandwidth media frames and frame-adjacent metadata.

Reconnect must be gated by the ops lane. Data-lane loss, dropped frames, decode gaps, backpressure, or missing keyframes should trigger media recovery behavior, not socket reconnect, unless the ops lane proves carrier loss.

## Architecture Position

The goal is not to remove GossipMesh from the hot path. The goal is to make GossipMesh simple, bounded, and mathematically legible.

The mesh should be decentralized in media/data flow but not chaotic:

- Peers can forward media data according to a small deterministic gossip rule.
- The backend can still admit participants and sign initial room/call policy.
- The ops lane maintains carrier/liveness and topology hints.
- The data lane forwards bounded media frames without owning reconnect.
- No browser should invent room admission, but admitted peers can participate in forwarding.

## Best-Practice Constraints

The decentralized design still needs to conform to hard-won real-time media practice. The point is not to copy a traditional SFU blindly, but to preserve the operational properties that make real-time systems survivable.

### Separate Liveness From Media Quality

Best practice is to keep transport/carrier health independent from media quality.

- Heartbeats, acks, membership, and topology belong on the ops lane.
- Frame loss, jitter, decode failures, and frozen video belong on the data lane.
- Data quality can degrade while carrier remains alive.
- Reconnect is justified only by carrier loss, auth/session rotation, or explicit server revocation.

This directly prevents the current failure class where media symptoms can cascade into constant reconnects.

### Bound All Fanout

Gossip must have strict upper bounds.

- Fixed fanout, not fanout proportional to room size.
- TTL clamped by room size.
- Per-publisher seen windows.
- Per-peer byte budgets.
- Per-neighbor pressure scores.

Unbounded gossip is just accidental broadcast amplification. The intended mesh should behave more like controlled epidemic forwarding than flooding.

### Prefer Repair Over Reconnect

Real-time systems should repair streams before replacing transports.

Use this ladder for data-lane problems:

1. Drop stale deltas.
2. Request a keyframe through ops.
3. Lower layer or frame rate.
4. Change gossip neighbor or relay path.
5. Reduce fanout.
6. Mark data route degraded.

Reconnect comes after ops-lane carrier loss, not after this ladder is exhausted.

### Keep Admission Backend-Authoritative

Decentralized forwarding should not mean decentralized trust.

- Backend admits participants into `call_id` and `room_id`.
- Backend signs or authorizes the initial ops epoch.
- Peers forward only for admitted participants.
- Room policy, revocation, and downgrade rules stay backend-authoritative.
- Peers must not create room identity, participant identity, or admission state.

This keeps the mesh compatible with existing access control while still allowing peer forwarding.

### Protect Payloads Independently Of Transport

Transport encryption is not enough for a mesh because intermediate peers can forward data.

- Media payloads should remain protected at the application frame/envelope layer.
- Forwarding peers may inspect bounded routing metadata only.
- Forwarding peers should not decrypt media.
- Ops messages that affect topology, epochs, or recovery should be authenticated or bound to the admitted session.

This matches the current protected-media direction without forcing all media through a central SFU.

### Make Congestion Local

Pressure should affect the pressured route or peer first.

- A slow peer should receive fewer frames, lower layers, or different routes.
- A slow peer should not force healthy peers to reconnect.
- Publisher downshift should happen only on publisher-side pressure or broad route pressure.
- Neighbor selection should avoid peers with sustained data-lane pressure while keeping their ops carrier alive.

This keeps a single bad route from poisoning the whole call.

### Use Monotonic Identities

Every meaningful event needs stable monotonic identity.

- Ops: `ops_epoch`, `signal_sequence`.
- Data: `media_generation`, `frame_sequence`.
- Transport: `socket_generation` or `carrier_generation`.
- Route: `route_epoch` if topology changes independently.

Without monotonic identity, the receiver cannot distinguish reorder, duplicate, stale epoch, and actual loss.

### Design For Idempotence

Gossip creates duplicates by design, so duplicate handling must be normal and cheap.

- Duplicate data frame: drop silently or low-rate count.
- Duplicate keyframe request: coalesce by publisher track and cooldown.
- Duplicate topology hint: ignore if same or older epoch.
- Duplicate heartbeat ack: update liveness only if sequence is current.

Idempotence is what makes decentralized forwarding practical.

### Keep The Algorithm Observable

Every recovery or routing decision should identify its lane and cause.

Required fields:

- `lane`
- `call_id`
- `room_id`
- `peer_id`
- `publisher_id`
- `track_id`
- `ops_epoch`
- `signal_sequence`
- `media_generation`
- `frame_sequence`
- `carrier_state`
- `route_id`
- `recovery_action`
- `reconnect_allowed`

Best practice here is not more logs; it is structured cause tracing. We need to prove whether a reconnect was caused by ops carrier loss or by a data-plane symptom.

### Keep Timing Windows Conservative

Carrier windows should avoid flapping.

- Heartbeats can be frequent, but carrier loss should require several missed intervals.
- Degraded carrier should trigger topology refresh before reconnect.
- Data recovery cooldowns should coalesce repeated frame gaps.
- Route changes should have cooldowns to avoid oscillation.

The design should prefer short media glitches over reconnect storms.

## Non-Negotiable Invariants

- There are exactly two logical lanes: `ops` and `data`.
- Reconnect is an ops-lane decision only.
- Data-lane frame loss never directly reconnects a socket.
- Ops-lane carrier loss means missed heartbeat/ack quorum over a bounded window, not merely remote video freeze.
- Every data frame has identity: `call_id`, `room_id`, `publisher_id`, `track_id`, `media_generation`, `frame_sequence`.
- Every ops signal has identity: `call_id`, `room_id`, `peer_id`, `ops_epoch`, `signal_sequence`.
- Each receiver accepts monotonic data frames per `publisher_id + track_id + media_generation`.
- Duplicate data frames are expected in gossip and must be cheap to discard.
- Delta frames are disposable. Keyframes are recovery anchors.
- Slow peers are isolated by routing and layer choice, not by reconnecting healthy peers.
- The algorithm must be bounded by fixed fanout, TTL, seen-window size, and byte budgets.

## Two-Lane Model

### Ops Lane

The ops lane is the carrier signal. It should be low-rate and reliable enough to decide whether a peer is still connected to the call graph.

Ops messages:

- `hello`
- `heartbeat`
- `heartbeat_ack`
- `membership_delta`
- `topology_hint`
- `pressure_signal`
- `keyframe_request`
- `layer_request`
- `carrier_lost`
- `carrier_restored`
- `leave`

Ops state:

- `ops_epoch`
- `signal_sequence`
- `last_seen_ops_at_ms`
- `last_ack_at_ms`
- `missed_heartbeat_count`
- `carrier_state`: `connected`, `degraded`, `lost`
- `neighbor_set`
- `relay_candidates`

Reconnect rule:

- Reconnect only when `carrier_state` becomes `lost`.
- `lost` requires missed ops heartbeats or a failed ops ack quorum for a bounded window.
- Media freeze, decode failure, high data loss, or backpressure can mark data health as degraded, but cannot mark carrier lost by themselves.

### Data Lane

The data lane carries media frames. It is allowed to be lossy, duplicated, and partially ordered.

Data messages:

- `media_keyframe`
- `media_delta`
- `media_layer`
- `data_pressure_sample`

Data state:

- `media_generation`
- `frame_sequence`
- `seen_window`
- `last_keyframe_sequence`
- `last_accepted_sequence`
- `loss_estimate`
- `jitter_estimate`
- `route_age_ms`

Data recovery rule:

- Missing delta: drop and continue.
- Gap beyond tolerance: request keyframe on ops lane.
- Repeated data loss: ask ops lane for topology/layer change.
- Slow route: reduce fanout, switch relay neighbor, or lower layer.
- Do not reconnect unless ops carrier is lost.

## Simple Gossip Algorithm

Use a bounded eager gossip rule.

For each data frame:

1. Receiver checks `frame_id = publisher_id + track_id + media_generation + frame_sequence`.
2. If `frame_id` is in `seen_window`, drop it.
3. Add `frame_id` to `seen_window`.
4. If the frame is useful locally, enqueue it for decode/render.
5. If `ttl > 0`, forward to up to `fanout` neighbors selected deterministically.
6. Decrement `ttl`.

Neighbor selection:

- Maintain a small `neighbor_set`, for example 3 to 5 peers.
- Prefer peers with healthy ops carrier and low recent data pressure.
- Rotate one neighbor periodically to avoid dead regions.
- Keep a fixed upper bound. Do not let peer count create unbounded fanout.

Recommended starting constants:

- `fanout = 2`
- `ttl = ceil(log2(active_peer_count + 1))`, clamped to `2..6`
- `seen_window = 512` frame ids per publisher track
- ops heartbeat interval: `1000ms`
- carrier degraded after `3` missed heartbeats
- carrier lost after `6` missed heartbeats or no ack quorum for `8000ms`
- data keyframe request cooldown: `1000ms` per publisher track
- topology change cooldown: `3000ms`

This is intentionally simple. It gives redundancy without making every peer flood every frame.

## Reconnect Policy

Reconnect should be rare.

Allowed reconnect causes:

- Ops WebSocket/DataChannel closed.
- Ops lane cannot send or receive heartbeat traffic.
- Ops ack quorum fails for the carrier-loss window.
- Server/backend explicitly revokes or rotates the ops epoch.
- Auth/session failure requires a fresh carrier.

Forbidden reconnect causes:

- Missing media delta.
- Missing keyframe.
- Decoder reset.
- Remote canvas freeze.
- Browser `bufferedAmount` on data lane.
- Slow subscriber.
- Data route pressure.
- Single relay neighbor failure.

Those forbidden cases should request keyframe, reduce layer, change route, or pause/drop data frames.

## Pressure Handling

Pressure must stay lane-specific.

Ops lane pressure:

- If ops is delayed, prioritize heartbeats and carrier state.
- Drop nonessential diagnostics before heartbeats.
- If ops carrier is lost, reconnect.

Data lane pressure:

- Drop deltas first.
- Keep keyframes within budget.
- Lower layer for pressured peers.
- Reduce fanout temporarily.
- Rotate away from pressured neighbors.
- Request keyframe after gaps, through ops lane.
- Never use data pressure alone as reconnect evidence.

## Rewrite Task Breakdown

This is likely a large rewrite. Treat it as a controlled replacement of the signalling and media-routing model, not as a one-shot refactor. Most implementation work should still happen under `demo/video-chat/`, but these root docs track the plan and current state.

### Task 0: Build Four-Client Local Gossip Smoke Harness

Goal: create the first non-live proof that the decentralized model can work before rollout. The harness should run four mock clients on one computer, feed them from the same camera/source, display four panes in one browser window, and log every ops/data event.

Work:

- Build a local-only page or test harness under `demo/video-chat/frontend-vue/` that creates four independent client runtimes in one browser window.
- Use one camera capture or deterministic fake-camera source as the shared input.
- Treat each pane as a separate client identity with its own ops state, data state, neighbor set, seen window, media generation, and frame sequence handling.
- Render four panes, one per client, so each pane shows the stream as received by that client through the mock gossip network.
- Implement an in-memory mock gossip transport with two lanes:
  - ops lane for heartbeat, ack, topology, pressure, recovery, carrier state, and reconnect permission
  - data lane for media frames, TTL, fanout, duplicate delivery, drops, and route pressure
- Allow scripted fault injection: data drop, data delay, duplicate frames, neighbor failure, slow peer, ops heartbeat loss, and ops carrier restoration.
- Log all events into a visible event panel and downloadable JSON trace:
  - ops heartbeat/ack
  - carrier state changes
  - topology changes
  - data send/receive/forward/drop
  - duplicate and stale frame drops
  - keyframe requests
  - layer or route changes
  - reconnect requests and whether they were allowed
- Make the harness prove that data-lane loss does not reconnect and ops-lane carrier loss does.

Files likely touched:

- `demo/video-chat/frontend-vue/src/` for the local harness page/component
- `demo/video-chat/frontend-vue/src/lib/sfu/` or a new `src/lib/gossipmesh/` module for reusable mock lane/routing logic
- `demo/video-chat/frontend-vue/tests/contract/` for deterministic algorithm checks
- `demo/video-chat/frontend-vue/tests/e2e/` for the four-pane browser smoke test

Acceptance:

- One browser window shows four panes, each representing an independent local client.
- All four clients use the same camera or deterministic fake-camera source.
- The mock gossip network forwards data with bounded fanout, TTL, and seen-window dedupe.
- Data-lane loss, duplicate frames, slow peer, and route pressure do not trigger reconnect.
- Ops-lane carrier loss triggers exactly one reconnect request for the affected client.
- Every reconnect event includes `lane: ops`, `carrier_state: lost`, `reconnect_allowed: true`, and first-cause fields.
- The harness exports or prints a structured event trace that can be attached to bug reports.

### Task 1: Freeze The Current Failure Surface

Goal: make the present behavior measurable before changing the architecture.

Work:

- Add a diagnostic for every reconnect attempt with `lane`, `carrier_state`, `reconnect_allowed`, `reconnect_reason`, and first-cause fields.
- Add a diagnostic for every data-lane recovery action: keyframe request, layer downshift, route change, frame drop, jitter reset.
- Add counters for duplicate, stale, out-of-order, and wrong-generation frames.
- Add a short test or probe that creates data-lane loss without ops-lane loss.

Files likely touched:

- `demo/video-chat/frontend-vue/src/domain/realtime/workspace/callWorkspace/runtimeHealth.js`
- `demo/video-chat/frontend-vue/src/domain/realtime/workspace/callWorkspace/publisherBackpressureController.js`
- `demo/video-chat/frontend-vue/src/domain/realtime/sfu/lifecycle.js`
- `demo/video-chat/frontend-vue/src/lib/sfu/sfuClient.ts`
- `demo/video-chat/backend-king-php/domain/realtime/realtime_sfu_gateway.php`

Acceptance:

- Every reconnect has exactly one first-cause diagnostic.
- No reconnect diagnostic is missing `lane`.
- We can distinguish data-lane degradation from ops-lane carrier loss in logs.

### Task 2: Define Wire Contracts For Two Lanes

Goal: stop treating all SFU/GossipMesh messages as one mixed stream.

Work:

- Define `ops` message schema: `ops_epoch`, `signal_sequence`, `peer_id`, `carrier_state`, `message_type`.
- Define `data` message schema: `media_generation`, `frame_sequence`, `publisher_id`, `track_id`, `ttl`, `route_id`.
- Add canonical constants/types for lane names and message kinds.
- Update frontend and backend decoders to classify incoming messages by lane.
- Fail closed on unknown lane values.

Files likely touched:

- `demo/video-chat/frontend-vue/src/lib/sfu/sfuTypes.ts`
- `demo/video-chat/frontend-vue/src/lib/sfu/sfuMessageHandler.ts`
- `demo/video-chat/frontend-vue/src/lib/sfu/framePayload.ts`
- `demo/video-chat/backend-king-php/domain/realtime/realtime_sfu_store.php`
- `demo/video-chat/backend-king-php/domain/realtime/realtime_gossipmesh.php`

Acceptance:

- Ops and data messages are explicitly tagged.
- Tests reject untagged or invalid lane messages.
- Existing media frames can still flow behind compatibility parsing while the rewrite is staged.

### Task 3: Build Ops-Lane Carrier State Machine

Goal: make reconnect an ops-lane-only decision.

Work:

- Add a carrier state module with states `connected`, `degraded`, and `lost`.
- Track heartbeat send/receive, ack receive, missed heartbeat count, and ack quorum.
- Expose one method that can request reconnect.
- Convert direct reconnect callers into symptom reporters.
- Add cooldowns to prevent carrier flapping.

Files likely touched:

- `demo/video-chat/frontend-vue/src/domain/realtime/sfu/lifecycle.js`
- `demo/video-chat/frontend-vue/src/domain/realtime/workspace/callWorkspace/runtimeHealth.js`
- `demo/video-chat/frontend-vue/src/domain/realtime/workspace/callWorkspace/sfuTransport.js`
- `demo/video-chat/frontend-vue/src/domain/realtime/workspace/callWorkspace/publisherBackpressureController.js`
- `demo/video-chat/frontend-vue/src/lib/sfu/sfuClient.ts`

Acceptance:

- Data-lane code cannot call socket reconnect directly.
- Remote video freeze reports data degradation and requests repair, not reconnect.
- Ops carrier loss produces one reconnect request with shared cooldown.

### Task 4: Add Media Generation And Monotonic Receiver Gate

Goal: make frame ordering and epoch changes explicit.

Work:

- Add `media_generation` to outbound data frames.
- Increment media generation on codec reset, profile reset, publisher transport epoch change, and reconnect.
- Preserve generation through backend decode, fanout, relay, and receiver handling.
- Add receiver gate keyed by `publisher_id + track_id + media_generation`.
- Drop duplicate, stale, wrong-generation, and non-monotonic frames.

Files likely touched:

- `demo/video-chat/frontend-vue/src/lib/sfu/sfuClient.ts`
- `demo/video-chat/frontend-vue/src/lib/sfu/inboundFrameAssembler.ts`
- `demo/video-chat/frontend-vue/src/domain/realtime/sfu/frameDecode.js`
- `demo/video-chat/backend-king-php/domain/realtime/realtime_sfu_gateway.php`
- `demo/video-chat/backend-king-php/domain/realtime/realtime_sfu_store.php`
- `demo/video-chat/backend-king-php/domain/realtime/realtime_sfu_broker_replay.php`

Acceptance:

- Out-of-order stale frames do not render.
- Duplicate gossip frames are ignored cheaply.
- Profile switch and reconnect require a fresh keyframe for the new generation.

### Task 5: Replace Replay Ambiguity With One Data Path Contract

Goal: remove the current ambiguity between direct fanout, live relay, and SQLite media replay.

Work:

- Decide which current paths remain during the transition.
- Keep direct fanout for same-worker delivery.
- Use one cross-worker data path at a time behind a feature flag.
- Move SQLite toward ops metadata, recovery requests, membership, and optional keyframe cache only.
- Prevent two backend paths from delivering the same data frame without receiver dedupe.

Files likely touched:

- `demo/video-chat/backend-king-php/domain/realtime/realtime_sfu_gateway.php`
- `demo/video-chat/backend-king-php/domain/realtime/realtime_sfu_broker_replay.php`
- `demo/video-chat/backend-king-php/domain/realtime/realtime_sfu_frame_buffer.php`
- `demo/video-chat/backend-king-php/domain/realtime/realtime_sfu_subscriber_budget.php`

Acceptance:

- One frame identity has one primary route or is deduped at receiver.
- SQLite is not used as high-rate media transport unless explicitly enabled.
- Cross-worker delivery does not fight the future gossip forwarding model.

### Task 6: Implement Bounded Gossip Routing

Goal: introduce the simple decentralized data-forwarding algorithm.

Work:

- Add per-peer `neighbor_set`.
- Add fixed `fanout`, clamped `ttl`, and per-track `seen_window`.
- Add deterministic neighbor selection using call/room/peer/frame identity.
- Add pressure-aware neighbor eligibility.
- Rotate one neighbor periodically to avoid dead regions.
- Keep backend admission authoritative while allowing admitted peers to forward.

Files likely touched:

- `demo/video-chat/backend-king-php/domain/realtime/realtime_gossipmesh.php`
- `demo/video-chat/frontend-vue/src/lib/sfu/sfuClient.ts`
- New frontend GossipMesh module under `demo/video-chat/frontend-vue/src/domain/realtime/` or `src/lib/sfu/`
- `demo/video-chat/backend-king-php/tests/realtime-gossipmesh-runtime-contract.php`

Acceptance:

- Fanout remains bounded as room size grows.
- TTL prevents infinite forwarding.
- Duplicates are normal and dropped by seen window.
- A slow peer is avoided as a route without being disconnected from ops.

### Task 7: Move Data Recovery Onto Ops Signals

Goal: repair data-plane failure through ops-lane requests instead of reconnect.

Work:

- Send keyframe requests as ops messages.
- Send layer requests as ops messages.
- Send topology refresh requests as ops messages.
- Coalesce duplicate recovery requests per publisher track.
- Add cooldowns for keyframe, layer, and topology repair.

Files likely touched:

- `demo/video-chat/frontend-vue/src/domain/realtime/workspace/callWorkspace/runtimeHealth.js`
- `demo/video-chat/frontend-vue/src/domain/realtime/workspace/callWorkspace/publisherBackpressureController.js`
- `demo/video-chat/frontend-vue/src/domain/realtime/sfu/lifecycle.js`
- `demo/video-chat/backend-king-php/domain/realtime/realtime_sfu_recovery_requests.php`
- `demo/video-chat/backend-king-php/domain/realtime/realtime_sfu_gateway.php`

Acceptance:

- Missing keyframes do not reconnect.
- Data loss triggers keyframe/layer/topology repair.
- Reconnect requires simultaneous ops carrier loss or explicit revocation.

### Task 8: Make Congestion Local To Route Or Peer

Goal: stop one bad peer from destabilizing the whole call.

Work:

- Track per-neighbor data pressure.
- Track per-peer ops health separately from data health.
- Lower data layer for pressured peers.
- Reduce fanout through pressured peers.
- Avoid global publisher downshift unless publisher ingress or broad route health is bad.

Files likely touched:

- `demo/video-chat/backend-king-php/domain/realtime/realtime_sfu_subscriber_budget.php`
- `demo/video-chat/frontend-vue/src/domain/realtime/workspace/callWorkspace/publisherBackpressureController.js`
- `demo/video-chat/frontend-vue/src/domain/realtime/workspace/callWorkspace/sfuTransport.js`
- Gossip routing module from Task 6

Acceptance:

- Slow peer does not trigger global reconnect.
- Healthy peers continue receiving.
- Publisher downshift explains whether pressure was local, route-wide, or publisher-side.

### Task 9: Secure And Authenticate The Mesh

Goal: keep decentralization from weakening room security.

Work:

- Keep backend-authoritative admission.
- Bind ops epoch to admitted session/call/room.
- Ensure forwarding peers cannot forge participant identity.
- Keep media protected at the application frame/envelope layer.
- Allow forwarding peers to inspect routing metadata only.
- Add tests for rejected forged ops messages and forbidden plaintext data.

Files likely touched:

- `demo/video-chat/contracts/v1/protected-media-frame.contract.json`
- `demo/video-chat/backend-king-php/domain/realtime/realtime_gossipmesh.php`
- `demo/video-chat/backend-king-php/domain/realtime/realtime_sfu_gateway.php`
- `demo/video-chat/frontend-vue/src/lib/sfu/framePayload.ts`
- `extension/tests/*gossipmesh*.phpt`

Acceptance:

- Mesh forwarding does not imply plaintext media.
- Browser peers cannot create room admission.
- Forged ops epoch or participant identity fails closed.

### Task 10: Replace Legacy SFU Recovery Loops

Goal: remove or neutralize old reconnect paths after ops carrier ownership exists.

Work:

- Audit all reconnect callers.
- Remove direct reconnect from publisher backpressure.
- Remove direct reconnect from remote freeze handling.
- Keep workspace reconnect only as ops carrier reconnect.
- Keep asset-version disconnect as explicit carrier revocation.
- Update diagnostics and tests to enforce the new rule.

Files likely touched:

- `demo/video-chat/frontend-vue/src/domain/realtime/workspace/callWorkspace/runtimeHealth.js`
- `demo/video-chat/frontend-vue/src/domain/realtime/workspace/callWorkspace/publisherBackpressureController.js`
- `demo/video-chat/frontend-vue/src/domain/realtime/sfu/lifecycle.js`
- `demo/video-chat/frontend-vue/src/domain/realtime/workspace/callWorkspace/socketLifecycle.js`

Acceptance:

- `rg "restartSfu|reconnect"` shows no data-lane direct reconnect path.
- Tests fail if data-lane pressure directly reconnects.
- Ops carrier state is the only reconnect authority.

### Task 11: Test Matrix And Rollout

Goal: prove the rewrite incrementally.

Work:

- Unit-test carrier state transitions.
- Unit-test bounded gossip fanout and TTL.
- Unit-test seen-window duplicate drops.
- Contract-test backend admission and forged-message rejection.
- Run the four-client local gossip smoke harness from Task 0 before any live rollout.
- E2E-test data loss without reconnect.
- E2E-test ops carrier loss with exactly one reconnect.
- E2E-test slow peer isolation.
- Add feature flags for old SFU fallback, two-lane ops/data, and bounded gossip.

Files likely touched:

- `demo/video-chat/frontend-vue/tests/contract/`
- `demo/video-chat/frontend-vue/tests/e2e/`
- `demo/video-chat/backend-king-php/tests/`
- `extension/tests/`

Acceptance:

- Old path can be disabled only after two-lane mesh tests pass.
- Production pressure tests can explain every reconnect as ops-lane carrier loss.
- Data-lane degradation does not trigger reconnect.

## Practical Refactor Plan

### Phase 1: Name The Lanes

Add explicit lane metadata to messages and diagnostics:

- `lane: ops | data`
- `ops_epoch`
- `signal_sequence`
- `media_generation`
- `frame_sequence`
- `carrier_state`
- `reconnect_allowed`
- `reconnect_reason`

Acceptance:

- Every reconnect diagnostic says `lane: ops`.
- Data-lane diagnostics never directly schedule reconnect.

### Phase 2: Centralize Carrier State

Create a small ops-lane carrier state machine.

States:

- `connected`
- `degraded`
- `lost`

Inputs:

- heartbeat sent
- heartbeat received
- ack received
- ops socket close
- server revocation
- auth failure

Outputs:

- carrier health diagnostic
- topology refresh request
- reconnect request only when state is `lost`

Acceptance:

- Runtime health and publisher backpressure can report symptoms but cannot reconnect directly.
- Only carrier state can call the reconnect path.

### Phase 3: Make Data Recovery Non-Reconnecting

Change data failures to use:

- keyframe request
- layer downshift
- route change
- fanout reduction
- frame drop
- jitter reset

Acceptance:

- Remote video freeze causes a keyframe request or topology hint first.
- Socket reconnect does not happen unless ops carrier is also lost.

### Phase 4: Implement Bounded Gossip Forwarding

Add the simple eager gossip rule behind a feature flag:

- fixed fanout
- clamped TTL
- per-track seen window
- deterministic neighbor selection
- ops-health-aware neighbor eligibility

Acceptance:

- Duplicate frames are dropped by `seen_window`.
- Fanout stays bounded as room size grows.
- Slow or pressured peers are avoided without disconnecting them.

### Phase 5: Split Stores By Lane

Do not use the same buffering semantics for ops and media.

Ops storage:

- membership
- epochs
- topology hints
- recovery requests
- pressure samples

Data storage:

- short-lived keyframe cache only if needed
- no durable high-rate SQLite media replay unless explicitly required

Acceptance:

- SQLite is not a high-rate media transport.
- Data replay cannot fight with gossip forwarding and create ambiguous order.

## First Code Patch

Start small:

1. Add `lane` to SFU/GossipMesh diagnostics.
2. Add `ops_epoch` and `signal_sequence` to ops messages.
3. Add `media_generation` to data frames.
4. Add a carrier state object that is the only code allowed to request reconnect.
5. Change remote freeze and data backpressure paths to request keyframe/layer/route changes instead of reconnect.

Do not change codec quality in this patch.

## Design Test Cases

- Data lane drops 20 percent of deltas; no reconnect occurs.
- Data lane drops all deltas for 3 seconds; keyframe requests occur, no reconnect unless ops heartbeats fail.
- One peer is slow; routing avoids that peer, healthy peers continue.
- A relay neighbor disappears; mesh rotates neighbor, no global reconnect.
- Ops heartbeats fail for the carrier-loss window; exactly one reconnect request occurs.
- Duplicate gossip frames arrive; receiver drops them by seen window.
- Out-of-order data arrives within one generation; receiver accepts only monotonic renderable frames.
