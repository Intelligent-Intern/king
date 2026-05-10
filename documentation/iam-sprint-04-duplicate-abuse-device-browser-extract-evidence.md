# IAM Sprint 04 Duplicate Abuse Device/Browser Extract Evidence

Worker ticket: IAM4-07

Source worktree reviewed:
`/home/jochen/projects/king.site/worktrees/iam-e2e-duplicate-abuse-device-browser-proof-3`

Source branch:
`local/iam-e2e-duplicate-abuse-device-browser-proof-3`

Source tip:
`2cd67944d703767871327c64df89f0d4005fcddc`

Current target branch:
`agent/iam-s4-07-duplicate-abuse-extract`

## Result

The duplicate-abuse device/browser proof value is already covered by the
current Sprint 03/04 IAM gate. No source or test code was ported.

The source branch is a stale, broad IAM branch. Its tip commit,
`2cd67944 Prove duplicate link abuse across devices`, adds a focused
`call-access-duplicate-link-device-browser.spec.js`, extends the old
parallel-account-tabs proof, and wires that spec into an older
`test:e2e:call-access` command. The current branch has already extracted the
portable proof value into stable, focused IAM contracts.

## Supersession Map

| Source proof intent | Current deployed IAM gate evidence | Reconcile decision |
| --- | --- | --- |
| Same personalized link used from two browser/device contexts must settle as one accepted call-access session and one conflict. | `demo/video-chat/frontend-vue/tests/contract/call-access-duplicate-device-browser-contract.mjs` pins the parallel-context browser proof and deterministic `[200, 409]` fixture. | Superseded. |
| Each browser/device must redeem with its own bearer token and verified user/session snapshot. | `call-access-duplicate-device-browser-contract.mjs`, `call-access-duplicate-abuse-contract.mjs`, and `call-access-verified-context-ui-contract.mjs` assert separate `sessionAuthorization` and verified body payloads for account A and B. | Superseded. |
| Rejected duplicate browser/device must keep its original session and never adopt winner or rejected call-access tokens. | `call-access-duplicate-device-browser-contract.mjs`, `call-access-duplicate-invite-replay-contract.mjs`, `call-access-mismatch-no-leak-states-contract.mjs`, and `call-access-logout-login-switch-contract.mjs` all pin no token bleed/no rebinding behavior. | Superseded with broader coverage. |
| Duplicate conflict payload must not leak foreign call, account, link, or session data. | `call-access-duplicate-device-browser-contract.mjs`, `call-access-duplicate-abuse-contract.mjs`, and `call-access-strong-mismatch-audit-redaction-contract.mjs` pin safe conflict/forbidden payloads and redacted audit fields. | Superseded with explicit redaction coverage. |
| Backend must reject stale verified-session/user replay as a deterministic conflict. | `demo/video-chat/backend-king-php/domain/calls/call_access_session.php` is pinned by `call-access-duplicate-device-browser-contract.mjs`, `call-access-duplicate-abuse-contract.mjs`, `call-access-duplicate-invite-replay-contract.mjs`, `call-access-logout-login-switch-contract.mjs`, and `call-access-session-route-guard-contract.php`. | Superseded. |
| Backend must reject duplicate generated session ids across normal sessions and call-access sessions. | `call-access-duplicate-device-browser-contract.mjs`, `call-access-duplicate-abuse-contract.mjs`, `call-access-tampered-verified-context-contract.mjs`, and `call-access-session-fixation-contract.php` pin `videochat_call_access_session_id_available()` and `session_id_not_available`. | Superseded with backend fixation proof. |
| Old source spec should be wired into `test:e2e:call-access`. | Current `test:contract:iam-call-access` wires the stable duplicate-device/browser contract, and `test:e2e:call-access` retains the live `call-access-join.spec.js` path containing the parallel-context browser scenario. | Superseded without reintroducing stale E2E spec churn. |

## Current Stable Coverage

The current gate already includes:

- `demo/video-chat/frontend-vue/tests/contract/call-access-duplicate-device-browser-contract.mjs`
- `demo/video-chat/frontend-vue/tests/contract/call-access-duplicate-abuse-contract.mjs`
- `demo/video-chat/frontend-vue/tests/contract/call-access-duplicate-invite-replay-contract.mjs`
- `demo/video-chat/frontend-vue/tests/contract/call-access-logout-login-switch-contract.mjs`
- `demo/video-chat/frontend-vue/tests/contract/call-access-verified-context-ui-contract.mjs`
- `demo/video-chat/frontend-vue/tests/contract/call-access-tampered-verified-context-contract.mjs`
- `demo/video-chat/backend-king-php/tests/call-access-session-route-guard-contract.php`
- `demo/video-chat/backend-king-php/tests/call-access-session-fixation-contract.php`

Release-gate wiring is already present:

- `demo/video-chat/frontend-vue/package.json` includes
  `call-access-duplicate-device-browser-contract.mjs`,
  `call-access-duplicate-abuse-contract.mjs`, and
  `call-access-duplicate-invite-replay-contract.mjs` in
  `test:contract:iam-call-access`.
- `demo/video-chat/contracts/v1/ui-parity-acceptance.matrix.json` lists the
  same stable duplicate contracts in `frontend:contract:iam-call-access`.
- `demo/video-chat/frontend-vue/tests/contract/iam-call-access-ci-wire-contract.mjs`
  pins those paths as required IAM contract coverage.

## Why No Code Was Ported

The source branch contains a broad stale diff and the tip commit depends on old
`call-access-parallel-account-tabs` wiring and a separate Playwright spec name.
Current IAM coverage consolidated the portable behavior into stable contracts
and the existing `call-access-join.spec.js` parallel-context browser scenario.
Porting the old spec or package wiring would duplicate coverage and increase
release-gate surface without adding a stronger contract.

The source worktree was only inspected. It was not reset, cleaned, deleted, or
rewritten.

## Commands Used

Inspection:

```sh
git -C /home/jochen/projects/king.site/worktrees/iam-e2e-duplicate-abuse-device-browser-proof-3 status --short --branch
git -C /home/jochen/projects/king.site/worktrees/iam-e2e-duplicate-abuse-device-browser-proof-3 log --oneline --decorate --max-count=12
git -C /home/jochen/projects/king.site/worktrees/iam-e2e-duplicate-abuse-device-browser-proof-3 show --stat --name-status 2cd67944
git -C /home/jochen/projects/king.site/worktrees/iam-e2e-duplicate-abuse-device-browser-proof-3 show 2cd67944 -- demo/video-chat/frontend-vue/tests/e2e/call-access-duplicate-link-device-browser.spec.js demo/video-chat/frontend-vue/tests/contract/call-access-parallel-account-tabs-contract.mjs demo/video-chat/backend-king-php/domain/calls/call_access_session.php demo/video-chat/backend-king-php/tests/call-access-parallel-account-tabs-contract.php demo/video-chat/frontend-vue/package.json demo/video-chat/contracts/v1/ui-parity-acceptance.matrix.json
rg -n "call-access-duplicate-device-browser-contract|call-access-duplicate-abuse-contract|call-access-duplicate-invite-replay-contract|same personalized link|session_id_not_available" demo/video-chat
```

Proof:

```sh
cd demo/video-chat/frontend-vue && node tests/contract/call-access-duplicate-device-browser-contract.mjs
cd demo/video-chat/frontend-vue && node tests/contract/call-access-duplicate-abuse-contract.mjs
cd demo/video-chat/frontend-vue && node tests/contract/call-access-duplicate-invite-replay-contract.mjs
cd demo/video-chat/frontend-vue && node tests/contract/call-access-logout-login-switch-contract.mjs
cd demo/video-chat/frontend-vue && node tests/contract/iam-call-access-ci-wire-contract.mjs
php demo/video-chat/backend-king-php/tests/call-access-session-fixation-contract.php
git diff --check
```

## Conclusion

There is no unique current IAM value left to extract from
`local/iam-e2e-duplicate-abuse-device-browser-proof-3`. The current IAM gate
covers the device/browser duplicate-abuse behavior more narrowly and more
stably than the stale source branch.
