# IAM11-08 Frontend Access Integration Note

Scope reviewed: current working-tree patch in `/home/jochen/projects/king.site/king`, limited to JoinView, callAccessSession, publicMessages, call-access e2e/contract, and seed-matrix contract changes. Runtime/test source was not edited.

## Files Changed In Reviewed Scope

- `demo/video-chat/contracts/v1/iam-call-access-seeding.matrix.json`
- `demo/video-chat/frontend-vue/package.json`
- `demo/video-chat/frontend-vue/src/domain/calls/access/JoinView.css`
- `demo/video-chat/frontend-vue/src/domain/calls/access/JoinView.vue`
- `demo/video-chat/frontend-vue/src/domain/calls/access/callAccessSession.ts`
- `demo/video-chat/frontend-vue/src/modules/localization/publicMessages.js`
- `demo/video-chat/frontend-vue/tests/contract/call-access-strong-mismatch-privacy-contract.mjs`
- `demo/video-chat/frontend-vue/tests/contract/call-access-verified-context-ui-contract.mjs`
- `demo/video-chat/frontend-vue/tests/e2e/call-access-join.spec.js`
- `demo/video-chat/frontend-vue/tests/e2e/helpers/callAccessSeedMatrix.js`

## Integration Hunks

- Strong personalized-link mismatch UI is added in `JoinView.vue` lines 169-237: host-name verification, optional first/last name re-entry, decline, and confirmation-email buttons.
- `JoinView.vue` lines 325-331 and 362-378 add/reset `personalizedMismatch` state.
- `JoinView.vue` lines 380-399 interprets backend `error.details.mismatch === "strong_personalized_link"` with `fields.host_name` states.
- `JoinView.vue` lines 779-830 sends host/update/profile decisions through `loginWithCallAccess` and reuses the existing admission wait after a successful response.
- `callAccessSession.ts` lines 14-21 surfaces structured error details, lines 44-61 serializes `host_name`, `mismatch_update_decision`, and `profile_update`, and lines 112-117 returns `errorDetails` on non-OK responses.
- `publicMessages.js` adds the UI labels used by the new mismatch flow: host name, first name, last name, verify host, continue without updating, send confirmation email, and mismatch guidance.
- `call-access-join.spec.js` lines 1032-1303 adds the focused Playwright proof for correct-host mismatch branches: initial denial, host verification, decline branch, update-confirm-email branch, session body assertions, and foreign-data non-disclosure.
- `callAccessSeedMatrix.js` lines 39-59, 95-108, 237-342, and 507-724 add organization seed support, direct-join decision modeling, session override support, decision logging, and `/api/calls/resolve` plus `/api/calls/{id}` authorization behavior.
- `iam-call-access-seeding.matrix.json` lines 31-222 adds organizations and call organization keys; lines 294-407 adds direct-join scenario rows for system admin, org admin, owner, guest-list user, forged client role, and forged token.

## Findings

1. Build/release risk: `JoinView.vue` is now 887 lines, exceeding the King hard target of keeping files below 800 lines. The IAM11-08 UI hunk adds enough new state, parsing, and action wiring that integration should either extract the personalized mismatch flow or otherwise reduce the file before merge.

2. Scope risk: `demo/video-chat/frontend-vue/package.json` only changes background-filter/background-runtime scripts in the current diff. That is outside IAM11-08 frontend/access and conflicts with the requested no Background scope. It should not be part of this integration hunk.

3. Coverage risk: the new direct-join rows and helper logic are not visibly consumed by the existing call-access seed-matrix spec. `call-access-seed-matrix.spec.js` still covers the older principal inventory and personal link flow, while `directJoinDecisionForSeedUser`, `getSeedOrganization`, `decisionLog`, and the new direct-join scenario keys appear unused. Integration should add or point to the e2e/contract consumer before treating the direct-join matrix expansion as covered.

4. Contract-shape risk: `callPayload()` now returns `my_participation: false` when there is no viewer participation row. The previous shape was `null` for no viewer. If production/API consumers distinguish `null` from `false`, this helper may mask a response-shape mismatch in e2e tests.

5. UX/message risk: `applyPersonalizedMismatchError()` always sets `public.join.personalized_mismatch_verify_host`, including the `host_name: "verified"` decision-required state. The UI shows the update/decline controls, but the error copy still says to verify the host name. Integration should decide whether a separate decision-required message is required.

## Verification Status

No runtime or test files were edited. No test command was run for this analysis-only pass.
