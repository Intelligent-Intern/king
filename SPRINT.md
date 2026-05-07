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

## Sprint: Whiteboard Call App Hardening And Production Integration

Branch:
- `develop/1.0.8-beta`

Status:
- Active as of 2026-05-07.
- Completed Gossip and Call Apps foundation tickets were removed from the active
  sprint.
- Open non-active Governance, admin UX, and broad refactoring work was moved to
  `BACKLOG.md`.

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
