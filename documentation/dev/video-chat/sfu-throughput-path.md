# SFU/WLVC Throughput Path

This document is the sprint anchor for `full-path-throughput-analysis`.
It names every hot media stage that can create `sfu_send_backpressure_critical`
and lists the timing or byte measurement that must travel with a frame.

## Correlation Contract

Every sampled media frame must be tied together by:

- `frame_sequence`
- `outgoing_video_quality_profile`
- `sender_sent_at_ms`
- `track_id`
- `publisher_id`
- `transport_path`

The publisher owns `frame_sequence`. King and receivers must preserve it when
they add receive, fanout, send, decode, and render timings.

## Stage Inventory

| Stage id | Stage | Current implementation | Required measurement |
| --- | --- | --- | --- |
| `camera_capture` | Camera capture constraints | `buildLocalMediaConstraints()` in `mediaOrchestration.js` | browser track settings, capture width, capture height, capture fps |
| `background_processing` | Background/matte pipeline | `segmentationPipeline.js`, consumed by `publisherPipeline.js` | mask enabled, matte-guided flag, background update cadence |
| `dom_canvas_readback` | DOM video to canvas readback | `drawImage()` and `getImageData()` in `publisherPipeline.js` | `draw_image_ms`, `readback_ms`, source and output dimensions |
| `wlvc_encode` | WLVC encode | `encoder.encodeFrame()` and tile patch encoding in `publisherPipeline.js` | `encode_ms`, frame type, encoded bytes, keyframe/cache epoch |
| `selective_tile_planning` | Selective tile plan | `selectiveTilePlanner.js` via `publisherPipeline.js` | selected tiles, total tiles, selected tile ratio, ROI area ratio |
| `binary_envelope_build` | Binary payload/envelope build | `prepareSfuOutboundFramePayload()` in `framePayload.ts` | payload bytes, projected binary envelope overhead, continuation state |
| `outbound_queue` | Browser outbound frame queue | `SfuClient.enqueuePreparedFrame()` in `sfuClient.ts` | `queued_age_ms`, queue depth, drop reason |
| `browser_websocket_buffer` | Browser `WebSocket.bufferedAmount` | `waitForSendBufferDrain()` and `sendBinaryFrame()` in `sfuClient.ts` | buffered bytes before send, drain wait ms, low-water/high-water state |
| `network_proxy` | Browser-to-King websocket/TLS/proxy | production ingress in front of `/sfu` | wire payload bytes, continuation threshold, close/error status |
| `king_websocket_receive` | King websocket receive loop | `videochat_handle_sfu_routes()` in `realtime_sfu_gateway.php` | King receive timestamp and sender-to-King receive latency |
| `king_binary_decode` | King binary decode | `videochat_sfu_decode_binary_client_frame()` in `realtime_sfu_store.php` | decoded payload bytes, wire payload bytes, decode status |
| `sfu_relay_fanout_broker` | Direct fanout, live relay, broker path | `realtime_sfu_gateway.php`, `realtime_sfu_broker_replay.php` | fanout latency, relay publish latency, relay age, subscriber count |
| `king_websocket_send` | King websocket send to receivers | `videochat_sfu_send_outbound_message()` in `realtime_sfu_store.php` | subscriber send latency, send path, outbound payload bytes |
| `receiver_decode` | Receiver frame assembly and WLVC decode | `inboundFrameAssembler.ts`, `frameDecode.js` | receiver receive latency, decode wait/error state, decoded frame count |
| `receiver_render` | Receiver canvas/video render | `frameDecode.js` and `CallWorkspaceView` remote canvas binding | render latency, rendered frame count, frozen-frame age |

## Current Byte Budgets

The active sprint path is binary SFU media with application chunking disabled.
The current envelope reports:

- `payload_bytes`
- `payload_chars`
- `wire_payload_bytes`
- `projected_binary_envelope_overhead_bytes`
- `legacy_base64_overhead_bytes`
- `binary_continuation_state`
- `binary_continuation_threshold_bytes`

Per-profile budgets are enforced in the frontend profile configuration and must
be copied into outbound frame metadata before the socket send:

- `budget_max_encoded_bytes_per_frame`
- `budget_max_wire_bytes_per_second`
- `budget_max_encode_ms`
- `budget_max_queue_age_ms`
- `budget_max_buffered_bytes`

## Production Proxy Probe

`demo/video-chat/frontend-vue/tests/e2e/production-socket-proxy-budget.mjs`
opens real production `/sfu` publisher and subscriber sockets, sends binary SFU
envelopes around the 65,535 byte continuation threshold and up to the `quality`
profile payload budget, then fails on websocket close/error, SFU error frames,
critical `bufferedAmount`, missing subscriber binary delivery, or post-drain
buffering above the active quality budget.

## Failure Interpretation

`sfu_send_backpressure_critical` means at least one earlier stage produced,
queued, copied, or delayed bytes beyond budget. The correct response is not only
to reconnect. The next fixes must identify the first stage where the measured
frame exceeds its profile budget, then apply capture, encoder, queue, or fanout
pressure before browser `bufferedAmount` reaches the critical threshold.
