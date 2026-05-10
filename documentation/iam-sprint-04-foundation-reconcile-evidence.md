# IAM Sprint 04 Foundation Reconcile Evidence

Worker ticket: IAM4-01

Dirty source worktree reviewed:
`/home/jochen/projects/king.site/worktrees/king-domain-registry`

Dirty source branch:
`codex/iam-call-access-e2e-foundation`

Current target branch:
`agent/iam-s4-01-foundation-reconcile`

## Result

The dirty foundation worktree is fully superseded by the deployed IAM gate on
`prod-kingrt-do-not-push-to-github`. No source code or test wiring was ported.

The dirty worktree still contains local edits to:

- `demo/video-chat/contracts/v1/ui-parity-acceptance.matrix.json`
- `demo/video-chat/frontend-vue/package.json`
- `demo/video-chat/frontend-vue/tests/contract/iam-call-access-e2e-foundation-contract.mjs`
- `demo/video-chat/scripts/smoke.sh`

Those edits try to split the live Call Access Playwright journey out of the
shared E2E matrix and into a focused compose smoke command. The current branch
already has that split and has stronger runtime coverage.

## Supersession Map

| Dirty source intent | Current deployed IAM gate evidence | Reconcile decision |
| --- | --- | --- |
| Remove `call-access-join.spec.js` from `test:e2e:matrix`. | `demo/video-chat/frontend-vue/package.json` defines `test:e2e:matrix` with chat/layout specs only. `demo/video-chat/contracts/v1/ui-parity-acceptance.matrix.json` also omits the Call Access join spec from `frontend:e2e:matrix`. | Superseded. |
| Keep Call Access join coverage in UI parity. | `frontend:e2e:ui-parity` still lists `frontend-vue/tests/e2e/call-access-join.spec.js`. | Superseded without weakening release parity. |
| Add a focused `test:e2e:call-access` smoke path. | `demo/video-chat/frontend-vue/package.json` exposes `test:e2e:call-access` and includes `call-access-join`, seed matrix, calendar unregistered invite, and admin join boundaries with `--workers=1`. | Superseded with broader focused IAM coverage. |
| Run focused Call Access Playwright inside compose smoke. | `demo/video-chat/scripts/smoke.sh` runs a separate "compose frontend Playwright call-access gate" before the chat/layout matrix. | Superseded. |
| Use backend service DNS for compose Call Access E2E. | `smoke.sh` sets `VITE_VIDEOCHAT_BACKEND_ORIGIN='http://videochat-backend-v1:18080'` for the focused Call Access gate. | Superseded. |
| Preserve the broader matrix on the host-style backend origin. | `smoke.sh` keeps `VITE_VIDEOCHAT_BACKEND_ORIGIN='http://127.0.0.1:${compose_backend_port}'` for `test:e2e:matrix`. | Superseded. |

## Stronger Current Coverage

The deployed IAM gate includes current protections that are absent from the
dirty source patch:

- `smoke.sh` injects `VIDEOCHAT_CALL_ACCESS_SEED_MATRIX_JSON` into the frontend
  compose container, allowing deterministic Call Access seed-matrix coverage
  even when `contracts/v1` is outside the container mount.
- `smoke.sh` passes `VITE_VIDEOCHAT_BACKEND_PORT`, `VITE_VIDEOCHAT_WS_ORIGIN`,
  `VITE_VIDEOCHAT_WS_PORT`, `VITE_VIDEOCHAT_SFU_ORIGIN`,
  `VITE_VIDEOCHAT_SFU_PORT`, and `VITE_VIDEOCHAT_ALLOW_INSECURE_WS` for the
  focused live Call Access path.
- `iam-call-access-e2e-foundation-contract.mjs` asserts that the shared matrix
  must not execute the live Call Access join spec, and that the focused Call
  Access command must list both `call-access-join.spec.js` and
  `call-access-seed-matrix.spec.js`.
- `iam-call-access-e2e-foundation-contract.mjs` pins service-DNS backend and
  websocket origins, seed-matrix injection, serialized Call Access Playwright
  execution, and host-style origin isolation for the broader chat/layout matrix.
- `test:contract:iam-call-access` also runs the stable IAM CI wire contract,
  Docker runtime proof wrapper, guest-list direct join proof, cross-org proof,
  membership-removal proof, stale organization role proof, and the SQLite
  runtime proof wrapper.

## Commands Used

Inspection:

```sh
git -C /home/jochen/projects/king.site/worktrees/king-domain-registry status --short --branch
git -C /home/jochen/projects/king.site/worktrees/king-domain-registry diff --stat
git -C /home/jochen/projects/king.site/worktrees/king-domain-registry diff -- demo/video-chat/frontend-vue/package.json demo/video-chat/frontend-vue/tests/contract/iam-call-access-e2e-foundation-contract.mjs demo/video-chat/scripts/smoke.sh demo/video-chat/contracts/v1/ui-parity-acceptance.matrix.json
rg -n "iam-call-access-e2e-foundation|test:contract:iam-call-access|test:e2e:call-access|test:e2e:matrix|VIDEOCHAT_CALL_ACCESS_SEED_MATRIX_JSON" demo/video-chat
```

Proof:

```sh
cd demo/video-chat/frontend-vue && node tests/contract/iam-call-access-e2e-foundation-contract.mjs
cd demo/video-chat/frontend-vue && node tests/contract/iam-call-access-ci-wire-contract.mjs
bash -n demo/video-chat/scripts/smoke.sh
git diff --check
```

## Conclusion

The dirty source worktree should remain untouched for manager/manual cleanup.
There is no unique current IAM value to extract into source. The only IAM4-01
output is this evidence record proving the dirty foundation edits are already
covered, and covered more strongly, by the deployed IAM gate.
