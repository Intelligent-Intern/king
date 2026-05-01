# Video Call Real Media Plane Architecture

This document is the sprint anchor for `real-media-plane-contract`.

The current protected SFU path is allowed to exist only as the compatibility
fallback while the real media plane is implemented. It must not be treated as
the final video architecture.

## Target Path

The target production media path is:

`MediaStreamTrack -> encoder -> packet/datagram media transport -> SFU packet/layer forwarder -> jitter buffer/keyframe/layer recovery -> native renderer`

The WebSocket path remains the authorized control and signaling surface:

- authentication and room/call admission,
- join/publish/subscribe lifecycle,
- receiver layer preference,
- keyframe and recovery requests,
- structured diagnostics,
- fallback binary media transport while the dedicated media plane is incomplete.

WebSocket/TCP `bufferedAmount` is not the final congestion-control layer for
video. Whole-frame WebSocket relay is a fallback transport, not the target data
plane.

## Required Media-Plane Features

The replacement media data plane must provide:

- packet or datagram pacing before browser or King buffers reach critical
  pressure,
- loss-aware transport behavior that avoids TCP head-of-line blocking for live
  video,
- receiver jitter buffering and frame-order handling,
- keyframe request and equivalent NACK/PLI recovery,
- per-subscriber layer routing for primary, thumbnail, and fullscreen use,
- slow-subscriber isolation without publisher-wide quality collapse,
- receiver feedback that can lower or raise quality automatically,
- backend-routed diagnostics for capture, encode, send, King receive,
  broker/fanout, subscriber send, decode, and render.

## King Runtime Direction

The implementation route can be King RTP/SRTP/RTCP, WebTransport/QUIC
datagrams, or a King-native media datagram primitive. The chosen route must keep
the existing stronger contracts:

- server-authoritative room and call admission,
- app-level protected media envelopes where policy requires them,
- bounded public metadata visible to SFU/relay code,
- no plaintext downgrade,
- no client-invented topology or admission,
- automatic quality and layer selection,
- backend diagnostics instead of console-only debugging.

## Fallback Boundaries

Until the dedicated media plane is active, the current WebSocket fallback must
stay bounded and observable:

- outbound client queues stay capped by frame count, bytes, and age,
- broker replay buffers stay short-lived, RAM-backed, row-capped, byte-capped,
  and age-biased,
- hard reconnect is a last resort after keyframe/layer recovery,
- every pressure event names the first known over-budget stage.

SQLite and live-relay buffers are replay/fallback infrastructure only. They are
not the target live media forwarding layer.

## Acceptance

This sprint is complete only when production proof shows smooth moving video
with:

- no recurring hard reconnect loop during normal motion,
- no critical WebSocket send-buffer pressure in the stable window,
- no `VideoFrame` lifecycle warnings,
- visible primary-layer quality for two-participant fullscreen/main views,
- backend diagnostics that identify the first over-budget stage when pressure
  occurs.
