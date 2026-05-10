# IAM Sprint 05 Browser Proof Path

Date: 2026-05-10

Worker: IAM5-18

Branch: `agent/iam-s5-18-browser-proof-path`

Worktree:
`/home/jochen/projects/king.site/worktrees/iam-s5-18-browser-proof-path`

Base:
`prod-kingrt-do-not-push-to-github` at `17c851ace650903f17b8b02776028d0d01a9b783`

## Scope

This pass only checked the focused IAM browser proof invocation already present
in the current integration branch. It did not add or run Background, Gossip,
SFU, MediaSecurity, BTGF, deploy, or sprint-checklist coverage.

No product UI repair was needed.

## Discovered Command

Working directory:
`demo/video-chat/frontend-vue`

Local dependency preparation for the fresh worktree:

```sh
npm ci
```

Focused browser proof invocation:

```sh
npm run test:e2e:call-access
```

The package script expands to:

```sh
PLAYWRIGHT_IAM_CALL_ACCESS_ARTIFACTS=1 playwright test tests/e2e/call-access-join.spec.js tests/e2e/call-access-seed-matrix.spec.js tests/e2e/call-access-calendar-unregistered-invite.spec.js tests/e2e/call-access-admin-join-boundaries.spec.js --workers=1
```

## Result

Command:

```sh
npm run test:e2e:call-access
```

Result: PASS, exit code 0.

Summary:

```text
Running 12 tests using 1 worker
12 passed (10.7s)
```

The run covered only the four focused IAM call-access Playwright specs listed in
the package script:

- `tests/e2e/call-access-join.spec.js`
- `tests/e2e/call-access-seed-matrix.spec.js`
- `tests/e2e/call-access-calendar-unregistered-invite.spec.js`
- `tests/e2e/call-access-admin-join-boundaries.spec.js`

## Repair Status

No local invocation repair was required. No test file was changed.
