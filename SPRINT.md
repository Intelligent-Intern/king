# King Active Issues

Purpose:
- This file contains the active sprint issues for the current branch only.
- Detailed history, parked ideas, and overflow items belong in `BACKLOG.md`.
- Completion evidence belongs in `READYNESS_TRACKER.md`.

Sprint rule:
- This sprint must close the architecture gap between the current protected SFU
  app-frame relay and a smooth video-call media path.
- Do not weaken media-security, room/admission, binary envelope, diagnostics, or
  automatic quality contracts to get temporary throughput.
- Do not grow `CallWorkspaceView.vue`; new call-runtime behavior belongs in
  focused helpers/modules.
- Video quality stays automatic. No user-facing quality selector.
- Debugging must be backend-routed and structured enough to identify the first
  over-budget stage without browser-console screenshots.

## Sprint: Video Call Real Media Path Architecture

Sprint branch:
- `sprint/video-call-real-media-path-architecture`

PR target:
- `development/1.0.7-beta`

Production symptom:
- Video quality and smoothness regress under protected SFU load: repeated hard
  reconnects, blocky frames, and slow frame turnover even after local profile
  and thumbnail fixes.
- The current hot path still behaves like application message relay:
  browser-encoded frames enter WebSocket/TCP, pass through King PHP relay,
  bounded SQLite/live relay replay, browser-side decode, and canvas render.

Technical target:
- Make the sprint explicit: the correct target is a real media plane shape,
  not another round of queue-threshold tuning.
- Until the dedicated media plane lands, make every remaining WebSocket/SFU
  fallback buffer bounded, age-biased, observable, and incapable of runaway
  memory/disk growth.
- Add a deployment-quality diagnostics surface that tells us whether pressure
  starts at capture, encode, browser send, King receive, broker/fanout, receiver
  decode, or render.

## Active Issues

1. [x] `[sfu-bounded-age-biased-frame-buffer]` Make the SFU broker buffer bounded by age, rows, and bytes.

   Scope:
   - Extract frame-buffer ownership out of the oversized SFU store into a
     focused helper.
   - Keep the short-lived SQLite broker buffer, but enforce row and room-byte
     bounds on every insert.
   - Evict age-biased: stale frames first, then oldest frames before newer
     frames, with a small freshness grace so the newest live frame is protected
     whenever possible.
   - Emit backend diagnostics with evicted rows, bytes, before/after pressure,
     oldest age, and max bounds.

   Done when:
   - `sfu_frames` cannot grow beyond the per-room row or byte budget between
     cleanup intervals.
   - Eviction is deterministic and age-biased, not random and not only
     opportunistic cleanup.
   - Contracts prove the helper, byte cap, eviction policy, and diagnostics.

   Report:
   - Implemented in this WIP branch.

2. [x] `[real-media-plane-contract]` Define the target media plane that replaces WebSocket whole-frame transport.

   Scope:
   - Add a contract/doc for the production media path:
     `MediaStreamTrack -> encoder -> packet/datagram media transport -> SFU
     packet/layer forwarder -> jitter buffer/keyframe/layer recovery -> native
     renderer`.
   - Keep app-level protected media metadata and room/admission controls.
   - Make WebSocket an SFU control/signaling path, not the long-term video data
     plane.
   - Decide the implementation route: King RTP/SRTP/RTCP, WebTransport/QUIC
     datagrams, or a King-native media datagram primitive, with fallback rules.

   Done when:
   - Contracts fail if the active sprint tries to bless WebSocket/TCP
     `bufferedAmount` as the final video congestion-control layer.
   - The doc names required media-plane features: packet pacing, jitter buffer,
     keyframe request, NACK/PLI or equivalent, layer routing, receiver feedback,
     and per-subscriber quality choice.

   Report:
   - Added `documentation/dev/video-chat/real-media-plane-architecture.md` as
     the target media-plane contract.
   - Pinned WebSocket/TCP whole-frame relay as fallback/control-compatible only,
     not the final video data plane.
   - Added contract coverage so the sprint must keep packet/datagram pacing,
     jitter buffering, keyframe/NACK/PLI recovery, per-subscriber layer routing,
     backend diagnostics, and SQLite/live-relay fallback boundaries explicit.

3. [x] `[sfu-control-data-plane-split]` Split SFU control messages from media payload transport.

   Scope:
   - Keep `/sfu` WebSocket for auth, join, publish, subscribe, layer preference,
     diagnostics, and recovery controls.
   - Introduce an explicit media payload interface behind the client and backend
     so the data plane can move off WebSocket without touching UI/runtime code.
   - Preserve current binary envelope compatibility while making it a fallback
     transport, not the architecture target.

   Done when:
   - Client code has a media transport abstraction with WebSocket fallback and a
     real-media-plane implementation seam.
   - Backend route code separates control handling from payload fanout.
   - Diagnostics identify `control_transport` and `media_transport` separately.

   Report:
   - Added a frontend `SfuWebSocketFallbackMediaTransport` abstraction so binary
     frame send no longer calls the socket directly from the SFU client hot path.
   - Added explicit `websocket_sfu_control` and
     `websocket_binary_media_fallback` identifiers in client and backend
     diagnostics.
   - Backend welcome/frame metadata now marks WebSocket binary media as
     `fallback_until_real_media_plane`, preserving room/admission control while
     separating it from the future media data plane.

4. [x] `[packet-layer-sfu-forwarder]` Replace whole-frame fanout with packet/layer forwarding semantics.

   Scope:
   - Model primary/thumbnail/fullscreen layers as independently routable media
     streams.
   - Forward per-subscriber layers without forcing publisher global downshift.
   - Add keyframe request and layer-switch control messages that do not hard
     restart the SFU socket.
   - Keep slow-subscriber isolation and room/admission security.

   Done when:
   - A frozen receiver requests keyframe/layer recovery before any hard
     reconnect.
   - Fullscreen subscriber quality is isolated from mini/grid subscribers.
   - Backend diagnostics show per-subscriber media layer and recovery actions.

   Report:
   - Added `sfu/media-recovery-request` on the SFU control plane so a receiver
     can request publisher-side keyframe/layer recovery without restarting the
     media socket.
   - King routes recovery control directly when publisher and receiver are in
     the same worker, and falls back to a bounded SQLite broker table for
     cross-worker publisher delivery.
   - Publisher clients consume `sfu/publisher-recovery-request` and route
     `force_full_keyframe` into the existing WLVC full-frame keyframe path, so
     normal freezes now have a targeted recovery path before reconnect.

5. [x] `[native-render-and-jitter-buffer]` Stop treating canvas repaint as the primary receiver media runtime.

   Scope:
   - Move receiver recovery toward jitter-buffered frame ordering and native
     playback/render where available.
   - Keep canvas only for effects/compositing or fallback rendering.
   - Make render diagnostics report decode delay, dropped stale frames, frame
     ordering gaps, and final displayed frame cadence.

   Done when:
   - Reconnect is no longer the primary response to normal media jitter.
   - Receiver can smooth short gaps without resetting publisher/subscriber state.
   - Online probes verify moving video cadence, not just non-black pixels.

   Report:
   - Added a bounded receiver jitter buffer for small sequence gaps: up to 8
     frames, 90 ms hold window, and a maximum reorder gap of 3 frames.
   - The decoder now holds slightly future deltas before continuity drop,
     drains them once missing frames arrive, and only releases to normal
     keyframe recovery after the hold window expires.
   - Diagnostics now report `sfu_receiver_jitter_buffer_hold`,
     `sfu_receiver_jitter_buffer_drain`, and
     `sfu_receiver_jitter_buffer_release` so receiver jitter can be separated
     from encoder/network pressure.

6. [ ] `[end-to-end-media-pressure-observability]` Add full-path performance logging and gates.

   Scope:
   - Preserve correlation by `frame_sequence`, publisher, track, layer, profile,
     and transport generation.
   - Emit backend-routed samples for capture, encode, queue, send, King receive,
     broker/fanout, subscriber send, receiver decode, and render.
   - Keep console clean: browser warnings become structured diagnostics where
     the app can catch them.

   Done when:
   - Production smoke can report where pressure starts.
   - Critical pressure logs include the first over-budget stage, not only the
     final symptom.
   - The report per issue has enough evidence to compare quality/performance
     before and after deploy.

## Execution Order

1. Close `[sfu-bounded-age-biased-frame-buffer]` first because it prevents
   fallback buffer runaway while the media plane is rebuilt.
2. Close `[real-media-plane-contract]` before further tuning so the sprint cannot
   drift into threshold-only fixes.
3. Implement the control/data split and packet/layer forwarder.
4. Replace receiver recovery/render assumptions.
5. Deploy only when the branch is complete enough to prove smooth video cadence
   and clean backend diagnostics.
