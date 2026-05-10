# IAM11-13 Parallel Account Tabs Proof

Scope:
- Frontend E2E/contract proof only.
- No backend implementation changes.
- No Background, Gossip, SFU, MediaSecurity, or BTGF changes.

Proof anchor:
- `demo/video-chat/frontend-vue/tests/e2e/call-access-join.spec.js`
- Test: `same personalized link in parallel contexts keeps account sessions isolated`
- Contract pin: `demo/video-chat/frontend-vue/tests/contract/call-access-verified-context-ui-contract.mjs`

What the E2E proves:
- Opens the same personalized link in two browser contexts at the same time with `Promise.all`.
- Seeds account A and account B with distinct localStorage session records before navigation.
- Verifies `/api/auth/session-state` uses each context's own bearer token.
- Verifies `/api/call-access/{accessId}/session` sends `Bearer ${accountA.sessionToken}` for account A and `Bearer ${accountB.sessionToken}` for account B.
- Verifies the session POST bodies keep each account's verified context separate: `verified_user_id`/`verified_session_id` from account A never cross into account B, and account B never crosses into account A.
- Drives a mixed outcome for the same call link: account A receives the accepted call-access session, account B receives a conflict.
- Verifies account B keeps its original `storedB.sessionToken` and does `not.toBe(accountA.issuedCallAccessToken)` or the rejected conflict token.
- Verifies the rejected account does not render foreign call/session/person data and does not navigate into `/workspace/call`.
- Verifies each context performs exactly one join GET and one session POST.

Result:
- Multiple browser contexts/accounts in the same call keep identity and session state isolated across verified context capture, session issuance, localStorage persistence, UI rendering, and navigation.
