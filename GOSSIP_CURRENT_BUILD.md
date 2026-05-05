# Gossip Mesh Current Build

Last updated: 2026-05-05

## Current State

The gossip mesh is no longer only a centralized simulation at the controller boundary.

The current frontend build has:

- A `gossipmesh` module with ops/data lane concepts, frame IDs, TTL, duplicate suppression, carrier state, heartbeats, keyframe request cooldowns, and topology events.
- Deterministic neighbor assignment through `routing.ts` with production fanout defaulting to degree 4 and clamped to degree 3..5, so eligible rooms do not collapse into a degree-2 cycle graph.
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
- The backend now consumes `gossip/topology-repair/request` on the ops lane, validates room/call/authenticated peer context, rejects media/signaling/secret fields, and returns a bounded replacement `call/gossip-topology` topology hint to the requester.
- Gossip telemetry now exposes read-only counters/events for sends, receives, forwards, drops, duplicate suppression, TTL exhaustion, stale generation drops, RTC queue late drops, peer outbound fanout, avoided server fanout, and transport kind.
- Repair handling now writes sanitized link-health observations to King object_store-compatible records keyed by room/call/peer/lost-neighbor hashes and avoids fresh failed peer pairs during replacement topology generation.
- Repair planning now reads bounded recent King object_store topology-health observations when available, validates schema/version/kind/room/call/peer fields, rejects malformed/stale/unsafe records, and feeds recent failed pairs into topology avoidance.
- Active clients can emit sanitized `gossip/telemetry/snapshot` ops-lane messages; the backend validates and aggregates room-level counters and transport labels without storing media, SDP, ICE, socket, token, or secret fields.
- Telemetry aggregates now derive rollout-gate readiness metrics for duplicate rate, TTL exhaustion rate, late-drop rate, topology repair rate, RTC readiness, neighbor readiness, and topology epoch readiness.
- The frontend consumes sanitized `gossip/telemetry/ack` aggregate/gate payloads through a focused rollout-gate helper and emits diagnostic-only `gossip_rollout_gate_state` events.
- Rollout gates keep `off` inert, keep `shadow` observational, and only mark active gossip allowed when explicit active mode, RTC/topology readiness, and clean telemetry thresholds are all present.
- The standalone four-peer local gossip harness remains available at `demo/video-chat/frontend-vue/public/gossip-harness.html`, has adjustable fanout with default degree 4, and clamps fanout to degree 3..5 plus the available peer degree.
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
- Reconnect hints and topology repair. The first backend repair handler is operational for requester-scoped replacement hints.
- Keyframe/repair control messages.

The current production media path is still SFU/server mediated outside the gossip module.

## Current Peer Role

The implemented peer-side model in `GossipController` is:

- Maintain a fixed neighbor set.
- Apply server-provided topology hints.
- Publish to neighbors only, with local forwarding bounded by the shared degree-3 minimum and hard fanout cap.
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
- Consume server replacement topology hints after repair. Complete for `call/gossip-topology` and direct `topology_hint` messages.
- Expose telemetry proving the peer fanout shape. Complete for controller/workspace/RTC transport counters.
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
- `./demo/video-chat/backend-king-php/tests/realtime-gossipmesh-runtime-contract.sh`
- `make -C extension test TESTS=tests/748-native-toolchain-linker-selector-contract.phpt`
- `make -C extension -j1 V=1`
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

- The macOS `-soname` linker issue is fixed for `make -C extension`. The broader `make build` path can still fail earlier if local curl headers/pkg-config are unavailable.

### Step 10: Backend Topology Repair Handling

Status: complete.

Changes:

- Added an ops-lane decoder for `gossip/topology-repair/request`.
- Required `lane: ops`, matching room/call context, authenticated `peer_id`, active room membership, and a non-empty lost neighbor distinct from the requester.
- Rejected media/signaling/secret fields in the wrapper and nested payload, including SDP, ICE candidates, sockets, raw media data, protected frames, encoded frames, and sender keys.
- Added backend topology-hint builders for requester-scoped `call/gossip-topology` responses.
- Recomputed a bounded topology from current room participants and sent a replacement `topology_hint` only to the requester.
- Kept the backend out of media distribution: no frame relay, no SDP/ICE expansion, no protected media payloads.
- Extended the backend runtime contract with repair decode, safety rejection, authenticated-peer validation, context mismatch rejection, and topology response assertions.

Verification:

- `php -l demo/video-chat/backend-king-php/domain/realtime/realtime_gossipmesh.php` passed.
- `php -l demo/video-chat/backend-king-php/http/module_realtime_websocket_commands.php` passed.
- `php -l demo/video-chat/backend-king-php/tests/realtime-gossipmesh-runtime-contract.php` passed.
- `./demo/video-chat/backend-king-php/tests/realtime-gossipmesh-runtime-contract.sh` passed.

Known gap:

- Topology repair remains server-assisted control-plane coordination. It does not make gossip carry primary media responsibility.

### Step 17: Topology Health object_store Readback

Status: complete.

Changes:

- Added optional King object_store readback helpers for bounded `vcgmh_` topology-health inventory scans and payload fetches.
- Validated readback records against the gossip runtime contract, topology-health kind, schema version, room/call context, peer/lost-peer IDs, object key, pair key, and cooldown deadline.
- Ignored absent object_store APIs, malformed JSON, wrong-context records, wrong schema/kind records, stale observations, and payloads containing media, SDP, ICE, socket, token, or secret fields.
- Merged valid historical failed pairs with the fresh repair observation before replacement topology planning.
- Extended the backend runtime contract to prove readback avoidance, stale expiry, unsafe/malformed rejection, websocket repair consumption, and absent object_store inertness.

Verification:

- `php -l demo/video-chat/backend-king-php/domain/realtime/realtime_gossipmesh.php` passed.
- `php -l demo/video-chat/backend-king-php/http/module_realtime_websocket_commands.php` passed.
- `php -l demo/video-chat/backend-king-php/tests/realtime-gossipmesh-runtime-contract.php` passed.
- `./demo/video-chat/backend-king-php/tests/realtime-gossipmesh-runtime-contract.sh` passed.

### Step 11: Gossip Telemetry

Status: complete.

Changes:

- Added `GossipTelemetryCounters` to `GossipController` peer state and `getStats()`.
- Counted sent, received, forwarded, dropped, duplicate, TTL-exhausted, stale-generation-drop, RTC queue late-drop, peer outbound fanout, avoided server fanout, RTCDataChannel send, and in-memory harness send paths.
- Added transport kind labels for `in_memory_harness` and `rtc_datachannel`.
- Added hop-latency metadata when timestamp fields are available.
- Added RTC transport telemetry callbacks and wired them through the live call workspace without changing routing decisions.
- Added `gossip-telemetry-contract.mjs` and included it in `npm run test:contract:gossip`.

Verification:

- `npm run test:contract:gossip` passed.
- `npm run test:contract:build-size` passed.
- `npm run test:contract:refactor-commit-boundaries` passed.
- `npm run build` passed.

Known gap:

- Telemetry now aggregates in backend room presence state. Persisting telemetry history outside live presence remains future rollout work.

### Step 12: Four-Peer Local Harness Contract

Status: complete.

Changes:

- Kept the standalone browser harness at `public/gossip-harness.html` as a four-peer local test with Alice, Bob, Charlie, and Diana.
- Added an adjustable fanout control to the local harness.
- Set the default local fanout to degree 4.
- Added a hard local fanout cap of 5 while also clamping to the available peer degree, so the four-peer harness cannot exceed 3 live neighbors per peer.
- Wired `gossip-harness-faults-contract.mjs` into `npm run test:contract:gossip`.
- Extended the harness contract to pin the four-peer setup, fault modes, adjustable fanout control, hard cap, bounded neighbor selection, bounded forwarding, and package-script wiring.

Verification:

- `node tests/contract/gossip-harness-faults-contract.mjs` passed.
- `npm run test:contract:gossip` passed.

Known gap:

- The standalone harness is an in-browser/manual visual test backed by static executable contracts. The automated contract verifies the harness behavior surface and wiring, not live browser video rendering.

### Step 13: Production Fanout Minimum

Status: complete.

Changes:

- Raised frontend production gossip routing default from fanout 2 to fanout 4.
- Added `MIN_EXPANDER_FANOUT = 3` and `MAX_FANOUT = 5` to the shared frontend routing module.
- Made `GossipController` use the shared routing default instead of a local fanout constant.
- Kept backend `VIDEOCHAT_GOSSIPMESH_DEFAULT_NEIGHBORS` at 4 and raised `VIDEOCHAT_GOSSIPMESH_DEFAULT_FORWARD_COUNT` from 2 to 4.
- Added backend `VIDEOCHAT_GOSSIPMESH_MIN_EXPANDER_FANOUT = 3`.
- Clamped backend topology creation, repair topology hints, and forward-target selection to the 3..5 degree policy while still respecting the number of available peers.
- Extended frontend and backend contracts so fanout 2 cannot silently return as the production default.

Verification:

- `npm run test:contract:gossip` passed.
- `./demo/video-chat/backend-king-php/tests/realtime-gossipmesh-runtime-contract.sh` passed.
- PHP syntax checks for changed backend files passed.

Known gap:

- Rooms with fewer than four active peers cannot physically reach degree 3; those are clamped to the available peer degree.

### Step 14: Persistent Topology Health Records

Status: complete.

Changes:

- Added topology-health object keys with the `vcgmh_` prefix, keyed by hashed room/call/peer/lost-neighbor identifiers.
- Added sanitized topology-health observations with schema version, pair key, reason, cooldown deadline, and bounded link-health metadata.
- Wrote repair observations through King object_store when available, with a test override for executable contracts.
- Added a 120 second failed-pair cooldown map.
- Made replacement topology generation avoid fresh failed peer pairs during repair.
- Extended the backend runtime contract to pin object_store key shape, payload safety, cooldown expiry, and failed-pair avoidance.

Verification:

- `php -l demo/video-chat/backend-king-php/domain/realtime/realtime_gossipmesh.php` passed.
- `php -l demo/video-chat/backend-king-php/http/module_realtime_websocket_commands.php` passed.
- `php -l demo/video-chat/backend-king-php/tests/realtime-gossipmesh-runtime-contract.php` passed.
- `./demo/video-chat/backend-king-php/tests/realtime-gossipmesh-runtime-contract.sh` passed.

Known gap:

- Gate decisions are now handled by the rollout-gate diagnostics step. Active gossip still remains explicit and SFU-first.

### Step 15: Room-Level Gossip Telemetry Aggregation

Status: complete.

Changes:

- Added `GossipController.createTelemetrySnapshot()` for sanitized local counter snapshots.
- Emitted `gossip/telemetry/snapshot` only when the gossip data lane is explicitly active with publish and receive enabled.
- Kept telemetry rollout labeled `sfu_first_explicit`.
- Added backend telemetry snapshot decoding, field rejection, counter whitelisting, transport label whitelisting, and room-level aggregate storage in presence state.
- Added `gossip/telemetry/ack` responses for accepted snapshots.
- Extended frontend and backend contracts to pin ops-lane-only telemetry, safe counters, safe transport labels, and media/signaling/secret rejection.

Verification:

- `npm run test:contract:gossip` passed.
- `./demo/video-chat/backend-king-php/tests/realtime-gossipmesh-runtime-contract.sh` passed.
- PHP syntax checks for changed backend files passed.
- `npm run build` passed.

Known gap:

- Aggregates are currently live room presence state. Longer-term retention and rollout dashboards are future work.

### Step 16: Native Linker Selector Cleanup

Status: complete.

Changes:

- Added a host selector to the tracked extension libtool script.
- On macOS/Darwin, extension linking now uses Darwin-compatible bundle/dynamiclib/install-name flags and avoids ELF-only `-soname`.
- Linux keeps the shared object naming path using the existing soname flags.
- Added `extension/tests/748-native-toolchain-linker-selector-contract.phpt`.

Verification:

- `make -C extension test TESTS=tests/748-native-toolchain-linker-selector-contract.phpt` passed.
- `make -C extension -j1 V=1` passed and linked without `-soname`.

Known gap:

- Top-level `make build` now has curl and OpenSSL prerequisite selectors. Remaining native warnings are compiler diagnostics, not build blockers.

### Step 17: Native Curl/pkg-config Build Prerequisite Cleanup

Status: complete.

Changes:

- Added `infra/scripts/native-curl-build-prereqs.sh` as the top-level native curl prerequisite selector.
- Top-level profile builds now accept vendored curl headers, `KING_CURL_INCLUDE_DIR`, `KING_CURL_CFLAGS`, pkg-config `libcurl` cflags, or OS-specific macOS/Linux system include paths.
- The selector documents matching macOS/Linux libcurl runtime/library candidates without adding a hard libcurl link to the extension build.
- Missing curl headers now fail with an actionable OS-specific install command, including `brew install curl pkg-config` on macOS and `sudo apt-get update && sudo apt-get install -y libcurl4-openssl-dev pkg-config` on Linux.
- The phpize generated-file restore path now makes read-only generated outputs writable before restoring, so failed profile builds do not leave generated churn.
- Added `infra/scripts/check-native-curl-build-prereqs.sh` as the executable selector/diagnostic contract.

Verification:

- `bash -n infra/scripts/native-curl-build-prereqs.sh` passed.
- `bash -n infra/scripts/check-native-curl-build-prereqs.sh` passed.
- `bash -n infra/scripts/build-profile.sh` passed.
- `./infra/scripts/check-native-curl-build-prereqs.sh` passed.
- `./infra/scripts/native-curl-build-prereqs.sh --cflags` returned `-I/opt/homebrew/opt/curl/include` on this macOS host while `pkg-config` was unavailable.
- `make -C extension test TESTS=tests/744-libcurl-runtime-platform-selector-contract.phpt` passed.
- `make -C extension test TESTS=tests/748-native-toolchain-linker-selector-contract.phpt` passed.
- `make -C extension -j1 V=1` passed.
- `make build` reached configure and compilation with `pkg-config... no` and `-I/opt/homebrew/opt/curl/include`; it no longer failed on missing curl headers/pkg-config.

Known gap:

- The OpenSSL header/library prerequisite cleanup is now handled by Step 19.

### Step 18: Gossip Rollout Dashboard Gates From Aggregates

Status: complete.

Changes:

- Added a focused frontend rollout-gate helper that consumes sanitized telemetry aggregate, snapshot, or `gossip/telemetry/ack` shapes.
- Kept `off` inert and `shadow` observational; active mode is only diagnostically allowed when channel, topology, and telemetry thresholds are ready.
- Added forbidden-field rejection so media, signaling, socket, token, and secret fields fail closed before entering rollout gate state.
- Added a lightweight workspace diagnostic surface for `gossip_rollout_gate_state` without expanding `CallWorkspaceView` logic.
- Added `topology_repairs_requested` telemetry so repair rate can be part of the gate.
- Extended backend telemetry aggregation and ack payloads with duplicate, TTL exhaustion, late-drop, repair-rate, and RTC readiness gate metrics.
- Added frontend and backend executable contracts for the rollout-gate behavior and safe aggregate surface.

Verification:

- `node tests/contract/gossip-rollout-gate-contract.mjs` passed.
- `npm run test:contract:gossip` passed.
- `./demo/video-chat/backend-king-php/tests/realtime-gossipmesh-runtime-contract.sh` passed.
- `npm run test:contract:build-size` passed.
- `npm run build` passed.

Known gap:

- Gate decisions are diagnostic-only. Active gossip still remains explicit and SFU-first; this does not make gossip carry primary media responsibility.

### Step 19: Native OpenSSL Header/Library Prerequisite Cleanup

Status: complete.

Changes:

- Added `infra/scripts/native-openssl-build-prereqs.sh` as the top-level native OpenSSL prerequisite selector.
- Top-level profile builds now accept vendored OpenSSL/BoringSSL headers, `KING_OPENSSL_INCLUDE_DIR`, `KING_OPENSSL_CFLAGS`, `KING_OPENSSL_LIBS`, pkg-config `openssl` flags, or OS-specific macOS/Linux system paths.
- The selector keeps OpenSSL headers and libraries aligned on the same installation root before falling back to broader library candidates.
- Missing OpenSSL headers now fail with an actionable OS-specific install command, including `brew install openssl@3 pkg-config` on macOS and `sudo apt-get update && sudo apt-get install -y libssl-dev pkg-config` on Linux.
- Added `infra/scripts/check-native-openssl-build-prereqs.sh` as the executable selector/diagnostic contract.
- Wired OpenSSL cflags and ldflags into `infra/scripts/build-profile.sh` before configure.

Verification:

- `bash -n infra/scripts/native-openssl-build-prereqs.sh` passed.
- `bash -n infra/scripts/check-native-openssl-build-prereqs.sh` passed.
- `bash -n infra/scripts/build-profile.sh` passed.
- `./infra/scripts/check-native-openssl-build-prereqs.sh` passed.
- `./infra/scripts/native-openssl-build-prereqs.sh --cflags` returned `-I/opt/homebrew/opt/openssl@3/include` on this macOS host while `pkg-config` was unavailable.
- `./infra/scripts/native-openssl-build-prereqs.sh --ldflags` returned `-L/opt/homebrew/opt/openssl@3/lib -lssl -lcrypto`.
- `make build` passed and staged release artifacts under `extension/build/profiles/release`.

Known gap:

- Native compiler/linker warning cleanup is now handled by Step 20.

### Step 20: Native Compiler/Linker Warning Cleanup

Status: complete.

Changes:

- Replaced macOS server-session thread id lookup with `pthread_threadid_np()` before the Linux `SYS_gettid` fallback.
- Converted native `%ld` PHP-extension formatting to portable `ZEND_LONG_FMT` usage across `extension/src`.
- Added casts where values are true C `long` but are intentionally printed through PHP's `zend_long` format path.
- Switched DTLS RSA key generation to `EVP_RSA_gen()` on OpenSSL 3 while keeping the legacy RSA path behind an OpenSSL/LibreSSL selector.
- Hardened the Darwin phpize/libtool path so generated libtool uses `-undefined dynamic_lookup`, avoids deprecated `-undefined suppress`, and caches `lt_cv_apple_cc_single_mod=no`.
- Added explicit Linux/macOS/Windows selector coverage in native build and curl/OpenSSL prerequisite scripts, including vcpkg/MSYS2 candidate paths.
- Fixed `740`/`741` SKIPIF executable probing so missing `python3` skips cleanly instead of emitting `proc_open()` warnings.
- Added `extension/tests/749-native-compiler-warning-cleanup-contract.phpt` and `extension/tests/750-phpt-skipif-executable-probe-hygiene-contract.phpt`.

Verification:

- `make build` passed; warning scan of `/private/tmp/king-build-warning-final5.log` found no compiler/linker warnings. The only `single_module` text is `checking for -single_module linker flag... (cached) no`.
- `make -C extension -j1 V=1` passed with no warning/deprecated/error matches in `/private/tmp/king-extension-j1-warning4.log`.
- `make -C extension test TESTS=tests/748-native-toolchain-linker-selector-contract.phpt` passed.
- `make -C extension test TESTS=tests/749-native-compiler-warning-cleanup-contract.phpt` passed.
- `make -C extension test TESTS=tests/750-phpt-skipif-executable-probe-hygiene-contract.phpt` passed.
- `make -C extension test TESTS=tests/740-http1-listener-exclusive-bind-contract.phpt` skipped cleanly on macOS because `/proc/net/tcp` is unavailable; no BORK.
- `make -C extension test TESTS=tests/741-http1-listener-reuseport-opt-in-contract.phpt` skipped cleanly on macOS because `/proc/net/tcp` is unavailable; no BORK.
- `./infra/scripts/check-native-curl-build-prereqs.sh` passed.
- `./infra/scripts/check-native-openssl-build-prereqs.sh` passed.

Known gap:

- Windows selectors are now explicit in the build/prerequisite scripts, but a real Windows CI runner is still needed to validate the full native extension build on Windows.
