# IAM11-08 Foreign Personalized Mismatch Current Proof

Stand: 2026-05-10

## Source Branch

- Branch: `local/iam-e2e-foreign-personalized-mismatch`
- Observed HEAD: `17618082`
- Source value: wrong-host foreign personalized-link mismatch denial plus
  correct-host decline/update-confirm-email browser journeys.

The source tip added a focused browser spec and a strong-mismatch UI flow. The
current integration tree now carries the needed value through a smaller
extraction instead of a wholesale historical branch import.

## Current Proof

The current integration proves the denied wrong-host/no-leak value:

- `demo/video-chat/frontend-vue/tests/contract/call-access-mismatch-no-leak-states-contract.mjs`
  checks that denied strong personalized-link mismatch states are rendered from
  stable error codes, stay out of lobby/workspace states, keep the current
  session authoritative, and do not render foreign person, call, calendar,
  organization, or denied-session data.
- `demo/video-chat/frontend-vue/tests/contract/call-access-strong-mismatch-privacy-contract.mjs`
  pins the Playwright wrong-host path and the correct-host
  decline/update-confirm-email paths in
  `demo/video-chat/frontend-vue/tests/e2e/call-access-join.spec.js`, including
  foreign-data sentinels, current bearer use, verified current-session payload,
  no workspace navigation, no denied token adoption, manual host verification,
  continue-without-update, and account-update confirmation to the logged-in
  account email.
- `demo/video-chat/backend-king-php/tests/call-access-strong-mismatch-privacy-contract.php`
  proves wrong-user join, wrong-host session issuance, and unverified-host
  session issuance return redacted `call_access_forbidden` envelopes, omit
  private result payloads, and do not persist denied sessions.
- `demo/video-chat/frontend-vue/src/domain/calls/access/CallAccessJoinFooter.vue`
  and
  `demo/video-chat/frontend-vue/src/domain/calls/access/callAccessPersonalizedMismatch.ts`
  keep the correct-host mismatch UI and helper state extracted from
  `JoinView.vue`.

This is a current no-leak, wrong-host denial, and correct-host browser proof.

## Completed Source Value

The correct-host decline/update-confirm-email browser value is covered without
importing the broad historical branch:

- the join footer accepts manual host-name verification and then offers
  continue-without-update;
- the join footer can request
  `/api/call-access/{id}/account-update-confirmation` after manual display-name
  re-entry from the current account session;
- the Playwright proof covers the source-equivalent decline and
  update-confirm-email journeys through
  `strong personalized-link mismatch correct host supports decline and
  update-confirm-email without foreign data`.

## Closure

IAM11-08 is closed in the current integration branch. The historical branch
remains source evidence only; its relevant value is now represented by the
focused JoinView extraction, the strong-mismatch helper module, the browser E2E
proof, and the contract gate above.

No Background, Gossip, SFU, DNS, Certbot, deploy, broad UI/E2E import, branch
checkout, merge, reset, deletion, or push action was performed.
