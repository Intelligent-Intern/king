# Gossip Harness Instructions

## Purpose

Use the local GossipMesh harness to test the two-lane video model before live rollout.

The harness runs four mock clients in one browser window. Each client publishes its own video stream from the same shared camera source, and the selected viewer shows what one peer sees:

- its own local video
- three remote videos received through the mock gossip data lane
- ops/data event logs
- reconnect decisions

## Start The Harness

From the repo root:

```sh
cd demo/video-chat/frontend-vue
npm run dev:gossip
```

Open:

```text
http://localhost:3456/gossip-harness.html
```

If `localhost` does not work in your environment, try:

```text
http://127.0.0.1:3456/gossip-harness.html
```

## Basic Use

1. Click `Start 4-Peer Mesh`.
2. Allow camera access if prompted.
3. Use `View as` to choose the peer perspective.
4. Use `Fault peer` to choose which peer should be impaired.
5. Use `Fault` to inject a failure mode.
6. Watch the four video panes and the event trace.
7. Click `Export JSON` to save the event log.

The source indicator shows either:

- `shared camera`: browser camera is active
- `synthetic fallback`: camera was unavailable, so generated frames are used

## Reading The View

The selected peer's view has four panes:

- `own local publish`: the selected peer's own outgoing video
- `received via gossip`: remote publishers received through the mock data lane

This is intentionally not a four-person dashboard. It is one peer's local truth.

Example:

- `View as: Alice`
- Alice's pane is her own local publish.
- Bob, Charlie, and Diana panes are what Alice received from those peers.

## Fault Modes

### Data Drop 20%

Randomly drops data-lane frames.

Expected:

- Remote panes may stutter.
- Event log shows `drop_data` with `reason=data_drop_fault`.
- No reconnect should occur.

### Duplicate Frames

Injects duplicate data delivery.

Expected:

- Event log shows `drop_duplicate`.
- Receiver dedupe prevents duplicate render semantics.
- No reconnect should occur.

### Slow Selected Peer

Drops most incoming data frames for the `Fault peer`.

Expected:

- If you view as that peer, remote panes may become stale.
- Other peers should continue normally.
- Event log shows `reason=slow_peer_budget`.
- No reconnect should occur.

### Neighbor Failure

Prevents the `Fault peer` from forwarding to neighbors.

Expected:

- Event log shows `reason=neighbor_failure`.
- The mesh should route around or show data degradation.
- No reconnect should occur unless ops carrier is also lost.

### Ops Heartbeat Loss

Drops ops heartbeats for the `Fault peer`.

Expected:

- Carrier state degrades, then can become lost after the timeout window.
- Event log shows dropped ops heartbeats.
- Reconnect is allowed only when carrier state becomes `lost`.

### Ops Carrier Loss

Forces ops carrier timeout for the `Fault peer`.

Expected:

- Event log shows `carrier_state_change` on the ops lane.
- Event log shows `reconnect_requested` with `reconnect_allowed=true`.
- A lost peer stops accepting fresh remote mesh data.
- A lost peer's remote panes become stale.
- Its own local preview may still render, because local capture is not proof of mesh carrier health.

## Important Rule

Data-lane failures must not reconnect.

Only ops-lane carrier loss may request reconnect.

Correct reconnect event shape:

```text
lane=ops
event=reconnect_requested
carrier_state=lost
reconnect_allowed=true
reconnect_reason=ops_carrier_timeout
```

If a data-lane event directly requests reconnect, that is a bug.

## Contract Test

Run:

```sh
cd demo/video-chat/frontend-vue
node tests/contract/gossip-harness-faults-contract.mjs
```

Expected output:

```text
[gossip-harness-faults-contract] PASS
```

This test verifies that each visible fault mode has an actual execution path in the harness and that reconnect is tied to ops-lane carrier loss.

