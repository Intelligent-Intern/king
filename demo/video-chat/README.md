# King Video Call Demo (Active Replatform Track)

This directory is the active build-out of a production-grade video-call
workspace on top of King runtime primitives.

What we are building here:

- a Vue workspace UI that follows the approved UX/interaction contract
- a King PHP backend (`backend-king-php`) as the authoritative API + WS layer
- contract-first REST + realtime behavior with deterministic error envelopes
- role-aware collaboration surfaces (admin/moderator/user)
- invite-driven call orchestration, room presence, chat, and signaling

Target architecture:

- frontend: `demo/video-chat/frontend-vue`
- backend: `demo/video-chat/backend-king-php`
- protocol catalog: `demo/video-chat/contracts/v1/api-ws-contract.catalog.json`
- WLVC frame wire contract: `demo/video-chat/contracts/v1/wlvc-frame.contract.json`
- transport payload format: `@intelligentintern/iibin` from `node_modules`

Documentation policy for this README:

- this is a living spec for the active video-call stack
- update it on every commit that changes behavior, contracts, runtime paths, or UX flow
- keep it aligned with `ISSUES.md`, `READYNESS_TRACKER.md`, and the active contract tests

Latest commit-level progress:

- 2026-04-13: closed `#47` with explicit `POST /api/invite-codes` endpoint contract coverage (`backend-king-php/tests/invite-code-create-endpoint-contract.sh`)
- 2026-04-13: closed `#48` with explicit `POST /api/invite-codes/redeem` endpoint contract coverage (`backend-king-php/tests/invite-code-redeem-endpoint-contract.sh`)
- 2026-04-13: closed `#49` with strict `WS /ws` handshake validation + structured close-descriptor contract coverage (`backend-king-php/tests/realtime-websocket-gateway-contract.sh`)
- 2026-04-13: closed `#50` with server-authoritative room presence snapshot + join/leave delta coverage (including room-change/reconnect phantom-user guards) in `backend-king-php/tests/realtime-presence-contract.sh`
- 2026-04-13: closed `#51` with room-scoped chat fanout hardening (sender-in-room enforcement, bounded malformed-payload rejection, stable message ids for dedupe, deterministic `chat/ack` ids) in `backend-king-php/tests/realtime-chat-contract.sh` + `backend-king-php/tests/contract-catalog-parity-contract.sh`
- 2026-04-13: closed `#52` with typing indicator hardening (room-scoped debounce + expiry, no self-echo, sender-in-room fail-closed validation, explicit `typing/stop` catalog parity) in `backend-king-php/tests/realtime-typing-contract.sh` + `backend-king-php/tests/contract-catalog-parity-contract.sh`
- 2026-04-13: closed `#53` with signaling-routing authorization hardening (sender/target membership fail-closed + no cross-room leakage) in `backend-king-php/tests/realtime-signaling-contract.sh`
- 2026-04-13: closed `#54` with lobby runtime authorization hardening (atomic queue/mutation flow, moderator-only actions, sender room-membership fail-closed) in `backend-king-php/tests/realtime-lobby-contract.sh`
- 2026-04-13: closed `#55` with reaction event stream hardening (room-scoped fanout, deterministic payload boundaries, server-side per-user throttling with retry diagnostics) in `backend-king-php/tests/realtime-reaction-contract.sh` + `backend-king-php/tests/contract-catalog-parity-contract.sh`
- 2026-04-13: closed `#56` frontend parity with explicit reconnect/auth states (`online`, `retrying`, `blocked`, `expired`) in `frontend-vue/src/views/CallWorkspaceView.vue`
- 2026-04-13: closed `#57/#58/#67` with backend-authoritative session recovery + strict RBAC redirecting + backend-backed settings/avatar/theme/time-format in `frontend-vue/src/stores/session.js`, `frontend-vue/src/router/index.js`, and `frontend-vue/src/layouts/WorkspaceShell.vue`
- 2026-04-13: closed `#59/#60/#68` with backend-bound admin overview metrics + admin user CRUD/pagination/search + admin-only branding parity flow in `frontend-vue/src/views/AdminOverviewView.vue` and `frontend-vue/src/views/AdminUsersView.vue`
- 2026-04-13: closed `#61/#62/#63` with backend-bound user dashboard calls/calendar/invite parity plus invite redeem -> workspace flow in `frontend-vue/src/views/UserDashboardView.vue`
- 2026-04-13: closed `#64/#65/#66` with server-driven workspace tab data, moderation feedback states, and control-bar realtime synchronization in `frontend-vue/src/views/CallWorkspaceView.vue`
- 2026-04-13: closed `#69/#70/#71` with backend integration-matrix tests, Playwright UI-parity journeys, and compose-level smoke gates (`demo/video-chat/scripts/smoke.sh`, `.github/workflows/ci.yml`)

Latest UX/runtime updates (2026-04-17):

- call access mode (`invite_only` / `free_for_all`) can now be managed consistently from schedule/edit/admin surfaces and from the in-call owner settings card
- invite-only join flow is now queue-first: invited users enter waiting state, host/admin/moderator receives lobby badge/toast notification, and admission is explicit
- free-for-all mode no longer depends on per-user call-access mapping; joins are room-based with explicit display-name capture
- call participant rail now reflects connected participants only; single-user sessions hide the mini-strip
- enter-call modal parity was tightened (brand header, unified close behavior, camera/mic/speaker + blur controls)
- in-call background blur controls are exposed as two presets (`Blur`, `Strong blur`) and support fast mode switching
- admin/user management shells were aligned for responsive table behavior and consistent action/button semantics
- settings theme editor now includes a larger live preview surface modeled after the real Video Call Management page

Current new-stack baseline capabilities:

- required login surface (display name) with persisted local session identity across reloads
- explicit sign-out lifecycle that tears down websocket reconnect loops, call peers, and local media tracks
- authenticated workspace gating so room/chat/call actions and local media init only run with a valid signed-in session
- backend-authoritative session recovery/refresh with fail-closed invalid-session handling
- strict route-level RBAC for admin/moderator/user surfaces with deterministic redirect behavior
- backend-backed settings modal for profile/avatar/theme/time-format with global theme/time-format application
- admin overview widgets are live API-driven (auth/session/users/calls snapshots with explicit loading/error states)
- admin users view is fully server-driven (`GET/POST/PATCH/deactivate/reactivate`) with search + pagination + row-level mutation feedback
- admin branding parity flow is available as admin-only local persistence for UI contract parity where no backend branding endpoint exists
- user dashboard now has backend-bound calls list + calendar + create/edit + invite popover/redeem flows
- room directory is fetched from backend API and normalized to deterministic ordering with live member counters
- room directory with create/join/switch behavior
- room creation submits to backend with optimistic list updates and automatic room-id conflict retries
- room switching applies explicit room-scoped reset boundaries for typing/call/ui draft state
- invite-code generation roundtrip for active room with context-panel code display
- invite-code redeem/join flow resolves target room id and switches active room on success
- invite-code copy action uses clipboard API first with a legacy copy fallback for non-secure contexts
- participant roster is sourced from live `room/snapshot` events with normalized ordering and live snapshot timestamp
- chat timeline is server-fanout driven with message normalization and room-local dedupe by message id
- chat sender acks now carry deterministic `ack_id` + stable `message_id` so client retries can dedupe without local echo drift
- typing indicators are room-scoped, exclude self display, auto-expire on idle timeout windows, and fail closed when sender room membership is invalid
- reaction events are room-scoped, validate emoji/client-id boundaries fail-closed, and apply per-user throttle windows with deterministic retry hints
- lobby queue snapshots are room-scoped and mutation paths now fail closed when sender room membership is invalid
- chat composer enforces bounded draft length and rejects empty/whitespace payloads before websocket send
- chat and roster timestamps are rendered in deterministic locale-independent UTC `HH:MM UTC` format
- pre-call local media preview is an explicit gate; join is enabled only after preview succeeds, with visible permission/device errors
- call join/leave lifecycle updates participant call presence immediately from room-scoped signaling and snapshot reconciliation
- peer connection ownership is managed by a dedicated remote-user-id keyed manager with deterministic release cleanup
- offer/answer negotiation is enforced as targeted peer-to-peer signaling (no broadcast fallback) for multi-peer rooms
- signaling authorization now fails closed for invalid sender identity, sender-not-in-room state, self-target, and target-not-in-room paths
- ICE candidate forwarding is targeted per peer and candidate payloads are normalized before safe client-side apply
- remote track tiles now attach/detach safely via lifecycle watchers and participant-based pruning to avoid stale remnants
- microphone toggles now mutate only local audio-track enabled state in place (no renegotiation churn)
- camera toggles now mutate only local video-track enabled state in place while preserving active peer connections
- room-switch, sign-out, and component-unmount boundaries now enforce full call teardown (peers + local media tracks)
- websocket reconnect now uses bounded exponential backoff and re-syncs room/call state on recovery
- workspace reconnect/auth UI exposes explicit states: `online`, `retrying`, `blocked`, `expired`
- multi-user room chat over websocket fanout
- browser video call signaling (`offer`/`answer`/`ice`) with peer tiles
- responsive shell layout with reduced-motion-safe slide transitions for chat/call stage switching
- shared UI token layer in `frontend-vue/src/styles.css` for color, spacing, border, radius, and elevation
- normalized typography and control sizing (inputs/buttons/headlines/body text) from one baseline scale

## Runtime Boundaries (Active vs Historical)

Active development and runtime path:

- `demo/video-chat/backend-king-php`
- `demo/video-chat/frontend-vue`

Launch active stack:

```bash
cd demo/video-chat/backend-king-php
./run-dev.sh
```

```bash
cd demo/video-chat/frontend-vue
npm run dev
```

Current demo caveats:

- login/user directory is persisted in SQLite (`KING_DEMO_DB_PATH`)
- no durable room/message persistence across backend restart
- TURN relay setup is available through the opt-in `turn` compose profile; default local demo remains STUN-only unless `VITE_VIDEOCHAT_ICE_SERVERS` is set
- no production moderation/audit policy
- background blur uses browser `FaceDetector` / center-mask fallback by default; optional MediaPipe/TFJS segmentation backends are opt-in via `VITE_VIDEOCHAT_ENABLE_MEDIAPIPE=true` / `VITE_VIDEOCHAT_ENABLE_TFJS=true`
- frontend debug console output is quiet by default; enable verbose runtime logs with `VITE_VIDEOCHAT_DEBUG_LOGS=true`
- frontend runtime proxy may emit Node deprecation warnings from transitive proxy dependencies; behavior remains functional

## Quick Video-Call Test (Simple)

Verified on 2026-04-15 with the active stack in this repo.

1. Start everything with Docker:

```bash
cd demo/video-chat
docker compose -f docker-compose.v1.yml up --build
```

2. Open the app:

- frontend: `http://127.0.0.1:5176`
- backend health: `http://127.0.0.1:18080/health`
- websocket signaling gateway: `ws://127.0.0.1:18081/ws`
- websocket SFU gateway: `ws://127.0.0.1:18082/sfu`
- frontend container runs Vite dev-server with HMR on compose; source edits under
  `demo/video-chat/frontend-vue` hot-reload automatically in browser

3. Login with demo admin:

- email: `admin@intelligent-intern.com`
- password: `admin123`

4. Open an existing seeded call (for example `Platform Standup`) from the calls view.

5. Optional: generate a join link and open it in a second browser profile/incognito:
   - route shape: `/join/<access-link-uuid>`
   - example full URL: `http://127.0.0.1:5176/join/<uuid>`

6. Stop stack:

```bash
docker compose -f docker-compose.v1.yml down
```

### API-only sanity check (real call + real access-link UUID)

```bash
BASE=http://127.0.0.1:18080
TOKEN=$(curl -sS -X POST "$BASE/api/auth/login" \
  -H 'content-type: application/json' \
  -d '{"email":"admin@intelligent-intern.com","password":"admin123"}' | jq -r '.session.token')

CREATE=$(curl -sS -X POST "$BASE/api/calls" \
  -H 'content-type: application/json' \
  -H "authorization: Bearer $TOKEN" \
  -d '{"title":"Readme Test Call","starts_at":"2030-01-01T10:00:00Z","ends_at":"2030-01-01T11:00:00Z"}')

CALL_ID=$(printf '%s' "$CREATE" | jq -r '.result.call.id')
curl -sS -X POST "$BASE/api/calls/$CALL_ID/access-link" \
  -H 'content-type: application/json' \
  -H "authorization: Bearer $TOKEN" \
  -d '{}' | jq -r '.result.join_path'
```

The generated `join_path` is UUID-based and points to a real new call (not only seeded demo calls).

## Can We Run This On A Server?

Short answer:

- Yes, for dev/staging and internal demos on a single node.
- Not yet production-ready for large public traffic.

What is already working:

- backend + frontend containers start with `docker-compose.v1.yml`
- auth/session/calls/invite/access-link APIs are live
- websocket endpoints `/ws` and `/sfu` are active

What is still missing for robust production operation:

- TLS termination + reverse proxy websocket upgrade config for both `/ws` and `/sfu`
- production TURN infrastructure still needs environment-specific NAT evidence; the repo provides an opt-in coturn baseline profile plus credential rotation tooling
- multi-node implementation is not active yet; the binding state split, persistence, fanout, SFU, rollout, and rollback contract is in `demo/video-chat/MULTI_NODE_RUNTIME_ARCHITECTURE.md`
- secret management + hardened env config (no demo credentials in deployed environments)
- operational hardening is baseline-wired for SQLite backup/restore, central OTLP metrics/logs/alerts catalog, and rollout/rollback runbooks in `demo/video-chat/OPS_HARDENING.md`

Edge deployment decision:

- `demo/video-chat` intentionally ships no internal Edge/TLS deploy pack.
- The active compose file is for dev/staging/internal demos only.
- A future production Edge path must be introduced as its own issue and must not reuse demo defaults.
- The static guard is `bash demo/video-chat/scripts/check-edge-deployment-decision.sh`.

TURN baseline:

- `docker compose --profile turn -f docker-compose.v1.yml up --build` starts the optional coturn service.
- TURN requires `VIDEOCHAT_V1_TURN_STATIC_AUTH_SECRET` or `VIDEOCHAT_V1_TURN_STATIC_AUTH_SECRET_FILE`; no demo secret is checked in.
- Rotating frontend ICE JSON is generated with `php demo/video-chat/scripts/generate-turn-ice-servers.php`.
- The static guard is `bash demo/video-chat/scripts/check-turn-baseline.sh`.

Secret-management baseline:

- Local demo defaults remain available only outside hardened deployments.
- `VIDEOCHAT_KING_ENV=production|staging` or `VIDEOCHAT_REQUIRE_SECRET_SOURCES=1` makes backend start fail closed on default demo credentials or enabled demo seed calls.
- `VIDEOCHAT_DEMO_ADMIN_PASSWORD_FILE` and `VIDEOCHAT_DEMO_USER_PASSWORD_FILE` are supported for mounted secret files.
- The runbook and static guard are in `demo/video-chat/SECRET_MANAGEMENT.md` and `bash demo/video-chat/scripts/check-secret-management.sh`.

Multi-node runtime architecture:

- Current compose remains a single-node dev/staging path.
- The binding architecture and migration contract is `demo/video-chat/MULTI_NODE_RUNTIME_ARCHITECTURE.md`.
- It defines the required split for Session/Auth, Call State, Roster/Presence, Realtime Fanout, SFU topology, shared SQL replacement for SQLite, inter-node bus topics, zero-downtime rollout, and rollback gates.
- The static guard is `bash demo/video-chat/scripts/check-multi-node-runtime-architecture.sh`.

Ops hardening baseline:

- SQLite backups use `bash demo/video-chat/scripts/backup-sqlite.sh`; restores use `bash demo/video-chat/scripts/restore-sqlite.sh`.
- The restore drill and rollout/rollback runbook are in `demo/video-chat/OPS_HARDENING.md`.
- The K-01..K-15 / A-01..A-15 pipeline catalog is `demo/video-chat/ops/metrics-alerts.catalog.json`.
- Backend HTTP/WS/SFU compose services accept OTLP collector binding through `VIDEOCHAT_OTEL_EXPORTER_ENDPOINT`.
- The static guard is `bash demo/video-chat/scripts/check-ops-hardening.sh`.

Host-runtime note:

- `backend-king-php/run-dev.sh` requires `pdo_sqlite` in host PHP.
- If host PHP is missing `pdo_sqlite`, use the Docker compose path above.

## Repeated + Nested Frame Example

The same `/ws` path can carry repeated+nested control frames, not only one flat
chat record per message. A typical server-side IIBIN shape is:

```php
<?php

king_proto_define_schema('PeerState', [
    'peer_id' => ['tag' => 1, 'type' => 'string', 'required' => true],
    'tracks' => ['tag' => 2, 'type' => 'repeated_string'],
]);

king_proto_define_schema('RoomSyncEnvelope', [
    'room' => ['tag' => 1, 'type' => 'string', 'required' => true],
    'peers' => ['tag' => 2, 'type' => 'repeated_PeerState', 'required' => true],
    'ack_ids' => ['tag' => 3, 'type' => 'repeated_string'],
]);

$payload = king_proto_encode('RoomSyncEnvelope', [
    'room' => 'general',
    'peers' => [
        ['peer_id' => 'ada', 'tracks' => ['cam', 'mic']],
        ['peer_id' => 'lin', 'tracks' => ['mic']],
    ],
    'ack_ids' => ['req-42', 'req-43'],
]);

king_websocket_send($socket, $payload, true);
```

This is the same repeated+nested compatibility model documented in
[`documentation/iibin.md`](/home/jochen/projects/king.site/king/documentation/iibin.md):
older readers keep shared fields and ignore newly added fields.

## Commands

Run backend and frontend in separate terminals:

```bash
cd demo/video-chat/backend-king-php
./run-dev.sh
```

```bash
cd demo/video-chat/frontend-vue
npm run dev
```

Useful commands:

- `cd demo/video-chat/backend-king-php && ./run-dev.sh`
- `cd demo/video-chat/frontend-vue && npm run dev`
- `curl -s http://127.0.0.1:18080/`
- `bash demo/video-chat/scripts/backup-sqlite.sh`

## Verification Closure

Automated parity and smoke checks:

```bash
bash demo/video-chat/scripts/smoke.sh
```

`demo/video-chat/scripts/smoke.sh` now verifies:

- backend and frontend launchers plus syntax checks
- demo-scope security policy, no-internal-edge-deploy, optional TURN, secret-management, multi-node architecture, and ops-hardening baseline gates
- docker-compose v1 stack boot (`frontend-vue` + `backend-king-php` + sqlite volume) with runtime migration snapshot and auth/session sanity checks
- backend boot and live `/health` probe
- shared REST/WS error envelope contract (`error-envelope-contract`) for typed REST errors and realtime `system/error` frames
- versioned REST/WS DTO schema contract (`contract-schema-versioning-contract`) for the canonical `contracts/v1` catalog
- UI-parity acceptance matrix contract (`ui-parity-acceptance-matrix-contract`) for executable frontend/backend coverage plus release-blocking gaps
- API/WS catalog drift gate against the canonical versioned contract fixture (`contract-catalog-parity-contract`)
- login route handshake (`/api/auth/login`), authenticated session read, and logout revoke path
- dedicated logout revoke contract (`session-logout-contract`) with persisted revocation metadata assertions
- WLVC wire envelope contract (`wlvc-wire-contract`) for versioned binary frame packaging/parsing parity
- gateway JWT binding contract (`gateway-jwt-binding-contract`) for future Gateway Join `sub/effective_id` + `room/call_id` enforcement
- gateway/backend signaling mapping contract (`gateway-backend-mapping-contract`) for `call.signaling` AMQP payload parity with backend `offer/answer/ice/hangup`
- room join/presence contract (`realtime-presence-contract`)
- room chat fanout contract (`realtime-chat-contract`)
- room reaction stream contract (`realtime-reaction-contract`)
- invite redeem contract (`invite-code-redeem-contract`)
- call signaling bootstrap contract (`realtime-signaling-contract`)
- SFU room-binding/publish/subscribe/frame relay contract (`realtime-sfu-contract`)
- frontend dev-server boot probe

Additional automated coverage:

- backend integration matrix tests:
  - `demo/video-chat/backend-king-php/tests/videochat-integration-matrix-http-contract.sh`
  - `demo/video-chat/backend-king-php/tests/videochat-integration-matrix-realtime-contract.sh`
- frontend Playwright UI-parity journeys:
  - `demo/video-chat/frontend-vue/tests/e2e/ui-parity-journeys.spec.js`
- canonical UI-parity acceptance matrix:
  - `demo/video-chat/contracts/v1/ui-parity-acceptance.matrix.json`
  - `demo/video-chat/backend-king-php/tests/ui-parity-acceptance-matrix-contract.sh`
  - `cd demo/video-chat/frontend-vue && npm run test:e2e:ui-parity`

Release-bound runtime honesty:

- active path is `backend-king-php` + `frontend-vue`
- historical Node runtime remains in-repo as reference only and is outside the active parity gate

## Docker Compose (Frontend + Backend)

New-stack compose file:

- `demo/video-chat/docker-compose.v1.yml`

Run from `demo/video-chat`:

```bash
docker compose -f docker-compose.v1.yml up --build
```

Default host ports:

- frontend: `http://127.0.0.1:5176`
- backend: `http://127.0.0.1:18080`

Override host ports when needed:

```bash
VIDEOCHAT_V1_FRONTEND_PORT=35174 VIDEOCHAT_V1_BACKEND_PORT=38080 docker compose -f docker-compose.v1.yml up --build
```

Optional frontend preflight origin override for non-default routing:

```bash
VIDEOCHAT_V1_BACKEND_ORIGIN=http://127.0.0.1:38080 docker compose -f docker-compose.v1.yml up --build
```

Optional for Docker Desktop/remote FS watcher stability:

```bash
VIDEOCHAT_VUE_POLLING=1 docker compose -f docker-compose.v1.yml up --build
```

Optional TURN relay baseline:

```bash
export VIDEOCHAT_TURN_STATIC_AUTH_SECRET_FILE=/run/secrets/videochat-turn-static-auth-secret
export VIDEOCHAT_TURN_URIS='turn:turn.example.com:3478?transport=udp,turn:turn.example.com:3478?transport=tcp'
export VITE_VIDEOCHAT_ICE_SERVERS="$(php demo/video-chat/scripts/generate-turn-ice-servers.php)"

cd demo/video-chat
VIDEOCHAT_V1_TURN_STATIC_AUTH_SECRET_FILE=/run/secrets/videochat-turn-static-auth-secret \
VIDEOCHAT_V1_TURN_REALM=videochat.example.com \
VITE_VIDEOCHAT_ICE_SERVERS="${VITE_VIDEOCHAT_ICE_SERVERS}" \
docker compose --profile turn -f docker-compose.v1.yml up --build
```

SQLite data is persisted in a mounted Docker volume:

- volume: `videochat-v1-sqlite`
- path in backend container: `/data/video-chat.sqlite`

## Runtime Notes

- backend scaffold endpoint: `GET http://127.0.0.1:18080/`
- backend health endpoint: `GET http://127.0.0.1:18080/health`
- backend runtime preflight endpoint: `GET http://127.0.0.1:18080/api/runtime`
- backend version endpoint: `GET http://127.0.0.1:18080/api/version`
- backend login endpoint: `POST http://127.0.0.1:18080/api/auth/login`
- backend session endpoint: `GET http://127.0.0.1:18080/api/auth/session` (requires token)
- backend logout endpoint: `POST http://127.0.0.1:18080/api/auth/logout` (requires token, revokes session + closes session websocket connections)
- backend RBAC admin probe: `GET http://127.0.0.1:18080/api/admin/ping` (admin only)
- backend admin users list: `GET http://127.0.0.1:18080/api/admin/users?query=&page=1&page_size=10` (admin only)
- backend admin user create: `POST http://127.0.0.1:18080/api/admin/users` (admin only)
- backend admin user update: `PATCH http://127.0.0.1:18080/api/admin/users/{id}` (admin only)
- backend admin user deactivate: `POST http://127.0.0.1:18080/api/admin/users/{id}/deactivate` (admin only)
- backend RBAC moderation probe: `GET http://127.0.0.1:18080/api/moderation/ping` (admin/moderator)
- backend RBAC user probe: `GET http://127.0.0.1:18080/api/user/ping` (authenticated admin/moderator/user)
- backend user settings read: `GET http://127.0.0.1:18080/api/user/settings` (authenticated admin/moderator/user)
- backend user settings update: `PATCH http://127.0.0.1:18080/api/user/settings` (authenticated admin/moderator/user)
- backend avatar upload: `POST http://127.0.0.1:18080/api/user/avatar` (authenticated admin/moderator/user)
- backend avatar file read: `GET http://127.0.0.1:18080/api/user/avatar-files/{filename}` (authenticated admin/moderator/user)
- backend calls list: `GET http://127.0.0.1:18080/api/calls?scope=my&status=all&query=&page=1&page_size=10` (authenticated admin/moderator/user)
- backend call create: `POST http://127.0.0.1:18080/api/calls` (authenticated admin/moderator/user)
- backend call update: `PATCH http://127.0.0.1:18080/api/calls/{id}` (authenticated admin/moderator/user; owner/admin/moderator policy)
- backend call cancel: `POST http://127.0.0.1:18080/api/calls/{id}/cancel` (authenticated admin/moderator/user; owner/admin/moderator policy)
- backend websocket endpoint (compose default): `WS ws://127.0.0.1:18081/ws`
- backend SFU endpoint (compose default): `WS ws://127.0.0.1:18082/sfu`
- backend startup applies ordered sqlite migrations (`schema_migrations`) and exposes migration state in runtime/health responses
- frontend scaffold endpoint: `http://127.0.0.1:5176`
- frontend consumes backend preflight metadata on startup (`app/version + runtime health`)
- the previous Node runtime remains in-repo only as historical reference, not as active dev path

## Scope

This directory is a demo application surface (frontend + backend), not the
source of truth for King v1 runtime guarantees. Repo-level runtime and
transport contracts stay in the extension tests and root documentation.
