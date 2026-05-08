# King Active Issues

Purpose:
- `SPRINT.md` contains only the active top-priority sprint.
- Completed sprint detail is intentionally removed from this file.
- Parked or deferred work lives in `BACKLOG.md`.
- Completion evidence belongs in commit history, contracts, and readiness docs.

Rules:
- Work one checkbox at a time unless the user explicitly expands scope.
- A checkbox is only closed after implementation and proof.
- Do not weaken King v1 contracts to make the sprint smaller.
- Do not grow `CallWorkspaceView.vue` or other oversized files; extract focused
  helpers/components when adding behavior.

## Sprint: Browser-Resilient Background Segmentation Fallback

Branch:
- `develop/1.0.8-beta`

Status:
- Active as of 2026-05-08.
- This sprint replaces the completed Whiteboard Call App hardening work as the
  active top-priority sprint.
- User-facing problem: Chrome/Chromium can break MediaPipe segmentation init by
  forcing GPU-related internals even when CPU delegation is requested. The
  current fallback can degrade into a matte that swallows the participant.

Sprint goal:
- Keep Pierre Joye's worker/WebGL background pipeline as the production
  replacement path: if background replacement is possible and the user wants it,
  apply it.
- If background replacement is not possible, do not silently degrade the matte
  or apply synthetic replacement over the person. Keep media alive and ask the
  user in a modal to choose a standard avatar, upload an avatar, or send
  unfiltered camera video with background replacement disabled.
- Preserve visual quality: no softmax/sigmoid participant blending, no ghost
  translucency, no full-person disappearance. Edge treatment must use contour
  alpha smoothing only.
- Make browser updates survivable: init failures, GPU service errors, model
  load failures, and worker crashes must quarantine the failing backend without
  reload loops, audio loss, or video publication failure.

Current baseline:
- Production uses Pierre's worker-scoped MediaPipe Tasks pipeline with the
  WebGL compositor contribution preserved.
- The background-unavailable path is a user choice, not a hidden fallback:
  standard avatar, uploaded avatar, or unfiltered video. Avatar mode is a
  static control-state signal plus live audio, not a fake streamed video track.
- The compositor must render the source frame while the worker has no renderable
  matte, so a failed or warming segmenter cannot swallow the participant.
- Whiteboard Call App is deployed and installed; the Call Apps attach tab is now
  visible for resolved calls and requests a room snapshot after attach.
- Media reconnect cleanup now has a focused contract proving stale local capture
  cleanup preserves active camera/audio/screenshare streams and emits
  reconnect-specific diagnostics instead of looking like an intentional media
  shutdown. A second browser-oriented smoke runs the real retired-stream cleanup
  helper in a fake MediaStream sandbox and proves active camera, microphone, and
  screen-share tracks stay live while retired tracks stop once. The main smoke
  script exposes a deterministic Node-only release gate for these reconnect and
  screenshare contracts without requiring real devices.
- Realtime websocket reconnect now treats transient auth/backend and call-room
  backfill failures as retryable instead of silently falling back to lobby or
  closing as a revoked session. Requested call reconnects receive explicit
  retryable diagnostics and authoritative room/lobby snapshots once backfill is
  available.
- Public call-access join now captures the verified logged-in user/session after
  link verification and sends that context plus the current bearer token into
  call-access session issuance, so a later login switch cannot bind the link to
  a different account.

Contract anchors:
- `demo/video-chat/frontend-vue/src/domain/realtime/background/stream.ts`
- `demo/video-chat/frontend-vue/src/domain/realtime/background/backendWorkerSegmenter.js`
- `demo/video-chat/frontend-vue/src/domain/realtime/background/workers/imageSegmenterWorker.js`
- `demo/video-chat/frontend-vue/src/domain/realtime/background/BackgroundReplacementUnavailableModal.vue`
- `demo/video-chat/frontend-vue/src/domain/realtime/background/pipeline/compositorStage.js`
- `demo/video-chat/frontend-vue/tests/standalone/king-background-segmentation-harness.ts`
- `demo/video-chat/frontend-vue/tests/contract/background-filter-mask-contract.mjs`
- `demo/video-chat/frontend-vue/tests/contract/background-king-wasm-contract.mjs`
- `demo/video-chat/frontend-vue/tests/contract/background-segmentation-harness-contract.mjs`
- `demo/video-chat/frontend-vue/tests/contract/mediapipe-cdn-contract.mjs`
- `demo/video-chat/deploy.sh`
- `demo/video-chat/docker-compose.v1.yml`
- `demo/video-chat/scripts/lib/deploy-hetzner.sh`
- `demo/video-chat/edge/edge.php`
- `demo/video-chat/frontend-vue/src/support/backendOrigin.ts`
- `demo/video-chat/frontend-vue/src/domain/realtime/callApps/callAppWorkspaceState.js`
- `demo/video-chat/backend-king-php/domain/call_apps/call_app_semantic_dns.php`
- `demo/video-chat/backend-king-php/domain/marketplace/call_app_marketplace.php`

Execution boundary:
- Preserve Pierre Joye's WebGL/background-removal contribution history and do
  not rewrite or squash that work.
- Do not replace Pierre's worker pipeline with an unrelated production SINet or
  "degraded matte" implementation.
- Do not add softmax, sigmoid, or whole-mask alpha curves that make a person
  semi-transparent.
- Do not grow `CallWorkspaceView.vue`; new behavior belongs in focused
  background backend, compositor, diagnostics, or harness modules.
- Do not turn background-filter failure into call failure. Camera, audio,
  screenshare, and reconnect must continue when segmentation is unavailable.

Acceptance criteria:
- Chrome/Chromium MediaPipe GPU-service init failures do not break calls.
- If background replacement init fails, the source remains visible and the user
  gets a modal choice: standard avatar, uploaded avatar, or unfiltered video.
- The participant center remains opaque in the matte harness; only contour
  pixels receive alpha smoothing.
- No `Math.exp`, softmax, sigmoid, or equivalent probabilistic blending exists
  in the production fallback matte path.
- Background filter failure cannot mute audio or stop media publication.
  Avatar choice must stop avatar/video frame publication and signal one static
  image state to peers until another media control-state replaces it.
- Diagnostics name the selected backend, failed backend, browser family, GPU
  availability, model source, fallback reason, and that user choice is required.
- Production deploy smoke proves call app static assets and background model
  assets are served from the expected origins.
- Production service domains are rooted at `kingrt.com` only:
  `api.kingrt.com`, `ws.kingrt.com`, `sfu.kingrt.com`, `cdn.kingrt.com`,
  `turn.kingrt.com`, and `registry.kingrt.com`. No service origin may derive
  from `app.kingrt.com`.
- Whiteboard is hosted as `whiteboard.kingrt.com`, appears in the Marketplace,
  can be added to a production `kingrt.com` organization, and then appears in
  that organization's Call Apps tab.
- `registry.kingrt.com` is the canonical Semantic-DNS and mothernode join
  registry, including dev-key approval for KingRT network membership and
  self-hosted call-app manifests that point at a private mothernode.

Tickets:
- [x] BGF-01 Browser regression matrix and reproducible failure capture
  - Capture Chrome Stable/Chromium Ubuntu/Firefox behavior for MediaPipe demo
    and King production paths.
  - Record exact browser versions, failing console signatures, backend choice,
    and whether CPU delegation still touches GPU internals.
  - Add a contract fixture for the known Chrome GPU-service init failure shape.
  - Proof: `background-regression-matrix-fixture.json` records Chrome Stable,
    Chromium Ubuntu, Firefox, disabled-GPU Chrome failure capture, exact
    GPU-service signatures, and CPU delegation touching GPU internals; `node
    tests/contract/background-regression-matrix-contract.mjs` PASS.

- [x] BGF-02 Backend selection ladder with quarantine
  - Keep production on Pierre's worker segmenter pipeline, with MediaPipe scoped
    to the worker boundary.
  - When worker init fails, render the source frame and open the user-choice
    modal instead of silently selecting another matte backend.
  - Ensure backend switching is idempotent and cannot trigger reload loops.
  - Proof: `background-regression-matrix-contract.mjs` and
    `background-king-wasm-contract.mjs` pin the worker boundary, idempotent init,
    source-visible unavailable state, and explicit modal alternatives.

- [x] BGF-03 Matte correctness: hard foreground plus contour smoothing
  - Remove any remaining softmax/sigmoid-style probability blending from the
    fallback path.
  - Treat foreground/background classification as hard membership, then apply
    alpha only on the contour band.
  - Add harness checks that the torso/face center stays opaque and background
    pixels do not leak into the participant.
  - Proof: `background-filter-mask-contract.mjs` pins Pierre's worker pipeline,
    no softmax/sigmoid fallback blending, source-visible warmup/failure
    rendering, and explicit modal alternatives.

- [x] BGF-04 Background-unavailable user choice
  - Ask the user to choose standard avatar, uploaded avatar, or unfiltered video
    when background replacement is unavailable.
  - Avatar choice signals one static image state to peers and keeps only live
    audio in the published stream; it must not stream avatar frames or deltas.
  - Never apply synthetic replacement over the participant without a renderable
    matte.
  - Keep user media tracks alive while the filter is disabled or warming up.
  - Proof: `BackgroundReplacementUnavailableModal.vue`,
    `avatarFallbackSignal.ts`, `staticAvatarRender.ts`,
    `mediaOrchestration.ts`, and `background-filter-mask-contract.mjs` wire the
    standard-avatar, uploaded-avatar, and unfiltered-video choices while
    preserving audio tracks and rendering avatars as static tile media.

- [x] BGF-05 Compositor and warmup safety
  - Make WebGL/canvas compositor warmup deterministic across backend changes.
  - Avoid stale-mask freeze, blue-screen swallow, and one-frame full-background
    flashes.
  - Add pixel-level compositor contracts for warmup, backend switch, and
    segmentation-unavailable states.
  - Proof: `background-compositor-warmup-safety-contract.mjs` pins warmup,
    stale-mask reuse, backend reset, no blue-background swallow, and
    segmentation-unavailable source rendering; `npm run
    test:contract:background-filter` PASS.

- [x] BGF-06 Runtime diagnostics and field observability
  - Emit throttled diagnostics for backend init, unavailable transition, modal
    choice, and matte rejection.
  - Include enough local context to debug browser regressions without leaking
    media frames, SDP, ICE, or tokens.
  - Surface concise state in existing diagnostics channels, not new reload UI.
  - Proof: `runtimeDiagnostics.js` emits throttled safe diagnostics for backend
    init, unavailable transition, modal choice, and matte rejection without SDP,
    ICE, token, or media-frame leakage; `node
    tests/contract/background-runtime-diagnostics-contract.mjs` PASS.

- [ ] BGF-07 Online proof, deploy, and browser smoke
  - Run focused background contracts, build, deploy, and production smoke.
  - Verify a real call in Chrome/Chromium and Firefox with camera, audio,
    screenshare, reconnect, and background filter transitions.
  - Record proof commands and results in this sprint before closing.
  - Proof 2026-05-08 (non-deploy support checks actually run; deploy/browser
    proof remains open): `bash -n demo/video-chat/scripts/prod-debug.sh &&
    bash -n demo/video-chat/scripts/deploy-smoke.sh` PASS;
    `VIDEOCHAT_PROD_DEBUG_DRY_RUN=1 VIDEOCHAT_PROD_DEBUG_SKIP_REMOTE=1
    demo/video-chat/scripts/prod-debug.sh` PASS; `(cd
    demo/video-chat/frontend-vue && npm run test:contract:background-filter)`
    PASS; `(cd demo/video-chat/frontend-vue && node
    tests/contract/prod-debug-observability-contract.mjs)` PASS; `(cd
    demo/video-chat/frontend-vue && npm ci && npm run build)` PASS;
    `VIDEOCHAT_DEPLOY_DOMAIN=kingrt.com VIDEOCHAT_DEPLOY_SMOKE_SKIP_REMOTE=1
    VIDEOCHAT_DEPLOY_SMOKE_SKIP_ADMIN=1 demo/video-chat/scripts/deploy-smoke.sh`
    PASS; `VIDEOCHAT_DEPLOY_DOMAIN=kingrt.com
    VIDEOCHAT_PROD_DEBUG_SKIP_REMOTE=1 demo/video-chat/scripts/prod-debug.sh`
    PASS.

- [x] BGF-08 KingRT Domain Contract Cutover
  - Split deploy configuration into `kingrt.com` as the base domain and
    `app.kingrt.com` as the frontend application domain.
  - Serve production services only on `api.kingrt.com`, `ws.kingrt.com`,
    `sfu.kingrt.com`, `cdn.kingrt.com`, `turn.kingrt.com`, and
    `registry.kingrt.com`.
  - Hard-remove old nested service domains such as `cdn.app.kingrt.com` and
    `api.app.kingrt.com`; do not keep aliases for the cutover.
  - Add a domain-contract test that fails if any generated service domain ends
    in `.app.kingrt.com`.
  - Proof: `codex/domain-registry-cutover` deployed to production, deploy smoke
    passed, live frontend bundle scan found no `*.app.kingrt.com` service
    origins, and authoritative Hetzner DNS no longer serves old nested A
    records.

- [x] BGF-09 Call App Hosting and Semantic Registry
  - Host Whiteboard at `whiteboard.kingrt.com` and resolve future Call Apps as
    `{app_key}.kingrt.com`.
  - Reserve service names such as `app`, `api`, `ws`, `sfu`, `cdn`, `turn`,
    and `registry` so Call Apps cannot claim platform domains.
  - Use `registry.kingrt.com` as the dev-key-approved registry for Call App
    registration, Semantic DNS, and mothernode join announcements.
  - Allow self-hosted Call App manifests to declare a private mothernode that
    is not part of the KingRT network.
  - Proof: production deploy serves Whiteboard from `whiteboard.kingrt.com`,
    uses `registry.kingrt.com` for mothernode/registry configuration, and
    semantic Call App DNS reserves platform service labels.

- [x] BGF-10 Whiteboard Marketplace Production Proof
  - Ensure Whiteboard is visible in the production Marketplace.
  - Ensure the add-to-organization/install action is present and persists
    backend entitlements/installations.
  - Ensure Whiteboard appears in the Call Apps tab for calls owned by that
    organization after installation.
  - Proof 2026-05-08: `VIDEOCHAT_DEPLOY_DOMAIN=kingrt.com
    demo/video-chat/scripts/prod-whiteboard-org-install-proof.sh` exercises
    the authenticated `api.kingrt.com` Marketplace catalog/order/install
    endpoints, persists an enabled Whiteboard organization installation with an
    active entitlement, creates a temporary call for that organization, verifies
    Whiteboard in `/call-apps/available`, attaches it through
    `/call-app-sessions`, reaches `whiteboard.kingrt.com`, and deletes only the
    temporary proof call.

- [x] BGF-11 sicherstellen, dass whiteboard auch bei kingrt.com einer orga zugefügt werden kann
  - Run the production `kingrt.com` Marketplace journey end to end.
  - Prove that a real organization can add Whiteboard from Marketplace and use
    it inside a call without manual database edits.
  - Record the production proof command/output before closing the sprint.
  - Proof 2026-05-08: `VIDEOCHAT_DEPLOY_DOMAIN=kingrt.com
    demo/video-chat/scripts/prod-whiteboard-org-install-proof.sh` is the
    idempotent production add-to-organization proof; it uses only public
    authenticated API endpoints, no database edits, then proves Call Apps tab
    availability, session attach, entitlement-backed access policy, and
    `whiteboard.kingrt.com` iframe reachability.

## Sprint: Whiteboard Call App Hardening And Production Integration

Branch:
- `develop/1.0.8-beta`

Status:
- Completed as of 2026-05-08.
- Completed Gossip and Call Apps foundation tickets were removed from the active
  sprint.
- Open non-active Governance, admin UX, and broad refactoring work was moved to
  `BACKLOG.md`.
- [x] `[real-media-plane-contract]` remains closed by
  `documentation/dev/video-chat/real-media-plane-architecture.md` while this
  sprint moves the carrier from SFU-first fallback toward Gossip-primary media.
- [x] `[sfu-control-data-plane-split]` remains closed by the SFU control/data
  split contracts and documentation.
- 4. [x] `[packet-layer-sfu-forwarder]` remains closed by the recovery-control
  routing and packet/layer forwarding contracts.
- 5. [x] `[native-render-and-jitter-buffer]` remains closed by the receiver
  jitter-buffer contracts.
- 6. [x] `[end-to-end-media-pressure-observability]` remains closed by the
  end-to-end media pressure diagnostics contracts.
- [x] GSP-02 Publisher pipeline decoupling remains closed by
  `gossip-publisher-pipeline-decoupling-contract.mjs`.
- [x] GSP-03 Join/snapshot/churn topology hints remains closed by
  `gossip-room-state-topology-contract.mjs`.
- [x] GSP-04 Dedicated bounded neighbor lifecycle remains closed by
  `gossip-dedicated-neighbor-lifecycle-contract.mjs`; production stack-overflow
  proof `gossip-neighbor-renegotiate-stack-contract.mjs` now pins queued
  renegotiation as deduped, timer-based, bounded, and cleared on peer close
  instead of recursively calling `negotiatePeer`.
- [x] GSP-07 Gossip-native recovery remains closed by
  `gossip-native-recovery-contract.mjs`; backend recovery routing stays ops-only
  with no media fanout.
- [x] GSP-08 Server no-normal-media-fanout guard remains closed by
  `gossip-server-no-media-fanout-contract.mjs`; normal media fanout is rejected
  as `normal_media_fanout_forbidden`.
- [x] GSP-09 Integration contracts and smoke checks remains closed by
  `gossip-media-carrier-integration-smoke-contract.mjs`; smoke covers
  `gossip_primary`, `sfu_first`, and `sfu_mirror`.

Sprint goal:
- Make the Whiteboard Call App the first production-usable CRDT Call App for
  video calls.
- Keep Call Apps organization-installable through Marketplace, discoverable via
  Semantic DNS/MCP metadata, launchable only through short-lived iframe tokens,
  and synchronized through King CRDT envelopes.
- Preserve the stronger security model: the iframe never receives the user's
  primary session token and participant grants remain backend-authoritative.

Current baseline:
- Call Apps live under `demo/call-app/<app-key>/`; Whiteboard is the first
  concrete package at `demo/call-app/whiteboard/`.
- Call App package root exists at `demo/call-app/whiteboard`.
- Package metadata exists:
  - `call-app.manifest.json`
  - `mcp.descriptor.json`
  - `crdt.schema.json`
  - `health.descriptor.json`
  - `public/index.html`
- Backend Call Apps domains exist for Semantic DNS, MCP metadata, Marketplace
  entitlements, app availability, session lifecycle, launch tokens, participant
  grants, and CRDT persistence.
- Frontend Call Apps host/sidebar/grant/iframe/CRDT bridge modules exist under
  `demo/video-chat/frontend-vue/src/domain/realtime/callApps`.
- `CallWorkspaceView.vue` must not grow with Call App implementation logic.

Contract anchors:
- Capabilities:
  - `call_apps.discover`
  - `call_apps.marketplace.order`
  - `call_apps.marketplace.install`
  - `call_apps.marketplace.disable`
  - `call_apps.call.attach`
  - `call_apps.call.remove`
  - `call_apps.call.view`
  - `call_apps.permissions.manage`
  - `call_apps.permissions.use`
  - `call_apps.permissions.revoke`
  - `call_apps.launch`
  - `call_apps.launch.validate`
  - `call_apps.crdt.read`
  - `call_apps.crdt.append`
  - `call_apps.crdt.replay`
  - `call_apps.presence.publish`
  - `call_apps.export.request`
  - `call_apps.export.download`
- Routes:
  - `GET /api/admin/marketplace/apps`
  - `POST /api/admin/marketplace/apps`
  - `GET /api/admin/marketplace/apps/{app_id}`
  - `PATCH /api/admin/marketplace/apps/{app_id}`
  - `DELETE /api/admin/marketplace/apps/{app_id}`
  - `GET /api/marketplace/call-apps`
  - `GET /api/marketplace/call-apps/{app_key}`
  - `POST /api/marketplace/call-apps/{app_key}/orders`
  - `POST /api/marketplace/call-apps/{app_key}/installations`
  - `PATCH /api/marketplace/call-apps/{app_key}/installations/{installation_id}`
  - `GET /api/calls/{call_id}/call-apps/available`
  - `GET /api/calls/{call_id}/call-app-sessions`
  - `POST /api/calls/{call_id}/call-app-sessions`
  - `PATCH /api/call-app-sessions/{session_id}`
  - `DELETE /api/call-app-sessions/{session_id}`
  - `GET /api/call-app-sessions/{session_id}/participant-grants`
  - `PATCH /api/call-app-sessions/{session_id}/participant-grants`
  - `POST /api/call-app-sessions/{session_id}/launch-token`
  - `POST /api/call-app-sessions/{session_id}/launch-token/validate`
  - `GET /api/call-app-sessions/{session_id}/crdt/bootstrap`
  - `GET /api/call-app-sessions/{session_id}/crdt/ops`
  - `POST /api/call-app-sessions/{session_id}/crdt/ops`
  - `POST /api/call-app-sessions/{session_id}/crdt/snapshots`
  - `POST /api/call-app-sessions/{session_id}/exports`
  - `GET /api/call-app-exports/{job_id}`
  - `GET /api/call-app-exports/{job_id}/download`
- MCP methods:
  - `call_app.describe`
  - `call_app.capabilities`
  - `call_app.crdt_schema`
  - `call_app.launch_contract`
  - `call_app.health`
  - `call_app.export_formats`
  - `call_app.marketplace_listing`

Execution boundary:
- Do not touch Pierre-owned MediaPipe/background segmentation internals.
- Do not change the video media carrier while hardening Whiteboard, except for
  necessary Call App host integration.
- Do not expose primary auth/session tokens to iframe apps.
- Do not replace backend grants with UI-only flags.
- Keep the app package self-describing through manifest, MCP descriptor, CRDT
  schema, and health descriptor.

Acceptance criteria:
- Whiteboard can be discovered from the package metadata and Marketplace/Call
  App catalog path.
- A call owner can attach Whiteboard to a call and choose default participant
  access.
- Permitted participants can draw concurrently.
- Viewer-only or revoked participants cannot submit CRDT ops.
- The iframe runs sandboxed and only communicates through the Call App bridge.
- Whiteboard supports the first usable tool set: select/move, pen, highlighter,
  line, rectangle, ellipse, text, sticky note, eraser, undo, redo, PNG export,
  and PDF export.
- A browser E2E proof covers attach, draw from two participants, revoke one
  participant, reconnect, and verify state.

Tickets:
- [x] WCA-01 Sprint/backlog hygiene and package contract
  - Remove completed Gossip and Call Apps foundation tickets from `SPRINT.md`.
  - Move remaining open non-active work to `BACKLOG.md`.
  - Keep Whiteboard Call App as the active sprint.
  - Update Call App contracts so they check the active Whiteboard sprint instead
    of completed CAP checklist archaeology.
  - Proof:
    - `SPRINT.md` now contains only this active Whiteboard sprint.
    - `BACKLOG.md` contains the parked Governance, admin UX, and broad
      refactoring work.

- [x] WCA-02 Whiteboard runtime tool completeness first pass
  - Add select/move behavior for existing shapes, text, and sticky notes.
  - Add line and ellipse shape tools alongside rectangle.
  - Add actor-local undo/redo controls for add/delete flows.
  - Preserve viewer/editor mode from launch grant capabilities.
  - Keep PNG/PDF export and the no-primary-token iframe contract.
  - Proof:
    - `demo/call-app/whiteboard/public/index.html` includes Select, Line,
      Oval, Undo, and Redo controls.
    - Runtime still disables edit controls when `canAppend()` is false.

- [x] WCA-03 Split Whiteboard runtime into maintainable assets
  - Extract the current iframe CSS into `public/whiteboard.css`.
  - Extract the current iframe runtime into `public/whiteboard.js`.
  - Keep `public/index.html` as a thin sandbox entrypoint.
  - Keep every source file below the 800-line target.
  - Update package health/contracts to include the runtime assets.
  - Proof:
    - `public/index.html` is now a 64-line sandbox shell.
    - `public/whiteboard.css` is 181 lines.
    - `public/whiteboard.js` is 669 lines.
    - `health.descriptor.json` checks the HTML, CSS, and JS runtime assets.
    - `npm run test:contract:call-apps` passes, with the three PDO-SQLite
      backend contracts skipped when the local driver is unavailable.

- [x] WCA-04 Browser E2E for Whiteboard call journey
  - Order/install or seed Whiteboard for a test organization.
  - Attach Whiteboard to a call as owner.
  - Open two participants and draw from both.
  - Revoke one participant and prove append is denied.
  - Reconnect and verify snapshot/replay state remains correct.
  - Proof:
    - `tests/e2e/call-app-whiteboard.spec.js` runs the real Whiteboard iframe
      runtime in a sandboxed browser frame.
    - The harness orders, installs, and attaches Whiteboard for a test
      organization before launch.
    - Owner and participant both launch against the same CRDT document and
      admit `stroke.add` and `sticky_note.add` operations.
    - Participant revocation forwards `participant_grant_denied` through the
      iframe bridge and disables editing without admitting another op.
    - Owner reconnects, bootstraps from replay, and renders the existing
      document state.
    - `npm run test:e2e:call-app-whiteboard` passes.
    - Merged install-sidebar proof keeps the Marketplace install/sidebar access
      journey in the same command; latest integrated run passed both Whiteboard
      E2E specs.

- [x] WCA-05 Backend SQLite-runtime proof
  - Run PDO-backed backend Call App contracts in a SQLite-enabled PHP runtime.
  - Cover Semantic DNS refresh, MCP metadata, Marketplace entitlement,
    availability, session lifecycle, launch tokens, grants, and CRDT ops.
  - Record exact commands and results.
  - Proof:
    - Added `tests/call-app-sqlite-runtime-proof.sh`.
    - Added `npm run test:contract:call-apps:sqlite` for a reproducible
      frontend-package entrypoint.
    - Host runtime observed:
      `php -m` contains `PDO` and `pdo_pgsql`, but not `pdo_sqlite`.
    - Container runtime observed:
      `docker run --rm php:8.5-cli-trixie php -m` contains `pdo_sqlite`.
    - Exact command:
      `npm run test:contract:call-apps:sqlite`
    - Result:
      - `[call-app-marketplace-entitlement-contract] PASS`
      - `[call-app-availability-contract] PASS`
      - `[call-app-session-lifecycle-contract] PASS`
      - `[call-app-sqlite-runtime-proof] PASS`

- [x] WCA-06 Whiteboard CRDT hardening
  - Add shape/text/sticky move undo where CRDT-safe.
  - Add duplicate and out-of-order replay checks in browser E2E.
  - Add cursor/selection presence throttling proof.
  - Add snapshot compaction proof after enough operations.
  - Keep presence non-authoritative and non-persistent.
  - Proof:
    - `cursor.move`, `selection.update`, and `tool.preview` stay in
      `presence.types` and are rejected by CRDT append persistence.
    - Whiteboard emits throttled `call_app.presence.publish` messages for
      cursor and selection state.
    - Additional merged proof gates cursor presence by Call App grants, keeps
      named remote cursors visible only for authorized participants, and clears
      cursor/selection state after revocation:
      `call-app-whiteboard-cursors-access-contract`.
    - Named cursor proof now renders the authorized sender display name into
      the Whiteboard DOM overlay (`.remote-cursor-label`) as well as the canvas;
      E2E asserts the participant sees `Owner` and that the label is removed
      after grant revocation.
    - Regression proof renders multiple named remote cursors (`Owner`,
      `Reviewer`, `Facilitator`), removes only the leaving remote cursor on
      `call_app.presence.leave`, clears all remote cursor labels on revoke, and
      asserts the participant iframe launch count and URL do not change.
    - Move undo/redo for shapes, text, and sticky notes uses CRDT update ops.
    - Browser E2E covers duplicate/out-of-order replay injection, throttled
      non-persistent presence, snapshot compaction, revoke, and reconnect.
    - Exact commands:
      - `npm run test:e2e:call-app-whiteboard`
      - `npm run test:contract:call-apps`
      - `npm run test:contract:call-apps:sqlite`
    - Result:
      - All three commands passed. Host-PHP PDO-SQLite contracts still skip in
        `test:contract:call-apps`; the SQLite runtime proof passes in the
        pinned `php:8.5-cli-trixie` container.

- [x] WCA-07 Marketplace and host UX polish
  - Make the Call Apps sidebar list use the standard search/pagination spacing.
  - Show installed/enabled/healthy app state clearly.
  - Make attach flow choose default participant access without modal stacking.
  - Keep iframe host size stable under mini participant videos.
  - Add clear no-access/read-only state for revoked participants.
  - Proof:
    - Call Apps sidebar search and pagination use right-aligned action controls
      with fixed 20px spacing.
    - Sidebar app rows and details show Installed, Enabled, and Healthy state
      explicitly.
    - Attach flow remains inline in the sidebar and sends the selected
      `default_app_policy` to the backend session endpoint.
    - Call App workspace reserves fixed mini-strip height above the iframe on
      desktop and mobile, keeping iframe sizing stable.
    - Launch grant capabilities now drive visible no-access/read-only notices
      in the Call App workspace host.
    - Additional merged proof makes the Call Apps sidebar responsive at narrow
      widths, shows Default participant access as Blocked/Allowed choices, and
      exposes labeled Allow/Revoke controls for participant grants:
      `call-app-sidebar-access-ux-contract`.
    - `call-app-whiteboard-install-browser-proof-contract` adds a browser proof
      path for Marketplace order/install, installed Whiteboard availability in
      the Call Apps sidebar, default participant access selection, backend
      participant grant mutation, and narrow-sidebar responsiveness without
      manual database edits.
    - The same browser proof now runs the real Whiteboard iframe beside the
      host/sidebar controls, renders the `Owner` remote cursor label, toggles a
      sidebar participant grant, and asserts the cursor label, iframe URL, and
      Whiteboard launch count remain unchanged.
    - Exact commands:
      - `npm run test:contract:call-apps`
      - `npm run build`
    - Result:
      - Both commands passed. Host-PHP PDO-SQLite contracts still skip in
        `test:contract:call-apps` when the local driver is unavailable.

- [x] WCA-08 Observability and acceptance form
  - Add diagnostics for launch-token failures, grant changes, CRDT append/replay
    latency, duplicate suppression, snapshot compaction, and iframe bridge
    errors.
  - Create a Whiteboard acceptance form without filling it as passed.
  - Include manual call checks for owner, moderator, participant, guest,
    revoked participant, reconnect, and export.
  - Proof:
    - Backend Call App routes now attach diagnostics for grant changes, launch
      token failures, CRDT append/replay latency, duplicate suppression, and
      snapshot compaction.
    - Frontend Call App bridges emit `king:call-app-diagnostic` browser events
      and redact sensitive fields before dispatching diagnostics.
    - `prod-debug.sh` provides a read-only production diagnostics process for
      runtime/version, app/CDN/API/WS/SFU reachability, marketplace/call-app
      checks, container status, and redacted recent logs without deploys,
      restarts, DB writes, DNS changes, or admin actions.
    - Merged production-debug proof labels media reconnect, screen-share
      reconnect exhaustion, stale local media capture discard, audio/video track
      loss, SFU reconnect, and Call App frame/CSP log slices; dry-run mode now
      avoids network/SSH and preserves explicit domain overrides over local
      `.env.local` values.
    - `call-app-csp-postmessage-contract` proves configured Whiteboard/call-app
      iframe hosts are normalized safely, launch/CRDT bridge messages are
      structured-clone safe, and postMessage failure is terminal for the current
      launch generation instead of causing retry/reload loops.
    - `call-app-frame-csp-headers-contract` and deploy smoke now require
      Whiteboard frame responses to deliver compatible `Content-Security-Policy`
      and `Allow-CSP-From` headers for `app.kingrt.com`, without
      `X-Frame-Options`, nested app domains, or wildcard script/connect/frame
      policies.
    - `WHITEBOARD_CHECK.md` is an unfilled manual acceptance form for owner,
      moderator, participant, guest, revoked participant, reconnect, and export.
    - Exact commands:
      - `php -l demo/video-chat/backend-king-php/http/module_call_apps.php`
      - `php -l demo/video-chat/backend-king-php/tests/call-app-session-lifecycle-contract.php`
      - `npm run test:contract:call-apps`
      - `npm run test:contract:call-apps:sqlite`
      - `npm run build`
      - `git diff --check -- SPRINT.md WHITEBOARD_CHECK.md demo/video-chat/backend-king-php/http/module_call_apps.php demo/video-chat/backend-king-php/tests/call-app-session-lifecycle-contract.php demo/video-chat/frontend-vue/package.json demo/video-chat/frontend-vue/src/domain/realtime/callApps/callAppDiagnostics.js demo/video-chat/frontend-vue/src/domain/realtime/callApps/useCallAppIframeBridge.js demo/video-chat/frontend-vue/src/domain/realtime/callApps/useCallAppCrdtBridge.js demo/video-chat/frontend-vue/tests/contract/call-app-observability-acceptance-contract.mjs`
    - Result:
      - All commands passed. Host-PHP PDO-SQLite contracts still skip in
        `test:contract:call-apps` when the local driver is unavailable; the
        pinned `php:8.5-cli-trixie` SQLite proof passes in Docker.

- [x] WCA-09 Production deployment, subdomain, and Mothernode registration
  - Add deploy configuration for a dedicated Call App iframe host and a
    Mothernode host without exposing deploy secrets.
  - Let the backend start Semantic DNS/Mothernode only in one eligible HTTP
    worker and register installed Call App package metadata there.
  - Make the Whiteboard iframe launch from the configured Call App origin while
    preserving sandboxed no-primary-session-token launch.
  - Wire compose/deploy env so DNS, certs, edge static serving, catalog refresh,
    and MCP/Semantic-DNS registration use the same host contract.
  - Add contracts proving deploy env parsing, Mothernode registration payload,
    Call App service registration payload, and iframe-origin handling.
  - Proof:
    - Backend startup now loads the Call App Semantic-DNS domain and starts the
      Semantic-DNS/Mothernode runtime only from an eligible HTTP worker.
    - `VIDEOCHAT_DEPLOY_CALL_APP_DOMAIN` defaults to `apps.<domain>` and
      `VIDEOCHAT_DEPLOY_MOTHERNODE_DOMAIN` defaults to `mother.<domain>`.
    - Deploy DNS targets, Hetzner A-record provisioning, certbot SANs, remote
      env, compose runtime env, frontend build args, and iframe URL generation
      now share the same Call App host contract.
    - Exact commands:
      - `php -l demo/video-chat/backend-king-php/domain/call_apps/call_app_semantic_dns.php`
      - `php -l demo/video-chat/backend-king-php/server.php`
      - `bash -n demo/video-chat/scripts/deploy.sh`
      - `bash -n demo/video-chat/scripts/lib/deploy-hetzner.sh`
      - `bash -n demo/video-chat/scripts/lib/deploy-remote-status.sh`
      - `demo/video-chat/backend-king-php/tests/call-app-semantic-dns-contract.sh`
      - `node tests/contract/call-app-production-deploy-contract.mjs`
      - `npm run test:contract:call-apps`
      - `npm run build`
      - `docker compose -f demo/video-chat/docker-compose.v1.yml config`
      - `npm run test:contract:call-apps:sqlite`
    - Result:
      - All commands passed. Host-PHP PDO-SQLite contracts still skip inside
        `test:contract:call-apps`; the pinned `php:8.5-cli-trixie` SQLite
        proof passes in Docker.





# Sprint Task: e2e-enhancement/iam-develop-1.0.8-beta

## Goal

Implement complete end-to-end test coverage for the IAM, call-access, invitation, lobby, guest-account, owner-rights, call-lifecycle, and role-based join flows of the videocall solution.

This sprint is focused on turning the complete permission and identity model into executable E2E tests that run reliably in CI. The tests must validate all relevant user states, organization roles, personalized invitation links, anonymous join links, temporary accounts, account reconciliation flows, lobby behavior, rejoin behavior, ownership transfer rules, invitation invalidation, guest cleanup, call rescheduling, call deletion, call ending, owner absence timeout, and duplicate-link abuse detection.

The expected outcome is not a manual checklist only, but an automated E2E test suite that can be executed in CI and prevents regressions in the IAM and videocall access model.

## Background

The product supports:

- organizations
- registered users
- organization-level roles
- system admins
- call owners
- guest lists
- calendar-based invitations
- personalized call links
- anonymous join links
- temporary guest accounts
- lobby-based admission
- temporary moderators
- ownership transfer
- explicit and implicit call ending
- guest-account cleanup when call state changes

A registered user can belong to an organization and can have either an `Admin` or `User` role inside that organization.

A user may be:

- registered and logged in
- registered and logged out
- not registered
- represented by a temporary personalized guest account
- represented by a temporary anonymous guest account

There are two main link types:

1. Personalized call links
   These are created through invitation / calendar flows and are associated with a specific invitee or temporary account.

2. Anonymous join links
   These allow people to attempt joining a call without a personalized identity binding.

The system must correctly distinguish between:

- registered users
- logged-in users
- logged-out users
- organization admins
- normal organization users
- system admins
- call owners
- temporary guest accounts
- anonymous temporary accounts
- users on the guest list
- users admitted through the lobby
- users who were kicked
- users rejoining after admission
- users opening links that were issued for someone else
- users whose organization membership changed after invitation
- users whose invitation link was invalidated
- users trying to join deleted, ended, or rescheduled calls

---

# Required Work for Codex

Codex must create, extend, or refactor the E2E test suite so that the described IAM and videocall access model is fully covered.

The preferred test framework is Playwright unless the repository already uses another E2E framework.

If Playwright is not yet present, add it in a way that is compatible with the existing frontend, backend, auth, media, and CI setup.

The E2E tests must run automatically in CI.

The implementation must include:

- deterministic test data setup
- isolated organizations
- isolated users
- isolated calls
- isolated invitations
- isolated personalized guest links
- isolated anonymous join links
- repeatable cleanup
- CI-safe execution
- headless browser execution
- test fixtures for authenticated users
- test fixtures for unauthenticated users
- test fixtures for organization admins
- test fixtures for normal organization users
- test fixtures for system admins
- test fixtures for call owners
- test fixtures for registered invited guests
- test fixtures for temporary personalized guests
- test fixtures for temporary anonymous guests
- test coverage for personalized and anonymous join flows
- test coverage for temporary guest account creation
- test coverage for account reconciliation
- test coverage for lobby admission
- test coverage for lobby rejection
- test coverage for kick behavior
- test coverage for rejoin behavior
- test coverage for owner transfer
- test coverage for permission retention / permission loss
- test coverage for duplicate personalized-link usage
- test coverage for review-flag creation
- test coverage for organization membership changes after invitation
- test coverage for invite invalidation
- test coverage for guest-account cleanup
- test coverage for call rescheduling
- test coverage for call deletion
- test coverage for explicit call ending
- test coverage for implicit call ending after owner absence
- test coverage for final 5-minute countdown display

---

# Playwright / E2E Framework Requirements

Use Playwright unless a different E2E framework already exists and is the established project standard.

If Playwright is already present:

- extend the existing Playwright setup
- reuse existing fixtures
- reuse existing auth helpers
- reuse existing seed helpers
- reuse existing CI jobs where possible
- do not create a parallel test architecture

If Playwright is not present:

- add Playwright with browser installation suitable for CI
- add a dedicated E2E test command
- add test fixtures
- add authenticated browser contexts
- add unauthenticated browser contexts
- add trace capture on failure
- add screenshot capture on failure
- add video capture on failure if feasible
- add retry policy only where acceptable for CI stability
- add project-level documentation for running tests locally and in CI

Required capabilities:

- create organizations
- create registered users
- assign organization roles
- log in users
- create calls
- create personalized invitations
- create anonymous join links
- invalidate links
- modify guest lists
- remove users from organizations
- promote / demote users
- delete calls
- reschedule calls
- end calls
- simulate owner disconnect
- simulate owner reconnect
- assert visible UI states
- assert backend state when needed
- assert audit logs when available
- assert review flags when expected

---

# CI Requirements

The E2E suite must be executable in CI using a single documented command.

The CI job must:

- start all required services
- migrate the test database
- seed deterministic test data
- start the application
- start auth dependencies if required
- start media / signaling infrastructure if required
- start `king` participant containers where needed
- execute E2E tests headlessly
- collect Playwright traces on failure
- collect screenshots on failure
- collect videos on failure if feasible
- collect application logs on failure
- collect backend logs on failure
- collect media / signaling logs on failure
- collect `king` container logs on failure
- fail the pipeline if any required E2E test fails
- avoid depending on external services unless explicitly mocked or sandboxed
- avoid leaking real user data
- avoid real production credentials
- be deterministic and repeatable
- run without GPU requirements
- run without manual interaction
- not wait 15 real minutes for owner-timeout tests

---

# Virtual Call Participants in CI: `king` Containers

Some tests require multiple concurrent call participants.

For CI, use lightweight virtual participant containers named `king` containers.

A `king` container represents a simulated participant that can join a call and stream deterministic dummy media into the call. These containers do not need to render real UI unless the architecture requires it. Their purpose is to simulate real call participants in a reproducible CI-safe way.

If the existing system already has a test participant simulator, extend it instead of creating a parallel implementation.

If no such simulator exists, add the minimal required `king` container implementation for CI-based E2E coverage.

## `king` Container Capabilities

Each `king` participant container must be able to:

- join a call using a provided link or token
- optionally act as the call owner
- optionally act as a logged-in registered user
- optionally act as an organization admin
- optionally act as a normal organization user
- optionally act as a temporary personalized guest
- optionally act as an anonymous temporary guest
- provide deterministic fake audio input
- provide deterministic fake video input
- stay connected for the duration of the test
- disconnect gracefully
- simulate abrupt disconnect
- simulate browser crash / process kill
- simulate network loss
- reconnect with the same identity
- reconnect with a different identity when explicitly needed
- simulate multiple participants using the same link
- simulate multiple participants using different links
- expose current call state
- expose participant identity state
- expose whether the owner-absence countdown is visible
- expose countdown value or allow Playwright to assert it through UI
- expose logs for join, disconnect, reconnect, kick, end, and media state
- terminate cleanly after test completion
- run in CI without GPU requirements

For owner-timeout tests:

- at least one `king` container must be able to act as the owner participant
- the owner `king` container must be able to disconnect without explicitly ending the call
- at least one additional `king` container must remain in the call
- remaining participants must be able to observe the final 5-minute countdown
- remaining participants must be able to observe the automatic call-ended state

---

# Product Rule: Membership Revocation After Invitation

A valid explicit call invitation must remain usable even if the invited user is removed from the organization after the invitation was issued.

Organization membership is evaluated for organization-level privileges, but not as the sole condition for invitation validity.

This prevents the following failure case:

- a host invites participants to a call while they are still organization members
- those participants are removed from the organization before the call starts
- the participants can no longer join the very call in which the removal / offboarding is supposed to happen

## Rule

If a registered user was explicitly invited to a call before losing organization membership, the invitation remains valid as a call-scoped guest permission.

The user no longer has organization-level rights, but they may still join the specific invited call as an invited participant.

## Resulting Behavior

- removed organization member loses all organization-based permissions immediately
- removed organization admin loses organization-admin permissions immediately
- removed user cannot browse, create, manage, or join other organization calls through org membership
- removed user cannot access organization resources outside the specific invitation
- removed user may still join the specific call if they have a still-valid explicit invitation
- the invitation is downgraded to call-scoped guest access
- the user joins as an invited guest, not as an organization member
- the user does not regain organization membership
- the user does not regain organization role permissions
- the user does not receive admin, org-admin, or owner rights through the old membership
- the user can be admitted directly if the invitation grants direct access
- the user can be routed through lobby if the invitation requires host approval
- the host / admin can manually invalidate the invite if access should be revoked
- if the invite link is invalidated, the removed user can no longer join
- if the call is deleted, the removed user can no longer join
- if the call is ended, the removed user can no longer join
- if the user was kicked, kick rules override invitation access

## Active Session Rule

If a participant is already inside the call and is then removed from the organization:

- they remain in the current call if their access came from an explicit invitation or call-scoped guest permission
- they immediately lose organization-level privileges
- they do not lose ordinary participant presence automatically
- they cannot perform organization-admin actions anymore
- they cannot use organization membership to join other calls
- they may remain until the call ends, they leave, or they are kicked
- the host / owner / moderator can remove them manually if needed

## Admin / Owner Exception

If the removed participant was an organization admin:

- org-admin privileges are revoked immediately
- call participation may continue only if they also have call-scoped permission
- if they were call owner, owner handling must follow the owner-transfer / call-ending rule
- if no valid call-scoped permission remains, they should be moved to lobby or removed according to the call policy

---

# Product Rule: Personalized Link Opened by Logged-In Different User

When a logged-in user opens a personalized call link that was issued for a temporary account or another invitee:

- the currently logged-in account must remain active
- the session must not be replaced by the temporary guest account
- the temporary link account must be compared with the logged-in account
- if there is no strong mismatch, the logged-in account is used for the call
- if there is a strong mismatch, the system must show a warning modal
- strong mismatch means first name and/or last name are materially different
- the warning modal must not show the other person’s data
- the user must be asked to provide the host name
- if the host name is wrong, access is denied or routed to manual review / lobby
- if the host name is correct, the user may be asked whether to update account data
- account data differences must not be displayed
- the user must re-enter the differing values manually
- if the user wants to update account data, a confirmation email must be sent to the email address of the currently logged-in account
- account data must not be updated until email confirmation is completed
- the email must not be sent to the temporary guest account
- the logged-in account remains the active account throughout the flow

If another logged-in account later uses the same personalized link:

- the account must be flagged for review
- this must also happen if another account uses the link while the first account is already in the call
- duplicate personalized-link use must be audit-logged

---

# Product Rule: Anonymous Join Links

When a logged-in user opens an anonymous join link:

- no temporary account should be used as the active identity
- any temporary anonymous identity created for this flow should be removed or discarded
- the user joins as the logged-in account
- the logged-in account’s rights are used

When a non-logged-in user opens an anonymous join link:

- a temporary anonymous guest account may be created
- the temporary anonymous guest lands in the lobby unless the product explicitly grants direct access
- the host, temporary moderator, org admin, or system admin may admit the guest
- once admitted and not kicked, the temporary guest may leave and rejoin without needing to be admitted again
- if kicked, kick rules override previous admission

---

# Product Rule: Join Permissions

System admins:

- can join every call through normal active-call paths
- do not need to be on the guest list
- do not need an invitation
- cannot bypass deleted or ended call state unless an explicit recovery/debug path exists outside normal join flow

Organization admins:

- can join every active call belonging to their own organization
- do not need to be on the guest list for calls in their organization
- cannot join calls of other organizations through org-admin rights
- cannot bypass deleted or ended call state through normal join flow

Normal users:

- can join calls when they are on the guest list
- can join calls they own
- can join calls through a valid explicit invitation
- cannot directly join unrelated calls
- can create their own calls
- become owner of calls they create

Call owners:

- have admin rights in their own call
- may admit lobby participants
- may remove / kick participants
- may transfer ownership
- may end the call

Owner transfer:

- if a normal org user transfers owner rights to another user, the old owner loses call-admin rights
- if an organization admin transfers owner rights to another user, the old owner keeps admin rights
- the new owner receives owner rights
- there must be exactly one current owner unless the product explicitly supports multiple owners

---

# Product Rule: Call Rescheduling

When a call is rescheduled:

- stale access paths must not remain valid unintentionally
- old personalized links must be invalidated or migrated according to the product rule
- old temporary guest accounts must be deleted, invalidated, or migrated according to the product rule
- old lobby entries must be cleared or migrated according to the product rule
- admitted temporary participants from the old schedule must be cleared or migrated according to the product rule
- old links must not join users into the wrong call instance
- all changes must be audit-logged

---

# Product Rule: Call Deletion

When a call is deleted:

- the deleted call cannot be joined through normal product paths
- owner cannot join the deleted call
- org admin cannot join the deleted call
- system admin cannot join the deleted call through normal join flow
- personalized invite links become invalid
- anonymous join links become invalid
- temporary guest accounts are deleted or invalidated
- lobby entries are cleared
- admitted temporary participant state is cleared
- active participants are disconnected or moved into a safe deleted-call state
- audit log is preserved
- registered user accounts are not deleted
- unrelated calls and guests are not affected

---

# Product Rule: Call Ending

A call can be ended explicitly or implicitly.

## Explicit End

The owner intentionally ends the call or leaves in a way that the product treats as an explicit call end.

Expected behavior:

- call moves to ended state
- active participants are notified
- new joins are blocked
- rejoins are blocked
- personalized invite links are invalidated
- anonymous join links are invalidated
- temporary guest accounts are deleted or invalidated
- lobby entries are cleared
- audit log is preserved

## Implicit End by Owner Absence

If the owner is absent from the call for 15 minutes, the call ends automatically.

Examples of owner absence:

- internet outage
- owner loses connection
- owner closes browser tab
- owner browser crashes
- owner process is killed
- owner network is disconnected

The final 5 minutes of the implicit-end timer must be visible to the remaining participants.

Expected behavior:

- owner absence starts a 15-minute server-side timer
- the timer is based on server time, not client time
- the last 5 minutes of that timer are shown to remaining participants
- countdown starts when 5 minutes remain
- countdown updates correctly
- countdown is synchronized across participants
- countdown disappears if owner rejoins
- owner reconnect before timeout cancels pending implicit end
- call automatically ends when owner has been absent for 15 minutes
- once ended, the call is no longer joinable through normal links
- temporary call-scoped access is cleaned up after end

## CI Timer Requirement

Owner-absence timeout must be E2E-testable without making CI wait for real 15-minute durations.

Codex must implement or use a test-safe time-control mechanism.

Preferred approaches:

- configurable timeout values in test environment
- fake timers at the service layer
- server-side test clock injection
- admin/test-only endpoint to advance call lifecycle time
- deterministic event simulation for owner disconnect and reconnect

The CI tests must not sleep for 15 real minutes.

Required CI timer coverage:

- simulate owner absence
- advance time to 9 minutes 59 seconds equivalent
- verify no countdown is visible yet unless product rule says otherwise
- advance time to 10 minutes equivalent
- verify final 5-minute countdown is visible
- advance time during countdown
- verify countdown updates
- reconnect owner during countdown
- verify countdown disappears and call remains active
- disconnect owner again
- advance time to 15 minutes equivalent
- verify call ends
- verify all participants receive ended state
- verify new joins are blocked
- verify guest accounts / links are invalidated or cleaned up

---

# Main Acceptance Criteria

The sprint is complete when:

- the automated E2E suite covers the full IAM and videocall access matrix
- tests run successfully in CI
- CI fails on permission regressions
- CI fails on identity regressions
- CI fails on guest-link regressions
- CI fails on lobby regressions
- CI fails on owner-transfer regressions
- CI fails on invite-invalidation regressions
- CI fails on duplicate-link regressions
- CI fails on call-lifecycle regressions
- CI fails on owner-timeout regressions
- virtual participants can be simulated in CI using `king` containers
- test data is isolated and repeatable
- failed test runs provide enough artifacts to debug the issue
- all new tests are documented
- the manual checklist is mapped to automated test case IDs

---

# E2E Case Checklist

## 1. Organization, User, Roles, Login States

- [x] Organization can be created
- [x] User can be registered in an organization
- [x] User can have organization role `User`
- [x] User can have organization role `Admin`
- [x] User with role `User` can log in
- [x] User with role `Admin` can log in
- [x] User can be logged out
- [x] Logged-in user remains logged in when opening a call link
- [x] Logged-out user has no active account session
- [x] User without organization cannot receive organization-based rights
- [x] User from organization A does not receive rights from organization B
- [x] Organization admin from organization A cannot join organization B calls through org-admin rights
- [x] Organization role is evaluated server-side
- [x] Stale organization role in client cache is ignored
- [x] Stale organization role in session token is revalidated where required

Proof: `call-access-stale-organization-role-contract` issues a session while a
user has tenant and organization admin roles, downgrades both roles without
rotating the token, and proves auth/session payloads, local session cache
fallback, call access, call-admin decisions, forged role input, and stale
client role cache data all resolve from current backend membership state.

Proof: `iam-core-org-session-journey-contract` creates a real organization
through Governance, registers isolated users through the admin user route,
persists organization `User`/`Admin` memberships, logs both users in through
`/api/auth/login`, logs one out through `/api/auth/logout`, proves missing and
revoked session-state responses expose no active account session, opens an
anonymous call link while authenticated and proves the original account session
remains valid, and verifies a tenant member without organization membership
receives no organization-admin/direct-call rights. Browser proof:
`call-access-core-org-session-journey.spec.js` plus the seed-matrix no-org
scenario cover the same organization/account/session journeys in the focused
Call Access Playwright gate.

## 2. Call Creation and Owner Rights

- [ ] Registered user with role `User` can create own call
- [ ] Registered user with role `Admin` can create own call
- [ ] Call creator becomes call owner
- [ ] Call creator receives admin rights in own call
- [x] Owner can add users to guest list
- [x] Owner can manage guest list
- [ ] Owner can admit lobby participants
- [ ] Owner can remove / kick participants
- [x] Owner rights can be transferred to another user
- [x] If organization role `User` transfers owner rights, old owner loses call-admin rights
- [x] If organization role `Admin` transfers owner rights, old owner keeps admin rights
- [x] New owner receives owner rights
- [x] New owner receives admin rights in call
- [x] After owner transfer, there is exactly one current owner
- [x] Former owner without admin role can no longer perform owner actions
- [x] Organization admin can keep call-admin rights after owner transfer
- [x] Owner rights cannot be transferred to a non-existent user
- [x] Owner rights cannot be transferred across forbidden organization boundaries
- [ ] Owner transfer is audit-logged

Proof: `call-owner-transfer-contract.sh`, `call-temporary-moderator-contract.sh`,
and `iam-owner-transfer-temp-moderator.spec.js` cover owner transfer, exactly
one current owner, old owner rights loss/retention by organization role, new
owner rights, forbidden transfer targets, temporary moderator grant/revoke, and
forged moderator denial. The Playwright owner-transfer/temp-moderator spec
passed 3 tests; PHP persistence portions were skipped only where local
`pdo_sqlite` is unavailable.

## 3. Join Permissions

- [x] System admin can join every active call
- [x] System admin can join without guest-list entry
- [x] System admin can join without invitation
- [x] System admin cannot join deleted call through normal join flow
- [x] System admin cannot join ended call through normal join flow
- [x] Organization admin can join every active call of own organization
- [x] Organization admin can join own organization call without guest-list entry
- [x] Organization admin cannot join another organization’s call through org-admin rights
- [x] User can join call when on guest list
- [x] User cannot directly join call when not on guest list
- [x] User can join own call as owner
- [x] User cannot directly join unrelated foreign call
- [x] Deleted / disabled user cannot join
- [x] Removed guest-list entry revokes direct join access
- [x] Newly added guest-list entry grants direct join access
- [x] Permissions are checked server-side
- [x] Manipulated client role does not grant access
- [x] Manipulated call ID does not grant access to another call

Proof: `call-access-seed-matrix.spec.js` runs the direct workspace join matrix
for system admin, same-organization admin, normal owner, guest-list user,
foreign-organization admin denial, forged client admin denial, and forged
system-admin session token denial. `npx playwright test
tests/e2e/call-access-seed-matrix.spec.js --workers=1 --reporter=list` passed
25 tests; `npm run test:contract:iam-call-access` passed frontend contracts and
skipped backend SQLite subcontracts because local PHP lacks `pdo_sqlite`.
The same seed-matrix proof now also binds main journeys for logged-in anonymous
own-rights, system-admin direct join, guest-list direct join, and normal
non-guest denial/lobby policy. The focused journey/direct-join run passed 18
tests.
`call-guest-list-direct-join-contract.php` was also run under Docker PHP 8.4
with `pdo_sqlite` and proves owner add/remove/restore, newly added registered
and temporary users gaining direct join, removed entries losing direct join,
duplicate adds merging into one row, call/organization scoping, and audit events
without raw guest identifiers.

Proof: `call-access-deleted-ended-disabled-join-contract` and the integrated
Playwright run of `call-access-seed-matrix.spec.js` plus
`call-access-join.spec.js` cover system-admin denial for deleted/ended calls,
disabled/deleted user denial before call resolution, safe deleted/ended public
link states, no replacement session issuance, and no leaked private call/user
data. The focused integrated Playwright run passed 18 tests.

## 4. Calendar Invitation Flow

- [x] Host can invite person through calendar flow
- [x] Invitee can select appointment in calendar form
- [ ] Invitee can be registered and logged in
- [ ] Invitee can be registered and logged out
- [x] Invitee can be unregistered
- [x] Temporary account is created for non-logged-in invitee
- [x] Temporary account contains form data
- [x] Personalized call link is associated with temporary account
- [x] Personalized call link is unique
- [x] Personalized call link is not guessable
- [x] Personalized call link is server-side bound to temporary account
- [x] Calendar appointment is correctly associated with call / host
- [x] Multiple invitees receive different personalized links
- [x] Appointment change does not modify unrelated invitations
- [x] Invitation cancellation invalidates personalized link
- [x] Expired personalized link cannot be used if expiry exists
- [x] Reopening same personalized link by same valid context behaves consistently

Proof: `call-access-invalidation-contract.sh`,
`call-access-invitation-cancellation-contract.sh`,
`call-access-expired-personalized-link-contract.sh`, and
`call-access-invite-invalidation.spec.js` cover manual invite
invalidation, call cancellation invalidation, expired personalized links,
stale session rejection, stale websocket rejoin rejection, and safe
invalid-link UI without private invite data. Frontend E2E and static
contracts passed locally; SQLite-backed PHP runtime execution is blocked on
this host because PHP does not load `pdo_sqlite`.

Proof: `call-calendar-invitation-flow-contract` statically pins the calendar
booking implementation and, in SQLite-capable runtimes, creates two public
appointment bookings, creates call-scoped temporary guest accounts without
tenant membership, persists form data, binds personalized links to those
temporary accounts, verifies UUIDv4/non-sequential link ids, proves each
invitee receives distinct link/account/call records, moves one appointment
without mutating the unrelated invitation, rejects a manipulated link and wrong
authenticated account, and reopens the same valid link without creating another
temporary account. Local PHP syntax passed; runtime execution is skipped on
this host because `pdo_sqlite` is unavailable.

## 5. Personalized Link: User Not Logged In

- [x] Not logged-in user opens personalized link
- [x] Temporary account from link data is created / used
- [x] Temporary account does not automatically log in existing registered account
- [x] User enters intended flow with temporary account
- [ ] Temporary account may be on guest list
- [ ] Temporary account on guest list can join directly
- [x] Temporary account not on guest list lands in lobby
- [x] Temporary account cannot see other users’ data
- [x] Temporary account receives no registered account rights
- [x] Temporary account cannot administer call unless explicitly permitted
- [ ] Temporary account cannot assume another identity by changing link parameters
- [x] Temporary account remains consistent for same link / call
- [ ] Temporary account can be recognized after leaving
- [x] Temporary account cannot receive organization-wide rights
- [x] Invalid personalized link is rejected
- [x] Manipulated personalized link is rejected
- [x] Error state for invalid personalized link leaks no data

Proof: `call-access-personalized-identity.spec.js` covers a logged-out
personalized link entering the linked call session without identity proof, plus
safe failure states that do not expose foreign link data. `npx playwright test
tests/e2e/call-access-personalized-identity.spec.js --workers=1 --reporter=list`
passed 5 tests.
`call-access-main-journey-smoke.spec.js` covers
`e2e_journey_002_registered_logged_out_invitee_uses_temp_account`: the
registered-but-logged-out invitee path uses a temporary guest account, does not
take over the existing registered account, enters lobby/admission flow, and
does not expose registered account data or grant tenant/lobby/admin rights. The
focused run passed 3 tests; the integrated seed-matrix run passed 21 tests.

## 6. Personalized Link: Logged-In User, No / Light Mismatch

- [x] Logged-in user opens personalized link
- [x] Logged-in account remains active
- [x] Temporary link account does not replace active session
- [x] Link account is compared with logged-in account
- [ ] No mismatch does not show warning modal
- [ ] Light mismatch does not show strong foreign-link warning
- [x] Logged-in account is used for call
- [x] Temporary account is not set as active session
- [x] Permission check uses logged-in account
- [x] User can join if logged-in account is authorized
- [x] User lands in lobby if logged-in account is not directly authorized
- [x] Temporary link data does not overwrite account data automatically
- [ ] Light mismatches are optionally logged
- [x] No link data is unnecessarily exposed in frontend
- [ ] Same logged-in account can reopen same link without duplicate-link flag

Proof: `call-access-verified-context-ui-contract` proves the public join view
captures a stable verified user/session/token snapshot after link verification,
sends `verified_user_id`, `verified_session_id`, and the current bearer token to
call-access session issuance, and fails safely with `call_access_conflict` if
the verified context remains after local logout. Backend route-guard proof keeps
the authenticated account authoritative for the issued session.

## 7. Personalized Link: Logged-In User, Strong Mismatch

- [x] Logged-in user opens personalized link with strongly different link data
- [ ] Strong mismatch is detected when first name differs
- [ ] Strong mismatch is detected when last name differs
- [ ] Strong mismatch is detected when first and last name differ
- [ ] Warning modal is displayed
- [ ] Warning modal explains link may have been issued for someone else
- [ ] Warning modal explains link data differs from account data
- [ ] Warning modal asks for host name
- [x] Link data of other person is not displayed
- [x] Differing link data is not exposed in clear text
- [x] Host name is verified server-side
- [x] Wrong host name grants no direct access
- [x] Wrong host name does not reveal foreign data
- [ ] Wrong host name may lead to lobby / manual review
- [ ] Correct host name is accepted
- [ ] Correct host name shows success confirmation
- [ ] After correct host name, user is asked whether account data should be updated
- [ ] User can decline update
- [ ] Declining update leaves logged-in account unchanged
- [ ] Declining update continues with logged-in account
- [x] User can request account update
- [x] User must re-enter differing values manually
- [x] System does not show differing link values
- [x] System does not show data from guessed / foreign link
- [x] Email confirmation is sent to logged-in account email
- [x] Email is not sent to temporary link-account email
- [x] Without email confirmation, account data is not updated
- [x] With email confirmation, only confirmed data is updated
- [x] After update, user remains logged in as original account
- [x] Update does not modify temporary foreign account
- [x] Update does not modify other registered accounts
- [ ] Flow is audit-logged
- [x] Host-name brute force is rate-limited
- [ ] Repeated wrong host names trigger lock / review if configured
- [x] Host-name error messages leak no host data

Proof: `call-access-strong-mismatch-privacy-contract` pins the focused browser
case in `call-access-personalized-identity.spec.js`: a logged-in wrong account opens a
personalized link, the simulated server returns a strong-mismatch wrong-host
denial, the join/session responses contain no invitee/host/session sentinels,
the UI renders only the generic forbidden state, no workspace/lobby admission is
entered, and the active browser session remains unchanged.

Proof: `call-access-strong-mismatch-privacy-contract` creates a personalized
link for one invitee, authenticates a strongly different logged-in account, and
proves the backend `/api/call-access/{id}/join` and `/session` routes return
only generic mismatch/host-name field errors for unverified or wrong host-name
attempts. The responses contain no target invitee, host, external participant,
call title, call id, or denied session id, and no call-access session is
persisted.
`call-access-duplicate-review-email.spec.js` covers the account-update request
path by requiring manually re-entered values, sending confirmation only to the
logged-in account, refusing updates before confirmation, and confirming only
the re-entered fields without adopting the link-target session.

## 8. Duplicate Personalized Link / Abuse Detection

- [x] Personalized link is first opened by account A
- [x] Same personalized link is reopened by account A
- [x] Reuse by same account does not create false foreign-account flag
- [x] Same personalized link is later opened by account B
- [x] Use of same personalized link by different logged-in account is detected
- [x] Account B is flagged for review
- [x] Account A may appear as affected reference in audit log
- [x] Flag is created even if account B provides correct host name
- [x] Flag is created even if account B does not enter the call
- [ ] Flag is created when account B reaches warning modal if policy requires it
- [x] Concurrent use of same personalized link by two accounts is detected
- [x] Race condition on parallel link open creates no inconsistent assignment
- [x] Link already used inside call marks later use by other account as suspicious
- [x] Temporary account cannot be taken over by second registered account without review
- [x] Review flag contains call, link ID, affected accounts, and timestamps
- [x] Review flag contains no unnecessary sensitive link data
- [x] Admin / reviewer can understand the flag
- [ ] Abuse detection works after logout / login switch in same browser
- [ ] Abuse detection works across devices
- [ ] Abuse detection works across browsers

Proof: `call-access-duplicate-review-email-contract.mjs` pins duplicate-link
review flags, safe review payloads, logged-in account preservation,
host-verification rate limiting, account-bound confirmation tokens, manual
re-entry, no pre-confirmation update, no session rebinding, and the duplicate
race E2E coverage. `call-access-duplicate-review-contract.php` now proves a
real `pcntl` parallel linked-account/foreign-account session race against
SQLite: the linked account reopens the personal link, the foreign account is
review-flagged and receives no session, the link assignment remains unchanged,
and a later foreign use references the first in-call linked account. It also
pins foreign personalized-link review flags and audit events to the call's
organization/call, records the foreign actor plus linked-account affected
reference, keeps reviewer status understandable as manual review, and proves
raw link IDs, host names, session IDs, tokens, SDP, ICE, and account emails are
omitted in favor of fingerprints. `npx
playwright test tests/e2e/call-access-duplicate-review-email.spec.js
tests/e2e/call-access-duplicate-race.spec.js --workers=1 --reporter=list`
passed 5 tests; `npm run test:ci:iam-call-access:static` passed. Host PHP still
lacks `pdo_sqlite`, so host IAM SQLite wrappers skip cleanly; the duplicate PHP
proof was validated in `php:8.4-cli` with `pdo_sqlite`; that local image lacks
`pcntl_fork`, so the optional parallel fork subcheck skipped cleanly.

## 9. Anonymous Join Link: User Logged In

- [x] Logged-in user opens anonymous join link
- [x] Temporary account is not permanently created or is removed
- [x] User joins as logged-in user
- [x] Logged-in user’s rights are used
- [x] Logged-in user receives no rights from anonymous link
- [x] Logged-in user can join if own rights allow it
- [x] Logged-in user lands in lobby if no direct permission exists
- [x] Logged-in system admin can join every active call through anonymous link
- [ ] Logged-in organization admin can join own organization calls through anonymous link
- [ ] Logged-in organization admin cannot join foreign organization calls through anonymous link
- [ ] Logged-in guest-list user can join through anonymous link
- [x] Logged-in user not on guest list lands in lobby through anonymous link
- [x] Anonymous link does not overwrite account data
- [ ] Anonymous link does not modify guest list
- [ ] Anonymous link creates no personalized identity binding
- [x] Invalid anonymous link is rejected
- [x] Manipulated anonymous link grants no access

Proof: `call-access-seed-matrix.spec.js` covers anonymous open links for a
logged-in user keeping their own account, avoiding temporary identity creation,
using only the logged-in account rights, and waiting in lobby without direct
permission. The same spec covers a logged-out anonymous guest entering the lobby
with no platform, tenant, lobby-management, or admission rights. `npx playwright
test tests/e2e/call-access-seed-matrix.spec.js --workers=1 --reporter=list`
passed 11 tests. `call-access-anonymous-disabled-link-contract` also proves a
manipulated logged-in anonymous access id is rejected before session issuance
and a forged anonymous session body cannot bind a foreign call.

## 10. Anonymous Join Link: User Not Logged In

- [x] Not logged-in user opens anonymous join link
- [x] Temporary anonymous account is created
- [x] Temporary anonymous account lands in lobby
- [x] Temporary anonymous account receives no registered user rights
- [x] Temporary anonymous account receives no organization rights
- [x] Temporary anonymous account receives no owner rights
- [ ] Lobby shows waiting anonymous user according to privacy rules
- [ ] Host can admit anonymous user
- [ ] Temporary moderator can admit anonymous user
- [ ] Admin can admit anonymous user
- [ ] Unauthorized participant cannot admit anonymous user
- [x] After admission, anonymous temporary user enters call
- [x] If admitted anonymous user leaves and was not kicked, they can rejoin
- [x] Rejoin after admission does not require another approval
- [x] If anonymous user was kicked, rejoin requires approval or is blocked
- [ ] Anonymous temporary user cannot gain rights by changing display name
- [ ] Multiple anonymous users through same link are separate temporary participants
- [x] Anonymous link does not reveal guest list or account data
- [x] Anonymous link can be disabled if supported
- [x] Disabled anonymous link allows no lobby entry

Proof: `call-access-main-journey-smoke.spec.js` covers the logged-out
anonymous path: the anonymous link creates a least-privilege temporary guest,
keeps the user out of moderation, platform-admin, tenant-admin, and guest-list
visibility, places the guest in the lobby, admits the guest into the call, then
allows leave and same-session rejoin without a second approval. The focused
integrated run also covers kicked anonymous rejoin denial and passed 6 tests.
`call-access-anonymous-disabled-link-contract` migrates and uses
`call_access_links.disabled_at`, disables an open anonymous link, and proves
fresh resolve/session plus HTTP join/session return generic not-found responses
without creating a temporary guest, call-access session, or lobby participant;
the focused Docker SQLite run passed. `call-access-anonymous-disabled-link.spec.js`
covers `e2e_anon_logged_out_011_disabled_anonymous_link_allows_no_lobby_entry`
with no session POST and no `lobby/queue/join` frame; the focused Playwright run
passed.

## 11. Lobby and Admission

- [ ] User without direct permission lands in lobby
- [ ] Anonymous not logged-in user lands in lobby
- [ ] Personalized temporary user without direct permission lands in lobby
- [ ] Logged-in user without direct permission lands in lobby
- [ ] Lobby entry informs host / authorized moderators
- [ ] Host sees waiting participant
- [ ] Temporary moderator sees waiting participant
- [ ] Organization admin sees waiting participant for own organization call
- [x] System admin sees waiting participant
- [ ] Unauthorized user sees no lobby management controls
- [x] Host can admit participant
- [ ] Temporary moderator can admit participant
- [x] Organization admin can admit participant
- [x] System admin can admit participant
- [x] Host can reject participant
- [ ] Temporary moderator can reject participant
- [x] Organization admin can reject participant
- [x] System admin can reject participant
- [ ] Rejected participant cannot enter call
- [ ] Admitted participant enters call
- [x] Admission is stored call-scoped
- [x] Admission does not apply to other calls
- [x] Admission does not apply to other organizations
- [ ] Temporary user admission applies only to same temporary user / link context
- [x] Concurrent admission by multiple moderators creates no error state
- [x] Concurrent rejection and admission resolves deterministically
- [x] Lobby status updates correctly
- [x] Participant is removed from lobby after admission
- [ ] Participant is removed from lobby after aborting join attempt
- [x] Participant is not shown twice in lobby
- [x] Manipulated lobby-admission request without permission is rejected

Proof: `call-access-session-contract` creates a personal access link, persists
the access/session binding with access ID, call ID, room ID, user ID, and link
kind, proves the session waits for the bound room until allowed, then enters only
that bound room. The same contract requests a secondary call ID with the
access-bound session and proves it does not enter or queue admission for the
secondary room. It also creates an open anonymous access session with a guest
name and proves a distinct guest user plus open call-scoped binding is created.
`realtime-lobby-concurrency-contract` simulates two backend workers against the
same call-participant row and proves concurrent `lobby/allow` is idempotent,
late duplicate admission returns `already_allowed` without mutating state, and
admit/reject races resolve deterministically with rejection winning and no
queued or admitted handoff left behind.
`lobby-concurrency-ui.spec.js` drives the browser workspace with duplicate queue
snapshots, an admitted-plus-stale-queue race snapshot, duplicate room
participant rows, and final reject-empty state; it proves the lobby badge and
panel render one queued user, admitted/rejected states leave no stale allow
controls, and duplicate participant snapshot rows aggregate into one UI row.
`iam-lobby-concurrency-remaining-contract.mjs` binds `e2e_lobby_010`,
`e2e_lobby_011`, and `e2e_lobby_012` to the backend compare-and-set race
contract plus the focused browser snapshot proof, and pins both package and CI
gate wiring.
`call-access-anonymous-lobby-contract.php` now proves the system-admin
connection can see the waiting participant in the lobby snapshot, keeps stored
`call_role` separate from owner-equivalent `effective_call_role`, and admits the
participant through the revalidated lobby authority. The focused PHP 8.5
Docker SQLite run passed.

## 12. Rejoin, Leave, Kick

- [x] Admitted temporary user can leave call
- [x] Admitted temporary user can reopen same call
- [x] Admitted temporary user can rejoin without approval
- [ ] Rejoin works after browser refresh
- [x] Rejoin works after short network interruption
- [ ] Rejoin works after closing tab and reopening if session remains
- [ ] Rejoin does not work as another user with same temporary context if account binding is violated
- [x] Kicked temporary user cannot directly rejoin
- [x] Kicked temporary user lands back in lobby or is blocked
- [x] Kicked logged-in user cannot immediately reenter through same link if kick overrides access
- [x] Kick state overrides previous admission
- [x] Kick state is stored server-side
- [ ] Kick state is scoped to affected call if intended
- [ ] Kick state is scoped to affected user / temporary account
- [ ] Registered authorized user can rejoin after leaving
- [ ] Admin can rejoin after leaving
- [ ] Organization admin can rejoin after leaving
- [ ] Guest-list user can rejoin after leaving
- [ ] Rejoin after guest-list removal is denied or routed to lobby
- [x] Rejoin after admin-role removal uses updated permissions
- [ ] Rejoin after owner transfer uses updated permissions

Proof: `call-access-rejoin-kick-membership.spec.js` covers network reconnect
backfill without a leave frame, explicit hangup followed by same-session rejoin
with a fresh snapshot, and stale lobby kick controls remaining hidden. `npx
playwright test tests/e2e/call-access-rejoin-kick-membership.spec.js
--workers=1 --reporter=list` passed 4 tests, including
`e2e_security_009_kick_during_active_call_removes_user`; PHP SQLite
membership/kick contracts were syntax-checked, and
`call-access-rejoin-kick-contract.sh` passed in `php:8.5-cli-trixie` with
`pdo_sqlite`. Host PHP still skipped SQLite-backed contracts because local PHP
lacks `pdo_sqlite`. `call-access-main-journey-smoke.spec.js` also proves the admitted
anonymous temporary user can leave, reopen the same call, and rejoin without a
second approval in the full lobby-to-call journey. The backend
`call-access-rejoin-kick-contract.php` and the same browser spec now prove
kicked temporary guests and active logged-in participants cannot directly
rejoin, are routed back to renewed approval/blocked state, and that the
persisted kick state overrides previous admission.

## 13. Temporary Moderators

- [x] Host can assign temporary moderator if supported
- [x] Temporary moderator can admit lobby participants
- [x] Temporary moderator can reject lobby participants
- [x] Temporary moderator can only moderate assigned call
- [x] Temporary moderator cannot perform organization-wide admin actions
- [x] Temporary moderator cannot transfer owner rights unless allowed
- [x] Temporary moderator cannot modify guest list outside permissions
- [x] Temporary moderator loses rights after moderation ends
- [x] Temporary moderator loses rights after call end if configured
- [x] Revoked temporary moderator rights take effect immediately
- [x] Manipulated temporary-moderator role in client is rejected server-side

Proof: `call-temporary-moderator-contract.php` and
`iam-owner-transfer-temp-moderator.spec.js` prove temporary moderator grant,
admit/reject, assigned-call scoping, no organization-admin actions, no guest-list
mutation, revocation/inactive-invite/call-end rights loss, and forged client
role denial. `npm run test:e2e:temp-moderator -- --reporter=list` passed 7
tests; the backend contract passed with persistence skipped only where host PHP
lacks `pdo_sqlite`.

## 14. Privacy and Data Minimization

- [x] Foreign link data is not shown on strong mismatch
- [ ] Differing data is not shown as comparison list
- [ ] User must re-enter differing data manually
- [x] Guessed link reveals no personal data
- [x] Invalid link reveals no personal data
- [x] Wrong host name reveals no personal data
- [ ] Account data is updated only after email confirmation
- [ ] Email confirmation goes only to logged-in account
- [ ] Temporary account data is not persisted unnecessarily
- [ ] Temporary accounts are removed when logged-in user uses anonymous link
- [ ] Temporary accounts are not merged with wrong registered account
- [x] Audit logs contain only necessary personal data
- [x] Frontend state contains no foreign link data
- [x] API responses contain no foreign link data
- [x] Browser DevTools / network response contains no foreign link data
- [x] Error messages contain no foreign link data
- [ ] Email texts contain no foreign link data unless explicitly safe and necessary
- [ ] Host-name verification does not allow host enumeration
- [x] Rate limits protect sensitive verification paths
- [x] Privacy-relevant actions are logged

## 15. Security and Manipulation Cases

- [x] Personalized link with modified link ID is rejected
- [x] Personalized link with modified call ID is rejected
- [x] Anonymous link with modified call ID is rejected
- [x] Expired link is rejected
- [x] Disabled link is rejected
- [x] Deleted temporary account cannot be revived through old link
- [x] API request with forged user ID is rejected
- [x] API request with forged role parameter is rejected
- [x] API request with forged organization parameter is rejected
- [x] API request with foreign call ID is rejected
- [x] Owner transfer request without owner/admin right is rejected
- [x] Lobby admission request without moderator right is rejected
- [x] Kick request without moderator right is rejected
- [x] Account-data update request without email confirmation is rejected
- [x] Replay of old email confirmation link is prevented
- [x] Email confirmation link is one-time use
- [ ] Email confirmation link is time-limited
- [x] CSRF protection works for account-data change
- [x] Session fixation during link opening is prevented
- [x] Login switch during link verification does not cause wrong account binding
- [x] Logout during link verification causes no data leak
- [x] Parallel tabs with different accounts cause no incorrect merge
- [x] Permission changes during active call are applied correctly
- [x] Owner transfer during active call is applied correctly
- [x] Guest-list change during active call is applied correctly
- [x] Kick during active call removes user
- [x] Deleted call cannot be entered
- [x] Ended call cannot be entered

Proof: `call-access-session-fixation-contract` rejects reuse of an existing
session ID for a new call-access binding, rejects login/account switches between
verified and authenticated context, rejects wrong-account personalized-link
issuance, and makes tampered or expired call-access session bindings fail
session validation. `call-access-session-route-guard-contract` proves the real
`/api/call-access/{id}/session` route passes authenticated and verified
user/session context into that guard, rejects wrong logged-in accounts and
session switches safely, and preserves anonymous personalized/open-link issuance.
It also keeps logged-in anonymous/open links bound to the authenticated account
without creating a temporary guest identity, matching the anonymous-link product
rule.
`call-access-verified-context-ui-contract` adds the frontend half of that
contract by proving the browser join flow forwards the verified context and
current bearer token into session issuance instead of trusting mutable local
state. The focused Playwright case `call-access-join.spec.js` covers the
browser login-switch path after link verification, asserting the session POST
carries the verified context plus the current bearer, returns a safe conflict,
does not render foreign response data, and does not replace local session state.
The focused Playwright logout case clears the verified browser session after
link verification and proves the join flow fails locally without a
call-access session POST, workspace navigation, foreign data rendering, or
foreign session adoption.
`call-access-active-permission-change-contract` proves active-call permissions
are revalidated from current backend state: guest-list removal routes stale
guest sessions back to renewed approval, organization-admin downgrade removes
stale moderation and direct call binding, reconnect/backfill snapshots do not
restore stale moderator/admin rights or leak Call App sessions from the stale
requested call, Direct Join remains fail-closed after downgrade, and Call App
routes deny the stale org-admin context. Owner transfer revalidates old and new
owner rights without trusting stale connection fields. The Docker PHP 8.4
runtime proof passed with `pdo_sqlite`; host PHP reports the same proof as
skipped because the local extension is unavailable.
`call-access-security-manipulation-contract` and
`call-access-security-manipulation.spec.js` prove call-access routes reject
client-supplied call/user/org/role authority fields, bind call-access sessions
to their original call/access id, block cross-origin account-update request and
confirmation attempts, deny ended/deleted call-access sessions, and keep two
parallel authenticated browser contexts from merging issued call-access
sessions. Static and Docker PHP 8.4 runtime proofs passed; host PHP skips the
SQLite backend leg because `pdo_sqlite` is unavailable.

## 16. Email Confirmation for Account Data Update

- [x] Email is triggered only after explicit update request
- [x] Email is sent to logged-in account
- [x] Email is not sent to temporary account
- [x] Email contains secure confirmation link
- [x] Confirmation link is account-bound
- [x] Confirmation link cannot be used by another logged-in account
- [x] Confirmation link is time-limited
- [x] Confirmation link is one-time use
- [x] Without confirmation, account data remains unchanged
- [x] After confirmation, only re-entered data is updated
- [ ] Confirmation success state is shown
- [x] Expired confirmation link updates no data
- [x] Already used confirmation link updates no data again
- [ ] Confirmation is audit-logged
- [x] Failed confirmation shows no sensitive data
- [x] While confirmation is pending, user can continue with original account
- [ ] Multiple pending confirmations are handled correctly
- [ ] Newer change invalidates older confirmation if configured
- [ ] Race condition between two confirmations resolves deterministically

Proof: `call-access-duplicate-review-email.spec.js` covers account-update
confirmation request, logged-in-account-only delivery, account-bound
confirmation, wrong-account denial, cross-browser same-account confirmation,
replay denial, rate limiting, no update before confirmation, manually re-entered
field updates, pending-state continuity, and safe failed-confirmation payloads.
The backend `call-access-email-confirmation-contract.php` additionally proves
the dispatched email contains an absolute HTTPS account-update confirmation URL,
the URL carries only the high-entropy confirmation token, the email and API
response expose the configured expiry, expired confirmations update no account
data and remain unconsumed, and no raw call-access id, host, link-target, or
session data leaks into email, storage, or responses. Host PHP still skips this
runtime proof because `pdo_sqlite` is unavailable; Docker PHP 8.4 with
`pdo_sqlite` passed the contract locally.

## 17. Guest List

- [x] Host can add registered user to guest list
- [x] Host can add temporary invited account to guest list
- [x] Host can remove guest-list entry
- [x] User on guest list can directly join
- [x] User not on guest list cannot directly join
- [x] Temporary account on guest list can directly join
- [x] Temporary account not on guest list lands in lobby
- [x] Organization admin does not need guest-list entry for own organization call
- [x] System admin does not need guest-list entry
- [x] Guest list is call-scoped
- [x] Guest list of one call grants no rights to another call
- [x] Guest list of one organization grants no rights to another organization
- [x] Duplicate guest-list entries are prevented or merged
- [x] Removing guest-list entry affects new join attempts immediately
- [x] Removing guest-list entry during active call follows product rule
- [x] Guest-list changes are audit-logged

## 18. System Admin

- [x] System admin can join call from every organization
- [x] System admin can join call without organization if such calls exist
- [x] System admin can join without guest-list entry
- [x] System admin can manage lobby
- [x] System admin can admit participants
- [x] System admin can reject participants
- [x] System admin can kick participants
- [x] System admin can view / handle review flags if supported
- [x] System admin rights are never granted to temporary accounts
- [x] System admin rights cannot be simulated through link data
- [x] System admin rights remain after owner transfer
- [x] System admin cannot be degraded through call-owner transfer

Proof: `system-admin-call-rights-contract.php` creates an active tenantless call
and proves system-admin direct join, null `tenant_id` preservation, no
participant-row dependency, owner-equivalent admin rights, and negative
organization-admin, forged-role, and temporary-account cases. It also records,
lists, resolves, dismisses, and audits call-access review flags through the
system-admin-only domain and HTTP routes while keeping access fingerprints and
raw account identifiers out of public/audit payloads. The focused PHP 8.5 Docker
SQLite run passed.

## 19. Organization Admin

- [x] Organization admin can join every active call of own organization
- [x] Organization admin can join own organization call without guest-list entry
- [x] Organization admin can manage lobby for own organization calls
- [x] Organization admin can admit participants for own organization calls
- [x] Organization admin can reject participants for own organization calls
- [x] Organization admin can kick participants for own organization calls
- [x] Organization admin cannot join foreign organization calls through this role
- [x] Organization admin cannot manage lobby of foreign organization
- [x] Organization admin rights remain after owner transfer
- [ ] Organization admin can transfer owner rights if allowed
- [ ] Organization admin keeps admin rights when transferring ownership
- [x] Revoking organization-admin role affects new joins and admin actions immediately
- [ ] Organization admin rights cannot be expanded through manipulated organization ID

## 20. Normal User

- [x] Normal user can create own call
- [x] Normal user becomes owner of own call
- [x] Normal user has admin rights in own call
- [ ] Normal user can join foreign call only when authorized
- [x] Normal user can join foreign call when on guest list
- [x] Normal user cannot join foreign call when not on guest list
- [x] Normal user cannot manage foreign lobby
- [x] Normal user can admit participants in own call
- [x] Normal user can reject participants in own call
- [x] Normal user can kick participants in own call
- [x] Normal user loses call-admin rights when transferring ownership
- [x] Normal user cannot perform owner actions after owner transfer
- [x] Normal user keeps no hidden admin rights after owner transfer
- [x] Normal user cannot receive admin rights through anonymous link
- [x] Normal user cannot receive admin rights through personalized link

Proof: `call-creation-owner-rights-contract` creates calls through the backend
`POST /api/calls` route as a registered normal user and as a registered admin,
then verifies the persisted `calls.owner_user_id`, creator room ownership,
creator `call_participants.call_role = owner`, owner role contexts,
`can_moderate`, `can_manage_owner`, and own-call update authority. It does not
exercise owner transfer.
`call-access-admin-prevention-contract` issues a personalized link for a
normal user and an anonymous/open link while a normal user context is present,
then authenticates the issued sessions and proves role, tenant-admin,
system-admin, moderator, owner-management, and call-admin checks all remain
false.

## 21. Cross-Organization Cases

- [x] User from organization A opens personalized link for organization A call
- [x] User from organization A opens personalized link for organization B call
- [x] User from organization A opens anonymous link for organization B call
- [x] Organization admin from organization A opens link to organization A call
- [x] Organization admin from organization A opens link to organization B call
- [x] Organization admin from organization A receives no org-admin rights in organization B call
- [x] User with accounts in multiple organizations is checked in correct call context
- [x] Changing active organization in frontend does not change server-side call permission
- [x] Guest-list entry in organization A does not apply to organization B
- [x] Temporary account from organization A invitation receives no rights in organization B
- [x] Owner rights of organization A call do not apply to organization B call
- [x] Review flags are assigned to correct organization / call

Proof: `realtime-call-scope-contract` resolves realtime call context with
tenant-aware room/call binding, rejects forged same-tenant and foreign-tenant
room joins, prevents foreign tenant lobby hydration, and proves presence in one
call room does not imply subscription or moderation rights in another room.
`call-access-seed-matrix.spec.js` now also proves active-org switch snapshots do
not mint foreign call permissions, owner and guest-list rights do not cross org
boundaries, and duplicate personalized-link review flags stay bound to the
target organization and call. The integrated seed-matrix browser run passed 19
tests.
`call-access-cross-org-contract.php` now proves organization A personalized
links for organization A calls, explicit organization B personalized links for
organization A users as call-scoped participant access, foreign personalized
link mismatch denial without session persistence, organization B anonymous
links for logged-in organization A users without tenant-membership or admin
rights, multi-organization account checks against the linked call tenant instead
of browser active organization, and organization A temporary invite accounts
receiving no organization B call/admin/direct-join rights. Browser proof:
`call-access-cross-org-foreign-join.spec.js` covers linked-call tenant binding,
foreign anonymous link account scoping, and foreign personalized mismatch
privacy/session safety. Proof commands passed: Docker PHP 8.4
`call-access-cross-org-contract.sh`; `npm run test:ci:iam-call-access:static`;
`npx playwright test tests/e2e/call-access-cross-org-foreign-join.spec.js
--workers=1 --reporter=list`.

## 22. Multi-Session, Devices, Browsers

- [x] Logged-in user opens personalized link in browser A
- [x] Same user opens same link in browser B
- [x] Same user opens same link on another device
- [x] Different user opens same personalized link on another device
- [x] Different active session triggers review flag
- [ ] Not logged-in user opens same personalized link on another device
- [ ] Parallel use of same temporary account is handled correctly
- [x] Concurrent join attempts create no duplicate participants
- [ ] Logout in one tab affects link verification in another tab correctly
- [x] Login switch during warning modal is handled correctly
- [ ] Email confirmation in another browser updates correct account
- [x] Session expiry while waiting in lobby is handled correctly
- [x] Session expiry during call creates defined state
- [x] Refresh during host-name verification creates defined state
- [x] Refresh while email confirmation is pending creates defined state

Proof: `call-access-multi-session-device-safety.spec.js` covers the same user
opening one personalized link in two isolated browser/device contexts with
concurrent session requests and separate stored session tokens, a different
user/device receiving a manual-review duplicate flag without session issuance or
foreign-data leakage, login switch during host-verification warning failing
closed, lobby-session expiry clearing stale access without entering the call,
call-workspace session expiry redirecting to login and clearing storage,
host-verification refresh refetching safe state without replaying issuance, and
pending account-update email confirmation staying bound to the current account
through refresh. The backend session contract also proves same-user device
joins deduplicate participants while preserving separate call-access sessions.
The focused Playwright run passed 7 tests; `npm run
test:contract:iam-call-access` passed with the existing `pdo_sqlite` skips.

Proof: `realtime-reconnect-backfill-contract` proves transient auth backend
errors remain retryable inside a bounded grace window, revoked sessions still
close as policy violations, requested call reconnect backfill failures return
retryable 503 diagnostics before websocket upgrade, and successful backfill
sends authoritative lobby/room snapshots for the call room.
Browser proof: `npm run test:contract:realtime-reconnect-browser` proves
transient websocket auth/backfill errors stay in retrying UI state with
retryable diagnostics and request room snapshot backfill after reconnect.
Browser E2E proof: `npm run test:e2e:realtime-reconnect-websocket` drives the
workspace through retryable websocket auth and reconnect-backfill failures in a
fake browser WebSocket and proves no reload/logout path fires while reconnect
requests a fresh `room/snapshot/request`.

## 23. Organization Membership Changes After Invitation

- [x] Invited registered user is removed from organization before opening personalized invite link
- [x] Removed invited user can still open still-valid personalized invite link
- [x] Removed invited user joins only as call-scoped invited guest
- [x] Removed invited user does not retain organization-member rights
- [x] Removed invited user does not retain organization-admin rights
- [x] Removed invited user cannot join other organization calls
- [x] Removed invited user cannot access organization resources
- [x] Removed invited user cannot manage call unless separately owner/moderator
- [x] Removed invited user cannot use stale role data from token/session/cache
- [x] Removed invited user is blocked if invite was manually invalidated
- [ ] Removed invited user is blocked if call was deleted
- [ ] Removed invited user is blocked if call was ended
- [ ] Removed invited user is blocked or routed according to policy if kicked
- [ ] User already inside call remains connected after org removal if access was call-scoped
- [ ] User already inside call immediately loses organization-level privileges after removal
- [ ] Removed org-admin already inside call loses org-admin controls immediately
- [ ] Removed org-admin already inside call remains only if explicit call-scoped access exists
- [ ] Removed user can leave and rejoin same call only while invitation remains valid
- [ ] Removed user cannot rejoin after invite invalidation
- [x] Audit log records membership removal
- [ ] Audit log records permission downgrade
- [x] Audit log records continued call-scoped access
- [ ] User invited as org member but later moved to another organization joins only through call-scoped invitation
- [ ] User invited as org admin but later downgraded to user loses org-admin access but keeps explicit invite access
- [ ] User invited as normal user but later promoted to org admin receives current org-admin rights if still member
- [ ] Removed org admin cannot use org-admin rights from stale invite payload
- [ ] Removed user in lobby loses org-based rights but may remain in lobby through call-scoped invitation

Proof: `call-access-membership-removal-contract` removes tenant, organization,
and group memberships before opening the personalized link; proves a pre-removal
normal tenant session with elevated tenant/org rights is rejected after removal,
including through the locally issued session cache fallback; verifies an
organization-scoped resource grant is lost with organization membership;
verifies the link still resolves; issues a call-scoped session; authenticates
through the call-access fallback without recreating membership; proves
`tenant_admin` stays false and organization resource access stays denied; and
confirms the admitted user enters only the bound call room.
`call-access-invited-user-org-removal-contract` keeps tenant membership while
removing organization membership after a personalized invite, proves stale
normal sessions re-read least-privilege tenant state, denies unrelated
organization-call browse/direct-entry/admin rights, issues a call-scoped
personal-link session bound only to the invited call, keeps the invitee in lobby
before admission, and admits them only as a non-moderating participant after
host approval. The focused
Playwright spec `call-access-join.spec.js` proves the public join/session browser
path and waiting-for-host state.

## 24. Invite Link Invalidation

- [x] Personalized invite link is manually invalidated before use
- [x] Personalized invite link is invalidated after first use
- [ ] Personalized invite link is invalidated while invitee is in lobby
- [ ] Personalized invite link is invalidated while invitee is already in call
- [ ] Anonymous join link is manually invalidated before use
- [ ] Anonymous join link is invalidated while anonymous guest is in lobby
- [ ] Anonymous join link is invalidated while anonymous guest is already in call
- [x] Invalidated link cannot be used for fresh join attempts
- [x] Invalidated link cannot be used for rejoin unless product rule allows admitted rejoin
- [x] Invalidated link does not reveal whether original invitee exists
- [x] Invalidated link does not reveal guest account data
- [x] Invalidated link does not recreate deleted temporary accounts
- [x] Invalidated link state is enforced server-side
- [ ] Invalidated link state works across browsers
- [ ] Invalidated link state works across devices
- [ ] Invalidated link state works across sessions
- [ ] Invalidated link state survives application restart during CI
- [x] Rejected invalidated link shows safe invalid-link state
- [x] Rejected invalidated link does not leak personal data
- [x] Stale client-side state cannot join with invalidated link

Proof: `call-access-invalidation-contract` proves manual personalized invite
invalidation before use and after a session was issued, rejects fresh
join/session attempts without exposing call, link, or target-user data, and
rejects stale websocket rejoin through the call-access session binding.
`call-access-invite-invalidation.spec.js` proves stale browser state renders
the safe invalid-link state and does not issue a replacement session. Local
PHP syntax checks passed; SQLite-backed contract execution is blocked on this
host by missing `pdo_sqlite`.

## 25. Guest Account Lifecycle

- [x] Guest account is created from personalized calendar invitation
- [x] Guest account is deleted when call is deleted
- [x] Guest account is deleted or invalidated when invitation is deleted
- [x] Guest account is deleted or invalidated when invite link is manually invalidated
- [x] Guest account is updated, recreated, or invalidated when call is rescheduled according to product rule
- [x] Guest account cannot join original call after call was rescheduled and original link invalidated
- [x] Guest account cannot join after call was deleted
- [x] Guest account cannot join after call was ended
- [x] Guest account cannot rejoin after cleanup
- [x] Guest account cannot be used to infer deleted call data
- [x] Guest account cleanup does not delete registered user accounts
- [x] Guest account cleanup does not alter registered user profile data
- [x] Guest account cleanup does not remove unrelated temporary guests from other calls
- [x] Guest account cleanup is scoped to affected call / invitation
- [x] Guest account cleanup is idempotent
- [x] Guest account cleanup is audit-logged
- [x] Old guest account cannot be revived through old personalized link
- [x] Old guest account cannot be revived through stale browser state
- [x] Old guest account cannot be revived after application restart

Proof: `demo/video-chat/backend-king-php/tests/call-guest-lifecycle-contract.php`
now repeats personalized guest cleanup after the account/session were already
invalidated, verifies the second pass has zero destructive changes, and asserts
both the first pass and idempotent repeat append sanitized
`guest_account_cleanup` audit events without raw guest, session, or access-link
identifiers.

Proof: `call-guest-cleanup-sqlite-proof.sh` passed through the Docker
fallback and ran the explicit cleanup, call-end cleanup, and call-delete
cleanup split contracts. Those contracts prove scoped temporary guests are
disabled, stale guest sessions cannot authenticate, stale guest links cannot
rejoin, registered accounts remain active and unchanged, repeat cleanup is a
no-op, cleanup audit events are sanitized, and delete/end paths expose the
guest cleanup result while preserving the stronger lifecycle cleanup.

Proof: `call-guest-cleanup-sqlite-proof.sh` now also runs the invitation-delete,
restart, and remaining lifecycle cleanup splits. Those contracts prove
invitation deletion disables only the scoped temporary guest and revokes only
that access session, registered invite invalidation does not run destructive
guest cleanup, old personalized guest links cannot allocate a new session after
a reopened database connection, and delete/end cleanup covers lobby, admitted,
registered, and anonymous temporary participant states.

## 26. Call Rescheduling

- [ ] Owner reschedules call before guest opens invite link
- [ ] Owner reschedules call while guest is in lobby
- [x] Owner reschedules call while guest is already inside call
- [x] Personalized invite link from old time is invalidated after reschedule if required
- [x] New personalized invite link is issued after reschedule if required
- [x] Old temporary guest account is deleted, invalidated, or migrated according to product rule
- [x] Guest using old link after reschedule cannot join stale call state
- [ ] Guest using new link after reschedule can join according to current permissions
- [x] Registered invited user receives correct behavior when using old link after reschedule
- [ ] Anonymous join link behavior after reschedule is tested
- [ ] Lobby entries from old schedule are cleared or migrated according to product rule
- [x] Admitted temporary participants from old schedule are cleared or migrated according to product rule
- [x] Audit log records reschedule
- [x] Audit log records related invite cleanup
- [x] Audit log records guest cleanup
- [x] Stale links do not join users into wrong call instance
- [x] Frontend shows safe and clear state for old links

Proof: `call-lifecycle-contract` covers schedule changes against admitted
registered and temporary participants, invalidates old personalized links,
revokes active call-access sessions, clears realtime presence, disables the
scoped temporary guest, preserves registered accounts, emits sanitized
reschedule/guest-cleanup audit events, and proves a fresh registered link can
resolve after reschedule. `call-access-lifecycle-stale-links.spec.js` passed
the rescheduled stale-link safe-screen case.

## 27. Call Deletion

- [ ] Owner deletes call before any guest joins
- [x] Owner deletes call while guests are in lobby
- [x] Owner deletes call while registered users are inside
- [x] Owner deletes call while temporary guests are inside
- [x] Owner deletes call while anonymous guests are inside
- [x] Deleted call cannot be joined by owner
- [x] Deleted call cannot be joined by organization admin
- [x] Deleted call cannot be joined by system admin through normal join flow
- [x] Deleted call cannot be joined through personalized invite link
- [x] Deleted call cannot be joined through anonymous join link
- [x] Deleted call cannot be rejoined by previously admitted guest
- [x] Deleted call removes or invalidates temporary guest accounts
- [x] Deleted call clears lobby entries
- [x] Deleted call clears admitted temporary participant state
- [x] Deleted call preserves audit log
- [x] Deleted call does not delete registered user accounts
- [x] Deleted call does not delete unrelated calls
- [x] Deleted call does not delete unrelated guests
- [x] Users currently in call are disconnected or moved into safe deleted state
- [x] Deleted call metadata is not leaked to unauthorized users

Proof: `call-lifecycle-contract` deletes calls with an open-link temporary
guest in presence, a queued lobby guest, an admitted temporary guest, and a
separate personalized temporary guest link. It blocks owner/admin join as not
found, rejects stale open and personalized links safely, revokes call-access
sessions, clears presence/lobby/admitted participant state, disables only scoped
temporary guests, preserves unrelated calls and unrelated guests, and emits
sanitized delete/guest-cleanup audit events. `call-access-deleted-ended-
disabled-join-contract` now also proves same-organization admin denial after
deletion plus system-admin deleted normal-join denial and deleted personalized
link denial. `call-access-seed-matrix.spec.js` passed the org-admin deleted
direct-join denial and deleted personalized-link UI safe-screen case;
`call-access-lifecycle-stale-links.spec.js` passed the deleted stale-link
safe-screen case. `call-guest-cleanup-lifecycle-remaining-contract` additionally
deletes a call with a pending lobby guest, an admitted temporary guest, and an
active registered participant, proving guest cleanup, registered account
preservation, access-link removal, and lobby/participant state clearing.

## 28. Explicit Call Ending

- [x] Owner explicitly ends call
- [ ] Owner leaves call and product treats this as explicit call end
- [x] Active registered participants receive ended state
- [x] Active temporary guests receive ended state
- [x] Active anonymous guests receive ended state
- [x] New joins are blocked after explicit end
- [x] Rejoins are blocked after explicit end
- [x] Personalized invite links are invalidated after explicit end
- [x] Anonymous join links are invalidated after explicit end
- [x] Temporary guest accounts are deleted or invalidated after explicit end
- [x] Lobby entries are cleared after explicit end
- [x] Audit log is preserved after explicit end
- [x] Organization admin cannot bypass ended-call state through normal join
- [x] System admin cannot bypass ended-call state through normal join unless explicit recovery/debug path exists
- [x] Late user opening old link sees safe ended-call state

Proof: `videochat_end_call` plus `call-lifecycle-contract` moves the call to
ended, cancels active registered and temporary participants, revokes their
call-access sessions, blocks normal owner/admin and active participant joins,
invalidates old personalized links, disables the scoped temporary guest,
clears realtime presence/lobby entries, invalidates anonymous open links, blocks
organization-admin and system-admin direct joins through the normal call
resolution path, and emits sanitized end/guest-cleanup audit events.
`call-access-lifecycle-stale-links.spec.js` passed the ended stale-link
safe-screen case. `call-access-seed-matrix.spec.js` passed the explicit-ended
direct-join denial cases for system admin and organization admin as part of the
24-test integrated run.
`call-guest-cleanup-lifecycle-remaining-contract` marks an anonymous open-link
guest as active, ends the call, and proves the participant receives cancelled
plus `left_at` state while the temporary account and stale open link are
invalidated.

## 29. Implicit Call Ending by Owner Absence

- [x] Owner loses connection
- [ ] Owner closes browser tab
- [ ] Owner browser crashes or context is killed
- [ ] Owner network is disconnected
- [x] Owner is absent for less than 10 minutes equivalent
- [x] Owner is absent for 10 minutes equivalent
- [x] Owner is absent for 15 minutes equivalent
- [ ] Owner rejoins before final 5-minute countdown starts
- [x] Owner rejoins during final 5-minute countdown
- [x] Owner does not rejoin before timer expires
- [x] Call ends automatically after 15 minutes owner absence equivalent
- [ ] Participants are notified when owner absence timer starts if applicable
- [x] Participants see visible countdown during last 5 minutes
- [x] Countdown starts when 5 minutes remain
- [x] Countdown shows correct remaining time
- [x] Countdown updates correctly over time
- [ ] Countdown survives participant refresh
- [ ] Countdown is synchronized across participants
- [x] Countdown does not reveal admin-only data
- [x] Countdown disappears if owner rejoins
- [x] Call does not end if owner rejoins before timeout
- [x] Call ends if owner does not rejoin before timeout
- [x] Call-ended state prevents new joins
- [x] Call-ended state prevents rejoins
- [ ] Call-ended state invalidates anonymous join link
- [x] Call-ended state invalidates personalized invite links
- [x] Call-ended state deletes or invalidates temporary guest accounts
- [x] Call-ended state clears lobby entries
- [x] Call-ended state preserves audit log
- [ ] Call-ended state is visible to late users opening old links
- [x] Timer is based on server time, not client time
- [x] CI test uses fake/test time and does not wait 15 real minutes

Proof: `realtime_owner_absence.php` applies a server-time owner-absence
deadline of 15 minutes total, starts the countdown in the final 5 minutes, and
now routes the automatic ended transition through the shared terminal call
lifecycle cleanup. `iam-king-participants-owner-timeout-contract.mjs` verifies
the contract shape, fake/test clock, room snapshot lifecycle payload, countdown
boundary, owner-return cancellation, terminal lifecycle binding, and persisted
implicit end. `call-access-owner-timeout-contract.php` passed under Docker PHP
8.4 with `pdo_sqlite` and proves automatic end blocks fresh joins and stale
session rejoins, invalidates personalized links, disables pending/admitted
temporary guests, clears lobby entries, and preserves call-ended audit.
`call-access-owner-absence-browser.spec.js` drives the browser workspace through
the final-countdown UI, countdown update, owner-absence automatic end, and owner
return cancellation paths using the backend timer constants and room snapshots.
The focused Playwright owner-absence browser run remains covered by the
integrated IAM call-access E2E run, which passed 43 tests.

## 30. Error and Edge Cases

- [x] Call does not exist
- [x] Call was deleted
- [x] Call was ended
- [ ] Call has not started yet if time-limited
- [ ] Call has expired if time-limited
- [ ] Organization does not exist
- [x] Organization is disabled
- [ ] Host no longer exists
- [x] Host is disabled
- [x] Invited temporary account was deleted
- [x] Registered account was disabled
- [x] Registered account was deleted
- [ ] User email is unconfirmed if relevant
- [ ] Calendar appointment was cancelled
- [x] Calendar appointment was moved
- [ ] Personalized link belongs to another appointment of same host
- [ ] Personalized link belongs to another call of same host
- [ ] Host name differs in capitalization
- [ ] Host name contains special characters
- [ ] Host name contains spaces / double names
- [ ] Host name is ambiguous
- [ ] Host name changed after invitation
- [ ] First name / last name of logged-in user is missing
- [ ] First name / last name of temporary link account is missing
- [ ] Only first name differs
- [ ] Only last name differs
- [ ] Email differs but name matches
- [ ] Address differs but name matches
- [ ] Street differs and is treated as possible move
- [ ] Different street is not displayed
- [ ] Phone number differs if present
- [ ] Special characters / umlauts in names are normalized correctly
- [ ] Different spelling with accents is evaluated according to rule
- [ ] Leading / trailing spaces in names do not cause false strong mismatch
- [ ] Empty inputs in update form are validated
- [ ] Invalid email configuration causes no data leaks
- [ ] Mail sending failure leaves account unchanged
- [ ] Database error during join leads to safe abort
- [ ] Network error during join leads to repeatable state
- [ ] Timeout during lobby admission leads to consistent state

Proof: `call-access-edge-error-matrix-contract` closes call-not-found, disabled
organization/workspace, disabled host, and deleted invited temporary-account
paths. The backend PHP proof drives direct `/api/calls/resolve`, public
`/api/call-access/{id}/join`, and session issuance paths, verifies missing calls
and missing access links fail closed, archives the tenant/workspace to prove the
organization-disabled path returns no call payload or session, disables the host
account to prove host-inactive denial, deletes an invited temporary guest account,
and asserts no secret call/user data is emitted. The Playwright safe-screen case
in `call-access-join.spec.js` covers the disabled organization, disabled host,
and deleted temporary-account browser states and proves no Join button/session
POST is possible. `call-access-edge-error-matrix-contract.mjs` binds the Sprint
checkboxes, package script, CI gate, backend contract, browser proof, and runtime
tenant/host session-binding checks.

## 31. Audit and Monitoring

- [x] Call creation is logged
- [x] Invitation creation is logged
- [x] Personalized link open is logged
- [x] Anonymous link open is logged
- [x] Temporary account creation is logged
- [x] Temporary account removal is logged
- [x] Link-account vs logged-in-account comparison is logged
- [x] Strong mismatch is logged
- [x] Host-name verification is logged
- [x] Successful host-name verification is logged
- [x] Failed host-name verification is logged
- [x] Account-update request is logged
- [ ] Confirmation email dispatch is logged
- [ ] Successful email confirmation is logged
- [ ] Failed email confirmation is logged
- [ ] Account-data change is logged
- [ ] Lobby entry is logged
- [ ] Lobby admission is logged
- [ ] Lobby rejection is logged
- [x] Call join is logged
- [x] Call leave is logged
- [x] Rejoin is logged
- [x] Kick is logged
- [x] Owner transfer is logged
- [ ] Guest-list change is logged
- [x] Review flag for duplicate link is logged
- [x] Membership removal is logged
- [ ] Invite invalidation is logged
- [x] Call reschedule is logged
- [x] Call deletion is logged
- [x] Explicit call end is logged
- [ ] Implicit call end is logged
- [ ] Owner absence timer start is logged
- [ ] Owner absence timer cancellation is logged
- [x] Audit logs contain time, actor, target, call, and organization
- [x] Audit logs contain no unnecessary sensitive link data
- [x] Security-relevant events are visible in monitoring
- [ ] Failed E2E test artifacts include relevant logs

Proof: `iam-call-access-audit-events-contract.mjs` and
`audit-call-access-events-contract.php` pin audit helpers and event types for
personalized link open, duplicate-link review, strong mismatch / failed
host-name verification, join, leave, rejoin, kick, owner transfer, and
membership removal. The contract also asserts real state transitions happen
before the audit write, fingerprints link/session identifiers, omits raw
access/session/token/password/SDP/ICE keys, and exposes the live audit probe
event list for monitoring. Lifecycle and guest-cleanup contracts cover
temporary account removal plus reschedule/delete/end audit records.
`call-access-duplicate-review-contract.php` additionally proves duplicate
foreign personalized-link review audit entries include timestamp, actor, target,
call, and organization while omitting raw link IDs, host names, session IDs,
tokens, SDP, ICE, and account emails.
`audit-call-access-events-contract.php` now also proves call creation,
invitation creation, anonymous/open link opening, temporary-account creation,
link-account comparison for strong mismatch and matched sessions, successful
host-name verification, and account-update request audit events. Docker PHP 8.4
runtime proof passed, and `iam-call-access-audit-events-contract.mjs` pins the
backend paths, event types, live probe expectations, and sensitive-data guards.

## 32. End-to-End Main Paths

- [ ] New unregistered guest books appointment through calendar, receives personalized link, opens logged out, lands in lobby, is admitted, joins, leaves, rejoins without approval
- [x] Registered but logged-out guest books appointment, opens personalized link logged out, temporary account is used, no automatic account takeover
- [x] Registered logged-in guest opens own personalized link with matching data, remains logged in, joins as registered user
- [ ] Registered logged-in guest opens personalized link with light mismatch, remains logged in, joins after permission check
- [ ] Registered logged-in user opens foreign personalized link with strong mismatch, sees warning modal, enters wrong host name, receives no foreign data
- [ ] Registered logged-in user opens foreign personalized link with strong mismatch, enters correct host name, declines data update, remains unchanged
- [ ] Registered logged-in user opens personalized link with strong mismatch, enters correct host name, re-enters data, confirms email, account is updated
- [x] Same personalized link is opened by second logged-in account and review flag is created
- [ ] Logged-in user opens anonymous link and joins as logged-in user with own rights
- [x] Not logged-in user opens anonymous link, temporary account is created, user lands in lobby, is admitted, can rejoin
- [x] System admin joins foreign active call without invitation
- [x] Organization admin joins own organization active call without invitation
- [x] Organization admin cannot join foreign organization call through org-admin rights
- [ ] Normal user on guest list joins foreign call
- [x] Normal user without guest-list entry lands in lobby or is denied
- [ ] User creates own call, becomes owner, transfers ownership, loses call-admin rights
- [ ] Organization admin creates call, transfers ownership, keeps admin rights
- [x] Temporary user is admitted, then kicked, and cannot rejoin without renewed approval
- [ ] Invited user is removed from organization before opening link, then joins as call-scoped invited guest
- [ ] Invite link is invalidated before use and cannot be used
- [ ] Call is rescheduled and stale link no longer grants stale access
- [ ] Call is deleted and all temporary access is revoked
- [ ] Owner explicitly ends call and all participants receive ended state
- [x] Owner disconnects, final 5-minute countdown is shown, owner does not return, call ends automatically
- [x] Owner disconnects, final 5-minute countdown is shown, owner returns, countdown disappears and call remains active

Proof: `call-access-main-journey-smoke.spec.js` covers
`e2e_journey_003_registered_logged_in_matching_invitee_joins_as_account` and
`e2e_journey_010_logged_out_user_anonymous_link_lobby_admit_rejoin` in one
integrated browser run. `npx playwright test
tests/e2e/call-access-main-journey-smoke.spec.js --workers=1 --reporter=list`
passed 6 tests including the temporary-user kick and renewed-approval denial
path.
`call-access-seed-matrix.spec.js` now covers
`e2e_journey_011_system_admin_join_without_invite` and
`e2e_journey_011b_system_admin_join_tenantless_call_without_org`; the focused
Playwright run passed 26 tests.

---

# Named Automated Test Checklist

## Test Group: Organization and Role Fixtures

- [x] `e2e_org_001_create_organization`
- [x] `e2e_org_002_register_user_in_organization`
- [x] `e2e_org_003_assign_user_role_user`
- [x] `e2e_org_004_assign_user_role_admin`
- [x] `e2e_org_005_login_normal_user`
- [x] `e2e_org_006_login_organization_admin`
- [x] `e2e_org_007_logged_out_user_has_no_session`
- [ ] `e2e_org_008_cross_org_rights_not_leaked`
- [ ] `e2e_org_009_stale_client_role_ignored`
- [ ] `e2e_org_010_stale_session_role_revalidated`

## Test Group: Call Creation and Ownership

- [x] `e2e_owner_001_normal_user_creates_call_and_becomes_owner`
- [x] `e2e_owner_002_admin_user_creates_call_and_becomes_owner`
- [ ] `e2e_owner_003_owner_can_manage_guest_list`
- [ ] `e2e_owner_004_owner_can_admit_lobby_participant`
- [ ] `e2e_owner_005_owner_can_kick_participant`
- [ ] `e2e_owner_006_normal_user_transfers_owner_and_loses_admin_rights`
- [ ] `e2e_owner_007_org_admin_transfers_owner_and_keeps_admin_rights`
- [ ] `e2e_owner_008_new_owner_receives_owner_and_admin_rights`
- [ ] `e2e_owner_009_exactly_one_current_owner_after_transfer`
- [ ] `e2e_owner_010_owner_transfer_to_nonexistent_user_rejected`
- [ ] `e2e_owner_011_owner_transfer_cross_org_rejected_if_forbidden`
- [ ] `e2e_owner_012_owner_transfer_audit_logged`

Proof: `call-creation-owner-rights-contract` is the backend/API proof for
`e2e_owner_001` and `e2e_owner_002`: both registered normal-user and admin-user
creators create their own call through `/api/calls`, become the persisted owner,
and receive own-call admin/moderation rights. Owner-transfer scenarios remain
unchecked for the separate owner-transfer lane.

## Test Group: Direct Join Permissions

- [x] `e2e_join_001_system_admin_can_join_any_active_call`
- [x] `e2e_join_002_system_admin_joins_without_guest_list`
- [x] `e2e_join_003_org_admin_can_join_own_org_call`
- [x] `e2e_join_004_org_admin_cannot_join_foreign_org_call`
- [x] `e2e_join_005_guest_list_user_can_join`
- [x] `e2e_join_006_user_not_on_guest_list_cannot_direct_join`
- [x] `e2e_join_007_owner_can_join_own_call`
- [x] `e2e_join_008_disabled_user_cannot_join`
- [x] `e2e_join_009_removed_guest_list_entry_revokes_join`
- [x] `e2e_join_010_added_guest_list_entry_grants_join`
- [x] `e2e_join_011_manipulated_role_rejected`
- [x] `e2e_join_012_manipulated_call_id_rejected`

## Test Group: Calendar Invitation

- [x] `e2e_invite_001_host_creates_calendar_invitation`
- [x] `e2e_invite_002_invitee_selects_appointment`
- [ ] `e2e_invite_003_registered_logged_in_invitee_flow`
- [x] `e2e_invite_004_registered_logged_out_invitee_flow`
- [x] `e2e_invite_005_unregistered_invitee_creates_temp_account`
- [x] `e2e_invite_006_personalized_link_bound_to_temp_account`
- [x] `e2e_invite_007_multiple_invitees_get_unique_links`
- [x] `e2e_invite_008_cancel_invitation_invalidates_link`
- [x] `e2e_invite_009_expired_personalized_link_rejected`
- [x] `e2e_invite_010_reopen_same_link_same_context_consistent`

Proof: `call-access-verified-context-ui-contract` pins the focused Playwright
case `same personalized link in parallel contexts keeps account sessions
isolated` in `call-access-join.spec.js`. It opens the same personalized link in
two isolated browser contexts with different authenticated sessions, submits the
session requests in parallel, proves each POST carries its own bearer plus
verified user/session snapshot, keeps localStorage/session state isolated after
one success and one conflict, renders no foreign response data, and guards
against duplicate join/session request loops.

## Test Group: Personalized Link Logged Out

- [x] `e2e_personalized_logged_out_001_temp_account_created_from_link`
- [x] `e2e_personalized_logged_out_002_existing_account_not_auto_logged_in`
- [ ] `e2e_personalized_logged_out_003_temp_guest_on_guest_list_direct_join`
- [x] `e2e_personalized_logged_out_004_temp_guest_not_on_guest_list_lobby`
- [x] `e2e_personalized_logged_out_005_temp_guest_no_registered_rights`
- [x] `e2e_personalized_logged_out_006_temp_guest_no_org_rights`
- [ ] `e2e_personalized_logged_out_007_manipulated_link_rejected`
- [ ] `e2e_personalized_logged_out_008_invalid_link_safe_error`

## Test Group: Personalized Link Logged In Without Strong Mismatch

- [ ] `e2e_personalized_logged_in_001_logged_in_session_preserved`
- [ ] `e2e_personalized_logged_in_002_temp_account_does_not_replace_session`
- [ ] `e2e_personalized_logged_in_003_matching_data_no_warning_modal`
- [ ] `e2e_personalized_logged_in_004_light_mismatch_no_foreign_link_warning`
- [ ] `e2e_personalized_logged_in_005_logged_in_account_used_for_permission_check`
- [ ] `e2e_personalized_logged_in_006_no_auto_account_data_overwrite`
- [ ] `e2e_personalized_logged_in_007_no_link_data_exposed`
- [ ] `e2e_personalized_logged_in_008_same_account_reopen_no_duplicate_flag`

## Test Group: Personalized Link Strong Mismatch

- [ ] `e2e_strong_mismatch_001_first_name_mismatch_detected`
- [ ] `e2e_strong_mismatch_002_last_name_mismatch_detected`
- [ ] `e2e_strong_mismatch_003_full_name_mismatch_detected`
- [ ] `e2e_strong_mismatch_004_warning_modal_displayed`
- [ ] `e2e_strong_mismatch_005_foreign_link_data_not_displayed`
- [ ] `e2e_strong_mismatch_006_wrong_host_name_no_access`
- [ ] `e2e_strong_mismatch_007_wrong_host_name_no_data_leak`
- [ ] `e2e_strong_mismatch_008_correct_host_name_accepted`
- [ ] `e2e_strong_mismatch_009_decline_account_update_keeps_account_unchanged`
- [x] `e2e_strong_mismatch_010_update_requires_manual_reentry`
- [x] `e2e_strong_mismatch_011_confirmation_email_sent_to_logged_in_account`
- [x] `e2e_strong_mismatch_012_email_not_sent_to_temp_account`
- [x] `e2e_strong_mismatch_013_no_update_without_email_confirmation`
- [x] `e2e_strong_mismatch_014_confirmed_update_changes_only_confirmed_fields`
- [x] `e2e_strong_mismatch_015_rate_limit_host_name_attempts`
- [ ] `e2e_strong_mismatch_016_audit_logged`

## Test Group: Duplicate Personalized Link

- [x] `e2e_duplicate_link_001_same_account_reuses_link_no_flag`
- [x] `e2e_duplicate_link_002_second_account_uses_link_flag_created`
- [x] `e2e_duplicate_link_003_second_account_flag_even_without_join`
- [x] `e2e_duplicate_link_004_second_account_flag_even_with_correct_host_name`
- [ ] `e2e_duplicate_link_005_concurrent_two_accounts_same_link_detected`
- [ ] `e2e_duplicate_link_006_parallel_open_no_inconsistent_assignment`
- [ ] `e2e_duplicate_link_007_cross_device_duplicate_detected`
- [ ] `e2e_duplicate_link_008_cross_browser_duplicate_detected`
- [x] `e2e_duplicate_link_009_review_flag_contains_required_metadata`
- [x] `e2e_duplicate_link_010_review_flag_avoids_sensitive_data`

## Test Group: Anonymous Link Logged In

- [ ] `e2e_anon_logged_in_001_logged_in_user_uses_own_account`
- [ ] `e2e_anon_logged_in_002_temp_account_discarded`
- [ ] `e2e_anon_logged_in_003_logged_in_rights_used`
- [ ] `e2e_anon_logged_in_004_system_admin_can_join_active_call`
- [ ] `e2e_anon_logged_in_005_org_admin_can_join_own_org_call`
- [ ] `e2e_anon_logged_in_006_org_admin_cannot_join_foreign_org_call`
- [ ] `e2e_anon_logged_in_007_guest_list_user_can_join`
- [ ] `e2e_anon_logged_in_008_non_guest_user_lands_in_lobby`
- [ ] `e2e_anon_logged_in_009_anonymous_link_does_not_overwrite_account`
- [ ] `e2e_anon_logged_in_010_invalid_anonymous_link_rejected`

## Test Group: Anonymous Link Logged Out

- [ ] `e2e_anon_logged_out_001_temp_anonymous_account_created`
- [ ] `e2e_anon_logged_out_002_temp_anonymous_user_lands_in_lobby`
- [ ] `e2e_anon_logged_out_003_temp_anonymous_has_no_registered_rights`
- [ ] `e2e_anon_logged_out_004_host_can_admit_anonymous_guest`
- [ ] `e2e_anon_logged_out_005_temp_moderator_can_admit_anonymous_guest`
- [ ] `e2e_anon_logged_out_006_admin_can_admit_anonymous_guest`
- [ ] `e2e_anon_logged_out_007_unauthorized_user_cannot_admit_guest`
- [x] `e2e_anon_logged_out_008_admitted_guest_can_rejoin`
- [x] `e2e_anon_logged_out_009_kicked_guest_cannot_direct_rejoin`
- [ ] `e2e_anon_logged_out_010_multiple_anonymous_guests_are_separate`
- [x] `e2e_anon_logged_out_011_disabled_anonymous_link_allows_no_lobby_entry`

## Test Group: Lobby

- [ ] `e2e_lobby_001_unauthorized_user_lands_in_lobby`
- [ ] `e2e_lobby_002_host_sees_waiting_participant`
- [ ] `e2e_lobby_003_temp_moderator_sees_waiting_participant`
- [ ] `e2e_lobby_004_org_admin_sees_waiting_participant_for_own_org`
- [ ] `e2e_lobby_005_unauthorized_user_no_lobby_controls`
- [ ] `e2e_lobby_006_host_admits_participant`
- [ ] `e2e_lobby_007_host_rejects_participant`
- [ ] `e2e_lobby_008_rejected_participant_cannot_enter`
- [ ] `e2e_lobby_009_admission_is_call_scoped`
- [x] `e2e_lobby_010_concurrent_admission_idempotent`
- [x] `e2e_lobby_011_concurrent_admit_reject_deterministic`
- [x] `e2e_lobby_012_lobby_state_updates_correctly`

## Test Group: Rejoin and Kick

- [x] `e2e_rejoin_001_admitted_temp_user_can_rejoin`
- [ ] `e2e_rejoin_002_rejoin_after_refresh`
- [ ] `e2e_rejoin_003_rejoin_after_network_interruption`
- [x] `e2e_rejoin_004_kicked_temp_user_cannot_direct_rejoin`
- [x] `e2e_rejoin_005_kick_overrides_previous_admission`
- [ ] `e2e_rejoin_006_registered_guest_can_rejoin`
- [x] `e2e_rejoin_007_rejoin_after_guest_list_removal_blocked_or_lobby`
- [x] `e2e_rejoin_008_rejoin_after_admin_role_removed_uses_new_permissions`
- [ ] `e2e_rejoin_009_rejoin_after_owner_transfer_uses_new_permissions`
- [x] `e2e_rejoin_010_kicked_logged_in_user_cannot_direct_rejoin`

## Test Group: Temporary Moderators

- [x] `e2e_temp_mod_001_host_assigns_temp_moderator`
- [x] `e2e_temp_mod_002_temp_moderator_admits_participant`
- [x] `e2e_temp_mod_003_temp_moderator_rejects_participant`
- [x] `e2e_temp_mod_004_temp_moderator_limited_to_assigned_call`
- [x] `e2e_temp_mod_005_temp_moderator_no_org_admin_actions`
- [x] `e2e_temp_mod_006_temp_moderator_rights_revoked_immediately`
- [x] `e2e_temp_mod_007_client_side_temp_mod_role_forgery_rejected`

## Test Group: Privacy and Security

- [x] `e2e_privacy_001_foreign_link_data_not_rendered`
- [x] `e2e_privacy_002_foreign_link_data_not_in_api_response`
- [x] `e2e_privacy_003_invalid_link_no_personal_data_leak`
- [x] `e2e_privacy_004_wrong_host_name_no_personal_data_leak`
- [x] `e2e_privacy_005_browser_network_response_no_foreign_data`
- [x] `e2e_privacy_006_audit_logs_minimize_sensitive_data`
- [x] `e2e_security_001_modified_personalized_link_id_rejected`
- [x] `e2e_security_002_modified_call_id_rejected`
- [x] `e2e_security_003_forged_user_id_rejected`
- [x] `e2e_security_004_forged_role_rejected`
- [x] `e2e_security_005_forged_org_id_rejected`
- [x] `e2e_security_006_csrf_account_update_protected`
- [ ] `e2e_security_007_session_fixation_prevented`
- [x] `e2e_security_008_parallel_tabs_no_wrong_merge`
- [x] `e2e_security_009_kick_during_active_call_removes_user`

Proof: `call-access-privacy-foreign-data.spec.js` covers the browser
privacy cases for invalid guessed links and wrong-host strong mismatch:
foreign title, host, invitee, call-id, and denied-session sentinels are absent
from rendered dialogs and captured `/api/call-access/{id}/join` plus
`/session` network bodies; invalid links show no join action, wrong-host
denials stay on `/join/{id}`, do not enter `/workspace/call`, and keep the
current logged-in session. `call-access-privacy-foreign-data-contract.mjs`
pins those E2E assertions to the existing backend privacy contracts.
`audit-call-access-privacy-minimization-contract.php` runs the real IAM audit
helpers through a PDO test double and proves raw access/session identifiers,
host name, invitee data, SDP/ICE/token values, and call title are omitted while
access/session fingerprints and safe counts remain.

## Test Group: Email Confirmation

- [x] `e2e_email_001_update_request_sends_confirmation_email`
- [x] `e2e_email_002_email_sent_to_logged_in_account`
- [x] `e2e_email_003_email_not_sent_to_temp_account`
- [x] `e2e_email_004_confirmation_link_account_bound`
- [x] `e2e_email_005_confirmation_link_one_time_use`
- [x] `e2e_email_006_confirmation_link_time_limited`
- [x] `e2e_email_007_no_update_without_confirmation`
- [x] `e2e_email_008_confirmation_updates_only_reentered_data`
- [x] `e2e_email_009_expired_confirmation_link_no_update`
- [ ] `e2e_email_010_multiple_pending_confirmations_resolved`

## Test Group: Guest List

- [ ] `e2e_guest_list_001_add_registered_user_to_guest_list`
- [ ] `e2e_guest_list_002_add_temp_account_to_guest_list`
- [ ] `e2e_guest_list_003_remove_guest_list_entry`
- [ ] `e2e_guest_list_004_guest_list_user_direct_join`
- [ ] `e2e_guest_list_005_non_guest_user_no_direct_join`
- [ ] `e2e_guest_list_006_temp_guest_list_user_direct_join`
- [ ] `e2e_guest_list_007_guest_list_call_scoped`
- [x] `e2e_guest_list_008_guest_list_cross_org_not_valid`
- [ ] `e2e_guest_list_009_duplicate_guest_entries_handled`
- [ ] `e2e_guest_list_010_guest_list_changes_audit_logged`

## Test Group: Cross Organization

- [ ] `e2e_cross_org_001_user_a_opens_org_a_link`
- [ ] `e2e_cross_org_002_user_a_opens_org_b_link`
- [x] `e2e_cross_org_003_org_admin_a_opens_org_a_call`
- [x] `e2e_cross_org_004_org_admin_a_opens_org_b_call`
- [x] `e2e_cross_org_005_org_admin_a_no_admin_rights_in_org_b`
- [x] `e2e_cross_org_006_active_org_switch_does_not_change_server_permission`
- [x] `e2e_cross_org_007_guest_list_not_cross_org`
- [x] `e2e_cross_org_008_owner_rights_not_cross_org`
- [x] `e2e_cross_org_009_review_flags_correct_org`

## Test Group: Multi Session and Device

- [x] `e2e_multi_session_001_same_user_same_link_two_browsers`
- [x] `e2e_multi_session_002_same_user_same_link_two_devices`
- [x] `e2e_multi_session_003_different_user_same_link_other_device_flags`
- [x] `e2e_multi_session_004_concurrent_join_no_duplicate_participants`
- [x] `e2e_multi_session_005_login_switch_during_warning_modal_safe`
- [x] `e2e_multi_session_006_email_confirmation_other_browser_correct_account`
- [x] `e2e_multi_session_007_session_expiry_in_lobby_safe`
- [x] `e2e_multi_session_008_session_expiry_in_call_safe`
- [x] `e2e_multi_session_009_refresh_during_host_verification_safe`
- [x] `e2e_multi_session_010_refresh_during_pending_email_confirmation_safe`

## Test Group: Membership Revocation After Invitation

- [x] `e2e_membership_001_removed_invited_user_can_use_valid_invite_as_call_guest`
- [x] `e2e_membership_002_removed_invited_user_no_org_member_rights`
- [x] `e2e_membership_003_removed_invited_admin_no_org_admin_rights`
- [x] `e2e_membership_004_removed_invited_user_cannot_join_other_org_calls`
- [x] `e2e_membership_005_removed_invited_user_cannot_access_org_resources`
- [ ] `e2e_membership_006_removed_invited_user_blocked_after_invite_invalidation`
- [ ] `e2e_membership_007_removed_invited_user_blocked_after_call_deleted`
- [ ] `e2e_membership_008_removed_invited_user_blocked_after_call_ended`
- [ ] `e2e_membership_009_removed_invited_user_kick_overrides_invite`
- [ ] `e2e_membership_010_user_inside_call_remains_if_call_scoped_access`
- [ ] `e2e_membership_011_user_inside_call_loses_org_privileges_immediately`
- [ ] `e2e_membership_012_removed_org_admin_inside_call_loses_admin_controls`
- [ ] `e2e_membership_013_removed_user_rejoin_allowed_only_while_invite_valid`
- [ ] `e2e_membership_014_membership_removal_audit_logged`
- [x] `e2e_membership_015_stale_role_cache_ignored_after_membership_removal`

## Test Group: Invite Invalidation

- [x] `e2e_invite_invalid_001_personalized_link_invalidated_before_use`
- [x] `e2e_invite_invalid_002_personalized_link_invalidated_after_first_use`
- [ ] `e2e_invite_invalid_003_personalized_link_invalidated_in_lobby`
- [ ] `e2e_invite_invalid_004_personalized_link_invalidated_in_call`
- [ ] `e2e_invite_invalid_005_anonymous_link_invalidated_before_use`
- [ ] `e2e_invite_invalid_006_anonymous_link_invalidated_in_lobby`
- [ ] `e2e_invite_invalid_007_anonymous_link_invalidated_in_call`
- [x] `e2e_invite_invalid_008_invalidated_link_blocks_fresh_join`
- [x] `e2e_invite_invalid_009_invalidated_link_blocks_rejoin_if_required`
- [x] `e2e_invite_invalid_010_invalidated_link_no_data_leak`
- [ ] `e2e_invite_invalid_011_invalidated_link_does_not_recreate_temp_account`
- [ ] `e2e_invite_invalid_012_invalidated_link_survives_app_restart`

## Test Group: Guest Account Lifecycle

- [x] `e2e_guest_lifecycle_001_temp_guest_created_from_calendar_invite`
- [x] `e2e_guest_lifecycle_002_temp_guest_deleted_when_call_deleted`
- [x] `e2e_guest_lifecycle_003_temp_guest_deleted_when_invitation_deleted`
- [x] `e2e_guest_lifecycle_004_temp_guest_invalidated_when_link_invalidated`
- [x] `e2e_guest_lifecycle_005_temp_guest_handled_on_call_reschedule`
- [x] `e2e_guest_lifecycle_006_temp_guest_cannot_join_after_call_deleted`
- [x] `e2e_guest_lifecycle_007_temp_guest_cannot_join_after_call_ended`
- [x] `e2e_guest_lifecycle_008_temp_guest_cannot_rejoin_after_cleanup`
- [x] `e2e_guest_lifecycle_009_cleanup_does_not_delete_registered_accounts`
- [x] `e2e_guest_lifecycle_010_cleanup_scoped_to_call`
- [x] `e2e_guest_lifecycle_011_cleanup_idempotent`
- [x] `e2e_guest_lifecycle_012_cleanup_audit_logged`

## Test Group: Call Rescheduling

- [ ] `e2e_reschedule_001_owner_reschedules_before_guest_opens_link`
- [ ] `e2e_reschedule_002_owner_reschedules_while_guest_in_lobby`
- [x] `e2e_reschedule_003_owner_reschedules_while_guest_in_call`
- [x] `e2e_reschedule_004_old_personalized_link_invalidated`
- [x] `e2e_reschedule_005_new_personalized_link_works`
- [x] `e2e_reschedule_006_old_temp_guest_handled_by_product_rule`
- [x] `e2e_reschedule_007_old_link_cannot_join_stale_call`
- [x] `e2e_reschedule_008_registered_invitee_old_link_behavior`
- [ ] `e2e_reschedule_009_anonymous_link_behavior_after_reschedule`
- [ ] `e2e_reschedule_010_lobby_entries_migrated_or_cleared`
- [x] `e2e_reschedule_011_admitted_temp_participants_migrated_or_cleared`
- [x] `e2e_reschedule_012_reschedule_audit_logged`

## Test Group: Call Deletion

- [ ] `e2e_delete_001_owner_deletes_call_before_guests_join`
- [x] `e2e_delete_002_owner_deletes_call_with_guests_in_lobby`
- [x] `e2e_delete_003_owner_deletes_call_with_registered_users_inside`
- [x] `e2e_delete_004_owner_deletes_call_with_temp_guests_inside`
- [x] `e2e_delete_005_owner_deletes_call_with_anonymous_guests_inside`
- [x] `e2e_delete_006_deleted_call_blocks_owner_join`
- [x] `e2e_delete_007_deleted_call_blocks_org_admin_join`
- [x] `e2e_delete_008_deleted_call_blocks_system_admin_normal_join`
- [x] `e2e_delete_009_deleted_call_blocks_personalized_link`
- [x] `e2e_delete_010_deleted_call_blocks_anonymous_link`
- [x] `e2e_delete_011_deleted_call_blocks_admitted_guest_rejoin`
- [x] `e2e_delete_012_deleted_call_cleans_temp_guests`
- [x] `e2e_delete_013_deleted_call_clears_lobby`
- [x] `e2e_delete_014_deleted_call_preserves_audit_log`
- [x] `e2e_delete_015_deleted_call_does_not_affect_unrelated_calls`

## Test Group: Explicit Call End

- [x] `e2e_end_explicit_001_owner_explicitly_ends_call`
- [ ] `e2e_end_explicit_002_owner_leave_treated_as_call_end_if_configured`
- [x] `e2e_end_explicit_003_registered_participants_receive_ended_state`
- [x] `e2e_end_explicit_004_temp_guests_receive_ended_state`
- [x] `e2e_end_explicit_005_anonymous_guests_receive_ended_state`
- [x] `e2e_end_explicit_006_new_joins_blocked_after_end`
- [x] `e2e_end_explicit_007_rejoins_blocked_after_end`
- [x] `e2e_end_explicit_008_personalized_links_invalidated_after_end`
- [x] `e2e_end_explicit_009_anonymous_links_invalidated_after_end`
- [x] `e2e_end_explicit_010_temp_guests_cleaned_after_end`
- [x] `e2e_end_explicit_011_lobby_cleared_after_end`
- [x] `e2e_end_explicit_012_late_old_link_shows_safe_ended_state`

## Test Group: Implicit Call End by Owner Absence

- [x] `e2e_end_implicit_001_owner_disconnect_starts_absence_timer`
- [ ] `e2e_end_implicit_002_owner_tab_close_starts_absence_timer`
- [ ] `e2e_end_implicit_003_owner_process_kill_starts_absence_timer`
- [ ] `e2e_end_implicit_004_owner_network_loss_starts_absence_timer`
- [x] `e2e_end_implicit_005_no_countdown_before_10_min_equivalent`
- [x] `e2e_end_implicit_006_countdown_visible_at_10_min_equivalent`
- [x] `e2e_end_implicit_007_countdown_updates_over_time`
- [ ] `e2e_end_implicit_008_countdown_synchronized_across_participants`
- [ ] `e2e_end_implicit_009_countdown_survives_participant_refresh`
- [ ] `e2e_end_implicit_010_owner_rejoin_before_countdown_cancels_timer`
- [x] `e2e_end_implicit_011_owner_rejoin_during_countdown_cancels_timer`
- [x] `e2e_end_implicit_012_owner_absent_15_min_equivalent_ends_call`
- [x] `e2e_end_implicit_013_automatic_end_notifies_participants`
- [x] `e2e_end_implicit_014_automatic_end_blocks_new_joins`
- [x] `e2e_end_implicit_015_automatic_end_blocks_rejoins`
- [x] `e2e_end_implicit_016_automatic_end_invalidates_links`
- [x] `e2e_end_implicit_017_automatic_end_cleans_guest_accounts`
- [x] `e2e_end_implicit_018_timer_uses_server_time`
- [x] `e2e_end_implicit_019_ci_uses_test_clock_no_real_15_min_sleep`

## Test Group: King Containers

- [ ] `e2e_king_001_king_can_join_as_owner`
- [ ] `e2e_king_002_king_can_join_as_registered_user`
- [ ] `e2e_king_003_king_can_join_as_personalized_guest`
- [ ] `e2e_king_004_king_can_join_as_anonymous_guest`
- [ ] `e2e_king_005_king_streams_deterministic_dummy_media`
- [ ] `e2e_king_006_king_disconnects_gracefully`
- [ ] `e2e_king_007_king_simulates_abrupt_disconnect`
- [ ] `e2e_king_008_king_simulates_network_loss`
- [ ] `e2e_king_009_king_reconnects_same_identity`
- [ ] `e2e_king_010_king_exposes_call_state`
- [ ] `e2e_king_011_king_exposes_countdown_state`
- [ ] `e2e_king_012_king_logs_are_collected_on_failure`
- [ ] `e2e_king_013_multiple_king_containers_join_same_call`
- [ ] `e2e_king_014_king_containers_terminate_cleanly`

## Test Group: Audit and Monitoring

- [x] `e2e_audit_001_call_creation_logged`
- [x] `e2e_audit_002_invitation_creation_logged`
- [x] `e2e_audit_003_link_open_logged`
- [x] `e2e_audit_004_temp_account_creation_logged`
- [x] `e2e_audit_005_strong_mismatch_logged`
- [x] `e2e_audit_006_host_verification_logged`
- [x] `e2e_audit_007_account_update_logged`
- [ ] `e2e_audit_008_lobby_events_logged`
- [x] `e2e_audit_009_join_leave_rejoin_logged`
- [x] `e2e_audit_010_kick_logged`
- [x] `e2e_audit_011_owner_transfer_logged`
- [x] `e2e_audit_012_duplicate_link_flag_logged`
- [x] `e2e_audit_013_membership_removal_logged`
- [x] `e2e_audit_014_invite_invalidation_logged`
- [x] `e2e_audit_015_reschedule_logged`
- [x] `e2e_audit_016_delete_logged`
- [x] `e2e_audit_017_explicit_end_logged`
- [ ] `e2e_audit_018_implicit_end_logged`
- [ ] `e2e_audit_019_owner_absence_timer_logged`
- [x] `e2e_audit_020_logs_minimize_sensitive_data`

## Test Group: Main E2E Journeys

- [ ] `e2e_journey_001_unregistered_calendar_guest_lobby_admit_join_leave_rejoin`
- [x] `e2e_journey_002_registered_logged_out_invitee_uses_temp_account`
- [x] `e2e_journey_003_registered_logged_in_matching_invitee_joins_as_account`
- [ ] `e2e_journey_004_registered_logged_in_light_mismatch_joins_after_permission_check`
- [ ] `e2e_journey_005_foreign_personalized_link_wrong_host_no_data_leak`
- [ ] `e2e_journey_006_foreign_personalized_link_correct_host_decline_update`
- [ ] `e2e_journey_007_foreign_personalized_link_correct_host_update_confirm_email`
- [x] `e2e_journey_008_duplicate_personalized_link_review_flag`
- [x] `e2e_journey_009_logged_in_user_anonymous_link_uses_own_rights`
- [x] `e2e_journey_010_logged_out_user_anonymous_link_lobby_admit_rejoin`
- [x] `e2e_journey_011_system_admin_join_without_invite`
- [ ] `e2e_journey_012_org_admin_join_own_org_without_invite`
- [ ] `e2e_journey_013_org_admin_foreign_org_denied`
- [x] `e2e_journey_014_normal_guest_list_user_joins_foreign_call`
- [x] `e2e_journey_015_normal_non_guest_user_lobby_or_denied`
- [ ] `e2e_journey_016_normal_user_owner_transfer_loses_admin`
- [ ] `e2e_journey_017_org_admin_owner_transfer_keeps_admin`
- [x] `e2e_journey_018_temp_user_kicked_cannot_rejoin_directly`
- [ ] `e2e_journey_019_removed_org_member_invite_becomes_call_scoped_guest`
- [ ] `e2e_journey_020_invalidated_invite_link_denied`
- [x] `e2e_journey_021_rescheduled_call_old_link_invalid_new_link_valid`
- [x] `e2e_journey_022_deleted_call_revokes_all_temp_access`
- [x] `e2e_journey_023_explicit_call_end_revokes_all_join_paths`
- [x] `e2e_journey_024_owner_absence_countdown_then_auto_end`
- [x] `e2e_journey_025_owner_absence_countdown_then_reconnect_cancels_end`

---

# Definition of Done

- [x] E2E test suite is implemented or extended
- [x] Playwright or existing E2E framework is configured for CI
- [ ] CI job runs E2E suite automatically
- [x] CI starts all required services
- [x] CI starts media/signaling infrastructure if required
- [ ] CI starts `king` containers for multi-participant tests
- [ ] CI collects traces, screenshots, videos, and logs on failure
- [x] Test data is deterministic
- [x] Test data is isolated per test or safely reset
- [ ] Tests cover all critical IAM and call-access flows
- [ ] Tests cover invitation invalidation
- [x] Tests cover guest account cleanup
- [ ] Tests cover call rescheduling
- [ ] Tests cover call deletion
- [ ] Tests cover explicit call ending
- [x] Tests cover implicit owner-absence ending
- [x] Owner timeout tests do not wait 15 real minutes
- [ ] Duplicate personalized-link abuse detection is tested
- [x] Membership removal after invitation is tested with call-scoped guest access behavior
- [x] Privacy and data minimization assertions are included
- [ ] Security manipulation cases are covered
- [x] Audit-relevant flows are asserted where audit logs exist
- [x] Test names are stable and mapped to the checklist
- [ ] Documentation explains how to run tests locally
- [ ] Documentation explains how to run tests in CI

Proof: `iam-call-access-seeding.matrix.json` adds deterministic IAM/call-access
E2E seed principals, calls, access links, and scenario IDs without replacing the
live backend `call-access-join.spec.js`. `call-access-seed-matrix.spec.js`
exercises the public join/session browser path against the deterministic matrix,
checks temporary guests are not elevated to tenant/platform/system admin, and
asserts the call-access session payload contains no SDP, ICE, media token, or
TURN credential material.

Merged CI-smoke proof splits `test:e2e:call-access` from the broader chat/layout
matrix, keeps the live backend call-access spec plus seed-matrix spec in a
serial `--workers=1` Playwright script, and runs the compose smoke against
backend/ws/sfu service-DNS origins. Integrated reruns passed
`npm run test:e2e:call-access`, `npm run test:e2e:matrix`,
`npm run test:e2e:release-gate`, and `npm run test:contract:iam-call-access`
with host-PHP `pdo_sqlite` unavailable.
