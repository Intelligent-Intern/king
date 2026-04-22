# GossipMesh: Decentralized SFU for Video Calls

## Overview

GossipMesh is a **decentralized SFU (Selective Forwarding Unit)** that enables video calls with 100+ participants without requiring a central media server. It uses:

1. **Peer-to-peer mesh** - direct WebRTC connections between browsers
2. **Gossip protocol** - frames propagate through the mesh like a virus
3. **Expander graph topology** - each peer connects to 3-5 neighbors
4. **SFU fallback** - signaling only, no media relay (unless P2P fails)

## Architecture

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                     VIDEO CALL ARCHITECTURE                        │
├─────────────────────────────────────────────────────────────────────────────┤
│                                                                      │
│   BROWSER A          BROWSER B          BROWSER C          ...          │
│       │               │               │                               │
│       │ WebRTC Data   │               │                              │
│       │  Channel     │               │                              │
│       ▼               ▼               ▼                              │
│   ┌─────────────────────────────────────────────────────────┐                 │
│   │           PEER-TO-PEER MESH (GossipMesh)               │                 │
│   │                                                 │                 │
│   │    A ◄──► B ◄──► C ◄──► D ◄──► ...          │                 │
│   │    (3-5 neighbors each, expander graph)           │                 │
│   │                                                 │                 │
│   │   Gossip forwarding:                          │                 │
│   │   Frame → check duplicate → callback         │                 │
│   │   If TTL > 0: forward to 2 random neighbors│                 │
│   └─────────────────────────────────────────────────────────┘                 │
│                        │                                        │
│                        │ WebSocket                               │
│                        ▼                                        │
│   ┌───────────────────────────────────────────────────┐              │
│   │        PHP BACKEND (King Extension)              │              │
│   │        /ws + /sfu (JSON/IIBIN dual transport)   │   SFU       │
│   │        - Bootstrap peer discovery             │   (signaling) │
│   │        - Relay WebRTC offer/answer/ICE     │              │
│   │        - Relay fallback when P2P fails   │              │
│   └───────────────────────────────────────────────────┘              │
│                                                                      │
└─────────────────────────────────────────────────────────────────────────────┘
```

## Key Concepts

### NAT (Network Address Translation)

Your computer is behind a router. The router has a public IP, but your computer has a private IP.

```
INTERNET ──────► ROUTER (:5000) ──────► YOUR PC (:8080)
               ↑
               │ Only router's public IP is visible externally
               │ Your PC's IP is hidden from the internet
```

**Problem:** Other computers can't initiate connections to you because they don't know your internal IP address.

### STUN (Session Traversal Utilities for NAT)

A public server that tells you your public IP:port combination.

```
YOU ──────────► STUN server
              ◄────── { your_ip: 1.2.3.4:5000 }
```

**Success rate:** ~60% of NAT types (full-cone, restricted cone)

**Doesn't work** for symmetric NAT (router uses different port for each destination).

### TURN (Traversal Using Relay NAT)

A relay server that forwards traffic both ways when direct connection isn't possible.

```
YOU ──────────► TURN server ◄─────────► PEER
              relay: my_public_ip:5000
```

**Pros:** Works 100% of the time  
**Cons:** 
- Requires your own TURN server
- Adds latency (extra network hop)
- You pay for bandwidth

### ICE (Interactive Connectivity Establishment)

A protocol that tries multiple methods in order:

```
1. Try direct (host candidate)
2. Try STUN (server-reflexive candidate)  
3. Try TURN (relayed candidate)
```

Your browser tries all ICE candidates and uses the first one that works.

## Protocol Flow

### Phase 1: Join and Bootstrap

```
Client ──► SFU: { type: 'sfu/join', room_id, peer_id }
SFU ──► Client: { type: 'sfu/welcome', peer_id, server_time }
SFU ──► Client: { type: 'sfu/peers', peers: [5 random peers] }
```

The SFU gives each new peer ~5 random existing peers to connect to.

### Phase 2: WebRTC Connection (via SFU relay)

```
A ──► SFU: { offer, target_peer_id: B, sdp: {...} }
SFU ──► B: { offer, from_peer_id: A, sdp: {...} }

B ──► SFU: { answer, target_peer_id: A, sdp: {...} }
SFU ──► A: { answer, from_peer_id: B, sdp: {...} }

A ──► SFU: { ice-candidate, target_peer_id: B, candidate: {...} }
SFU ──► B: { ice-candidate, from_peer_id: A, candidate: {...} }

(and vice versa from B to A)
```

**ICE candidates flow through SFU** but actual WebRTC connection is direct between browsers.

### Phase 3: Direct P2P Established

Once WebRTC handshake completes, peers communicate directly via WebRTC data channels. The SFU is no longer involved unless P2P fails.

### Phase 4: Neighbor Exchange (Graph Expansion)

Periodically (every 30s), peers share their neighbor lists:

```
Peer A ──► Peer B: { type: 'neighbors', neighbors: [C, D, E] }
Peer B ──► Peer C: { type: 'neighbors', neighbors: [D, E, F] }
```

This helps maintain the expander graph topology.

### Phase 5: Gossip Frame Forwarding

```
Frame format:
{
  publisher_id: "abc123",
  sequence: 1523,
  ttl: 4,           // dies after 4 hops
  data: <binary>
}

On receive frame F:
  if seen(F.publisher_id, F.sequence): drop  // duplicate
  mark seen(F.publisher_id, F.sequence)
  
  notify callback (play frame)
  
  if F.ttl > 0:
    forward to 2 random neighbors not yet received this frame
    F.ttl = F.ttl - 1
```

**TTL (Time To Live):**
- Starts at ~4 for 100 peers
- Decrements each hop
- Frame dies after TTL reaches 0

**Coverage:** With TTL=4 and 2 neighbors per hop: 2^4 = 16x coverage, reaches ~95%+ of 100 peers

### Phase 6: Relay Fallback

If WebRTC connection fails (ICE failed after 10s):

```
Peer A ──► SFU: { type: 'relay/request', target_peer_id: B }
SFU ──► A: { type: 'relay/enabled', mode: 'relay' }

# All frames to B go through SFU
A ──► SFU: { type: 'relay/frame', target_peer_id: B, data: {...} }
SFU ──► B: { type: 'relay/frame', data: {...} }
```

This ensures 100% connectivity even when NAT traversal fails.

## Files

### Extension (`extension/src/gossip_mesh/`)

| File | Description |
|------|-------------|
| `gossip_mesh.h` | C header with data structures |
| `gossip_mesh.c` | C implementation for fast forwarding |
| `gossip_mesh.php` | PHP reference implementation |
| `gossip_mesh_client.js` | JavaScript/WebRTC client |
| `sfu_signaling.php` | PHP SFU signaling server |

### Backend Integration

- `demo/video-chat/backend-king-php/http/module_realtime.php` 
  - `/ws` and `/sfu` support dual transport (`transport=iibin|json`)
  - `/ws` auto-detects IIBIN binary frames and keeps JSON fallback
  - `/sfu` signaling relay supports the same transport contract
  - Added: `ping`, `neighbors`, `offer`, `answer`, `ice-candidate`, `relay/request` message types

### Frontend

- `demo/video-chat/frontend-vue/src/lib/sfu/gossip_mesh_client.js`
  - GossipMeshClient class for browser

## Configuration

### JavaScript (Browser)

```javascript
const client = new GossipMeshClient({
    roomId: 'my-call-room',
    peerId: 'user-123',
    sfuUrl: 'wss://backend.example.com/sfu',
    transport: 'iibin', // optional: defaults to JSON fallback
    ttl: 4,
    forwardCount: 2,
    iceServers: [
        { urls: 'stun:stun.l.google.com:19302' },
        { urls: 'stun:stun1.l.google.com:19302' },
        // Add your TURN if needed:
        // { urls: 'turn:myserver.com', username: 'x', credential: 'y' }
    ],
    onFrameReceived: (publisherId, sequence, data) => {
        // Handle incoming video frame
    },
    onPeerConnected: (peerId) => {
        console.log('Peer connected:', peerId);
    },
    onPeerDisconnected: (peerId) => {
        console.log('Peer disconnected:', peerId);
    }
});

await client.connect(sessionToken);
```

### PHP (Backend)

```php
// The SFU runs automatically at /sfu endpoint
// Messages are handled in module_realtime.php
```

## Performance

| Metric | Value |
|--------|-------|
| Max participants | 100-500 |
| hops to cover | ~4 (TTL) |
| Worst latency | ~200ms (4 hops × 50ms) |
| Bandwidth overhead | ~2x (multiple paths) |
| P2P success rate | ~60% with STUN only |
| With SFU relay | 100% |

## Comparison

| Approach | Pros | Cons |
|---------|------|-----|
| **Traditional SFU** | Reliable, scales to 1000+ | Expensive, single point of failure |
| **Full Mesh** | No server cost | Only works for small calls (4-6) |
| **GossipMesh** | No single point of failure, scales to 100s | ~2x bandwidth, some latency |

## Usage in Video Chat Demo

The GossipMesh integrates with the existing video-chat demo:

1. **`/ws`** - Chat/presence/signaling with JSON or IIBIN framing
2. **`/sfu`** - WebRTC signaling relay with the same JSON/IIBIN dual transport
3. **Frontend** - `GossipMeshClient` for video forwarding

To enable video:
1. Start backend: `cd demo/video-chat/backend-king-php && ./run-dev.sh`
2. Frontend connects to `/sfu?transport=iibin` (or omit for JSON fallback)
3. WebRTC data channels carry encoded video frames
4. GossipMesh propagates through the mesh

## Transport Contract

- `transport` query parameter is supported on both `/ws` and `/sfu`.
- Allowed values: `iibin` or `json`; invalid/unknown values fall back to `json`.
- Server also auto-detects IIBIN frames by the `IIB` + version-byte header.
- Outbound frames are encoded per-connection in the negotiated or detected transport.
