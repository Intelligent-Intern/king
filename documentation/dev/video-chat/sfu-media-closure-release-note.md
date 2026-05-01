# 1.0.7 SFU Media Closure Release Note

Date: 2026-04-28

The online SFU media closure is release-ready for the current `1.0.7-beta`
path.

Shipped production evidence:

- Production bundle: `CallWorkspaceView-CnYCiV9E.js`.
- Production deploy smoke passed for frontend, CDN assets, public API health,
  API version, lobby websocket routing, SFU websocket routing, certificate SANs,
  and admin operations safe payloads.
- Online two-browser HD gate passed on `https://kingrt.com`: 1280x720 remote
  video on both participants for a 60s stable window, continuing SFU binary
  in/out traffic, moving frame hashes, and no stable-window media-security,
  SFU backpressure, send-buffer timeout, or legacy JSON media failures.

Release-impacting fixes:

- SFU gateway fanout now preserves codec, runtime, layout, cache, and tile
  metadata from binary media envelopes. Selective 96x96 tile/background patches
  are therefore rendered as patches over the 1280x720 base instead of shrinking
  the remote canvas.
- Protected SFU decrypt failures now invalidate stale remote decoder continuity
  state and require a fresh keyframe after media-security recovery.
- Binary media remains required for SFU frame payloads; JSON media chunking is
  rejected and not part of the active production path.

Operator proof commands:

```bash
demo/video-chat/scripts/deploy-smoke.sh
cd demo/video-chat/frontend-vue && npm run test:e2e:online-sfu-hd
```
