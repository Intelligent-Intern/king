# IAM4-09 Registered Invitee Extract Evidence

Date: 2026-05-10

Scope: classification and proof extraction for registered-invitee logged-in,
logged-out, and final proof-3 branches. No source worktree was deleted, reset,
or merged wholesale. Background, Gossip, SFU, MediaSecurity, and BTGF areas were
not touched.

Base used for comparison: local `prod-kingrt-do-not-push-to-github`.

## Source Branches

| Branch | Head | Source worktree | Extracted value | Current coverage | Decision |
| --- | --- | --- | --- | --- | --- |
| `local/iam-e2e-registered-invitee-logged-in-proof-3` | `a2682e84` | `/home/jochen/projects/king.site/worktrees/iam-e2e-registered-invitee-logged-in-proof-3` | Backend wrapper proved a logged-in registered invitee opens a personalized link as the existing account, does not create or overwrite a temporary guest, persists a call-bound access session, rejects wrong-call reuse, and resolves realtime lobby/admission against the invited call. | `call-access-registered-logged-in-invitee-contract.mjs`, `call-access-session-route-guard-contract.php`, and realtime binding assertions cover the same current-prod contract without importing the old branch base. | Superseded; extracted into `call-access-registered-invitee-extract-contract.mjs` as a focused coverage map. |
| `local/iam-e2e-invite-registered-logged-out-proof-3` | `f1601b97` | `/home/jochen/projects/king.site/worktrees/iam-e2e-invite-registered-logged-out-proof-3` | Backend wrapper proved a logged-out registered invitee link resolves to the registered account, issues a call-access session bound to the intended call/room/user, avoids temporary-account creation, preserves the registered profile, and applies lobby/admission boundaries. | `call-access-registered-logged-out-handoff-contract.mjs` pins the safe login redirect, backend-resolved invite rebinding, verified context handoff, bearer use, and route-guard denial for wrong-account/session-switch cases. The route-guard backend contract covers anonymous personal-link issuance to the linked registered user. | Superseded; no PHP wrapper import needed unless CI later requires a runtime duplicate. |
| `local/iam-e2e-registered-invitee-final-proof-3` | `e62ddc4e` | `/home/jochen/projects/king.site/worktrees/iam-e2e-registered-invitee-final-proof-3` | Tip commit updates only `SPRINT.md` final status. | Current Sprint 03/04 focused contracts already represent the logged-in and logged-out proof value. | Documentation-only; no product or proof file to port. |

## Extracted Contract

`demo/video-chat/frontend-vue/tests/contract/call-access-registered-invitee-extract-contract.mjs`
now pins the source-branch obligations against current files:

- seed matrix keeps `registered_guest` as a real non-temporary account;
- direct-join remains call-scoped through the registered guest-list row;
- logged-out personal links issue only the server-bound registered invitee;
- logged-in matching accounts issue, while wrong accounts and changed verified
  sessions fail closed without session persistence;
- guest-session creation remains limited to open links;
- session issuance re-checks current call and tenant access before persisting a
  call-scoped binding;
- login handoff resolves the original call with the logged-in bearer session and
  rebinds only to the backend-returned invite access link;
- realtime room resolution rejects stale call-access bindings for another room,
  call, or user.

No product-code change is required. The useful branch value is proof coverage and
evidence, not a direct merge of the old backend wrappers or SPRINT edits.
