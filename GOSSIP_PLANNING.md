# Gossip Mesh Planning

Last updated: 2026-05-05

## Operating Rule

Every gossip mesh iteration must update both:

- `GOSSIP_CURRENT_BUILD.md`
- `GOSSIP_PLANNING.md`

Every gossip mesh iteration must also add or update executable regression coverage and run it successfully before the step is considered complete.

## Ranked Next Tasks

### 1. Bind Gossip Data Transport To Live Native WebRTC Peers

Impact: very high.

Complexity: high.

Status: complete for the current scaffold.

Why:

This is the main missing piece between a decentralized controller boundary and a real peer-to-peer media data lane. The existing native WebRTC signaling path can create peer connections; the gossip data lane needs to bind only assigned neighbors to `GossipRtcDataChannelTransport`.

Done when:

- Assigned gossip neighbors get RTCDataChannel bindings. Complete for native peer connections behind `shadow`/`active`.
- Remote RTCDataChannel data messages feed `GossipController.handleData()`. Complete in `active`.
- Local deliveries from `onDataMessage()` can enter the remote frame path behind a feature flag. Complete for `sfu/frame`.
- Non-neighbor peers are not opened solely for gossip data. Complete through server-assigned neighbor filtering.
- New executable contracts pass. Complete for native peer binding, cleanup, server topology ingestion, and outbound live publication.

### 2. Add Server-Style Topology Snapshot Contract

Impact: high.

Complexity: medium.

Status: complete.

Why:

The server should be the source of admission and topology hints, not the frame distributor. The client needs a stable topology payload shape before live integration.

Done when:

- A topology hint message shape is documented in the wire contract.
- Client can apply a server-provided neighbor set.
- Local deterministic selection remains available for harness/testing fallback.
- New executable contract passes.

### 3. Feature Flag The Gossip Data Lane

Impact: high.

Complexity: medium.

Status: complete.

Why:

The gossip data lane should be introduced without destabilizing the current SFU path. A feature flag allows side-by-side telemetry and rollback.

Done when:

- Gossip data lane can be enabled independently from SFU publishing.
- Default production behavior remains conservative.
- Diagnostics indicate whether a frame arrived by SFU or gossip.
- New executable contract passes.

### 4. Wire Local Deliveries Into Remote Frame Decode

Impact: high.

Complexity: high.

Status: complete for active inbound `sfu/frame` gossip deliveries.

Why:

Decentralized transport is only useful once delivered gossip frames can be decoded/rendered by the existing remote video path.

Done when:

- `GossipController.onDataMessage()` feeds the same validation/decode path as server frames.
- Late-frame dropping is explicit.
- Keyframe requirements are respected.
- New executable contract passes.

### 5. Add Neighbor Health And Topology Repair

Impact: medium-high.

Complexity: medium-high.

Status: scaffold complete.

Why:

Real peer links fail. The mesh needs pressure signals, lost carrier detection, and server-assisted replacement neighbors.

Done when:

- RTCDataChannel close/error updates carrier state. Complete for assigned gossip neighbors.
- Lost neighbor triggers reconnect/topology repair request over ops lane. Complete for `gossip/topology-repair/request`.
- Cooldowns prevent reconnect storms. Complete with per-neighbor client cooldown.
- New executable contract passes. Complete.

### 6. Add Gossip Telemetry

Impact: medium.

Complexity: medium.

Why:

The scaling win needs proof. We need counts for server fanout avoided, peer outbound fanout, duplicates, TTL exhaustion, late drops, and per-hop latency.

Done when:

- Diagnostics expose gossip send/receive/forward/drop counters.
- Events distinguish in-memory harness transport vs RTCDataChannel transport.
- New executable contract passes.

## Current Priority

The next step is backend topology repair handling. The server should consume `gossip/topology-repair/request`, validate room/call membership, update topology state, and emit replacement `topology_hint` messages without becoming a media distributor.

Prerequisite now complete:

- The live gossip data lane must use IIBIN binary envelopes, not JSON text frames.
- Native/server relay work must align with King object_store for control-plane/topology persistence.
- Native/server relay and fallback work must keep LSQUIC/HTTP3 and King binary WebSocket compatibility in the transport contract.
- Browser peer links can remain RTCDataChannel, but server-assisted relay/repair should prefer the native LSQUIC stack where available.
- The King PHP extension builds and loads locally when passed explicitly to PHP. Targeted native PHPTs pass; the full extension suite still has broader existing failures and LSQUIC migration gaps that should stay separate from frontend gossip rollout contracts.

Recent completed step:

- Outbound live gossip publication now runs only after successful conservative SFU send and only when the gossip data lane is `active`.
- Live native gossip data channels are now bound only for server-assigned gossip neighbors.
- Executable contracts cover outbound live publication and server topology ingestion.
- Assigned-neighbor RTCDataChannel health now updates gossip carrier state and requests cooldown-bound topology repair over the ops lane in active mode.
- Live gossip workspace glue has been extracted into `workspace/callWorkspace/gossipDataLane.js`; shell/sidebar viewport state has been extracted into `workspace/callWorkspace/shellViewport.js`; diagnostics context registration has been moved into `clientDiagnostics.js`; the refactor-boundary contract now passes with `CallWorkspaceView.vue` at 2149 lines.

Current failure and warning follow-up:

- Backend topology repair handling still needs implementation and contracts.
- The previous Vite `CallWorkspaceView` large route chunk warning is resolved through manual route-graph chunks and a new build-size contract.
- Native MCP remote-control tests `340` and `341` now pass after aligning native runtime-control monotonic milliseconds with PHP `hrtime(true)` via `zend_hrtime() / 1000000`.

## Step Checklist Template

For each step:

1. Update implementation.
2. Add or update executable contract/test.
3. Run the focused new check.
4. Run adjacent existing checks.
5. Update `GOSSIP_CURRENT_BUILD.md`.
6. Update `GOSSIP_PLANNING.md`.
7. Record known gaps honestly.
