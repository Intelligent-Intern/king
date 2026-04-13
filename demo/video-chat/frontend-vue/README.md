# Video Chat Frontend (Vue)

This directory is the active Vue + Vite frontend for the new video-chat stack.

## Routes scaffolded

- `/login`
- `/admin/overview`
- `/admin/users`
- `/admin/calls`
- `/user/dashboard`
- `/workspace/call/:roomId?`

## Route guard behavior

- unauthenticated users are redirected to `/login`
- authenticated users are redirected away from `/login` to their role default page
- admin-only and user-only routes are enforced by role-aware guards

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
