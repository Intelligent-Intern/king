# Video Chat Frontend (Vue)

This directory is the active Vue + Vite frontend for the new video-chat stack.

## Routes

- `/login`
- `/admin/overview`
- `/admin/users`
- `/admin/calls`
- `/user/dashboard`
- `/workspace/call/:callRef?`

All listed routes are now backend-bound (no placeholder-only route left):

- `/admin/overview`: live metrics/widgets + admin branding parity panel
- `/admin/users`: server-driven CRUD table with search/pagination/deactivate/reactivate
- `/admin/calls`: backend-bound CRUD/calendar/invite actions
- `/user/dashboard`: backend-bound calls list/calendar + schedule/edit + invite redeem
- `/workspace/call/:callRef?`: realtime snapshots/moderation/chat/control-bar flows with reconnect/auth state machine

## Call workspace (backend-bound)

`/workspace/call/:callRef?` is now wired to the active King backend contracts:

- authenticated websocket session attach (`/ws`) with reconnect + resync
- server-driven room presence snapshots (`room/snapshot`, `room/joined`, `room/left`)
- server-driven lobby queue/admitted snapshots (`lobby/snapshot`) with moderator actions
- room-scoped chat + typing (`chat/send`, `chat/message`, `typing/start`, `typing/stop`)
- room invite create/redeem flows (`/api/invite-codes`, `/api/invite-codes/redeem`)
- invite-only waiting-room flow (`queued` -> explicit host/admin/moderator admit) with lobby badge + toast notification
- access-mode aware entry semantics (`invite_only` queue vs `free_for_all` direct room join)

## Route guard behavior

- unauthenticated users are redirected to `/login`
- authenticated users are redirected away from `/login` to their role default page
- admin-only and user-only routes are enforced by role-aware guards
- forbidden redirect targets are sanitized after login (role-safe redirect only)
- session recovery is backend-authoritative (`/api/auth/session`) and invalid sessions fail closed

## Settings + Session

- workspace settings modal is backend-backed:
  - `GET/PATCH /api/user/settings`
  - `POST /api/user/avatar`
- saved theme/time-format is applied globally from session state
- logout remains fail-closed and drops local session snapshot immediately
## Run locally

```bash
cd demo/video-chat/frontend-vue
npm install
npm run dev
```

Build:

```bash
npm run build
```

Run frontend click-through e2e tests:

```bash
npm run test:e2e
```

Run the UI-parity journey suite directly:

```bash
npx playwright test tests/e2e/ui-parity-journeys.spec.js
```

Run e2e tests headed (visual):

```bash
npm run test:e2e:headed
```

Run WLVC wire-envelope contract test (binary frame packaging/parsing parity):

```bash
npm run test:contract:wlvc
```

Default endpoint:

- `http://127.0.0.1:5176`

Backend runtime preflight:

- frontend probes `GET /api/runtime` on backend startup path
- default inferred backend origin: `http://<current-host>:18080`

Environment overrides:

- `VIDEOCHAT_VUE_HOST` (default `127.0.0.1`)
- `VIDEOCHAT_VUE_PORT` (default `5176`)
- `VITE_VIDEOCHAT_BACKEND_ORIGIN` (optional full origin override, e.g. `http://127.0.0.1:18080`)
- `VITE_VIDEOCHAT_BACKEND_PORT` (optional inferred backend port override, default `18080`)
- `VITE_VIDEOCHAT_WS_PORT` (optional WS gateway port override, default `18081`)
- `VITE_VIDEOCHAT_SFU_PORT` (optional SFU gateway port override, default `18082`)
- `VITE_VIDEOCHAT_ENABLE_MEDIAPIPE` (optional, default `false`; set `true` to allow MediaPipe segmentation backend)
- `VITE_VIDEOCHAT_ENABLE_TFJS` (optional, default `false`; set `true` to allow TFJS segmentation backend)
- `VITE_VIDEOCHAT_DEBUG_LOGS` (optional, default `false`; set `true` to re-enable verbose codec/SFU/debug console output)
