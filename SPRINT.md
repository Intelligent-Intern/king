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
- Keep background filters stable across browser ML/GPU regressions with a
  deterministic fallback ladder: MediaPipe GPU when healthy, MediaPipe CPU when
  genuinely isolated, SINet/WASM when MediaPipe is unsafe, then a degraded mode
  that keeps the participant visible instead of blending them into the
  background.
- Preserve visual quality: no softmax/sigmoid participant blending, no ghost
  translucency, no full-person disappearance. Edge treatment must use contour
  alpha smoothing only.
- Make browser updates survivable: init failures, GPU service errors, model
  load failures, and worker crashes must quarantine the failing backend without
  reload loops, audio loss, or video publication failure.

Current baseline:
- Production no longer depends on the MediaPipe worker fallback path that hit
  Chrome GPU initialization failures.
- SINet/WASM exists as the insulated segmentation backend.
- WebGL and canvas compositors exist; WebGL may fall back to canvas.
- The current matte behavior can still over-apply the background and visually
  swallow the participant.
- Whiteboard Call App is deployed and installed; the Call Apps attach tab is now
  visible for resolved calls and requests a room snapshot after attach.

Contract anchors:
- `demo/video-chat/frontend-vue/src/domain/realtime/background/stream.ts`
- `demo/video-chat/frontend-vue/src/domain/realtime/background/backendSinetWasm.js`
- `demo/video-chat/frontend-vue/src/domain/realtime/background/maskPostprocess.js`
- `demo/video-chat/frontend-vue/src/domain/realtime/background/pipeline/compositorCanvasStage.js`
- `demo/video-chat/frontend-vue/src/domain/realtime/background/pipeline/compositorWebglStage.js`
- `demo/video-chat/frontend-vue/tests/standalone/king-background-segmentation-harness.ts`
- `demo/video-chat/frontend-vue/tests/contract/background-filter-mask-contract.mjs`
- `demo/video-chat/frontend-vue/tests/contract/background-king-wasm-contract.mjs`
- `demo/video-chat/frontend-vue/tests/contract/background-sinet-defaults-contract.mjs`
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
- Do not restore a brittle MediaPipe-only path as the only production backend.
- Do not add softmax, sigmoid, or whole-mask alpha curves that make a person
  semi-transparent.
- Do not grow `CallWorkspaceView.vue`; new behavior belongs in focused
  background backend, compositor, diagnostics, or harness modules.
- Do not turn background-filter failure into call failure. Camera, audio,
  screenshare, and reconnect must continue when segmentation is unavailable.

Acceptance criteria:
- Chrome/Chromium MediaPipe GPU-service init failures do not break calls.
- If GPU init fails, CPU/SINet/degraded fallback is selected exactly once per
  cooldown window without reload loops.
- The participant center remains opaque in the matte harness; only contour
  pixels receive alpha smoothing.
- No `Math.exp`, softmax, sigmoid, or equivalent probabilistic blending exists
  in the production fallback matte path.
- Background filter failure cannot mute audio, remove the local video track, or
  stop media publication.
- Diagnostics name the selected backend, failed backend, browser family, GPU
  availability, model source, fallback reason, and cooldown state.
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
- [ ] BGF-01 Browser regression matrix and reproducible failure capture
  - Capture Chrome Stable/Chromium Ubuntu/Firefox behavior for MediaPipe demo
    and King production paths.
  - Record exact browser versions, failing console signatures, backend choice,
    and whether CPU delegation still touches GPU internals.
  - Add a contract fixture for the known Chrome GPU-service init failure shape.

- [ ] BGF-02 Backend selection ladder with quarantine
  - Introduce a backend selector contract for MediaPipe GPU, MediaPipe CPU,
    SINet/WASM, and degraded mode.
  - Quarantine a failing backend for a bounded cooldown instead of retrying per
    frame.
  - Ensure backend switching is idempotent and cannot trigger reload loops.

- [ ] BGF-03 Matte correctness: hard foreground plus contour smoothing
  - Remove any remaining softmax/sigmoid-style probability blending from the
    fallback path.
  - Treat foreground/background classification as hard membership, then apply
    alpha only on the contour band.
  - Add harness checks that the torso/face center stays opaque and background
    pixels do not leak into the participant.

- [ ] BGF-04 Degraded mode that preserves the person
  - Define degraded mode as "no synthetic replacement over the participant" when
    segmentation confidence or backend health is unsafe.
  - Prefer original camera video with clear diagnostics over a broken matte that
    hides the participant.
  - Keep user media tracks alive while the filter is disabled or warming up.

- [ ] BGF-05 Compositor and warmup safety
  - Make WebGL/canvas compositor warmup deterministic across backend changes.
  - Avoid stale-mask freeze, blue-screen swallow, and one-frame full-background
    flashes.
  - Add pixel-level compositor contracts for warmup, backend switch, and
    segmentation-unavailable states.

- [ ] BGF-06 Runtime diagnostics and field observability
  - Emit throttled diagnostics for backend init, fallback transition, quarantine,
    matte rejection, and degraded mode.
  - Include enough local context to debug browser regressions without leaking
    media frames, SDP, ICE, or tokens.
  - Surface concise state in existing diagnostics channels, not new reload UI.

- [ ] BGF-07 Online proof, deploy, and browser smoke
  - Run focused background contracts, build, deploy, and production smoke.
  - Verify a real call in Chrome/Chromium and Firefox with camera, audio,
    screenshare, reconnect, and background filter transitions.
  - Record proof commands and results in this sprint before closing.

- [ ] BGF-08 KingRT Domain Contract Cutover
  - Split deploy configuration into `kingrt.com` as the base domain and
    `app.kingrt.com` as the frontend application domain.
  - Serve production services only on `api.kingrt.com`, `ws.kingrt.com`,
    `sfu.kingrt.com`, `cdn.kingrt.com`, `turn.kingrt.com`, and
    `registry.kingrt.com`.
  - Hard-remove old nested service domains such as `cdn.app.kingrt.com` and
    `api.app.kingrt.com`; do not keep aliases for the cutover.
  - Add a domain-contract test that fails if any generated service domain ends
    in `.app.kingrt.com`.

- [ ] BGF-09 Call App Hosting and Semantic Registry
  - Host Whiteboard at `whiteboard.kingrt.com` and resolve future Call Apps as
    `{app_key}.kingrt.com`.
  - Reserve service names such as `app`, `api`, `ws`, `sfu`, `cdn`, `turn`,
    and `registry` so Call Apps cannot claim platform domains.
  - Use `registry.kingrt.com` as the dev-key-approved registry for Call App
    registration, Semantic DNS, and mothernode join announcements.
  - Allow self-hosted Call App manifests to declare a private mothernode that
    is not part of the KingRT network.

- [ ] BGF-10 Whiteboard Marketplace Production Proof
  - Ensure Whiteboard is visible in the production Marketplace.
  - Ensure the add-to-organization/install action is present and persists
    backend entitlements/installations.
  - Ensure Whiteboard appears in the Call Apps tab for calls owned by that
    organization after installation.

- [ ] BGF-11 sicherstellen, dass whiteboard auch bei kingrt.com einer orga zugefügt werden kann
  - Run the production `kingrt.com` Marketplace journey end to end.
  - Prove that a real organization can add Whiteboard from Marketplace and use
    it inside a call without manual database edits.
  - Record the production proof command/output before closing the sprint.

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
  `gossip-dedicated-neighbor-lifecycle-contract.mjs`.
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

- [ ] Organization can be created
- [ ] User can be registered in an organization
- [ ] User can have organization role `User`
- [ ] User can have organization role `Admin`
- [ ] User with role `User` can log in
- [ ] User with role `Admin` can log in
- [ ] User can be logged out
- [ ] Logged-in user remains logged in when opening a call link
- [ ] Logged-out user has no active account session
- [ ] User without organization cannot receive organization-based rights
- [ ] User from organization A does not receive rights from organization B
- [ ] Organization admin from organization A cannot join organization B calls through org-admin rights
- [ ] Organization role is evaluated server-side
- [ ] Stale organization role in client cache is ignored
- [ ] Stale organization role in session token is revalidated where required

## 2. Call Creation and Owner Rights

- [ ] Registered user with role `User` can create own call
- [ ] Registered user with role `Admin` can create own call
- [ ] Call creator becomes call owner
- [ ] Call creator receives admin rights in own call
- [ ] Owner can add users to guest list
- [ ] Owner can manage guest list
- [ ] Owner can admit lobby participants
- [ ] Owner can remove / kick participants
- [ ] Owner rights can be transferred to another user
- [ ] If organization role `User` transfers owner rights, old owner loses call-admin rights
- [ ] If organization role `Admin` transfers owner rights, old owner keeps admin rights
- [ ] New owner receives owner rights
- [ ] New owner receives admin rights in call
- [ ] After owner transfer, there is exactly one current owner
- [ ] Former owner without admin role can no longer perform owner actions
- [ ] Organization admin can keep call-admin rights after owner transfer
- [ ] Owner rights cannot be transferred to a non-existent user
- [ ] Owner rights cannot be transferred across forbidden organization boundaries
- [ ] Owner transfer is audit-logged

## 3. Join Permissions

- [ ] System admin can join every active call
- [ ] System admin can join without guest-list entry
- [ ] System admin can join without invitation
- [ ] System admin cannot join deleted call through normal join flow
- [ ] System admin cannot join ended call through normal join flow
- [ ] Organization admin can join every active call of own organization
- [ ] Organization admin can join own organization call without guest-list entry
- [ ] Organization admin cannot join another organization’s call through org-admin rights
- [ ] User can join call when on guest list
- [ ] User cannot directly join call when not on guest list
- [ ] User can join own call as owner
- [ ] User cannot directly join unrelated foreign call
- [ ] Deleted / disabled user cannot join
- [ ] Removed guest-list entry revokes direct join access
- [ ] Newly added guest-list entry grants direct join access
- [ ] Permissions are checked server-side
- [ ] Manipulated client role does not grant access
- [ ] Manipulated call ID does not grant access to another call

## 4. Calendar Invitation Flow

- [ ] Host can invite person through calendar flow
- [ ] Invitee can select appointment in calendar form
- [ ] Invitee can be registered and logged in
- [ ] Invitee can be registered and logged out
- [ ] Invitee can be unregistered
- [ ] Temporary account is created for non-logged-in invitee
- [ ] Temporary account contains form data
- [ ] Personalized call link is associated with temporary account
- [ ] Personalized call link is unique
- [ ] Personalized call link is not guessable
- [ ] Personalized call link is server-side bound to temporary account
- [ ] Calendar appointment is correctly associated with call / host
- [ ] Multiple invitees receive different personalized links
- [ ] Appointment change does not modify unrelated invitations
- [ ] Invitation cancellation invalidates personalized link
- [ ] Expired personalized link cannot be used if expiry exists
- [ ] Reopening same personalized link by same valid context behaves consistently

## 5. Personalized Link: User Not Logged In

- [ ] Not logged-in user opens personalized link
- [ ] Temporary account from link data is created / used
- [ ] Temporary account does not automatically log in existing registered account
- [ ] User enters intended flow with temporary account
- [ ] Temporary account may be on guest list
- [ ] Temporary account on guest list can join directly
- [ ] Temporary account not on guest list lands in lobby
- [ ] Temporary account cannot see other users’ data
- [ ] Temporary account receives no registered account rights
- [ ] Temporary account cannot administer call unless explicitly permitted
- [ ] Temporary account cannot assume another identity by changing link parameters
- [ ] Temporary account remains consistent for same link / call
- [ ] Temporary account can be recognized after leaving
- [ ] Temporary account cannot receive organization-wide rights
- [ ] Invalid personalized link is rejected
- [ ] Manipulated personalized link is rejected
- [ ] Error state for invalid personalized link leaks no data

## 6. Personalized Link: Logged-In User, No / Light Mismatch

- [ ] Logged-in user opens personalized link
- [ ] Logged-in account remains active
- [ ] Temporary link account does not replace active session
- [ ] Link account is compared with logged-in account
- [ ] No mismatch does not show warning modal
- [ ] Light mismatch does not show strong foreign-link warning
- [ ] Logged-in account is used for call
- [ ] Temporary account is not set as active session
- [ ] Permission check uses logged-in account
- [ ] User can join if logged-in account is authorized
- [ ] User lands in lobby if logged-in account is not directly authorized
- [ ] Temporary link data does not overwrite account data automatically
- [ ] Light mismatches are optionally logged
- [ ] No link data is unnecessarily exposed in frontend
- [ ] Same logged-in account can reopen same link without duplicate-link flag

## 7. Personalized Link: Logged-In User, Strong Mismatch

- [ ] Logged-in user opens personalized link with strongly different link data
- [ ] Strong mismatch is detected when first name differs
- [ ] Strong mismatch is detected when last name differs
- [ ] Strong mismatch is detected when first and last name differ
- [ ] Warning modal is displayed
- [ ] Warning modal explains link may have been issued for someone else
- [ ] Warning modal explains link data differs from account data
- [ ] Warning modal asks for host name
- [ ] Link data of other person is not displayed
- [ ] Differing link data is not exposed in clear text
- [ ] Host name is verified server-side
- [ ] Wrong host name grants no direct access
- [ ] Wrong host name does not reveal foreign data
- [ ] Wrong host name may lead to lobby / manual review
- [ ] Correct host name is accepted
- [ ] Correct host name shows success confirmation
- [ ] After correct host name, user is asked whether account data should be updated
- [ ] User can decline update
- [ ] Declining update leaves logged-in account unchanged
- [ ] Declining update continues with logged-in account
- [ ] User can request account update
- [ ] User must re-enter differing values manually
- [ ] System does not show differing link values
- [ ] System does not show data from guessed / foreign link
- [ ] Email confirmation is sent to logged-in account email
- [ ] Email is not sent to temporary link-account email
- [ ] Without email confirmation, account data is not updated
- [ ] With email confirmation, only confirmed data is updated
- [ ] After update, user remains logged in as original account
- [ ] Update does not modify temporary foreign account
- [ ] Update does not modify other registered accounts
- [ ] Flow is audit-logged
- [ ] Host-name brute force is rate-limited
- [ ] Repeated wrong host names trigger lock / review if configured
- [ ] Host-name error messages leak no host data

## 8. Duplicate Personalized Link / Abuse Detection

- [ ] Personalized link is first opened by account A
- [ ] Same personalized link is reopened by account A
- [ ] Reuse by same account does not create false foreign-account flag
- [ ] Same personalized link is later opened by account B
- [ ] Use of same personalized link by different logged-in account is detected
- [ ] Account B is flagged for review
- [ ] Account A may appear as affected reference in audit log
- [ ] Flag is created even if account B provides correct host name
- [ ] Flag is created even if account B does not enter the call
- [ ] Flag is created when account B reaches warning modal if policy requires it
- [ ] Concurrent use of same personalized link by two accounts is detected
- [ ] Race condition on parallel link open creates no inconsistent assignment
- [ ] Link already used inside call marks later use by other account as suspicious
- [ ] Temporary account cannot be taken over by second registered account without review
- [ ] Review flag contains call, link ID, affected accounts, and timestamps
- [ ] Review flag contains no unnecessary sensitive link data
- [ ] Admin / reviewer can understand the flag
- [ ] Abuse detection works after logout / login switch in same browser
- [ ] Abuse detection works across devices
- [ ] Abuse detection works across browsers

## 9. Anonymous Join Link: User Logged In

- [ ] Logged-in user opens anonymous join link
- [ ] Temporary account is not permanently created or is removed
- [ ] User joins as logged-in user
- [ ] Logged-in user’s rights are used
- [ ] Logged-in user receives no rights from anonymous link
- [ ] Logged-in user can join if own rights allow it
- [ ] Logged-in user lands in lobby if no direct permission exists
- [ ] Logged-in system admin can join every active call through anonymous link
- [ ] Logged-in organization admin can join own organization calls through anonymous link
- [ ] Logged-in organization admin cannot join foreign organization calls through anonymous link
- [ ] Logged-in guest-list user can join through anonymous link
- [ ] Logged-in user not on guest list lands in lobby through anonymous link
- [ ] Anonymous link does not overwrite account data
- [ ] Anonymous link does not modify guest list
- [ ] Anonymous link creates no personalized identity binding
- [ ] Invalid anonymous link is rejected
- [ ] Manipulated anonymous link grants no access

## 10. Anonymous Join Link: User Not Logged In

- [ ] Not logged-in user opens anonymous join link
- [ ] Temporary anonymous account is created
- [ ] Temporary anonymous account lands in lobby
- [ ] Temporary anonymous account receives no registered user rights
- [ ] Temporary anonymous account receives no organization rights
- [ ] Temporary anonymous account receives no owner rights
- [ ] Lobby shows waiting anonymous user according to privacy rules
- [ ] Host can admit anonymous user
- [ ] Temporary moderator can admit anonymous user
- [ ] Admin can admit anonymous user
- [ ] Unauthorized participant cannot admit anonymous user
- [ ] After admission, anonymous temporary user enters call
- [ ] If admitted anonymous user leaves and was not kicked, they can rejoin
- [ ] Rejoin after admission does not require another approval
- [ ] If anonymous user was kicked, rejoin requires approval or is blocked
- [ ] Anonymous temporary user cannot gain rights by changing display name
- [ ] Multiple anonymous users through same link are separate temporary participants
- [ ] Anonymous link does not reveal guest list or account data
- [ ] Anonymous link can be disabled if supported
- [ ] Disabled anonymous link allows no lobby entry

## 11. Lobby and Admission

- [ ] User without direct permission lands in lobby
- [ ] Anonymous not logged-in user lands in lobby
- [ ] Personalized temporary user without direct permission lands in lobby
- [ ] Logged-in user without direct permission lands in lobby
- [ ] Lobby entry informs host / authorized moderators
- [ ] Host sees waiting participant
- [ ] Temporary moderator sees waiting participant
- [ ] Organization admin sees waiting participant for own organization call
- [ ] System admin sees waiting participant
- [ ] Unauthorized user sees no lobby management controls
- [ ] Host can admit participant
- [ ] Temporary moderator can admit participant
- [ ] Organization admin can admit participant
- [ ] System admin can admit participant
- [ ] Host can reject participant
- [ ] Temporary moderator can reject participant
- [ ] Organization admin can reject participant
- [ ] System admin can reject participant
- [ ] Rejected participant cannot enter call
- [ ] Admitted participant enters call
- [ ] Admission is stored call-scoped
- [ ] Admission does not apply to other calls
- [ ] Admission does not apply to other organizations
- [ ] Temporary user admission applies only to same temporary user / link context
- [ ] Concurrent admission by multiple moderators creates no error state
- [ ] Concurrent rejection and admission resolves deterministically
- [ ] Lobby status updates correctly
- [ ] Participant is removed from lobby after admission
- [ ] Participant is removed from lobby after aborting join attempt
- [ ] Participant is not shown twice in lobby
- [ ] Manipulated lobby-admission request without permission is rejected

## 12. Rejoin, Leave, Kick

- [ ] Admitted temporary user can leave call
- [ ] Admitted temporary user can reopen same call
- [ ] Admitted temporary user can rejoin without approval
- [ ] Rejoin works after browser refresh
- [ ] Rejoin works after short network interruption
- [ ] Rejoin works after closing tab and reopening if session remains
- [ ] Rejoin does not work as another user with same temporary context if account binding is violated
- [ ] Kicked temporary user cannot directly rejoin
- [ ] Kicked temporary user lands back in lobby or is blocked
- [ ] Kicked logged-in user cannot immediately reenter through same link if kick overrides access
- [ ] Kick state overrides previous admission
- [ ] Kick state is stored server-side
- [ ] Kick state is scoped to affected call if intended
- [ ] Kick state is scoped to affected user / temporary account
- [ ] Registered authorized user can rejoin after leaving
- [ ] Admin can rejoin after leaving
- [ ] Organization admin can rejoin after leaving
- [ ] Guest-list user can rejoin after leaving
- [ ] Rejoin after guest-list removal is denied or routed to lobby
- [ ] Rejoin after admin-role removal uses updated permissions
- [ ] Rejoin after owner transfer uses updated permissions

## 13. Temporary Moderators

- [ ] Host can assign temporary moderator if supported
- [ ] Temporary moderator can admit lobby participants
- [ ] Temporary moderator can reject lobby participants
- [ ] Temporary moderator can only moderate assigned call
- [ ] Temporary moderator cannot perform organization-wide admin actions
- [ ] Temporary moderator cannot transfer owner rights unless allowed
- [ ] Temporary moderator cannot modify guest list outside permissions
- [ ] Temporary moderator loses rights after moderation ends
- [ ] Temporary moderator loses rights after call end if configured
- [ ] Revoked temporary moderator rights take effect immediately
- [ ] Manipulated temporary-moderator role in client is rejected server-side

## 14. Privacy and Data Minimization

- [ ] Foreign link data is not shown on strong mismatch
- [ ] Differing data is not shown as comparison list
- [ ] User must re-enter differing data manually
- [ ] Guessed link reveals no personal data
- [ ] Invalid link reveals no personal data
- [ ] Wrong host name reveals no personal data
- [ ] Account data is updated only after email confirmation
- [ ] Email confirmation goes only to logged-in account
- [ ] Temporary account data is not persisted unnecessarily
- [ ] Temporary accounts are removed when logged-in user uses anonymous link
- [ ] Temporary accounts are not merged with wrong registered account
- [ ] Audit logs contain only necessary personal data
- [ ] Frontend state contains no foreign link data
- [ ] API responses contain no foreign link data
- [ ] Browser DevTools / network response contains no foreign link data
- [ ] Error messages contain no foreign link data
- [ ] Email texts contain no foreign link data unless explicitly safe and necessary
- [ ] Host-name verification does not allow host enumeration
- [ ] Rate limits protect sensitive verification paths
- [ ] Privacy-relevant actions are logged

## 15. Security and Manipulation Cases

- [ ] Personalized link with modified link ID is rejected
- [ ] Personalized link with modified call ID is rejected
- [ ] Anonymous link with modified call ID is rejected
- [ ] Expired link is rejected
- [ ] Disabled link is rejected
- [ ] Deleted temporary account cannot be revived through old link
- [ ] API request with forged user ID is rejected
- [ ] API request with forged role parameter is rejected
- [ ] API request with forged organization parameter is rejected
- [ ] API request with foreign call ID is rejected
- [ ] Owner transfer request without owner/admin right is rejected
- [ ] Lobby admission request without moderator right is rejected
- [ ] Kick request without moderator right is rejected
- [ ] Account-data update request without email confirmation is rejected
- [ ] Replay of old email confirmation link is prevented
- [ ] Email confirmation link is one-time use
- [ ] Email confirmation link is time-limited
- [ ] CSRF protection works for account-data change
- [ ] Session fixation during link opening is prevented
- [ ] Login switch during link verification does not cause wrong account binding
- [ ] Logout during link verification causes no data leak
- [ ] Parallel tabs with different accounts cause no incorrect merge
- [ ] Permission changes during active call are applied correctly
- [ ] Owner transfer during active call is applied correctly
- [ ] Guest-list change during active call is applied correctly
- [ ] Kick during active call removes user
- [ ] Deleted call cannot be entered
- [ ] Ended call cannot be entered

## 16. Email Confirmation for Account Data Update

- [ ] Email is triggered only after explicit update request
- [ ] Email is sent to logged-in account
- [ ] Email is not sent to temporary account
- [ ] Email contains secure confirmation link
- [ ] Confirmation link is account-bound
- [ ] Confirmation link cannot be used by another logged-in account
- [ ] Confirmation link is time-limited
- [ ] Confirmation link is one-time use
- [ ] Without confirmation, account data remains unchanged
- [ ] After confirmation, only re-entered data is updated
- [ ] Confirmation success state is shown
- [ ] Expired confirmation link updates no data
- [ ] Already used confirmation link updates no data again
- [ ] Confirmation is audit-logged
- [ ] Failed confirmation shows no sensitive data
- [ ] While confirmation is pending, user can continue with original account
- [ ] Multiple pending confirmations are handled correctly
- [ ] Newer change invalidates older confirmation if configured
- [ ] Race condition between two confirmations resolves deterministically

## 17. Guest List

- [ ] Host can add registered user to guest list
- [ ] Host can add temporary invited account to guest list
- [ ] Host can remove guest-list entry
- [ ] User on guest list can directly join
- [ ] User not on guest list cannot directly join
- [ ] Temporary account on guest list can directly join
- [ ] Temporary account not on guest list lands in lobby
- [ ] Organization admin does not need guest-list entry for own organization call
- [ ] System admin does not need guest-list entry
- [ ] Guest list is call-scoped
- [ ] Guest list of one call grants no rights to another call
- [ ] Guest list of one organization grants no rights to another organization
- [ ] Duplicate guest-list entries are prevented or merged
- [ ] Removing guest-list entry affects new join attempts immediately
- [ ] Removing guest-list entry during active call follows product rule
- [ ] Guest-list changes are audit-logged

## 18. System Admin

- [ ] System admin can join call from every organization
- [ ] System admin can join call without organization if such calls exist
- [ ] System admin can join without guest-list entry
- [ ] System admin can manage lobby
- [ ] System admin can admit participants
- [ ] System admin can reject participants
- [ ] System admin can kick participants
- [ ] System admin can view / handle review flags if supported
- [ ] System admin rights are never granted to temporary accounts
- [ ] System admin rights cannot be simulated through link data
- [ ] System admin rights remain after owner transfer
- [ ] System admin cannot be degraded through call-owner transfer

## 19. Organization Admin

- [ ] Organization admin can join every active call of own organization
- [ ] Organization admin can join own organization call without guest-list entry
- [ ] Organization admin can manage lobby for own organization calls
- [ ] Organization admin can admit participants for own organization calls
- [ ] Organization admin can reject participants for own organization calls
- [ ] Organization admin can kick participants for own organization calls
- [ ] Organization admin cannot join foreign organization calls through this role
- [ ] Organization admin cannot manage lobby of foreign organization
- [ ] Organization admin rights remain after owner transfer
- [ ] Organization admin can transfer owner rights if allowed
- [ ] Organization admin keeps admin rights when transferring ownership
- [ ] Revoking organization-admin role affects new joins and admin actions immediately
- [ ] Organization admin rights cannot be expanded through manipulated organization ID

## 20. Normal User

- [ ] Normal user can create own call
- [ ] Normal user becomes owner of own call
- [ ] Normal user has admin rights in own call
- [ ] Normal user can join foreign call only when authorized
- [ ] Normal user can join foreign call when on guest list
- [ ] Normal user cannot join foreign call when not on guest list
- [ ] Normal user cannot manage foreign lobby
- [ ] Normal user can admit participants in own call
- [ ] Normal user can reject participants in own call
- [ ] Normal user can kick participants in own call
- [ ] Normal user loses call-admin rights when transferring ownership
- [ ] Normal user cannot perform owner actions after owner transfer
- [ ] Normal user keeps no hidden admin rights after owner transfer
- [ ] Normal user cannot receive admin rights through anonymous link
- [ ] Normal user cannot receive admin rights through personalized link

## 21. Cross-Organization Cases

- [ ] User from organization A opens personalized link for organization A call
- [ ] User from organization A opens personalized link for organization B call
- [ ] User from organization A opens anonymous link for organization B call
- [ ] Organization admin from organization A opens link to organization A call
- [ ] Organization admin from organization A opens link to organization B call
- [ ] Organization admin from organization A receives no org-admin rights in organization B call
- [ ] User with accounts in multiple organizations is checked in correct call context
- [ ] Changing active organization in frontend does not change server-side call permission
- [ ] Guest-list entry in organization A does not apply to organization B
- [ ] Temporary account from organization A invitation receives no rights in organization B
- [ ] Owner rights of organization A call do not apply to organization B call
- [ ] Review flags are assigned to correct organization / call

## 22. Multi-Session, Devices, Browsers

- [ ] Logged-in user opens personalized link in browser A
- [ ] Same user opens same link in browser B
- [ ] Same user opens same link on another device
- [ ] Different user opens same personalized link on another device
- [ ] Different active session triggers review flag
- [ ] Not logged-in user opens same personalized link on another device
- [ ] Parallel use of same temporary account is handled correctly
- [ ] Concurrent join attempts create no duplicate participants
- [ ] Logout in one tab affects link verification in another tab correctly
- [ ] Login switch during warning modal is handled correctly
- [ ] Email confirmation in another browser updates correct account
- [ ] Session expiry while waiting in lobby is handled correctly
- [ ] Session expiry during call creates defined state
- [ ] Refresh during host-name verification creates defined state
- [ ] Refresh while email confirmation is pending creates defined state

## 23. Organization Membership Changes After Invitation

- [ ] Invited registered user is removed from organization before opening personalized invite link
- [ ] Removed invited user can still open still-valid personalized invite link
- [ ] Removed invited user joins only as call-scoped invited guest
- [ ] Removed invited user does not retain organization-member rights
- [ ] Removed invited user does not retain organization-admin rights
- [ ] Removed invited user cannot join other organization calls
- [ ] Removed invited user cannot access organization resources
- [ ] Removed invited user cannot manage call unless separately owner/moderator
- [ ] Removed invited user cannot use stale role data from token/session/cache
- [ ] Removed invited user is blocked if invite was manually invalidated
- [ ] Removed invited user is blocked if call was deleted
- [ ] Removed invited user is blocked if call was ended
- [ ] Removed invited user is blocked or routed according to policy if kicked
- [ ] User already inside call remains connected after org removal if access was call-scoped
- [ ] User already inside call immediately loses organization-level privileges after removal
- [ ] Removed org-admin already inside call loses org-admin controls immediately
- [ ] Removed org-admin already inside call remains only if explicit call-scoped access exists
- [ ] Removed user can leave and rejoin same call only while invitation remains valid
- [ ] Removed user cannot rejoin after invite invalidation
- [ ] Audit log records membership removal
- [ ] Audit log records permission downgrade
- [ ] Audit log records continued call-scoped access
- [ ] User invited as org member but later moved to another organization joins only through call-scoped invitation
- [ ] User invited as org admin but later downgraded to user loses org-admin access but keeps explicit invite access
- [ ] User invited as normal user but later promoted to org admin receives current org-admin rights if still member
- [ ] Removed org admin cannot use org-admin rights from stale invite payload
- [ ] Removed user in lobby loses org-based rights but may remain in lobby through call-scoped invitation

## 24. Invite Link Invalidation

- [ ] Personalized invite link is manually invalidated before use
- [ ] Personalized invite link is invalidated after first use
- [ ] Personalized invite link is invalidated while invitee is in lobby
- [ ] Personalized invite link is invalidated while invitee is already in call
- [ ] Anonymous join link is manually invalidated before use
- [ ] Anonymous join link is invalidated while anonymous guest is in lobby
- [ ] Anonymous join link is invalidated while anonymous guest is already in call
- [ ] Invalidated link cannot be used for fresh join attempts
- [ ] Invalidated link cannot be used for rejoin unless product rule allows admitted rejoin
- [ ] Invalidated link does not reveal whether original invitee exists
- [ ] Invalidated link does not reveal guest account data
- [ ] Invalidated link does not recreate deleted temporary accounts
- [ ] Invalidated link state is enforced server-side
- [ ] Invalidated link state works across browsers
- [ ] Invalidated link state works across devices
- [ ] Invalidated link state works across sessions
- [ ] Invalidated link state survives application restart during CI
- [ ] Rejected invalidated link shows safe invalid-link state
- [ ] Rejected invalidated link does not leak personal data
- [ ] Stale client-side state cannot join with invalidated link

## 25. Guest Account Lifecycle

- [ ] Guest account is created from personalized calendar invitation
- [ ] Guest account is deleted when call is deleted
- [ ] Guest account is deleted or invalidated when invitation is deleted
- [ ] Guest account is deleted or invalidated when invite link is manually invalidated
- [ ] Guest account is updated, recreated, or invalidated when call is rescheduled according to product rule
- [ ] Guest account cannot join original call after call was rescheduled and original link invalidated
- [ ] Guest account cannot join after call was deleted
- [ ] Guest account cannot join after call was ended
- [ ] Guest account cannot rejoin after cleanup
- [ ] Guest account cannot be used to infer deleted call data
- [ ] Guest account cleanup does not delete registered user accounts
- [ ] Guest account cleanup does not alter registered user profile data
- [ ] Guest account cleanup does not remove unrelated temporary guests from other calls
- [ ] Guest account cleanup is scoped to affected call / invitation
- [ ] Guest account cleanup is idempotent
- [ ] Guest account cleanup is audit-logged
- [ ] Old guest account cannot be revived through old personalized link
- [ ] Old guest account cannot be revived through stale browser state
- [ ] Old guest account cannot be revived after application restart

## 26. Call Rescheduling

- [ ] Owner reschedules call before guest opens invite link
- [ ] Owner reschedules call while guest is in lobby
- [ ] Owner reschedules call while guest is already inside call
- [ ] Personalized invite link from old time is invalidated after reschedule if required
- [ ] New personalized invite link is issued after reschedule if required
- [ ] Old temporary guest account is deleted, invalidated, or migrated according to product rule
- [ ] Guest using old link after reschedule cannot join stale call state
- [ ] Guest using new link after reschedule can join according to current permissions
- [ ] Registered invited user receives correct behavior when using old link after reschedule
- [ ] Anonymous join link behavior after reschedule is tested
- [ ] Lobby entries from old schedule are cleared or migrated according to product rule
- [ ] Admitted temporary participants from old schedule are cleared or migrated according to product rule
- [ ] Audit log records reschedule
- [ ] Audit log records related invite cleanup
- [ ] Audit log records guest cleanup
- [ ] Stale links do not join users into wrong call instance
- [ ] Frontend shows safe and clear state for old links

## 27. Call Deletion

- [ ] Owner deletes call before any guest joins
- [ ] Owner deletes call while guests are in lobby
- [ ] Owner deletes call while registered users are inside
- [ ] Owner deletes call while temporary guests are inside
- [ ] Owner deletes call while anonymous guests are inside
- [ ] Deleted call cannot be joined by owner
- [ ] Deleted call cannot be joined by organization admin
- [ ] Deleted call cannot be joined by system admin through normal join flow
- [ ] Deleted call cannot be joined through personalized invite link
- [ ] Deleted call cannot be joined through anonymous join link
- [ ] Deleted call cannot be rejoined by previously admitted guest
- [ ] Deleted call removes or invalidates temporary guest accounts
- [ ] Deleted call clears lobby entries
- [ ] Deleted call clears admitted temporary participant state
- [ ] Deleted call preserves audit log
- [ ] Deleted call does not delete registered user accounts
- [ ] Deleted call does not delete unrelated calls
- [ ] Deleted call does not delete unrelated guests
- [ ] Users currently in call are disconnected or moved into safe deleted state
- [ ] Deleted call metadata is not leaked to unauthorized users

## 28. Explicit Call Ending

- [ ] Owner explicitly ends call
- [ ] Owner leaves call and product treats this as explicit call end
- [ ] Active registered participants receive ended state
- [ ] Active temporary guests receive ended state
- [ ] Active anonymous guests receive ended state
- [ ] New joins are blocked after explicit end
- [ ] Rejoins are blocked after explicit end
- [ ] Personalized invite links are invalidated after explicit end
- [ ] Anonymous join links are invalidated after explicit end
- [ ] Temporary guest accounts are deleted or invalidated after explicit end
- [ ] Lobby entries are cleared after explicit end
- [ ] Audit log is preserved after explicit end
- [ ] Organization admin cannot bypass ended-call state through normal join
- [ ] System admin cannot bypass ended-call state through normal join unless explicit recovery/debug path exists
- [ ] Late user opening old link sees safe ended-call state

## 29. Implicit Call Ending by Owner Absence

- [ ] Owner loses connection
- [ ] Owner closes browser tab
- [ ] Owner browser crashes or context is killed
- [ ] Owner network is disconnected
- [ ] Owner is absent for less than 10 minutes equivalent
- [ ] Owner is absent for 10 minutes equivalent
- [ ] Owner is absent for 15 minutes equivalent
- [ ] Owner rejoins before final 5-minute countdown starts
- [ ] Owner rejoins during final 5-minute countdown
- [ ] Owner does not rejoin before timer expires
- [ ] Call ends automatically after 15 minutes owner absence equivalent
- [ ] Participants are notified when owner absence timer starts if applicable
- [ ] Participants see visible countdown during last 5 minutes
- [ ] Countdown starts when 5 minutes remain
- [ ] Countdown shows correct remaining time
- [ ] Countdown updates correctly over time
- [ ] Countdown survives participant refresh
- [ ] Countdown is synchronized across participants
- [ ] Countdown does not reveal admin-only data
- [ ] Countdown disappears if owner rejoins
- [ ] Call does not end if owner rejoins before timeout
- [ ] Call ends if owner does not rejoin before timeout
- [ ] Call-ended state prevents new joins
- [ ] Call-ended state prevents rejoins
- [ ] Call-ended state invalidates anonymous join link
- [ ] Call-ended state invalidates personalized invite links
- [ ] Call-ended state deletes or invalidates temporary guest accounts
- [ ] Call-ended state clears lobby entries
- [ ] Call-ended state preserves audit log
- [ ] Call-ended state is visible to late users opening old links
- [ ] Timer is based on server time, not client time
- [ ] CI test uses fake/test time and does not wait 15 real minutes

## 30. Error and Edge Cases

- [ ] Call does not exist
- [ ] Call was deleted
- [ ] Call was ended
- [ ] Call has not started yet if time-limited
- [ ] Call has expired if time-limited
- [ ] Organization does not exist
- [ ] Organization is disabled
- [ ] Host no longer exists
- [ ] Host is disabled
- [ ] Invited temporary account was deleted
- [ ] Registered account was disabled
- [ ] Registered account was deleted
- [ ] User email is unconfirmed if relevant
- [ ] Calendar appointment was cancelled
- [ ] Calendar appointment was moved
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

## 31. Audit and Monitoring

- [ ] Call creation is logged
- [ ] Invitation creation is logged
- [ ] Personalized link open is logged
- [ ] Anonymous link open is logged
- [ ] Temporary account creation is logged
- [ ] Temporary account removal is logged
- [ ] Link-account vs logged-in-account comparison is logged
- [ ] Strong mismatch is logged
- [ ] Host-name verification is logged
- [ ] Successful host-name verification is logged
- [ ] Failed host-name verification is logged
- [ ] Account-update request is logged
- [ ] Confirmation email dispatch is logged
- [ ] Successful email confirmation is logged
- [ ] Failed email confirmation is logged
- [ ] Account-data change is logged
- [ ] Lobby entry is logged
- [ ] Lobby admission is logged
- [ ] Lobby rejection is logged
- [ ] Call join is logged
- [ ] Call leave is logged
- [ ] Rejoin is logged
- [ ] Kick is logged
- [ ] Owner transfer is logged
- [ ] Guest-list change is logged
- [ ] Review flag for duplicate link is logged
- [ ] Membership removal is logged
- [ ] Invite invalidation is logged
- [ ] Call reschedule is logged
- [ ] Call deletion is logged
- [ ] Explicit call end is logged
- [ ] Implicit call end is logged
- [ ] Owner absence timer start is logged
- [ ] Owner absence timer cancellation is logged
- [ ] Audit logs contain time, actor, target, call, and organization
- [ ] Audit logs contain no unnecessary sensitive link data
- [ ] Security-relevant events are visible in monitoring
- [ ] Failed E2E test artifacts include relevant logs

## 32. End-to-End Main Paths

- [ ] New unregistered guest books appointment through calendar, receives personalized link, opens logged out, lands in lobby, is admitted, joins, leaves, rejoins without approval
- [ ] Registered but logged-out guest books appointment, opens personalized link logged out, temporary account is used, no automatic account takeover
- [ ] Registered logged-in guest opens own personalized link with matching data, remains logged in, joins as registered user
- [ ] Registered logged-in guest opens personalized link with light mismatch, remains logged in, joins after permission check
- [ ] Registered logged-in user opens foreign personalized link with strong mismatch, sees warning modal, enters wrong host name, receives no foreign data
- [ ] Registered logged-in user opens foreign personalized link with strong mismatch, enters correct host name, declines data update, remains unchanged
- [ ] Registered logged-in user opens personalized link with strong mismatch, enters correct host name, re-enters data, confirms email, account is updated
- [ ] Same personalized link is opened by second logged-in account and review flag is created
- [ ] Logged-in user opens anonymous link and joins as logged-in user with own rights
- [ ] Not logged-in user opens anonymous link, temporary account is created, user lands in lobby, is admitted, can rejoin
- [ ] System admin joins foreign active call without invitation
- [ ] Organization admin joins own organization active call without invitation
- [ ] Organization admin cannot join foreign organization call through org-admin rights
- [ ] Normal user on guest list joins foreign call
- [ ] Normal user without guest-list entry lands in lobby or is denied
- [ ] User creates own call, becomes owner, transfers ownership, loses call-admin rights
- [ ] Organization admin creates call, transfers ownership, keeps admin rights
- [ ] Temporary user is admitted, then kicked, and cannot rejoin without renewed approval
- [ ] Invited user is removed from organization before opening link, then joins as call-scoped invited guest
- [ ] Invite link is invalidated before use and cannot be used
- [ ] Call is rescheduled and stale link no longer grants stale access
- [ ] Call is deleted and all temporary access is revoked
- [ ] Owner explicitly ends call and all participants receive ended state
- [ ] Owner disconnects, final 5-minute countdown is shown, owner does not return, call ends automatically
- [ ] Owner disconnects, final 5-minute countdown is shown, owner returns, countdown disappears and call remains active

---

# Named Automated Test Checklist

## Test Group: Organization and Role Fixtures

- [ ] `e2e_org_001_create_organization`
- [ ] `e2e_org_002_register_user_in_organization`
- [ ] `e2e_org_003_assign_user_role_user`
- [ ] `e2e_org_004_assign_user_role_admin`
- [ ] `e2e_org_005_login_normal_user`
- [ ] `e2e_org_006_login_organization_admin`
- [ ] `e2e_org_007_logged_out_user_has_no_session`
- [ ] `e2e_org_008_cross_org_rights_not_leaked`
- [ ] `e2e_org_009_stale_client_role_ignored`
- [ ] `e2e_org_010_stale_session_role_revalidated`

## Test Group: Call Creation and Ownership

- [ ] `e2e_owner_001_normal_user_creates_call_and_becomes_owner`
- [ ] `e2e_owner_002_admin_user_creates_call_and_becomes_owner`
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

## Test Group: Direct Join Permissions

- [ ] `e2e_join_001_system_admin_can_join_any_active_call`
- [ ] `e2e_join_002_system_admin_joins_without_guest_list`
- [ ] `e2e_join_003_org_admin_can_join_own_org_call`
- [ ] `e2e_join_004_org_admin_cannot_join_foreign_org_call`
- [ ] `e2e_join_005_guest_list_user_can_join`
- [ ] `e2e_join_006_user_not_on_guest_list_cannot_direct_join`
- [ ] `e2e_join_007_owner_can_join_own_call`
- [ ] `e2e_join_008_disabled_user_cannot_join`
- [ ] `e2e_join_009_removed_guest_list_entry_revokes_join`
- [ ] `e2e_join_010_added_guest_list_entry_grants_join`
- [ ] `e2e_join_011_manipulated_role_rejected`
- [ ] `e2e_join_012_manipulated_call_id_rejected`

## Test Group: Calendar Invitation

- [ ] `e2e_invite_001_host_creates_calendar_invitation`
- [ ] `e2e_invite_002_invitee_selects_appointment`
- [ ] `e2e_invite_003_registered_logged_in_invitee_flow`
- [ ] `e2e_invite_004_registered_logged_out_invitee_flow`
- [ ] `e2e_invite_005_unregistered_invitee_creates_temp_account`
- [ ] `e2e_invite_006_personalized_link_bound_to_temp_account`
- [ ] `e2e_invite_007_multiple_invitees_get_unique_links`
- [ ] `e2e_invite_008_cancel_invitation_invalidates_link`
- [ ] `e2e_invite_009_expired_personalized_link_rejected`
- [ ] `e2e_invite_010_reopen_same_link_same_context_consistent`

## Test Group: Personalized Link Logged Out

- [ ] `e2e_personalized_logged_out_001_temp_account_created_from_link`
- [ ] `e2e_personalized_logged_out_002_existing_account_not_auto_logged_in`
- [ ] `e2e_personalized_logged_out_003_temp_guest_on_guest_list_direct_join`
- [ ] `e2e_personalized_logged_out_004_temp_guest_not_on_guest_list_lobby`
- [ ] `e2e_personalized_logged_out_005_temp_guest_no_registered_rights`
- [ ] `e2e_personalized_logged_out_006_temp_guest_no_org_rights`
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
- [ ] `e2e_strong_mismatch_010_update_requires_manual_reentry`
- [ ] `e2e_strong_mismatch_011_confirmation_email_sent_to_logged_in_account`
- [ ] `e2e_strong_mismatch_012_email_not_sent_to_temp_account`
- [ ] `e2e_strong_mismatch_013_no_update_without_email_confirmation`
- [ ] `e2e_strong_mismatch_014_confirmed_update_changes_only_confirmed_fields`
- [ ] `e2e_strong_mismatch_015_rate_limit_host_name_attempts`
- [ ] `e2e_strong_mismatch_016_audit_logged`

## Test Group: Duplicate Personalized Link

- [ ] `e2e_duplicate_link_001_same_account_reuses_link_no_flag`
- [ ] `e2e_duplicate_link_002_second_account_uses_link_flag_created`
- [ ] `e2e_duplicate_link_003_second_account_flag_even_without_join`
- [ ] `e2e_duplicate_link_004_second_account_flag_even_with_correct_host_name`
- [ ] `e2e_duplicate_link_005_concurrent_two_accounts_same_link_detected`
- [ ] `e2e_duplicate_link_006_parallel_open_no_inconsistent_assignment`
- [ ] `e2e_duplicate_link_007_cross_device_duplicate_detected`
- [ ] `e2e_duplicate_link_008_cross_browser_duplicate_detected`
- [ ] `e2e_duplicate_link_009_review_flag_contains_required_metadata`
- [ ] `e2e_duplicate_link_010_review_flag_avoids_sensitive_data`

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
- [ ] `e2e_anon_logged_out_008_admitted_guest_can_rejoin`
- [ ] `e2e_anon_logged_out_009_kicked_guest_cannot_direct_rejoin`
- [ ] `e2e_anon_logged_out_010_multiple_anonymous_guests_are_separate`

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
- [ ] `e2e_lobby_010_concurrent_admission_idempotent`
- [ ] `e2e_lobby_011_concurrent_admit_reject_deterministic`
- [ ] `e2e_lobby_012_lobby_state_updates_correctly`

## Test Group: Rejoin and Kick

- [ ] `e2e_rejoin_001_admitted_temp_user_can_rejoin`
- [ ] `e2e_rejoin_002_rejoin_after_refresh`
- [ ] `e2e_rejoin_003_rejoin_after_network_interruption`
- [ ] `e2e_rejoin_004_kicked_temp_user_cannot_direct_rejoin`
- [ ] `e2e_rejoin_005_kick_overrides_previous_admission`
- [ ] `e2e_rejoin_006_registered_guest_can_rejoin`
- [ ] `e2e_rejoin_007_rejoin_after_guest_list_removal_blocked_or_lobby`
- [ ] `e2e_rejoin_008_rejoin_after_admin_role_removed_uses_new_permissions`
- [ ] `e2e_rejoin_009_rejoin_after_owner_transfer_uses_new_permissions`

## Test Group: Temporary Moderators

- [ ] `e2e_temp_mod_001_host_assigns_temp_moderator`
- [ ] `e2e_temp_mod_002_temp_moderator_admits_participant`
- [ ] `e2e_temp_mod_003_temp_moderator_rejects_participant`
- [ ] `e2e_temp_mod_004_temp_moderator_limited_to_assigned_call`
- [ ] `e2e_temp_mod_005_temp_moderator_no_org_admin_actions`
- [ ] `e2e_temp_mod_006_temp_moderator_rights_revoked_immediately`
- [ ] `e2e_temp_mod_007_client_side_temp_mod_role_forgery_rejected`

## Test Group: Privacy and Security

- [ ] `e2e_privacy_001_foreign_link_data_not_rendered`
- [ ] `e2e_privacy_002_foreign_link_data_not_in_api_response`
- [ ] `e2e_privacy_003_invalid_link_no_personal_data_leak`
- [ ] `e2e_privacy_004_wrong_host_name_no_personal_data_leak`
- [ ] `e2e_privacy_005_browser_network_response_no_foreign_data`
- [ ] `e2e_privacy_006_audit_logs_minimize_sensitive_data`
- [ ] `e2e_security_001_modified_personalized_link_id_rejected`
- [ ] `e2e_security_002_modified_call_id_rejected`
- [ ] `e2e_security_003_forged_user_id_rejected`
- [ ] `e2e_security_004_forged_role_rejected`
- [ ] `e2e_security_005_forged_org_id_rejected`
- [ ] `e2e_security_006_csrf_account_update_protected`
- [ ] `e2e_security_007_session_fixation_prevented`
- [ ] `e2e_security_008_parallel_tabs_no_wrong_merge`

## Test Group: Email Confirmation

- [ ] `e2e_email_001_update_request_sends_confirmation_email`
- [ ] `e2e_email_002_email_sent_to_logged_in_account`
- [ ] `e2e_email_003_email_not_sent_to_temp_account`
- [ ] `e2e_email_004_confirmation_link_account_bound`
- [ ] `e2e_email_005_confirmation_link_one_time_use`
- [ ] `e2e_email_006_confirmation_link_time_limited`
- [ ] `e2e_email_007_no_update_without_confirmation`
- [ ] `e2e_email_008_confirmation_updates_only_reentered_data`
- [ ] `e2e_email_009_expired_confirmation_link_no_update`
- [ ] `e2e_email_010_multiple_pending_confirmations_resolved`

## Test Group: Guest List

- [ ] `e2e_guest_list_001_add_registered_user_to_guest_list`
- [ ] `e2e_guest_list_002_add_temp_account_to_guest_list`
- [ ] `e2e_guest_list_003_remove_guest_list_entry`
- [ ] `e2e_guest_list_004_guest_list_user_direct_join`
- [ ] `e2e_guest_list_005_non_guest_user_no_direct_join`
- [ ] `e2e_guest_list_006_temp_guest_list_user_direct_join`
- [ ] `e2e_guest_list_007_guest_list_call_scoped`
- [ ] `e2e_guest_list_008_guest_list_cross_org_not_valid`
- [ ] `e2e_guest_list_009_duplicate_guest_entries_handled`
- [ ] `e2e_guest_list_010_guest_list_changes_audit_logged`

## Test Group: Cross Organization

- [ ] `e2e_cross_org_001_user_a_opens_org_a_link`
- [ ] `e2e_cross_org_002_user_a_opens_org_b_link`
- [ ] `e2e_cross_org_003_org_admin_a_opens_org_a_call`
- [ ] `e2e_cross_org_004_org_admin_a_opens_org_b_call`
- [ ] `e2e_cross_org_005_org_admin_a_no_admin_rights_in_org_b`
- [ ] `e2e_cross_org_006_active_org_switch_does_not_change_server_permission`
- [ ] `e2e_cross_org_007_guest_list_not_cross_org`
- [ ] `e2e_cross_org_008_owner_rights_not_cross_org`
- [ ] `e2e_cross_org_009_review_flags_correct_org`

## Test Group: Multi Session and Device

- [ ] `e2e_multi_session_001_same_user_same_link_two_browsers`
- [ ] `e2e_multi_session_002_same_user_same_link_two_devices`
- [ ] `e2e_multi_session_003_different_user_same_link_other_device_flags`
- [ ] `e2e_multi_session_004_concurrent_join_no_duplicate_participants`
- [ ] `e2e_multi_session_005_login_switch_during_warning_modal_safe`
- [ ] `e2e_multi_session_006_email_confirmation_other_browser_correct_account`
- [ ] `e2e_multi_session_007_session_expiry_in_lobby_safe`
- [ ] `e2e_multi_session_008_session_expiry_in_call_safe`
- [ ] `e2e_multi_session_009_refresh_during_host_verification_safe`
- [ ] `e2e_multi_session_010_refresh_during_pending_email_confirmation_safe`

## Test Group: Membership Revocation After Invitation

- [ ] `e2e_membership_001_removed_invited_user_can_use_valid_invite_as_call_guest`
- [ ] `e2e_membership_002_removed_invited_user_no_org_member_rights`
- [ ] `e2e_membership_003_removed_invited_admin_no_org_admin_rights`
- [ ] `e2e_membership_004_removed_invited_user_cannot_join_other_org_calls`
- [ ] `e2e_membership_005_removed_invited_user_cannot_access_org_resources`
- [ ] `e2e_membership_006_removed_invited_user_blocked_after_invite_invalidation`
- [ ] `e2e_membership_007_removed_invited_user_blocked_after_call_deleted`
- [ ] `e2e_membership_008_removed_invited_user_blocked_after_call_ended`
- [ ] `e2e_membership_009_removed_invited_user_kick_overrides_invite`
- [ ] `e2e_membership_010_user_inside_call_remains_if_call_scoped_access`
- [ ] `e2e_membership_011_user_inside_call_loses_org_privileges_immediately`
- [ ] `e2e_membership_012_removed_org_admin_inside_call_loses_admin_controls`
- [ ] `e2e_membership_013_removed_user_rejoin_allowed_only_while_invite_valid`
- [ ] `e2e_membership_014_membership_removal_audit_logged`
- [ ] `e2e_membership_015_stale_role_cache_ignored_after_membership_removal`

## Test Group: Invite Invalidation

- [ ] `e2e_invite_invalid_001_personalized_link_invalidated_before_use`
- [ ] `e2e_invite_invalid_002_personalized_link_invalidated_after_first_use`
- [ ] `e2e_invite_invalid_003_personalized_link_invalidated_in_lobby`
- [ ] `e2e_invite_invalid_004_personalized_link_invalidated_in_call`
- [ ] `e2e_invite_invalid_005_anonymous_link_invalidated_before_use`
- [ ] `e2e_invite_invalid_006_anonymous_link_invalidated_in_lobby`
- [ ] `e2e_invite_invalid_007_anonymous_link_invalidated_in_call`
- [ ] `e2e_invite_invalid_008_invalidated_link_blocks_fresh_join`
- [ ] `e2e_invite_invalid_009_invalidated_link_blocks_rejoin_if_required`
- [ ] `e2e_invite_invalid_010_invalidated_link_no_data_leak`
- [ ] `e2e_invite_invalid_011_invalidated_link_does_not_recreate_temp_account`
- [ ] `e2e_invite_invalid_012_invalidated_link_survives_app_restart`

## Test Group: Guest Account Lifecycle

- [ ] `e2e_guest_lifecycle_001_temp_guest_created_from_calendar_invite`
- [ ] `e2e_guest_lifecycle_002_temp_guest_deleted_when_call_deleted`
- [ ] `e2e_guest_lifecycle_003_temp_guest_deleted_when_invitation_deleted`
- [ ] `e2e_guest_lifecycle_004_temp_guest_invalidated_when_link_invalidated`
- [ ] `e2e_guest_lifecycle_005_temp_guest_handled_on_call_reschedule`
- [ ] `e2e_guest_lifecycle_006_temp_guest_cannot_join_after_call_deleted`
- [ ] `e2e_guest_lifecycle_007_temp_guest_cannot_join_after_call_ended`
- [ ] `e2e_guest_lifecycle_008_temp_guest_cannot_rejoin_after_cleanup`
- [ ] `e2e_guest_lifecycle_009_cleanup_does_not_delete_registered_accounts`
- [ ] `e2e_guest_lifecycle_010_cleanup_scoped_to_call`
- [ ] `e2e_guest_lifecycle_011_cleanup_idempotent`
- [ ] `e2e_guest_lifecycle_012_cleanup_audit_logged`

## Test Group: Call Rescheduling

- [ ] `e2e_reschedule_001_owner_reschedules_before_guest_opens_link`
- [ ] `e2e_reschedule_002_owner_reschedules_while_guest_in_lobby`
- [ ] `e2e_reschedule_003_owner_reschedules_while_guest_in_call`
- [ ] `e2e_reschedule_004_old_personalized_link_invalidated`
- [ ] `e2e_reschedule_005_new_personalized_link_works`
- [ ] `e2e_reschedule_006_old_temp_guest_handled_by_product_rule`
- [ ] `e2e_reschedule_007_old_link_cannot_join_stale_call`
- [ ] `e2e_reschedule_008_registered_invitee_old_link_behavior`
- [ ] `e2e_reschedule_009_anonymous_link_behavior_after_reschedule`
- [ ] `e2e_reschedule_010_lobby_entries_migrated_or_cleared`
- [ ] `e2e_reschedule_011_admitted_temp_participants_migrated_or_cleared`
- [ ] `e2e_reschedule_012_reschedule_audit_logged`

## Test Group: Call Deletion

- [ ] `e2e_delete_001_owner_deletes_call_before_guests_join`
- [ ] `e2e_delete_002_owner_deletes_call_with_guests_in_lobby`
- [ ] `e2e_delete_003_owner_deletes_call_with_registered_users_inside`
- [ ] `e2e_delete_004_owner_deletes_call_with_temp_guests_inside`
- [ ] `e2e_delete_005_owner_deletes_call_with_anonymous_guests_inside`
- [ ] `e2e_delete_006_deleted_call_blocks_owner_join`
- [ ] `e2e_delete_007_deleted_call_blocks_org_admin_join`
- [ ] `e2e_delete_008_deleted_call_blocks_system_admin_normal_join`
- [ ] `e2e_delete_009_deleted_call_blocks_personalized_link`
- [ ] `e2e_delete_010_deleted_call_blocks_anonymous_link`
- [ ] `e2e_delete_011_deleted_call_blocks_admitted_guest_rejoin`
- [ ] `e2e_delete_012_deleted_call_cleans_temp_guests`
- [ ] `e2e_delete_013_deleted_call_clears_lobby`
- [ ] `e2e_delete_014_deleted_call_preserves_audit_log`
- [ ] `e2e_delete_015_deleted_call_does_not_affect_unrelated_calls`

## Test Group: Explicit Call End

- [ ] `e2e_end_explicit_001_owner_explicitly_ends_call`
- [ ] `e2e_end_explicit_002_owner_leave_treated_as_call_end_if_configured`
- [ ] `e2e_end_explicit_003_registered_participants_receive_ended_state`
- [ ] `e2e_end_explicit_004_temp_guests_receive_ended_state`
- [ ] `e2e_end_explicit_005_anonymous_guests_receive_ended_state`
- [ ] `e2e_end_explicit_006_new_joins_blocked_after_end`
- [ ] `e2e_end_explicit_007_rejoins_blocked_after_end`
- [ ] `e2e_end_explicit_008_personalized_links_invalidated_after_end`
- [ ] `e2e_end_explicit_009_anonymous_links_invalidated_after_end`
- [ ] `e2e_end_explicit_010_temp_guests_cleaned_after_end`
- [ ] `e2e_end_explicit_011_lobby_cleared_after_end`
- [ ] `e2e_end_explicit_012_late_old_link_shows_safe_ended_state`

## Test Group: Implicit Call End by Owner Absence

- [ ] `e2e_end_implicit_001_owner_disconnect_starts_absence_timer`
- [ ] `e2e_end_implicit_002_owner_tab_close_starts_absence_timer`
- [ ] `e2e_end_implicit_003_owner_process_kill_starts_absence_timer`
- [ ] `e2e_end_implicit_004_owner_network_loss_starts_absence_timer`
- [ ] `e2e_end_implicit_005_no_countdown_before_10_min_equivalent`
- [ ] `e2e_end_implicit_006_countdown_visible_at_10_min_equivalent`
- [ ] `e2e_end_implicit_007_countdown_updates_over_time`
- [ ] `e2e_end_implicit_008_countdown_synchronized_across_participants`
- [ ] `e2e_end_implicit_009_countdown_survives_participant_refresh`
- [ ] `e2e_end_implicit_010_owner_rejoin_before_countdown_cancels_timer`
- [ ] `e2e_end_implicit_011_owner_rejoin_during_countdown_cancels_timer`
- [ ] `e2e_end_implicit_012_owner_absent_15_min_equivalent_ends_call`
- [ ] `e2e_end_implicit_013_automatic_end_notifies_participants`
- [ ] `e2e_end_implicit_014_automatic_end_blocks_new_joins`
- [ ] `e2e_end_implicit_015_automatic_end_blocks_rejoins`
- [ ] `e2e_end_implicit_016_automatic_end_invalidates_links`
- [ ] `e2e_end_implicit_017_automatic_end_cleans_guest_accounts`
- [ ] `e2e_end_implicit_018_timer_uses_server_time`
- [ ] `e2e_end_implicit_019_ci_uses_test_clock_no_real_15_min_sleep`

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

- [ ] `e2e_audit_001_call_creation_logged`
- [ ] `e2e_audit_002_invitation_creation_logged`
- [ ] `e2e_audit_003_link_open_logged`
- [ ] `e2e_audit_004_temp_account_creation_logged`
- [ ] `e2e_audit_005_strong_mismatch_logged`
- [ ] `e2e_audit_006_host_verification_logged`
- [ ] `e2e_audit_007_account_update_logged`
- [ ] `e2e_audit_008_lobby_events_logged`
- [ ] `e2e_audit_009_join_leave_rejoin_logged`
- [ ] `e2e_audit_010_kick_logged`
- [ ] `e2e_audit_011_owner_transfer_logged`
- [ ] `e2e_audit_012_duplicate_link_flag_logged`
- [ ] `e2e_audit_013_membership_removal_logged`
- [ ] `e2e_audit_014_invite_invalidation_logged`
- [ ] `e2e_audit_015_reschedule_logged`
- [ ] `e2e_audit_016_delete_logged`
- [ ] `e2e_audit_017_explicit_end_logged`
- [ ] `e2e_audit_018_implicit_end_logged`
- [ ] `e2e_audit_019_owner_absence_timer_logged`
- [ ] `e2e_audit_020_logs_minimize_sensitive_data`

## Test Group: Main E2E Journeys

- [ ] `e2e_journey_001_unregistered_calendar_guest_lobby_admit_join_leave_rejoin`
- [ ] `e2e_journey_002_registered_logged_out_invitee_uses_temp_account`
- [ ] `e2e_journey_003_registered_logged_in_matching_invitee_joins_as_account`
- [ ] `e2e_journey_004_registered_logged_in_light_mismatch_joins_after_permission_check`
- [ ] `e2e_journey_005_foreign_personalized_link_wrong_host_no_data_leak`
- [ ] `e2e_journey_006_foreign_personalized_link_correct_host_decline_update`
- [ ] `e2e_journey_007_foreign_personalized_link_correct_host_update_confirm_email`
- [ ] `e2e_journey_008_duplicate_personalized_link_review_flag`
- [ ] `e2e_journey_009_logged_in_user_anonymous_link_uses_own_rights`
- [ ] `e2e_journey_010_logged_out_user_anonymous_link_lobby_admit_rejoin`
- [ ] `e2e_journey_011_system_admin_join_without_invite`
- [ ] `e2e_journey_012_org_admin_join_own_org_without_invite`
- [ ] `e2e_journey_013_org_admin_foreign_org_denied`
- [ ] `e2e_journey_014_normal_guest_list_user_joins_foreign_call`
- [ ] `e2e_journey_015_normal_non_guest_user_lobby_or_denied`
- [ ] `e2e_journey_016_normal_user_owner_transfer_loses_admin`
- [ ] `e2e_journey_017_org_admin_owner_transfer_keeps_admin`
- [ ] `e2e_journey_018_temp_user_kicked_cannot_rejoin_directly`
- [ ] `e2e_journey_019_removed_org_member_invite_becomes_call_scoped_guest`
- [ ] `e2e_journey_020_invalidated_invite_link_denied`
- [ ] `e2e_journey_021_rescheduled_call_old_link_invalid_new_link_valid`
- [ ] `e2e_journey_022_deleted_call_revokes_all_temp_access`
- [ ] `e2e_journey_023_explicit_call_end_revokes_all_join_paths`
- [ ] `e2e_journey_024_owner_absence_countdown_then_auto_end`
- [ ] `e2e_journey_025_owner_absence_countdown_then_reconnect_cancels_end`

---

# Definition of Done

- [ ] E2E test suite is implemented or extended
- [ ] Playwright or existing E2E framework is configured for CI
- [ ] CI job runs E2E suite automatically
- [ ] CI starts all required services
- [ ] CI starts media/signaling infrastructure if required
- [ ] CI starts `king` containers for multi-participant tests
- [ ] CI collects traces, screenshots, videos, and logs on failure
- [ ] Test data is deterministic
- [ ] Test data is isolated per test or safely reset
- [ ] Tests cover all critical IAM and call-access flows
- [ ] Tests cover invitation invalidation
- [ ] Tests cover guest account cleanup
- [ ] Tests cover call rescheduling
- [ ] Tests cover call deletion
- [ ] Tests cover explicit call ending
- [ ] Tests cover implicit owner-absence ending
- [ ] Owner timeout tests do not wait 15 real minutes
- [ ] Duplicate personalized-link abuse detection is tested
- [ ] Membership removal after invitation is tested with call-scoped guest access behavior
- [ ] Privacy and data minimization assertions are included
- [ ] Security manipulation cases are covered
- [ ] Audit-relevant flows are asserted where audit logs exist
- [ ] Test names are stable and mapped to the checklist
- [ ] Documentation explains how to run tests locally
- [ ] Documentation explains how to run tests in CI
