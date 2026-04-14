# King Issues

> Status: 2026-04-14
> Focus: SFU-first media path + WLVC (Wavelet + Kalman) implemented in PHP backend

This tracker is now aligned to the requested direction:
- SFU (server-forwarded media), not mesh P2P as the primary architecture
- codec pipeline (wavelet + Kalman) implemented in PHP backend logic, not only frontend prototype logic

## Non-negotiable direction

- active backend path stays `King/PHP` (no Node fallback as target architecture)
- primary call topology is **SFU**, not browser mesh P2P
- WLVC codec path (wavelet + Kalman stages) must be implemented in backend PHP code and exposed through stable APIs
- no contract shrink to speed up CI; build the stronger path
- prove behavior with wire/runtime tests, not only mock UI flows

## Done in current branch

- [x] Canonical WLVC wire contract is versioned in-repo (`demo/video-chat/contracts/v1/wlvc-frame.contract.json`).
- [x] WLVC encode/decode contract tests exist for backend/frontend wire-envelope parity.

## Known prototype work (feature branch, not yet integrated here)

- [ ] RTP/DTLS/SRTP C slice from `feature/sfu-and-wasm-codec-for-video-demo` is not yet merged into this branch.
- [ ] SFU prototype from that branch is metadata/signaling oriented and still not the final server-side media-forwarding implementation required for v1.

## Open / To implement (priority order)

- [ ] `#1` Import and integrate the RTP C runtime slice into this branch (`extension/include/rtp.h`, `extension/src/media/rtp.c`, `extension/src/php_king.c`, `extension/config.m4`, stubs).
  Done when: extension builds cleanly with the RTP surface enabled and PHP stub parity reflects the exported API.

- [ ] `#2` Implement server-side SFU media forwarding in King runtime as the primary call topology.
  Done when: media forwarding is server-authoritative via SFU path and multi-party calls do not depend on mesh P2P as primary transport.

- [ ] `#3` Implement wavelet stage in PHP backend codec pipeline.
  Done when: wavelet transform runs in PHP backend path and is used in live media packets, not only local frontend loopback.

- [ ] `#4` Implement Kalman prediction/filter stage in PHP backend codec pipeline.
  Done when: Kalman stage is active in PHP encode/decode flow with deterministic behavior and test coverage.

- [ ] `#5` Bind end-to-end WLVC media path over King/PHP SFU pipeline.
  Done when: sender payload is encoded as WLVC, routed through SFU, and decoded remotely without local fake encode->decode loopback.

- [ ] `#6` Add codec negotiation + fallback policy (WLVC <-> standard WebRTC codec).
  Done when: mixed-capability clients connect reliably and fallback occurs deterministically with explicit telemetry.

- [ ] `#7` Complete PHP runtime hardening for RTP/DTLS/SRTP/SFU lifecycle.
  Done when: no resource leaks, no zombie peers, deterministic cleanup/reconnect/rekey behavior, and fail-closed error mapping.

- [ ] `#8` Add security and abuse protection for media/signaling channels.
  Done when: rate limits, room-membership authorization, replay/invalid-frame rejection, and clear close reasons are testably enforced.

- [ ] `#9` Lock performance budget and telemetry (CPU, RTT, join time, bitrate, frame drop, packet loss, SFU fanout cost).
  Done when: reproducible benchmarks and SLO targets are documented and exercised in CI/smoke.

- [ ] `#10` Close test matrix for SFU+WLVC runtime path.
  Done when: unit + PHPT + E2E multi-user + negative + fuzz coverage stay green under load and mixed client capabilities.

- [ ] `#11` Repo hygiene for codec track.
  Done when: no build/db/log artifacts are committed as product code and `.gitignore`/CI enforce clean artifact boundaries.

## Next step

- [ ] Start with `#1` (import RTP C slice), then `#2` (true SFU forwarding), then `#3/#4` (wavelet+Kalman in runtime).
