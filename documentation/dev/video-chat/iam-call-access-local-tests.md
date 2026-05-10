# IAM Call Access Local Test Guide

This guide is the repo-local entry point for IAM and call-access proof runs.
Run the frontend commands from `demo/video-chat/frontend-vue`.

## Host-Safe Static Gate

Use this first when validating a small IAM or call-access change locally:

```bash
cd demo/video-chat/frontend-vue
npm run test:ci:iam-call-access:static
```

This runs the host-safe IAM command-hygiene contracts:
`iam-call-access-ci-wire-contract.mjs` and
`iam-local-run-docs-contract.mjs`. The IAM gate does not invoke Background,
Gossip, SFU, MediaSecurity, or BTGF gates.

## Full IAM Contract Gate

The canonical local/CI gate is:

```bash
cd demo/video-chat/frontend-vue
npm run test:ci:iam-call-access
```

This delegates to `npm run test:contract:iam-call-access`, preserving the same
stable script and paths used by CI. Backend SQLite checks use host
`pdo_sqlite` when available. If the host PHP runtime lacks `pdo_sqlite`, the
backend wrappers use the configured Docker PHP fallback and fail if Docker is
unavailable. Treat a missing PHP extension or missing Docker fallback as a
blocked local environment, not as proof that the backend SQLite contracts
passed.

The explicit full form is equivalent:

```bash
cd demo/video-chat/frontend-vue
npm run test:ci:iam-call-access:full
```

## Backend Runtime Proofs

To run only the SQLite-backed IAM backend proof set:

```bash
cd demo/video-chat/frontend-vue
npm run test:ci:iam-call-access:sqlite
```

To run only the docker-proof wrapper discovery gate:

```bash
cd demo/video-chat/frontend-vue
npm run test:ci:iam-call-access:docker
```

The Docker wrapper discovers `*docker-proof.sh` files under
`demo/video-chat/backend-king-php/tests` and fails when no proof scripts are
present.

## Focused Browser E2E Gate

The focused browser gate is:

```bash
cd demo/video-chat/frontend-vue
npm run test:e2e:call-access -- --reporter=list
```

The package script keeps IAM artifacts enabled and runs the current stable
Call Access Playwright specs serially with `--workers=1`, so access-link and
session state do not race between tests. It is intentionally limited to the
Call Access specs listed in `package.json`.

For a single focused spec while developing:

```bash
cd demo/video-chat/frontend-vue
npx playwright test tests/e2e/call-access-join.spec.js --workers=1 --reporter=list
```

## Compose Smoke Gate

Use compose when the proof needs the same backend, websocket, SFU endpoint, and
frontend container shape as CI:

```bash
VIDEOCHAT_SMOKE_COMPOSE_ONLY=1 \
VIDEOCHAT_SMOKE_REQUIRE_COMPOSE=1 \
bash demo/video-chat/scripts/smoke.sh
```

To collect local failure artifacts in the same directory shape as CI:

```bash
VIDEOCHAT_SMOKE_COMPOSE_ONLY=1 \
VIDEOCHAT_SMOKE_REQUIRE_COMPOSE=1 \
VIDEOCHAT_SMOKE_ARTIFACTS_DIR=compat-artifacts/video-chat-smoke \
bash demo/video-chat/scripts/smoke.sh
```

Artifacts include `playwright-test-results`, `playwright-report` when present,
`manifest.env`, `compose-ps.txt`, `compose-all.log`, and per-service logs.

Use `VIDEOCHAT_SMOKE_SKIP_IAM_CI_GATE=1` only for local debugging after the
host-safe IAM gate has already passed in the same checkout.
