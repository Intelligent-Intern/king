# Video Call Live Proof Readiness

Date: 2026-05-11

Scope:
- Active sprint items: `VST-01` plus the Alex build-mesh station port tracking.
- Target call: `39c5b3ea-855b-40fd-b030-c8af1d512605`.
- Join-link token is not stored here; pass it at runtime via environment.

What already exists:
- `demo/video-chat/frontend-vue/playwright.config.js` supports production
  Playwright runs via `PLAYWRIGHT_PRODUCTION_BROWSER_SMOKE=1` and
  `VIDEOCHAT_PRODUCTION_BROWSER_SMOKE=1`.
- Production Chromium starts with fake camera/microphone permission flags.
- `tests/e2e/helpers/nativeAudioTransferHarness.js` can create authenticated
  pages, install a generated canvas/audio `getUserMedia` shim, instrument
  WebSockets, create invited calls, queue lobby admission and admit the first
  lobby user.
- The current production browser smoke proves browser media in a temporary
  call, captures screenshots and records redacted diagnostic artifacts.
- `installMediaDeviceShim()` now supports `deterministicVideoPattern` plus a
  `videoPatternLabel`, so a Playwright participant can publish a recognizable
  generated test image/video without a physical camera.
- `remoteVideoPixelSnapshot()` and `waitForDeterministicRemoteVideo()` can
  sample remote canvas/video surfaces and score the deterministic pattern.
- Gossip-primary runtime selection now keeps the WLVC path active when stage A
  is available; otherwise the native-runtime block can leave the media path
  `unsupported`, which results in no remote decoded canvas.
- `tests/e2e/live-call-video-proof.spec.js` is the dedicated production harness.
  It joins the target call with two browsers, waits for the deterministic
  receiver pixels, samples reload/socket/pixel state until the configured
  duration ends, and writes JSON artifacts into Playwright output plus
  `analysis/live-call-video-proof/`.

Gaps before the 30-minute proof:
- Existing production smoke is BGF/SFU-oriented and creates its own temporary
  call; the active sprint needs a separate live-call proof against the provided
  call or join URL.
- Existing remote video probing checks `canvas.remote-video` surfaces and SFU
  counters. Gossip needs a transport-neutral receiver pixel probe and render
  counter check.
- The deterministic media shim, receiver-side pixel matcher, long-run harness
  and artifact writer exist; the remaining gap is running the full 30-minute
  proof after deploy.
- The default Playwright timeout is short; the live proof must use an explicit
  30-minute test timeout and periodic evidence collection.
- Admission for the provided join link requires an admin/owner/mod page or a
  pre-admitted authenticated participant.

Alex build-mesh station port:
- Station 0, source map: record the Alex build-mesh branch/path/commit, station
  order, target modules and focused proof command before marking any station
  complete in `SPRINT.md`.
- Station 1, control cycle: server/head owns the expected participant set, the
  5-second second-connect attempt and the 5-minute not-ready diagnosis.
- Station 2, media plan: authoritative snapshots expose the selected
  `media_session_plan.v1` ladder and participant capabilities.
- Station 3, Gossip mesh: topology, neighbor selection, accepted egress and
  backpressure are ported without reconnect or reload side effects.
- Station 4, publisher path: the selected codec/profile/cadence is applied,
  including encoder-path proof, IDR/keyframe cadence and first-frame budget or
  downscale behavior.
- Station 5, receiver proof: receiver render evidence, remote pixel samples,
  stuck reasons and counters are reported without local reconnect triggers.
- Station 6, diagnostics/UI: fallback transitions, post-deploy diagnostics and
  normal-session status filtering prove no green transport-ack banners, retry
  countdowns or reload loops.

Runtime inputs for the new proof:
- `KINGRT_LIVE_CALL_ID=39c5b3ea-855b-40fd-b030-c8af1d512605`
- `KINGRT_LIVE_JOIN_URL=<provided in chat or secure env>`
- `VIDEOCHAT_PRODUCTION_ADMIN_EMAIL`
- `VIDEOCHAT_PRODUCTION_ADMIN_PASSWORD`
- `VIDEOCHAT_PRODUCTION_USER_EMAIL`
- `VIDEOCHAT_PRODUCTION_USER_PASSWORD`

Required proof output:
- Sender and receiver browser console logs with diagnostic spam filtered into
  artifacts, not normal console output.
- WebSocket lifecycle events and close/error counts.
- Selected media plan transitions.
- Sender egress counters and receiver render counters.
- Receiver pixel samples showing the deterministic stream.
- Asset version and post-deploy container diagnostics.
- Pass/fail reason for the full 30-minute window.
