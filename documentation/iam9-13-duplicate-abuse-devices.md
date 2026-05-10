# IAM9-13 Duplicate Abuse Across Devices

Worker branch:
`agent/iam9-13-duplicate-abuse-devices`

Source branch inspected:
`local/iam-e2e-duplicate-abuse-device-browser-proof-3`

Source tip:
`2cd67944d703767871327c64df89f0d4005fcddc`

Integration base:
`prod-kingrt-do-not-push-to-github`

## Extracted Value

IAM9-13 does not need a blind merge from the source branch. The reusable runtime
value is the cross-device duplicate-abuse race proof for one personalized call
access link:

- two independent browser/device sessions open the same personalized join link
  concurrently;
- each session sends its own bearer token plus verified user/session snapshot;
- runtime settlement is deterministic: one call-access session is accepted and
  the competing device receives `409 call_access_conflict`;
- the rejected device keeps its original session and never adopts the accepted
  or rejected call-access token;
- the conflict UI and payload stay safe and do not expose foreign call, account,
  link, or session data.

That value is already represented on the current branch by the focused browser
scenario in
`demo/video-chat/frontend-vue/tests/e2e/call-access-join.spec.js` and pinned by
`demo/video-chat/frontend-vue/tests/contract/call-access-duplicate-device-browser-contract.mjs`.
The source branch's standalone
`demo/video-chat/frontend-vue/tests/e2e/call-access-duplicate-link-device-browser.spec.js`
is stale extraction evidence, not a stronger runtime path to port.

## Current Proof Surface

Current IAM9-13 proof files:

- `demo/video-chat/frontend-vue/tests/e2e/call-access-join.spec.js`
- `demo/video-chat/frontend-vue/tests/contract/call-access-duplicate-device-browser-contract.mjs`
- `demo/video-chat/frontend-vue/tests/contract/call-access-duplicate-abuse-contract.mjs`
- `demo/video-chat/frontend-vue/tests/contract/call-access-duplicate-invite-replay-contract.mjs`
- `demo/video-chat/backend-king-php/domain/calls/call_access_session.php`
- `demo/video-chat/backend-king-php/http/module_calls_access.php`

The backend contract remains runtime-significant: stale verified user/session
replay is rejected as `session_context_changed`, generated call-access session
ids are unavailable if they already exist in either normal sessions or
call-access sessions, and the HTTP route maps conflicts to `409
call_access_conflict` without returning private call/link payloads.

## Scope Boundary

IAM9-13 is only the abuse/race across devices proof. It does not import the
separate IAM9-14 baseline branch
`local/iam-e2e-duplicate-link-abuse-device-browser`, does not add a new
Playwright spec name, and does not broaden package E2E wiring.

No Background, Gossip, SFU, MediaSecurity, BTGF, `SPRINT.md`, `BACKLOG.md`, or
`READYNESS_TRACKER.md` files were edited.
