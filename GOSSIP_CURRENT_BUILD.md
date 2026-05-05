# Gossip Mesh Current Build

Last updated: 2026-05-05

## Current State

The gossip mesh is no longer only a centralized simulation at the controller boundary.

The current frontend build has:

- A `gossipmesh` module with ops/data lane concepts, frame IDs, TTL, duplicate suppression, carrier state, heartbeats, keyframe request cooldowns, and topology events.
- Deterministic neighbor assignment through `routing.ts`.
- A `GossipController` data-plane API where `publishFrame()` seeds only the publisher neighbor set instead of iterating all peers.
- Peer-owned forwarding semantics: a receiving peer suppresses duplicates/stale generations, emits local delivery, then forwards from its own fixed neighbor set.
- An injectable `GossipDataTransport` boundary.
- A browser `GossipRtcDataChannelTransport` adapter that can bind assigned neighbors to RTCDataChannel links.
- A `topology_hint` ops-lane wire contract for server-provided neighbor assignments.
- `GossipController.applyTopologyHint()` for applying server-scoped topology epochs while rejecting wrong room/call, wrong peer, stale epochs, unknown peers, self-neighbors, duplicate neighbors, and over-fanout neighbor lists.
- A conservative gossip data-lane feature flag resolved from `VITE_VIDEOCHAT_GOSSIP_DATA_LANE`.
- Gossip data-lane modes: `off`, `shadow`, and `active`.
- Default production behavior is `off`; only `active` publishes/receives gossip data frames.
- Gossip data-lane events carry `data_lane_mode` and `diagnostics_label`.
- The local Vue gossip harness explicitly opts into `active` mode so harness simulation still runs while production defaults off.
- The live native WebRTC peer factory can now bind `RTCPeerConnection` instances to `GossipRtcDataChannelTransport` behind the gossip data-lane feature flag.
- `off` mode does not create or bind gossip data channels.
- `shadow` and `active` modes can bind data channels and observe channel readiness.
- Inbound live data-channel messages are dropped before media decode unless the flag is `active`.
- In `active`, inbound live RTCDataChannel messages now enter a live room/call-scoped `GossipController` receive path.
- Accepted live gossip `sfu/frame` deliveries are adapted into the existing SFU frame object shape and routed to `handleSFUEncodedFrame()`.
- Active live gossip decode routing emits `gossip_data_lane_frame_routed` diagnostics with source and frame identity.
- Live gossip controller and RTCDataChannel state are torn down on workspace unmount.
- Gossip RTCDataChannel transport now uses IIBIN binary envelopes by default instead of JSON text frames.
- The gossip IIBIN envelope identifies `king-video-chat-gossipmesh-iibin-media-envelope`.
- The gossip control-plane persistence contract is pinned as `king-object-store-gossipmesh-control-plane`.
- The native transport stack is explicitly tracked as `rtc_datachannel`, `king_lsquic_http3`, and `king_websocket_binary`.
- Topology hints can describe IIBIN codec/envelope metadata and King LSQUIC/WebSocket binary transports.
- Live call workspace topology hints now replace the assigned native gossip neighbor set.
- Live gossip RTCDataChannel binding is restricted to server-assigned gossip neighbors.
- Topology changes close gossip data channels for peers no longer assigned.
- Local WLVC encoded frames are offered to live gossip publication only after the conservative SFU send succeeds.
- Local live gossip publication is gated by `active`; `off` and `shadow` do not publish media frames.
- Live RTCDataChannel state changes now update gossip carrier state for assigned neighbors.
- Lost assigned gossip data-channel carriers request `gossip/topology-repair/request` over the server-backed ops WebSocket lane with a per-neighbor cooldown when the data lane is fully active.
- Executable regression coverage for the decentralized controller boundary and RTCDataChannel adapter expectations.

## What Is Still Simulated

The default controller transport is still an in-memory harness transport:

- It invokes `handleData(targetPeerId, msg, fromPeerId)` directly.
- It is useful for deterministic local testing.
- It is not a real browser-to-browser data path.

The live call workspace now binds assigned gossip neighbors to `GossipRtcDataChannelTransport` when the data lane is `shadow` or `active`.

## Current Server Role

The intended server role is coordinator/bootstrapper:

- Admission.
- Identity.
- Auth and abuse controls.
- Ops lane signaling.
- Neighbor assignment/topology hints.
- Reconnect hints and topology repair.
- Keyframe/repair control messages.

The current production media path is still SFU/server mediated outside the gossip module.

## Current Peer Role

The implemented peer-side model in `GossipController` is:

- Maintain a fixed neighbor set.
- Apply server-provided topology hints.
- Publish to neighbors only.
- Receive data from one hop.
- Suppress duplicates with `seen_window`.
- Drop stale media generations.
- Forward to neighbors except the immediate previous hop.
- Respect TTL.
- Emit local delivery events for decoder/render integration.

The missing live integration is:

- Construct per-peer gossip controllers or a per-local-peer controller facade in the call runtime. Complete with a per-room/call local controller facade.
- Bind assigned neighbor peer connections to `GossipRtcDataChannelTransport`. Complete for server-provided topology hints.
- Feed RTCDataChannel messages into `handleData()`. Complete for active inbound live data.
- Feed `onDataMessage()` deliveries into the existing remote frame/decode path. Complete for active inbound `sfu/frame` messages.
- Publish local encoded frames into the gossip data lane. Complete after successful SFU send and only in `active`.
- Update carrier state from live RTCDataChannel health. Complete for assigned neighbors.
- Request server topology repair after assigned neighbor carrier loss. Complete on the ops lane with cooldown in active mode.
- Keep live gossip workspace wiring outside the `CallWorkspaceView.vue` monolith. Complete through `workspace/callWorkspace/gossipDataLane.js`.
- Keep shell/sidebar viewport computed state outside the `CallWorkspaceView.vue` monolith. Complete through `workspace/callWorkspace/shellViewport.js`.

## Verification

Current passing checks:

- `npm run test:contract:gossip`
- `npm run test:contract:native-webrtc`
- `npm run test:contract:refactor-commit-boundaries`
- `npm run test:contract:build-size`
- `npm run test:contract:client-diagnostics`
- `npm run build`
- Targeted King extension PHPTs for IIBIN/WebSocket/LSQUIC loader guard and ticket-ring platform selectors.

Build-size status:

- The previous Vite large-route-chunk warning is resolved. `CallWorkspaceView` now builds as a small route chunk plus bounded manual chunks, all under the 500 KiB JavaScript chunk contract.

## Iteration Log

### Step 1: Decentralized Controller Boundary

Status: complete.

Changes:

- Removed central all-peer fanout from `publishFrame()`.
- Added injectable data transport.
- Added peer-owned forwarding after receive-side duplicate/stale checks.
- Added deterministic topology refresh.
- Added RTCDataChannel data-lane transport adapter.
- Added executable gossip controller regression contract.

Verification:

- `npm run test:contract:gossip` passed.
- `npm run test:contract:native-webrtc` passed.
- `npm run build` passed.

### Step 2: Build/Planning Process Docs And Server Topology Hints

Status: complete.

Changes:

- Keep this file and `GOSSIP_PLANNING.md` updated after every gossip mesh step.
- Add executable contracts for the process so the docs do not drift.
- Added `TopologyHintMessage` and `TopologyHintNeighbor` to the gossip wire contract.
- Added `GossipController.applyTopologyHint()` and ops-lane dispatch for `topology_hint`.
- Added topology epoch tracking to peer state and stats.
- Added executable topology-hint regression contract.

Verification:

- `npm run test:contract:gossip` passed.
- `npm run build` passed.

Known gap:

- Server-produced topology hints are not yet emitted by the backend or bound to the live call workspace.

### Step 3: Feature Flag The Gossip Data Lane

Status: complete.

Changes:

- Added `src/lib/gossipmesh/featureFlags.ts`.
- Added `GossipDataLaneMode` values: `off`, `shadow`, `active`.
- Added `resolveGossipDataLaneConfig()` using `VITE_VIDEOCHAT_GOSSIP_DATA_LANE`.
- Kept default production mode as `off`.
- Gated `GossipController.publishFrame()` and forwarding behind active publish mode.
- Added diagnostics fields to gossip data-lane events.
- Updated `GossipHarness.vue` to opt into active mode explicitly.
- Added executable feature-flag regression contract.

Verification:

- `npm run test:contract:gossip` passed.
- `npm run build` passed.

Known gap:

- The live call workspace does not yet consume this flag to bind RTCDataChannel transport or route decoded frames.

### Step 4: Bind Gossip RTCDataChannel Transport To Live Native Peers

Status: complete.

Changes:

- Added live call workspace creation of `GossipRtcDataChannelTransport` when `VITE_VIDEOCHAT_GOSSIP_DATA_LANE` is `shadow` or `active`.
- Added native peer factory callback hooks to bind existing and newly-created native peer connections.
- Added native peer lifecycle cleanup hook to close gossip data channels when native peers close.
- Kept `off` mode safe by avoiding transport creation and channel binding.
- Kept `shadow` observational by allowing channel state diagnostics while dropping inbound messages before media decode.
- Kept `active` explicit by allowing inbound data-channel observation but stopping at `gossip_data_lane_frame_received_unrouted` until decode routing is implemented.
- Added executable native WebRTC gossip binding contract.

Verification:

- `npm run test:contract:gossip` passed.
- `npm run build` passed.

Known gap:

- Live inbound gossip data-channel messages are not yet passed into a live `GossipController` or remote frame decode path.

### Step 5: Route Live Gossip Receives To Remote Decode

Status: complete.

Changes:

- Added a live room/call-scoped `GossipController` in the call workspace.
- Active inbound RTCDataChannel messages now feed `GossipController.handleData()` as local receives.
- Accepted controller deliveries now route `sfu/frame` messages toward `handleSFUEncodedFrame()`.
- Added a gossip-to-SFU-frame adapter with explicit `gossip_rtc_datachannel` provenance.
- Kept `shadow` observational by dropping before controller handling.
- Kept `off` inert by avoiding transport/controller creation.
- Added `GossipController.dispose()` and workspace teardown for live gossip timers/listeners/channels.
- Added executable live receive/decode route contract.

Verification:

- `npm run test:contract:gossip` passed.
- `npm run build` passed.

Known gap:

- Local outbound publication over live gossip data channels is not yet wired from the publisher path.
- Live binding currently attaches to native peer connections; it does not yet restrict data-channel creation to server-assigned gossip neighbors.

### Step 6: Native Binary Data-Plane Prerequisite

Status: complete.

Changes:

- Added `GossipIibinCodec` backed by the repo `packages/iibin` implementation.
- Made `GossipRtcDataChannelTransport` use binary `ArrayBuffer` IIBIN frames by default.
- Removed JSON stringify/parse from the gossip RTC data transport.
- Added native data-plane constants for IIBIN envelope, King object_store control plane, LSQUIC/HTTP3, and King binary WebSocket compatibility.
- Extended topology hints to carry codec/envelope/transport metadata.
- Added executable native binary data-plane contract.

Verification:

- `npm run test:contract:gossip` passed.
- `npm run build` passed.
- Existing native LSQUIC loader guard `extension/tests/676-client-http3-lsquic-loader-guard-contract.phpt` passed locally.

Known gap:

- Browser RTCDataChannel still provides the direct peer link. LSQUIC/HTTP3 and King binary WebSocket are pinned as native/server relay and fallback transport contracts for the next outbound/server-assigned topology work.
- The compiled King PHP extension loads locally when explicitly passed to PHP. Targeted native PHPTs pass, but the full extension PHPT suite still has broader existing failures and LSQUIC migration gaps.

### Step 7: Outbound Live Gossip Publication With Server-Assigned Neighbor Filtering

Status: complete.

Changes:

- Added server topology ingestion in the call workspace for direct `topology_hint` and `call/gossip-topology` ops-lane messages.
- Added an assigned native gossip neighbor set derived from `GossipController.applyTopologyHint()`.
- Restricted live `GossipRtcDataChannelTransport` binding to assigned gossip neighbors only.
- Closed gossip data channels when a topology update removes a neighbor.
- Added local encoded-frame gossip publication after `sendClient.sendEncodedFrame(outgoingFrame)` succeeds.
- Kept SFU conservative by returning on SFU send failure before gossip publication.
- Kept `off` and `shadow` inert for media publication by requiring `GOSSIP_DATA_LANE_CONFIG.publish`.
- Preserved SFU data/protected-frame payload fields, codec/runtime metadata, and tile layout metadata in outbound gossip messages.
- Added executable outbound live publication and server topology ingestion contracts.

Verification:

- `npm run test:contract:gossip` passed.
- `npm run test:contract:native-webrtc` passed.
- `npm run build` passed.

Known gap:

- The server still needs to produce operational topology hints from real room membership and repair state.
- Gossip publication currently mirrors the local encoded frame after SFU success; replacing SFU fanout remains gated behind later health, repair, and rollout work.

### Step 8: Neighbor Health And Topology Repair Scaffold

Status: complete.

Changes:

- Added `GossipController.setCarrierState()` for explicit live carrier updates from the transport integration layer.
- Carrier transitions now log `carrier_state_change`; lost carriers also log `reconnect_requested`.
- Mapped live RTCDataChannel states into connected/degraded/lost carrier states for assigned gossip neighbors.
- Added `gossip/topology-repair/request` ops-lane requests when an assigned gossip neighbor data channel is lost.
- Added a per-neighbor repair cooldown to avoid reconnect storms.
- Kept repair inert in `off` and `shadow` by requiring active publish and receive semantics.
- Added executable neighbor-health/topology-repair contract coverage.

Verification:

- `npm run test:contract:gossip` passed.
- `npm run test:contract:native-webrtc` passed.
- `npm run build` passed.

Known gap:

- The backend still needs to consume `gossip/topology-repair/request` and issue replacement topology hints from real membership/link-health state.

### Step 9: Extract Live Gossip Workspace Glue

Status: complete.

Changes:

- Moved live gossip data-lane orchestration from `CallWorkspaceView.vue` into `src/domain/realtime/workspace/callWorkspace/gossipDataLane.js`.
- Moved shell/sidebar viewport computed state into `src/domain/realtime/workspace/callWorkspace/shellViewport.js`.
- Kept the call workspace public wiring surface to five callbacks: topology hint ingestion, outbound gossip publication, native peer binding, native peer cleanup, and teardown.
- Updated gossip contracts to accept the helper-module implementation location while preserving the behavioral checks.
- Updated the refactor-boundary contract to require the new gossip and shell viewport helpers.
- Brought `CallWorkspaceView.vue` down to 2149 lines, safely under the tightened 2150-line refactor boundary.
- Fixed a stale `clientDiagnostics.js` relative import that the build surfaced during the helper-boundary pass.
- Moved inline call-workspace diagnostics context registration into `clientDiagnostics.js`.
- Added Vite manual chunks for the call workspace route graph so the build no longer emits the large chunk warning.
- Added an executable build-size contract that fails if any built JavaScript chunk exceeds 500 KiB.
- Fixed SFU retry exhaustion diagnostics to report `sfu_connect_exhausted` instead of the wrong subscribe-failure surface.
- Resolved the native MCP remote-control deadline/cancel/stop observation blocker by aligning native runtime-control monotonic milliseconds with PHP `hrtime(true)`.

Verification:

- `npm run test:contract:refactor-commit-boundaries` passed.
- `npm run test:contract:gossip` passed.
- `npm run test:contract:native-webrtc` passed.
- `npm run test:contract:client-diagnostics` passed.
- `npm run test:contract:build-size` passed.
- `npm run build` passed.
- Native MCP focused PHPT slice `340`, `341`, `310`, and `311` passed with the compiled King extension.

Known gap:

- Backend topology repair handling remains the next gossip implementation step.
