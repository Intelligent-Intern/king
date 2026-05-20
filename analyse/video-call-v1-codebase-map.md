# Video Call v1 Codebase Map

Source: local code inspection only, in `/home/jochen/projects/king.site/worktrees/bgf-sprint-integration`.

## Frontend workspace

- `demo/video-chat/frontend-vue/` is the Vue/Vite workspace. `package.json` defines the dev/build/test entrypoints and the main release-oriented contract suites for SFU, gossip, call apps, IAM/call access, background runtime, diagnostics, backend origin, and Playwright e2e coverage.
- `demo/video-chat/frontend-vue/src/main.ts`, `src/App.vue`, and `src/http/router.ts` bootstrap the app and route into public join/booking/login pages, the authenticated `WorkspaceShell`, admin/user call lists, and `workspace/call/:callRef?`.
- `demo/video-chat/frontend-vue/src/layouts/` owns the persistent workspace shell, navigation, call left sidebar, settings surfaces, mic level monitor, and viewport helpers.
- `demo/video-chat/frontend-vue/src/domain/calls/` owns non-realtime call workflows:
  - `access/` handles public join, admission gates, join previews, goodbye handling, and call-access session state.
  - `admin/` handles admin call management.
  - `dashboard/` handles user dashboard entry into calls.
  - `appointment/` handles booking and calendar-facing call scheduling.
  - `chat/archive.ts` and `components/ChatArchiveModal.vue` expose persisted chat history.
- `demo/video-chat/frontend-vue/src/domain/realtime/CallWorkspaceView.vue` is the central call workspace orchestrator. It imports the extracted helper modules for socket lifecycle, route resolution, room state, runtime switching, media stack, native stack, gossip data lane, SFU lifecycle, diagnostics, foreground recovery, chat runtime, and participant UI.
- `demo/video-chat/frontend-vue/src/domain/realtime/CallWorkspaceView.template.html` owns the stage composition: banners, compact header, main/grid/call-app workspace modes, local/remote/decoded video containers, fullscreen video overlay, mini strip, chat/lobby toasts, and side panels.
- `demo/video-chat/frontend-vue/src/domain/realtime/workspace/` holds shared workspace APIs and normalization helpers:
  - `api.ts` wraps backend REST and websocket URL construction.
  - `config.ts` centralizes feature flags, retry windows, SFU/WLVC constants, UI limits, and timing thresholds.
  - `roster.ts` and `utils.ts` normalize participant, role, room, and user ordering state.
- `demo/video-chat/frontend-vue/src/domain/realtime/workspace/callWorkspace/` is the main extraction area for call workspace behavior. Important slices include `socketLifecycle.ts`, `roomState.ts`, `roomStateTopology.ts`, `runtimeSwitching.ts`, `runtimeHealth.ts`, `mediaStack.ts`, `nativeStack.ts`, `sfuTransport.ts`, `gossipDataLane.ts`, `gossipNeighborLifecycle.ts`, `foregroundRecovery.ts`, `publisherBackpressureController.ts`, `publisherDiagnosticsSurface.ts`, `clientDiagnostics.ts`, `mediaSecurityRuntime.ts`, `videoLayout.ts`, `screenSharePan.js`, and `RightRosterPanel.vue`.
- `demo/video-chat/frontend-vue/src/modules/` contains admin/workspace modules and navigation descriptors. `modules/index.js`, `moduleRegistry.js`, `navigationBuilder.js`, and per-module `descriptor.js` files feed workspace route records and shell navigation.
- `demo/video-chat/frontend-vue/src/support/` contains frontend infrastructure adapters such as backend origin resolution, backend fetch, asset version handling, client diagnostics dispatch, foreground reconnect, runtime helpers, debug logging, locale formatting, and WLVC frame support.

## Frontend media/local/runtime

- `demo/video-chat/frontend-vue/src/domain/realtime/local/` owns local media capture and publisher-side frame handling:
  - browser encoder config, frame scaling, capture pipeline capabilities, DOM canvas fallback policy, local media permission policy, local preview element handling, stream lifecycle, media orchestration, protected browser encoding, publisher workers, publisher readback, frame dispatch/trace, video frame copy/source, screen share capture/publishing, SFU capture constraints, and frame sizing.
- `demo/video-chat/frontend-vue/src/domain/realtime/media/` owns cross-cutting media policy: audio/camera constraints, user preferences, protected frame budget, runtime capabilities, runtime telemetry, media security, sender-key identity, and speaker output routing.
- `demo/video-chat/frontend-vue/src/domain/realtime/native/` owns native WebRTC/audio bridge runtime:
  - `bridgeRuntime.ts`, `peerFactory.ts`, `peerLifecycle.ts`, `peerMedia.ts`, `signaling.ts`, and the audio bridge state/recovery/failure reporter helpers.
- `demo/video-chat/frontend-vue/src/domain/realtime/sfu/` owns browser-side SFU media behavior:
  - lifecycle, adaptive quality layers, send budget, frame decode, WLVC metadata, remote peers/canvas/render scheduler/jitter buffer, receiver feedback, keyframe recovery, stall recovery, recovery reasons, screen share frame identity, and connection status.
- `demo/video-chat/frontend-vue/src/lib/sfu/` contains lower-level SFU protocol/transport primitives: client, transport samples, message handling, session protocol, frame payloads, inbound assembler, outbound queue/budget, selective tile transport, carrier state, identifiers, and tile patch metadata.
- `demo/video-chat/frontend-vue/src/lib/gossipmesh/` contains decentralized routing/data-lane primitives: gossip controller, lanes, routing, feature flags, rollout gate, RTC data channel transport, media carrier mode, IIBIN codec, wire contract, and a local harness CLI.
- `demo/video-chat/frontend-vue/src/lib/wavelet/` and `src/lib/wasm/` contain the WLVC/wavelet codec stacks, WASM bridge, C++ sources, generated `wlvc.wasm`, and browser-facing codec wrappers.
- `demo/video-chat/frontend-vue/src/domain/realtime/background/` owns background replacement/blur runtime: UI controls, controller, capability gates, baseline collector, stream helpers, unavailable prompts, static avatar fallback, backend worker segmenter, diagnostics, pipeline stages, scheduler, compositor, segmenter, and image segmenter worker.
- `demo/video-chat/frontend-vue/public/cdn/vendor/tensorflow/` and `public/assets/orgas/kingrt/` provide local browser vendor assets and product icons/logos used by the media/background UI.

## Backend realtime/calls

- `demo/video-chat/backend-king-php/server.php` is the King PHP runtime entrypoint. It validates King extension availability, bootstraps SQLite, initializes chat object store, config hardening, Semantic-DNS runtime for call apps, in-memory websocket/presence/lobby/typing/reaction state, then runs `king_http1_server_listen_once` in a hot loop.
- `demo/video-chat/backend-king-php/http/router.php` is the deterministic REST/WS dispatcher. It applies CORS, public endpoint rules, REST auth/RBAC, and then dispatches modules in this order: runtime, auth session, infrastructure, operations, marketplace, tenancy, backend modules, localization, users, workspace calendars, workspace administration, invites, call apps, calls, appointment calendar, realtime.
- `demo/video-chat/backend-king-php/http/module_calls.php`, `module_calls_access.php`, `module_calls_leave.php`, `module_invites.php`, and `module_appointment_calendar.php` expose the call lifecycle, access links/sessions, leaving calls, invite codes, and appointment-calendar APIs.
- `demo/video-chat/backend-king-php/domain/calls/` owns call business logic:
  - call management create/update/query/cancel/delete/owner transfer/guest list.
  - call access decisions, public access, sessions, links, reviews, calendar guards, account confirmation, guest lifecycle, invite code contracts, and lifecycle state.
- `demo/video-chat/backend-king-php/http/module_realtime.php` fans realtime traffic into attachment routes, `/sfu`, or the main websocket route.
- `demo/video-chat/backend-king-php/http/module_realtime_websocket.php` owns `/ws` handshake validation, websocket auth/RBAC, room resolution/backfill retry, `king_server_upgrade_to_websocket`, active connection registration, presence joins, disconnect cleanup, lobby/typing/reaction cleanup, call participant join marking, asset-version disconnects, and the media-relay socket branch.
- `demo/video-chat/backend-king-php/http/module_realtime_websocket_commands.php`, `module_realtime_websocket_brokers.php`, `module_realtime_websocket_lobby.php`, `module_realtime_websocket_reconnect.php`, and `module_realtime_websocket_admin_sync.php` hold focused websocket command/broker/lobby/reconnect/admin-sync handling.
- `demo/video-chat/backend-king-php/domain/realtime/` owns backend realtime state and protocol logic:
  - presence, lobby, room snapshots, call context, call role context, signaling, chat, typing, reactions, activity layout, operator feedback, admin sync, owner absence, attachment/archive storage, gateway JWT/backend mapping, TURN ICE, connection contract, and asset version.
  - SFU/gossip paths live in `realtime_sfu_gateway.php`, `realtime_sfu_session_protocol.php`, `realtime_sfu_iibin.php`, `realtime_sfu_store.php`, `realtime_sfu_frame_buffer.php`, `realtime_sfu_broker_replay.php`, `realtime_sfu_recovery_requests.php`, `realtime_sfu_subscriber_budget.php`, `realtime_sfu_binary_payload.php`, `realtime_sfu_operations_mirror.php`, `realtime_gossipmesh.php`, `realtime_gossipmesh_room_state.php`, and `realtime_gossipmesh_recovery.php`.
- `demo/video-chat/backend-king-php/support/` provides shared runtime support: auth, RBAC, auth request/session cache, database core/migrations/seed, tenant context, error envelope, localization, backend modules, WLVC frame helpers, and workspace/call-app/user-profile migrations.

## Backend call diagnostics

- `demo/video-chat/backend-king-php/domain/realtime/client_diagnostics.php` normalizes client diagnostics batches, truncates and sanitizes payloads, caps batch size, validates event metadata, logs diagnostics entries, and persists/query-supports client runtime events.
- `demo/video-chat/frontend-vue/src/support/clientDiagnostics.ts` is the frontend sender for client diagnostics, while `demo/video-chat/frontend-vue/src/domain/realtime/workspace/callWorkspace/clientDiagnostics.ts` and `publisherDiagnosticsSurface.ts` wire call-workspace-specific reporting.
- `demo/video-chat/backend-king-php/domain/call_apps/call_app_diagnostics.php` owns the internal `call-diagnostics` app behavior. It identifies admin-only diagnostics app access, redacts secrets/media frames/SDP/ICE, captures memory/runtime status, queries recent errors, and supports telemetry snapshot handling.
- `demo/video-chat/backend-king-php/http/module_call_apps.php` calls `videochat_call_diagnostics_handle_telemetry_snapshot_route(...)` before normal call-app routes, so diagnostics is part of the call-app API module rather than the generic realtime module.
- `demo/call-apps/call-diagnostics/` is the packaged diagnostics Call App. It contains `call-app.manifest.json`, CRDT schema, MCP/health descriptors, and static `public/` assets.
- `demo/video-chat/frontend-vue/src/domain/realtime/callApps/` contains the frontend Call App host and observability bridge:
  - `CallAppWorkspaceHost.vue`, `CallAppsSidebarPanel.vue`, `callAppDiagnostics.js`, `callAppDiagnosticTailBridge.js`, `useCallAppIframeBridge.js`, `useCallAppCrdtBridge.js`, presence relay, workspace state, and participant grant helpers.
- Related verification files include `demo/video-chat/backend-king-php/tests/client-diagnostics-contract.php`, `call-app-diagnostics-internal-contract.php`, frontend `tests/contract/client-diagnostics-contract.mjs`, `client-console-warning-diagnostics-contract.mjs`, `call-app-call-diagnostics-contract.mjs`, and `sfu-diagnostic-surface-contract.mjs`.

## Edge/deploy/ops

- `demo/video-chat/docker-compose.v1.yml` is the v1 local/deploy composition:
  - `videochat-backend-v1` runs HTTP mode on `/api`/REST with shared SQLite volume.
  - `videochat-backend-ws-v1` runs WS mode on `/ws`.
  - `videochat-backend-sfu-v1` is an `sfu` profile service using WS mode and `VIDEOCHAT_KING_WS_PATH: /sfu`, with tmpfs broker buffer.
  - `videochat-turn-v1` is a `turn` profile coturn service.
  - `videochat-frontend-v1` runs Vite with backend, WS, SFU, ICE, SFU transport, gossip, media carrier, MediaPipe/TFJS, CDN, and Call App origin env wiring.
  - `videochat-edge-v1` is an `edge` profile King/PHP edge service.
- `demo/video-chat/scripts/compose-v1.sh` wraps Docker Compose with `.env` and `.env.local` loading for the v1 compose file.
- `demo/video-chat/backend-king-php/Dockerfile`, `frontend-vue/Dockerfile`, and `edge/Dockerfile` build the backend, frontend, and edge containers from the repo root context.
- `demo/video-chat/edge/edge.php` is the PHP edge runtime. It binds HTTP/HTTPS, validates cert/key/frontend dist, routes static frontend/CDN/call-app files, proxies API/WS/SFU upstreams, handles CORS, has separate idle/stall timeouts for HTTP and websocket tunnels, and maps root/API/WS/SFU/TURN/CDN/call-app domains from environment.
- `demo/video-chat/edge/call_app_static.php` supports static Call App serving through the edge.
- `demo/video-chat/scripts/check-edge-deployment-decision.sh` guards the active edge decision: King/PHP edge, compose services for HTTP/WS/SFU/frontend/edge, `/ws`, `/sfu`, CDN env, no discarded `deploy` or third-party proxy path.
- `demo/video-chat/scripts/check-ops-hardening.sh`, `generate-turn-ice-servers.php`, and `ops/metrics-alerts.catalog.json` cover ops hardening, TURN ICE generation, and production SLO/alert catalog. The metrics catalog tracks join latency/success, first media time, reconnect recovery, client errors, FPS, encode/decode, packet loss, jitter, RTT, SFU CPU/RAM, and signaling error rate.
- `demo/video-chat/contracts/v1/` contains API/WS/media/security/tenant/UI contract JSON files used as release-facing shape documentation.
- Repo-level `infra/scripts/` contains broader runtime, release, supply-chain, HTTP/3, package, migration, and smoke/soak utilities; the video-call v1 path uses the `demo/video-chat` compose/edge scripts as the nearest deploy surface.

## Tests

- Frontend contract tests live in `demo/video-chat/frontend-vue/tests/contract/`. The high-signal video-call groups are exposed from `package.json` scripts:
  - `test:contract:sfu` for SFU architecture, capture, protected encoder, readback, transport metrics, diagnostics, quality layers, backpressure, replay, slow subscribers, and online acceptance gates.
  - `test:contract:gossip` for gossip controller, routing, rollout, native recovery, room state topology, media carrier, production deploy profile, dual SFU/gossip continuity, server topology ingestion, and stale target pruning.
  - `test:contract:call-apps` for Call App architecture, package layout, diagnostics, availability, workspace view, sidebar, iframe/CSP, CRDT sync, whiteboard runtime, permission revocation, marketplace journey, observability, production deploy, and backend call-app contracts.
  - `test:contract:iam-call-access` for join/auth/admission, call access boundaries, anonymous/registered flows, terminal states, audit/redaction, cross-org behavior, active permission changes, lobby concurrency, and backend proofs.
  - `test:contract:background-runtime`, `test:contract:background-filter`, `test:contract:client-diagnostics`, `test:contract:native-audio-bridge`, `test:contract:media-security`, `test:contract:screenshare-fullscreen`, and `test:contract:backend-origin` cover media/runtime support surfaces.
- Frontend Playwright e2e tests live in `demo/video-chat/frontend-vue/tests/e2e/`. Relevant files include `call-access-join.spec.js`, `realtime-reconnect-websocket.spec.js`, `native-audio-transfer.spec.js`, `screenshare-fullscreen-zoom.spec.js`, `call-layout-strategies.spec.js`, `call-app-fullscreen-smoke.spec.js`, `call-app-whiteboard.spec.js`, `online-sfu-hd-acceptance.mjs`, `online-sfu-pressure-acceptance.mjs`, `production-socket-proxy-budget.mjs`, and `background-production-browser-smoke.spec.js`.
- Frontend standalone harnesses live in `demo/video-chat/frontend-vue/tests/standalone/`, including background segmentation and realtime call stabilization harnesses.
- Frontend unit coverage currently includes `demo/video-chat/frontend-vue/tests/unit/native-audio-bridge.test.mjs`.
- Backend contract tests live in `demo/video-chat/backend-king-php/tests/`. Core video-call groups include:
  - call lifecycle/access: `call-create-*`, `call-update-*`, `call-cancel-*`, `call-lifecycle-contract.*`, `calls-list-*`, `call-access-*`, `call-guest-*`, `call-owner-*`, `call-temporary-moderator-*`, and invite-code tests.
  - realtime: `realtime-websocket-gateway-contract.*`, `realtime-signaling-contract.*`, `realtime-presence-contract.*`, `realtime-lobby-*`, `realtime-reconnect-backfill-contract.*`, `realtime-room-leave-snapshot-contract.php`, `realtime-session-revocation-contract.*`, `realtime-chat-contract.*`, `realtime-typing-contract.*`, `realtime-reaction-contract.*`, and `realtime-activity-layout-contract.*`.
  - SFU/gossip/media: `realtime-sfu-contract.*`, `realtime-sfu-session-protocol-contract.*`, `realtime-gossipmesh-runtime-contract.*`, `realtime-gossipmesh-room-state-topology-contract.*`, `media-security-contract.*`, `turn-ice-contract.*`, and `wlvc-wire-contract.*`.
  - diagnostics/ops/API shape: `client-diagnostics-contract.php`, `operator-feedback-contract.*`, `admin-video-operations-contract.*`, `admin-infrastructure-contract.*`, `videochat-integration-matrix-http-contract.*`, `videochat-integration-matrix-realtime-contract.*`, `contract-catalog-parity-contract.*`, and `protected-api-semantics-contract.*`.
- Backend tests are a mix of direct PHP contracts and shell wrappers that exercise SQLite/runtime and Docker proof paths.
- Extension-level tests under `extension/tests/` include lower-level King server/runtime behavior relevant to the video-call backend, such as websocket cancel callbacks, per-call stream cancel, and telemetry export diagnostics.
