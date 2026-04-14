# King Issues

> Status: 2026-04-14
> Focus: King PHP Backend + specialized video codec (WLVC/Wavelet)

This file is intentionally cleaned up and replaces the old aggregate backlog.
It now contains only the current open items and the already completed/prototype state for the codec path.

## Guardrails

- active backend path stays `King/PHP` (no Node fallback as target architecture)
- no artificial contract shrink just to speed up CI; implement the stronger v1 path
- prove everything with protocol/runtime tests, not only local mock flows

## Done / Already present

- [x] RTP API added to the extension surface (`king_rtp_bind`, `king_rtp_ice_credentials`, `king_rtp_dtls_fingerprint`, `king_rtp_dtls_accept`, `king_rtp_recv`, `king_rtp_send`, `king_rtp_close`).
- [x] RTP/ICE-lite/DTLS-SRTP C implementation exists as a runtime building block (`extension/include/rtp.h`, `extension/src/media/rtp.c`).
- [x] Build/linking base for OpenSSL and optional libsrtp2 added (`extension/config.m4`).
- [x] Frontend codec prototype exists: Wavelet TypeScript, WASM wrapper, C++ WASM codec sources.
- [x] SFU prototype exists (signaling/track metadata), including frontend client binding.
- [x] Demo verification exists for the Node prototype (contract test + smoke script).

## Open / To implement (in order)

- [x] `#1` Finalize canonical WLVC wire contract (versioning, header, key/delta, fallback flags, error codes).
  Done when: a versioned spec is in the repo and encoder/decoder plus tests enforce exactly the same structure.

- [ ] `#2` Lock down media path for King/PHP (signaling, session, room membership, authorization) and remove Node special paths from target operation.
  Done when: all active realtime video-call flows run through the King/PHP backend path.

- [ ] `#3` Bind end-to-end WLVC over the real media path (no local encode->decode loopback as primary path).
  Done when: sent payload is decoded remotely as WLVC and the call works without local fake loop.

- [ ] `#4` Implement codec negotiation + fallback (WLVC <-> standard WebRTC codec).
  Done when: mixed clients connect stably and cleanly fall back to a compatible codec.

- [ ] `#5` Complete PHP runtime hardening for RTP/DTLS/SRTP (lifecycle, failure paths, cleanup, rekey/reconnect handling).
  Done when: PHPT and runtime tests show no resource leaks, no zombie peers, and deterministic recovery.

- [ ] `#6` Add security and abuse protection for media and signaling channels.
  Done when: rate limits, membership checks, replay/invalid-frame defense, and clear close reasons are testably enforced.

- [ ] `#7` Hard-wire performance budget and measurement (CPU, RTT, join time, bitrate, frame drop, packet loss).
  Done when: reproducible benchmarks plus target values are documented in-repo and run regularly in CI/smoke.

- [ ] `#8` Close test matrix (unit, PHPT, E2E multi-user, negative tests, decoder/parser fuzz).
  Done when: the WLVC path stays green under load, failures, and mixed client capability.

- [ ] `#9` Clean repo hygiene for codec track (no build/DB/log artifacts in branch as product code).
  Done when: `.gitignore`, CI, and branch content are clean and only required runtime/source artifacts stay committed.

## Next step

- [ ] Start with `#2` (King/PHP-only media path), then `#3` (real end-to-end WLVC path), before further UI/UX work.
