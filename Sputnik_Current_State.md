# Sputnik Current State

This document records the current local Sputnik gossip-primary implementation and the debugging conclusions behind it.

## Runtime Goal

The Sputnik example is a local stress harness for the video-call gossip mesh. It emulates Alice plus up to 10 Sputnik peers in the browser while keeping the server out of the media fanout path.

The current target architecture is:

- Server head: identity, admission, room membership, topology hints, and control-plane fallback.
- Peers: media publication, frame relay, local freshness decisions, and decode/render decisions.
- WebSocket workers: needed to emulate many local Sputnik peers and to carry control-plane membership/topology traffic, but not intended to scale with media fanout.

## Local Startup

Use:

```sh
demo/video-chat/scripts/kingrt-sputnik.local.sh
```

Current local endpoints:

- Frontend: `http://127.0.0.1:5176/`
- Backend HTTP: `http://127.0.0.1:18080/`
- Backend health: `http://127.0.0.1:18080/health`
- Backend WS: `ws://127.0.0.1:18081/ws`

Current startup profile starts 36 WS workers for the local Sputnik stress case.

The launcher prints:

```text
Sputnik HELL: enabled (select HELL 20 in the call controls)
user: admin@intelligent-intern.com
pass: admin123
```

## Sputnik Identity Mapping

The browser control can spawn Alice plus up to 10 Sputnik peers. The current real user/session mapping is:

- Alice: user id `3`
- Sputnik 1: user id `4`
- Sputnik 2: user id `5`
- Sputnik 3: user id `6`
- Sputnik 4: user id `7`
- Sputnik 5: user id `8`
- Sputnik 6: user id `9`
- Sputnik 7: user id `10`
- Sputnik 8: user id `11`
- Sputnik 9: user id `12`
- Sputnik 10: user id `13`

The admin viewing browser is user id `1`.

## Server Responsibilities

The server does not abandon peers after they enter the gossip mesh. It remains the control-plane authority.

The server currently:

- Tracks room presence and active connections.
- Builds admitted peer lists.
- Computes gossip topology.
- Sends each peer its assigned neighbors via topology hints.
- Re-sends topology hints on room snapshot/update.
- Provides optional fallback/control broker paths.

The server should not, in gossip-primary mode:

- Forward normal video media frames.
- Decide frame freshness.
- Run SFU-style remote video health checks that force reconnects.
- Prune media paths based on decoder continuity assumptions.

## Topology Model

The mesh is represented as a directed adjacency list, but every logical edge must be reciprocal.

That means if `A` has `B` in its assigned neighbor list, `B` must also have `A` in its assigned neighbor list.

So the expected shape is not an undirected structure. It is a directed graph with bidirectional connections:

```text
A -> B
B -> A
```

This matters because the local direct transport sends according to the sender's assigned neighbor set. If the graph is only outbound from admin, admin can attempt to connect to a peer while that peer does not publish back toward admin.

## Stable Topology Seed

Topology is now seeded with stable room lifecycle data instead of volatile event reasons like `snapshot`, `join`, or `room_snapshot`.

Reason: the same room membership must produce the same topology regardless of which room event caused the hint to be sent. Otherwise peers can receive different neighbor sets during normal room churn and silently break reciprocal paths.

## Reciprocal Neighbor Planner

The planner now builds symmetric previous/next ring-style neighbor sets. For the 12-peer local run, a representative topology is:

```text
9:4,3,7,6
3:9,6,4,13
6:3,13,9,10
13:6,10,3,5
10:13,5,6,1
5:10,1,13,11
1:5,11,10,8
11:1,8,5,12
8:11,12,1,7
12:8,7,11,4
7:12,4,8,9
4:7,9,12,3
```

In this state every directed edge has the reverse directed edge. The validation target is `nonrecip=0`.

## Why Sputnik 6 Froze

Sputnik 6 is user id `9`. In the topology above, admin user id `1` is not a direct neighbor of Sputnik 6.

A valid route exists:

```text
9 -> 7 -> 8 -> 1
```

The bug was not graph connectivity. The bug was router duplicate handling.

The old router treated first arrival as final:

```text
if frame_id was seen once, drop later copies
```

That fails in a gossip mesh because a longer path can arrive at an intermediate peer before a shorter/better path. In the observed route, a longer branch reached peer `7` first with worse TTL and marked the frame seen. Then the shorter direct `9 -> 7` route arrived and was dropped as a duplicate, so it never continued to `8 -> 1`.

The router now tracks the best TTL seen per frame at each peer:

- Drop a duplicate only when the peer has already seen that frame with equal or better remaining TTL.
- Allow a duplicate with better TTL to continue relaying.
- Do not deliver the same frame twice to the decoder.

This preserves duplicate suppression while avoiding premature route poisoning.

## Gossip Frame State

Each connected peer keeps local per-publisher/per-track history:

```text
peer_id
  publisher_id
    track_id
      media_generation
      latest_sequence_seen
      latest_keyframe_sequence
      latest_rendered_sequence
      latest_arrival_ms
      latest_forwarded_ms
      latest_keyframe_frame
      recent_delta_ring_buffer
```

The current distinction is:

- Publisher timestamp and sequence are for decode ordering.
- Local arrival time is for local freshness/render decisions.
- Forward time is for relay freshness/drop policy.
- Media generation rejects truly old publisher epochs, but it is not treated as a global route freshness decision across unrelated relays.

Do not relabel publisher identity, frame sequence, or media generation. Relay metadata is stamped separately:

- `received_at_ms`
- `forwarded_at_ms`
- `relay_peer_id`
- `hop_count`

## Removed/Disabled SFU-Style Decisions For Gossip Primary

The current gossip-primary path avoids SFU-era behavior that was wrong for peer gossip:

- Gossip-delivered frames bypass SFU continuity/cache/jitter gates.
- Runtime remote video stall checks return early in gossip-primary mode.
- Data-channel state changes do not mark carriers lost or trigger reconnects.
- Topology prune/unbind does not close direct/audio transports during normal churn.
- Peer health checks are not used to reconnect peers in the gossip mesh.
- UI freshness uses gossip arrival/render history instead of publisher timestamp-only assumptions.

The intended rule is: peers decide freshness locally from arrival and render history. The server does not decide frame freshness.

## UI/Layout State

The visible participant limit is raised so 10 Sputniks plus admin/Alice can be visible. Layout selection now uses the configured visible participant limit instead of hard-coded smaller caps.

This avoids confusing a hidden participant with a frozen participant during the 10-Sputnik local run.

## Current Diagnostics Notes

Useful checks:

```sh
curl -fsS --max-time 2 http://127.0.0.1:18080/health
lsof -nP -iTCP:18081 -sTCP:LISTEN | awk 'NR>1{c++}END{print c+0}'
sqlite3 -header -column demo/video-chat/backend-king-php/.local/video-chat-local-sputnik.sqlite \
  "select user_id,display_name,room_id,call_id,connection_id,connected_at,last_seen_at_ms,datetime(last_seen_at_ms/1000,'unixepoch') as last_seen_utc from realtime_presence_connections order by user_id,connection_id;"
```

Important interpretation:

- Fresh presence for all Sputniks means WS capacity is probably not the failure.
- A valid reciprocal topology means the server planner is probably not the failure.
- Frozen non-neighbor peers point at multi-hop forwarding, duplicate suppression, TTL, or client transport binding.

## Current Verification

The current code has been verified with:

```sh
npm run build
```

from:

```text
demo/video-chat/frontend-vue
```

The local stack was restarted after the TTL-aware duplicate routing change. Frontend and backend health endpoints responded successfully.
