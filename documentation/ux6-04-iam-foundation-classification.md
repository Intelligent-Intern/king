# UX6-04 IAM Foundation Dirty Worktree Classification

Date: 2026-05-10

Worker ticket: UX6-04

Source branch: `codex/iam-call-access-e2e-foundation`

Expected source path:
`/home/jochen/projects/king.site/worktrees/codex-iam-call-access-e2e-foundation`

Located source path:
`/home/jochen/projects/king.site/worktrees/king-domain-registry`

Integration branch: `prod-kingrt-do-not-push-to-github`

Worker branch: `agent/ux6-04-iam-foundation-classify`

## Classification

The dirty source worktree remains outside active implementation. No source code,
test wiring, or smoke-script diff was copied.

The committed source branch head
`fdf66140153d24e7a1917d1030911cbae767cbf8` is already the merge base with the
current integration branch, so committed branch content is contained by
`prod-kingrt-do-not-push-to-github`.

The source worktree has four unstaged local modifications and no staged
changes:

```text
M demo/video-chat/contracts/v1/ui-parity-acceptance.matrix.json
M demo/video-chat/frontend-vue/package.json
M demo/video-chat/frontend-vue/tests/contract/iam-call-access-e2e-foundation-contract.mjs
M demo/video-chat/scripts/smoke.sh
```

Those dirty edits try to split `call-access-join.spec.js` out of the shared
Playwright matrix and run it as a focused compose-smoke Call Access gate. The
current integration branch already carries that deploy-smoke proof in stronger
form:

- `demo/video-chat/frontend-vue/package.json` exposes
  `test:e2e:call-access` with the join, seed-matrix, calendar unregistered
  invite, and admin-join-boundary specs under `--workers=1`.
- `demo/video-chat/contracts/v1/ui-parity-acceptance.matrix.json` keeps Call
  Access in UI parity and records a dedicated `frontend:e2e:call-access`
  command while the shared matrix stays chat/layout-focused.
- `demo/video-chat/scripts/smoke.sh` runs a separate compose frontend
  Playwright Call Access gate before the chat/layout matrix.
- The current smoke gate also injects
  `VIDEOCHAT_CALL_ACCESS_SEED_MATRIX_JSON`, uses compose service-DNS backend,
  websocket, and SFU origins, passes explicit service ports, allows insecure
  local websocket transport, and serializes the live Call Access run.
- `demo/video-chat/frontend-vue/tests/contract/iam-call-access-e2e-foundation-contract.mjs`
  pins the current split, service-DNS origins, seed-matrix injection, and
  serialized Call Access smoke command.

One dirty source-only detail is not integrated: the old smoke matrix invocation
adds `--workers=2` to `npm run test:e2e:matrix`. That is not deploy-smoke proof
for the current branch and was not extracted.

## Removal Decision

For active implementation, the source worktree has no missing deploy-smoke proof
to extract.

For cleanup, preserve the source dirty worktree unless a manager explicitly
approves discarding the remaining source-local `test:e2e:matrix --workers=2`
tuning. The deploy-smoke value is superseded, but the dirty tree is not
byte-for-byte empty against integration.

## Proof Commands

```sh
git worktree list --porcelain
git -C /home/jochen/projects/king.site/worktrees/king-domain-registry status --short --branch
git -C /home/jochen/projects/king.site/worktrees/king-domain-registry diff --name-status
git -C /home/jochen/projects/king.site/worktrees/king-domain-registry diff --stat
git -C /home/jochen/projects/king.site/worktrees/king-domain-registry diff --cached --name-status
git -C /home/jochen/projects/king.site/worktrees/king-domain-registry diff --check
git merge-base prod-kingrt-do-not-push-to-github codex/iam-call-access-e2e-foundation
rg -n "test:e2e:call-access|call-access-join|iam-call-access-e2e-foundation|VIDEOCHAT_CALL_ACCESS_SEED_MATRIX_JSON" demo/video-chat/contracts/v1/ui-parity-acceptance.matrix.json demo/video-chat/frontend-vue/package.json demo/video-chat/frontend-vue/tests/contract/iam-call-access-e2e-foundation-contract.mjs demo/video-chat/scripts/smoke.sh
cd demo/video-chat/frontend-vue && node tests/contract/iam-call-access-e2e-foundation-contract.mjs
bash -n demo/video-chat/scripts/smoke.sh
git diff --check
```
