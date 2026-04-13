# SFU Architecture for 1000+ Video Participants

## Overview

This document describes the architecture needed to scale the video chat to 1000+ simultaneous participants using a Selective Forwarding Unit (SFU).

## Current Architecture vs Target Architecture

### Current (Mesh P2P)
```
User A ─── User B      User C ─── User D
   │         │            │         │
   └────┬────┘            └────┬────┘
        │                     │
        ▼                     ▼
   Each user sends        Each user receives
   (n-1) streams         (n-1) streams
   
For 4 users: Each user sends 3 streams = 12 total streams
For 1000 users: Each user sends 999 streams = 999,000 streams! ❌
```

### Target (SFU)
```
                    ┌─────────────────┐
                    │   Media Server  │
                    │      (SFU)      │
User A ────────────▶│                 │◀────────── User B
User C ────────────▶│  Selective      │◀────────── User D
User D ────────────▶│  Forwarding     │◀────────── User E
   ...              │                 │◀──────────  ...
User N ────────────▶│                 │◀────────── User N+1
                    └─────────────────┘
                    
For 1000 users: Each user sends 1 stream, receives N streams
Each user sends: 1 stream (to SFU)
Each user receives: up to N streams (configurable)
```

## SFU Architecture Components

```
┌─────────────────────────────────────────────────────────────────────────┐
│                           SFU CLUSTER                                    │
├─────────────────────────────────────────────────────────────────────────┤
│                                                                          │
│  ┌──────────────┐     ┌──────────────┐     ┌──────────────┐            │
│  │   Ingress    │────▶│    Router    │────▶│    Egress    │            │
│  │   Workers    │     │              │     │   Workers     │            │
│  │              │     │  - Routing   │     │              │            │
│  │  - WebRTC    │     │  - Mixing    │     │  - Transcode │            │
│  │  - DTLS/SRTP │     │  - Simulcast│     │  - Selective │            │
│  │  - STUN/TURN │     │  - Recording │     │    Forward   │            │
│  └──────────────┘     └──────────────┘     └──────────────┘            │
│          │                    │                     │                   │
│          └────────────────────┴─────────────────────┘                 │
│                                 │                                         │
│                    ┌────────────────────────┐                          │
│                    │     Control Plane       │                          │
│                    │  - Room Management     │                          │
│                    │  - User Authentication │                          │
│                    │  - Resource Allocation │                          │
│                    └────────────────────────┘                          │
└─────────────────────────────────────────────────────────────────────────┘
```

## Integration with Wavelet + Kalman

### Proposed Data Flow with SFU
```
Camera ──▶ Wavelet Encoder ──▶ Kalman Filter ──▶ SRTP ──▶ SFU ──▶ 
    │                                                    │
    │    ┌──────────────────────────────────────────────┐ │
    │    │              SFU Processing                  │ │
    │    │  - Receives compressed stream               │ │
    │    │  - Routes to recipients (selective forward)│ │
    │    │  - May transcode for bandwidth adaptation   │ │
    │    └──────────────────────────────────────────────┘ │
    │                                                    │
    ▼                                                    ▼
SRTP ◀── Kalman Update ◀── Wavelet Decoder ◀── SFU ◀── Other Users

Total bandwidth per user:
- Without Wavelet: 2-4 Mbps per participant
- With Wavelet (20:1 compression): 100-200 Kbps per participant
- With Kalman (residual only): Additional 5-10x reduction
```

### Bandwidth Comparison

| Users | Mesh P2P | SFU Only | SFU + Wavelet | SFU + Wavelet + Kalman |
|-------|-----------|----------|---------------|------------------------|
| 2     | 4 Mbps    | 4 Mbps   | 200 Kbps      | 20-40 Kbps            |
| 10    | 40 Mbps   | 4 Mbps   | 200 Kbps      | 20-40 Kbps            |
| 100   | 400 Mbps  | 4 Mbps   | 200 Kbps      | 20-40 Kbps            |
| 1000  | Impossible | 4 Mbps   | 200 Kbps      | 20-40 Kbps            |

## SFU Implementation Options

### Option 1: mediasoup (Recommended)

**Pros:**
- High performance (C++)
- Scales to thousands of users
- Supports Simulcast
- Active development
- Works with Node.js

**Cons:**
- Complex setup
- Requires dedicated server

```javascript
// Example mediasoup integration
const mediasoup = require('mediasoup')

const worker = await mediasoup.createWorker({
  logLevel: 'warn',
  rtcMinPort: 2000,
  rtcMaxPort: 4000,
})

const router = await worker.createRouter({
  mediaCodecs: [
    { kind: 'video', mimeType: 'video/vp8', clockRate: 90000 },
    { kind: 'video', mimeType: 'video/h264', clockRate: 90000 },
  ],
})

// Producer (sender)
const transport = await router.createWebRtcTransport({
  listenIps: [{ ip: '0.0.0.0' }],
  enableUdp: true,
  enableTcp: true,
})

// Consumer (receiver)
const consumerTransport = await router.createWebRtcTransport({...})
```

### Option 2: Janus (Video Room Plugin)

**Pros:**
- Battle-tested
- Built-in TURN
- Recording support
- Video room plugin for multi-party

**Cons:**
- C-based, harder to extend
- Configuration complexity

### Option 3: Daily.co (Managed Service)

**Pros:**
- No infrastructure management
- Built-in features (recording, analytics)
- Easy integration

**Cons:**
- Cost at scale
- Vendor lock-in

## Architecture for 1000 Users

### Tiered Architecture
```
┌─────────────────────────────────────────────────────────┐
│                    Load Balancer                         │
│              (WebSocket + API Routing)                  │
└─────────────────────┬───────────────────────────────────┘
                      │
        ┌─────────────┼─────────────┐
        ▼             ▼             ▼
   ┌─────────┐  ┌─────────┐  ┌─────────┐
   │ SFU Pod  │  │ SFU Pod  │  │ SFU Pod  │
   │ (200 up) │  │ (200 up) │  │ (200 up) │
   └────┬────┘  └────┬────┘  └────┬────┘
        │             │             │
        └─────────────┼─────────────┘
                      ▼
              ┌─────────────┐
              │   Redis     │
              │ (Pub/Sub)   │
              └─────────────┘
```

### Signaling vs Media Separation

```
┌─────────────────────────────────────────────────────────┐
│                    Signaling Layer                       │
│  ┌─────────────┐  ┌─────────────┐  ┌─────────────┐  │
│  │   API GW    │  │   API GW    │  │   API GW    │  │
│  │  (Node.js)  │  │  (Node.js)  │  │  (Node.js)  │  │
│  └──────┬──────┘  └──────┬──────┘  └──────┬──────┘  │
│         │                 │                 │          │
│  ┌──────┴─────────────────┴─────────────────┴──────┐  │
│  │              Redis Cluster (Signaling)          │  │
│  └─────────────────────┬───────────────────────────┘  │
└────────────────────────┼────────────────────────────────┘
                         │
┌────────────────────────┼────────────────────────────────┐
│                        │      Media Layer                  │
│  ┌────────────────────┴───────────────────────────────┐  │
│  │              Redis (Room State)                   │  │
│  └────────────────────┬───────────────────────────────┘  │
│                       │                                   │
│         ┌─────────────┼─────────────┐                   │
│         ▼             ▼             ▼                    │
│    ┌─────────┐  ┌─────────┐  ┌─────────┐             │
│    │  SFU 1  │  │  SFU 2  │  │  SFU N  │             │
│    └─────────┘  └─────────┘  └─────────┘             │
└──────────────────────────────────────────────────────────┘
```

## Wavelet + Kalman + SFU Integration

### Video Pipeline
```
┌────────────────────────────────────────────────────────────┐
│                      Encoder Side                           │
├────────────────────────────────────────────────────────────┤
│                                                             │
│  Camera Frame (1920×1080 @ 30fps)                        │
│           │                                                 │
│           ▼                                                 │
│  ┌─────────────────┐                                       │
│  │  Wavelet DWT    │  (4-level decomposition)             │
│  │  RGB → YUV      │                                       │
│  └────────┬────────┘                                       │
│           │                                                 │
│           ▼                                                 │
│  ┌─────────────────┐                                       │
│  │   Kalman Filter │  (Motion-compensated prediction)     │
│  │   Predict next   │                                       │
│  └────────┬────────┘                                       │
│           │                                                 │
│           ▼                                                 │
│  ┌─────────────────┐                                       │
│  │  Quantization   │  (Quality-adaptive step sizes)       │
│  │  + RLE          │                                       │
│  └────────┬────────┘                                       │
│           │                                                 │
│           ▼                                                 │
│  ┌─────────────────┐                                       │
│  │   Encoded Bit   │  (Compressed frame)                   │
│  │   Stream        │                                       │
│  └────────┬────────┘                                       │
│           │                                                 │
│           ▼                                                 │
│  SRTP Encapsulation                                        │
│           │                                                 │
│           ▼                                                 │
│      SFU Ingress                                           │
└────────────────────────────────────────────────────────────┘

┌────────────────────────────────────────────────────────────┐
│                      SFU Processing                         │
├────────────────────────────────────────────────────────────┤
│                                                             │
│  ┌─────────────────────────────────────────────────────┐ │
│  │  Selective Forwarding                                 │ │
│  │  - May transcode for different bandwidths              │ │
│  │  - Simulcast layer selection                           │ │
│  │  - Recording (optional)                               │ │
│  └─────────────────────────────────────────────────────┘ │
│                           │                                 │
│           ┌───────────────┼───────────────┐               │
│           ▼               ▼               ▼                │
│      Recipient 1     Recipient 2    Recipient N          │
└────────────────────────────────────────────────────────────┘

┌────────────────────────────────────────────────────────────┐
│                     Decoder Side                            │
├────────────────────────────────────────────────────────────┤
│                                                             │
│  SRTP Decapsulation                                        │
│           │                                                 │
│           ▼                                                 │
│      SFU Egress                                            │
│           │                                                 │
│           ▼                                                 │
│  ┌─────────────────┐                                       │
│  │   Decode Bit   │  (Receive compressed frame)           │
│  │   Stream       │                                       │
│  └────────┬────────┘                                       │
│           │                                                 │
│           ▼                                                 │
│  ┌─────────────────┐                                       │
│  │  Dequantization │  (Inverse quantization)               │
│  │  + RLD          │                                       │
│  └────────┬────────┘                                       │
│           │                                                 │
│           ▼                                                 │
│  ┌─────────────────┐                                       │
│  │   Kalman Update │  (State estimation update)           │
│  │   + Prediction  │                                       │
│  └────────┬────────┘                                       │
│           │                                                 │
│           ▼                                                 │
│  ┌─────────────────┐                                       │
│  │  Wavelet iDWT   │  (Reconstruct full frame)           │
│  │  YUV → RGB      │                                       │
│  └────────┬────────┘                                       │
│           │                                                 │
│           ▼                                                 │
│  Display Frame (1920×1080 @ 30fps)                       │
└────────────────────────────────────────────────────────────┘
```

## Implementation Roadmap

### Phase 1: Basic SFU Integration
- [ ] Set up mediasoup server
- [ ] Replace mesh P2P with SFU transport
- [ ] Basic room management

### Phase 2: Wavelet Integration
- [ ] Integrate WaveletVideoEncoder into producer
- [ ] Integrate WaveletVideoDecoder into consumer
- [ ] Test quality/bitrate tradeoffs

### Phase 3: Kalman Enhancement
- [ ] Add Kalman prediction to encoder
- [ ] Add Kalman update to decoder
- [ ] Measure compression improvements

### Phase 4: Scale Testing
- [ ] Test with 100 concurrent users
- [ ] Test with 500 concurrent users
- [ ] Test with 1000 concurrent users
- [ ] Optimize based on results

## Monitoring Requirements

### Metrics to Track
```
- Total concurrent users
- Active rooms
- Average bitrate per user
- Packet loss rate
- Latency (RTT)
- CPU usage per SFU worker
- Memory usage
- Network throughput
- Wavelet compression ratio
- Kalman prediction accuracy
- MOS score (quality)
```

### Alerts
```
- Packet loss > 5%
- Latency > 200ms
- CPU usage > 80%
- Memory usage > 85%
- Active users approaching limit
```

## Conclusion

For 1000 users, the architecture requires:
1. **SFU** - Essential for scalability
2. **Wavelet compression** - Reduces bandwidth by 10-50x
3. **Kalman filtering** - Further reduces bandwidth via prediction
4. **Tiered infrastructure** - Load balancers, multiple SFU instances
5. **Robust monitoring** - Track quality and performance

The combination of SFU + Wavelet + Kalman could theoretically support:
- **10,000+ users** with good quality
- **50,000+ users** with degraded quality (lecture mode)
