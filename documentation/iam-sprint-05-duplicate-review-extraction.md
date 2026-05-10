# IAM Sprint 05 Duplicate Review Extraction

Date: 2026-05-10

Scope: IAM5-07 duplicate review and abuse proof extraction only. Source
branches and source worktrees were inspected read-only; no source branch was
deleted, reset, rebased, merged, checked out, cleaned, or edited. Background,
Gossip, SFU, MediaSecurity, and BTGF areas were not touched.

Base checked: local `prod-kingrt-do-not-push-to-github` at
`49e5cdae858a3b572654ade6040f4b50d037632e`.

## Source Branches Inspected

| Branch | Head | State | Diff against base | Duplicate-review value | Decision |
| --- | --- | --- | --- | --- | --- |
| `codex/iam-e2e-duplicate-review-abuse-integration` | `4f8159fdc9a5a3b4de421ada3fae5a6398e05adc` | Clean source worktree | 218 files, 43716 insertions, 3151 deletions | Integrated duplicate review email, identity-mismatch review flow, duplicate race, and broad IAM proof set. | Preserve as source evidence; not a safe narrow merge. |
| `agent/iam-e2e-duplicate-review-email` | `a89ffcff40faf421c0c1be9bb1d02c39eca12349` | Branch only, no source worktree listed | 11 files, 2279 insertions | Narrowest source for duplicate review flags and account-update email confirmation, but still adds backend runtime files and routes. | Evidence only; current base lacks the implementation contracts needed to port safely in this lane. |
| `local/iam-e2e-review-abuse-cross-browser-proof-3` | `0e02e60542734f5221b1bd85fee7154e002e5077` | Clean source worktree | 210 files, 41117 insertions, 3084 deletions | Cross-browser duplicate personalized-link abuse, current-session preservation, and private warning payload rules. | Duplicate-abuse portion is superseded by current contracts; review/email feature remains deferred. |
| `local/iam-e2e-review-warning-modal-policy-proof-3` | `bdd29ffd2bc2a7ba9cbec4711dfc043931639044` | Clean source worktree | 210 files, 41174 insertions, 3083 deletions | Warning modal policy, host-name verification fields, and account-update confirmation flow. | Source-only feature branch; not ported without backend review/account-confirmation implementation. |
| `local/iam-e2e-light-mismatch-logging-proof-2` | `33a7cdf9c4696207fc53ac48afad8762c8549a2e` | Clean source worktree | 200 files, 38131 insertions, 3039 deletions | Same-account light mismatch reopen should not create duplicate review state. | Deferred; current base does not contain the light-mismatch implementation or maintained contract surface. |
| `local/iam-e2e-duplicate-abuse-device-browser-proof-3` | `2cd67944d703767871327c64df89f0d4005fcddc` | Clean source worktree | 211 files, 41930 insertions, 3089 deletions | Duplicate personalized link across devices/browsers. | Superseded by current duplicate-device/browser and duplicate-abuse contracts. |
| `local/iam-e2e-duplicate-link-abuse-device-browser` | `6599d8f27eed9abd246cd8d2498f885fe8ab06ed` | Clean source worktree | 183 files, 34792 insertions, 2828 deletions | Earlier duplicate link abuse proof. | Superseded by current duplicate-device/browser and duplicate-abuse contracts. |
| `local/iam-e2e-abuse-duplicate-race` | `111f4084052b9099f96a65aaa8e5477e7d8f9e62` | Clean source worktree | 112 files, 19193 insertions, 2365 deletions | Concurrent personalized-link race review flag proof. | Race intent preserved as evidence; current base supports deterministic conflict/exhaustion proof, not manual review flags. |

## Current Extracted Value

The reusable proof value already supported by current contracts is the
duplicate-abuse and privacy boundary, not the source-only manual-review/email
feature.

Current maintained duplicate proofs:

- `demo/video-chat/frontend-vue/tests/contract/call-access-duplicate-device-browser-contract.mjs`
  pins separate browser/device contexts using the same personalized link,
  separate bearer tokens and verified user/session snapshots, deterministic
  one-success/one-409 reconciliation, and no winner or rejected token adoption
  by the rejected browser.
- `demo/video-chat/frontend-vue/tests/contract/call-access-duplicate-abuse-contract.mjs`
  pins stale verified-context replay, parallel duplicate abuse, duplicate
  generated session-id rejection across normal and call-access session stores,
  and `call_access_conflict` routing.
- `demo/video-chat/frontend-vue/tests/contract/call-access-duplicate-invite-replay-contract.mjs`
  pins duplicate invite redemption and replay as deterministic conflict or
  exhausted states, including the atomic capped invite redemption update.

Current maintained privacy and denied-state proofs:

- `demo/video-chat/frontend-vue/tests/contract/call-access-mismatch-no-leak-states-contract.mjs`
  proves denied conflict/forbidden UI states render from stable error codes,
  keep the current browser session, do not enter lobby/workspace, and do not
  consume private backend result or message payloads.
- `demo/video-chat/frontend-vue/tests/contract/call-access-strong-mismatch-privacy-contract.mjs`
  proves a strong personalized-link wrong-host denial sends the current logged
  in verified context, grants no access, preserves the current session, and
  leaks no foreign person/session data.
- `demo/video-chat/frontend-vue/tests/contract/call-access-link-privacy-contract.mjs`
  proves invalid link states clear call-specific UI details and do not expose
  foreign call or email data.

Current maintained audit proofs:

- `demo/video-chat/frontend-vue/tests/contract/call-access-audit-event-compatibility-contract.mjs`
  keeps denied call-access aliases canonicalized to stable audit event names.
- `demo/video-chat/frontend-vue/tests/contract/call-access-audit-redaction-contract.mjs`
  keeps raw call-access ids, session ids, tokens, person fields, call fields,
  and signaling/media artifacts out of persisted audit payloads.
- `demo/video-chat/frontend-vue/tests/contract/call-access-strong-mismatch-audit-redaction-contract.mjs`
  keeps strong mismatch denial audit fields canonical and redacted, including
  safe `mismatch=strong_personalized_link`,
  `auth=not_bound_to_current_user`, and
  `host_name=not_verified|wrong_host_name` field states.

Together, those current contracts extract the safe value from the duplicate
review branches that current code actually supports: duplicate personalized
link abuse fails closed, does not rebind browser sessions, does not leak
foreign call/person/token data, and leaves audit artifacts redacted.

## Email And Manual-Review Safety

The unique manual-review and email-confirmation source value is not currently
implemented in this base. The following source-only paths are absent:

```text
demo/video-chat/backend-king-php/domain/calls/call_access_review.php
demo/video-chat/backend-king-php/domain/calls/call_access_account_confirmation.php
demo/video-chat/backend-king-php/domain/calls/call_access_account_confirmation_audit.php
demo/video-chat/backend-king-php/domain/calls/call_access_identity.php
demo/video-chat/frontend-vue/src/domain/calls/access/AccountUpdateConfirmationView.vue
demo/video-chat/frontend-vue/src/domain/calls/access/JoinStrongMismatchPanel.vue
demo/video-chat/frontend-vue/src/domain/calls/access/joinStrongMismatchFlow.js
demo/video-chat/frontend-vue/tests/contract/call-access-duplicate-review-email-contract.mjs
demo/video-chat/frontend-vue/tests/contract/call-access-identity-mismatch-review-flow-contract.mjs
demo/video-chat/frontend-vue/tests/e2e/call-access-duplicate-review-email.spec.js
demo/video-chat/frontend-vue/tests/e2e/call-access-duplicate-race.spec.js
demo/video-chat/backend-king-php/tests/call-access-duplicate-review-contract.php
demo/video-chat/backend-king-php/tests/call-access-email-confirmation-contract.php
demo/video-chat/backend-king-php/tests/call-access-identity-mismatch-review-flow-contract.php
```

Those source branches prove useful future behavior:

- duplicate personalized-link mismatches create a private
  `duplicate_personalized_link` review flag with
  `manual_review_required`;
- review payloads use access fingerprints and do not log raw link ids,
  account emails, host names, foreign account names, private call data, or
  session tokens;
- host-name verification attempts are rate-limited and surface only safe
  `host_name` field states;
- account-update confirmation is sent to the currently logged-in account, not
  the link target account;
- manual re-entry is required before pending account data changes;
- account-update tokens are account-bound, expiring, one-time tokens;
- confirmation does not rebind the current browser session or update the link
  target account;
- same-account light mismatch reopen should not create duplicate review state.

That value is intentionally classified as deferred implementation evidence
rather than extracted runtime behavior in IAM5-07. Porting it correctly would
require backend domain files, route wiring, frontend warning/confirmation UI,
runtime PHP contracts, package wiring, and browser specs outside this task's
write scope. Importing only the static assertions would falsely claim support
for missing contracts.

## Extraction Decision

Safe extraction performed in this branch:

- documented the current duplicate-abuse, privacy, and audit-redaction proof
  value supported by existing maintained contracts;
- preserved the manual-review, host-verification, light-mismatch, and
  account-update email-confirmation source value as future implementation
  evidence;
- added a narrow static extraction contract that checks this evidence against
  the current contract surface.

No product code, package scripts, shared CI wiring, `SPRINT.md`, or
`BACKLOG.md` were edited. No source branch was merged or cherry-picked.
