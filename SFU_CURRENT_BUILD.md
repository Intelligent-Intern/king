# SFU Current Build

Last updated: 2026-05-01

## Branch Context

- Working branch: `experiments/fix-signalling-unit`
- Source branch: `upstream/hotfix/ws-single-flight-turn-range`
- Local branch now tracks: `origin/experiments/fix-signalling-unit`
- `llama-fork/` is locally excluded because it belongs to the Voltron branch.

## Current Runtime Shape

The active video-call path is still mostly a WebSocket SFU fallback media path. The desired direction for this branch is a decentralized GossipMesh with a simple two-lane contract: an ops lane for carrier/liveness/topology signals and a data lane for media frames.

The codec quality work appears separate from the instability. The current risk is signalling, frame ownership, buffering, and recovery feedback loops.

The current declared runtime is `wlvc_sfu`.

Key active files, mostly under `demo/video-chat/`:

- `frontend-vue/src/lib/sfu/sfuClient.ts`
- `frontend-vue/src/lib/sfu/framePayload.ts`
- `frontend-vue/src/lib/sfu/outboundFrameQueue.ts`
- `frontend-vue/src/domain/realtime/sfu/lifecycle.js`
- `frontend-vue/src/domain/realtime/workspace/callWorkspace/sfuTransport.js`
- `frontend-vue/src/domain/realtime/workspace/callWorkspace/publisherBackpressureController.js`
- `frontend-vue/src/domain/realtime/workspace/callWorkspace/runtimeHealth.js`
- `backend-king-php/domain/realtime/realtime_sfu_gateway.php`
- `backend-king-php/domain/realtime/realtime_sfu_store.php`
- `backend-king-php/domain/realtime/realtime_sfu_broker_replay.php`
- `backend-king-php/domain/realtime/realtime_sfu_frame_buffer.php`
- `backend-king-php/domain/realtime/realtime_sfu_subscriber_budget.php`
- `backend-king-php/domain/realtime/realtime_gossipmesh.php`

## What Works

- Binary SFU media envelopes are active. Legacy JSON/base64 media fallback is intentionally rejected for media frames.
- The frontend assigns `frame_sequence` per track and includes timing/budget metadata before send.
- The backend stamps receive timing, validates room binding, and rejects cross-room frame commands.
- The backend has three delivery/recovery paths:
  - direct in-process fanout to subscribers on the same worker
  - live relay files under tmpfs or temp directory
  - SQLite-backed frame buffer/replay and recovery request broker
- Slow subscriber isolation exists on the backend.
- Publisher backpressure exists on the frontend and can pause encode, drop frames, request keyframes, downshift profile, or restart the SFU socket.
- Receiver-side stall detection can request recovery or trigger SFU socket restart with peer-level backoff.
- GossipMesh is currently a backend-authoritative topology helper. It is not yet the simple decentralized data-forwarding mesh we want.

## Current Hot Path

Publisher frame flow:

1. Browser captures and encodes a frame.
2. `SFUClient.sendEncodedFrame()` assigns `frame_sequence` and queues a prepared binary frame.
3. `SfuOutboundFrameQueue` serializes send attempts.
4. `SFUClient.sendPreparedEncodedFrame()` checks queue age, browser `bufferedAmount`, projected wire budget, rolling wire budget, and drain wait.
5. `SfuWebSocketFallbackMediaTransport` sends a binary envelope over the `/sfu` WebSocket.
6. `realtime_sfu_gateway.php` receives and decodes the frame.
7. Backend writes or attempts to write the frame into live relay and SQLite frame buffer.
8. Backend direct-fanouts to local subscribers.
9. Other workers poll live relay and SQLite frame buffer.
10. Receivers assemble, decode, and render.

## Current Timing And Buffer Constants

- King `/sfu` receive poll timeout: `15ms`
- Broker poll interval: `100ms`
- Live relay poll interval: `50ms`
- Live relay TTL: `2500ms`
- SQLite frame buffer TTL: `2500ms`
- Live relay max room files: `300`
- SQLite frame buffer max room rows: `300`
- Live relay max room bytes: `96 MiB`
- SQLite frame buffer max room bytes: `96 MiB`
- Subscriber video send budget: `35ms`
- Subscriber replay video send budget: `25ms`
- Subscriber replay delta max age: `900ms`
- Broker publisher leave grace: `3000ms`
- Publisher frame stall resubscribe: `6000ms`
- Publisher frame stall recovery cooldown: `5000ms`
- SFU websocket negotiation timeout: `5 minutes`

## Current Pressure And Recovery Loops

The system has several independent loops that can all react to the same symptom:

- Browser send buffer drain wait and outbound frame queue pressure.
- Publisher backpressure controller.
- Server ingress stale-frame drop and `sfu/publisher-pressure`.
- Backend slow subscriber isolation.
- Receiver media recovery requests.
- Publisher stall resubscribe timer.
- Runtime health remote video restart gate.
- Workspace/SFU lifecycle reconnect timer.
- Asset-version stale-client disconnect.
- GossipMesh planning and relay fallback logic.

This is probably the central practical problem: reconnect and recovery are not owned by the ops lane. Multiple layers can decide that the socket or subscription should be refreshed, which can look like constant reconnect churn even when the codec is producing good frames and the carrier signal may still be alive.

## Likely Failure Class

The likely failure is not a pure codec failure. It is a control-plane and queueing failure:

- Frame identity is per track on the publisher, but delivery can pass through direct fanout, file relay, and SQLite replay.
- Delivery paths can have different latency, ordering, duplication, and freshness semantics.
- Recovery can request resubscribe, keyframe, profile downshift, or socket restart without one ops-lane carrier owner.
- Backpressure can be observed at browser `bufferedAmount`, backend ingress age, subscriber send latency, replay age, and receiver render freeze age.
- GossipMesh terminology exists in the codebase, but the active system has not yet cleanly separated ops-lane carrier health from data-lane media loss.

## Target Correction

The intended architecture should be decentralized GossipMesh, but simple:

- Ops lane owns carrier signal, membership, topology hints, pressure signals, and reconnect permission.
- Data lane owns media frames and bounded gossip forwarding.
- Data-lane loss should request keyframes, lower layers, route changes, or frame drops.
- Reconnect should happen only after ops-lane carrier loss, not after ordinary media loss.
- Slow or lossy peers should be routed around or downgraded without forcing healthy peers to reconnect.

## Best-Practice Gap

The current build has several good pieces, but they are not yet organized around common real-time media practice:

- Liveness and media quality are not cleanly separated.
- Reconnect can still be reached from data-plane symptoms.
- Fanout/replay behavior is not expressed as one bounded gossip algorithm.
- Duplicate and stale-frame handling is spread across delivery paths instead of being a core receiver invariant.
- Congestion handling exists, but it is not yet clearly local to the pressured route or peer.
- Diagnostics do not yet prove that reconnects are caused only by ops-lane carrier loss.

The planning target is to keep backend-authoritative admission and protected media semantics while allowing admitted peers to forward bounded data-lane media.

## Immediate Unknowns To Measure

These should be captured before adding more architecture:

- For each remote freeze or reconnect, which loop fired first?
- Are duplicate frames arriving from direct fanout plus live relay or SQLite replay?
- Are frames arriving out of order across relay paths for the same `publisher_id`, `track_id`, and `frame_sequence`?
- Is `publisher_id` stable across SFU reconnects, or are receivers treating reconnect as a new publisher and losing decoder continuity?
- How often does server ingress drop frames due to trusted queue age versus wall-clock receive latency?
- Does slow subscriber isolation prevent publisher pressure, or does the publisher still react globally?
- Which current reconnects are caused by data-lane symptoms instead of proven ops-lane carrier loss?
- Does GossipMesh route planning actually affect media delivery in production, or is it still mostly a dormant planning layer?
