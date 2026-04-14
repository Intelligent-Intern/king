# Video Chat Frontend (Vue)

This directory is the active Vue + Vite frontend for the new video-chat stack.

## Routes

- `/login`
- `/admin/overview`
- `/admin/users`
- `/admin/calls`
- `/user/dashboard`
- `/workspace/call/:roomId?`

All listed routes are now backend-bound (no placeholder-only route left):

- `/admin/overview`: live metrics/widgets + admin branding parity panel
- `/admin/users`: server-driven CRUD table with search/pagination/deactivate/reactivate
- `/admin/calls`: backend-bound CRUD/calendar/invite actions
- `/user/dashboard`: backend-bound calls list/calendar + schedule/edit + invite redeem
- `/workspace/call/:roomId?`: realtime snapshots/moderation/chat/control-bar flows with reconnect/auth state machine

## Call workspace (backend-bound)

`/workspace/call/:roomId?` is now wired to the active King backend contracts:

- authenticated websocket session attach (`/ws`) with reconnect + resync
- server-driven room presence snapshots (`room/snapshot`, `room/joined`, `room/left`)
- server-driven lobby queue/admitted snapshots (`lobby/snapshot`) with moderator actions
- room-scoped chat + typing (`chat/send`, `chat/message`, `typing/start`, `typing/stop`)
- room invite create/redeem flows (`/api/invite-codes`, `/api/invite-codes/redeem`)

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

Run the mock-parity journey suite directly:

```bash
npx playwright test tests/e2e/mock-parity-journeys.spec.js
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

- `http://127.0.0.1:5174`

Backend runtime preflight:

- frontend probes `GET /api/runtime` on backend startup path
- default inferred backend origin: `http://<current-host>:18080`

Environment overrides:

- `VIDEOCHAT_VUE_HOST` (default `127.0.0.1`)
- `VIDEOCHAT_VUE_PORT` (default `5174`)
- `VITE_VIDEOCHAT_BACKEND_ORIGIN` (optional full origin override, e.g. `http://127.0.0.1:18080`)
- `VITE_VIDEOCHAT_BACKEND_PORT` (optional inferred backend port override, default `18080`)
