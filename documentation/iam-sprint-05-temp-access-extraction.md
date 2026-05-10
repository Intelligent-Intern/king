# IAM Sprint 05 Temp-Access Extraction

Date: 2026-05-10

Scope: IAM5-12 extraction review for temporary guest, direct-join,
temporary moderator, anonymous temporary-rights, and kicked temporary-user
proof value. Background, Gossip, SFU, MediaSecurity, and BTGF areas were not
touched. Source proof worktrees were inspected read-only; no source branch was
deleted, reset, rebased, merged, cleaned, or edited.

Base checked: local `prod-kingrt-do-not-push-to-github` at
`5988e6b7de705f2cfbad56ca14ae9f7efde36411`.

## Source Branches Inspected

| Branch | Head | Status | Extracted value |
| --- | --- | --- | --- |
| `agent/iam-e2e-direct-join-roles` | `02a2bdfe` | clean, 1 commit ahead of old base | Direct-join role matrix for system admin, organization admin, owner, guest-list user, normal denied user, and forged-role denial. |
| `agent/iam-e2e-rejoin-kick-membership` | `bbe9a8f7` | clean, 1 commit ahead of old base | Rejoin after normal leave, stale `left_at` clearing, owner-only kick/reject authority, and participant kick denial. |
| `local/iam-e2e-temp-guest-list-direct-join` | `c2f84b45` | clean, broad historical branch | Temporary personalized guest on the call guest list enters directly, sends no persistent credentials, receives no tenant/admin rights, and manipulated links do not issue sessions. |
| `local/iam-e2e-temp-moderator-remaining` | `f36b2ccc` | clean, broad historical branch | Temporary moderator can moderate only the assigned call, cannot mutate the guest list, cannot manage ownership, loses authority when inactive/revoked/ended, and forged client moderator state is denied. |
| `local/iam-e2e-temp-user-kick-rejoin` | `453ee854` | clean, broad historical branch | Kicked temporary user loses direct call-room admission after prior approval; re-entry must go through explicit admission rather than stale direct rejoin. |
| `local/iam-e2e-anonymous-temp-rights-proof-2` | `f6748e36` | clean, broad historical branch | Anonymous open-link temporary account keeps user/guest identity, gains no system/org/call admin rights, gains no direct join, and does not mutate guest-list rows. |
| `local/iam-e2e-anonymous-link-org-admin-rights` | `03223058` | clean, broad historical branch | Logged-in anonymous-link org-admin paths do not create guest-list escalation or cross-org direct join. |
| `local/iam-e2e-rejoin-refresh-session-safety` | `fe6fd427` | clean, broad historical branch | Rejoin refresh uses current effective role, rejects account-binding mismatches, and rejects duplicate temporary session ids. |
| `codex/iam-lane-61-temporary-call-link-account-proof` | `21684060` | clean, 1 commit ahead of old base | Email-only personal links create an isolated temporary guest instead of auto-logging into a registered same-email account, keep public payloads private, and reject invalid/manipulated links. |

The branch set is not a safe merge candidate. The local temp-access branches
are hundreds of prod commits behind, and several carry `SPRINT.md`,
`package.json`, shared CI, or broad E2E changes outside IAM5-12 write scope.

## Extracted Current Contract

The durable IAM5-12 value is preserved in current prod through maintained,
focused contracts rather than broad source-branch E2E imports:

- `demo/video-chat/frontend-vue/tests/contract/call-access-temp-call-link-boundaries-contract.mjs`
  pins the temporary personalized and anonymous seed users as guest accounts
  with no platform, tenant, system-admin, or direct guest-list rights. It also
  checks call/access/session/tenant binding, link expiry, cross-call replay
  denial, and temporary guest cleanup boundaries.
- `demo/video-chat/frontend-vue/tests/contract/call-access-personalized-temp-reuse-contract.mjs`
  pins email-only personalized links with no resolved target as temporary guest
  flows, prevents reuse by another account, rejects cross-call and cross-room
  replay, and requires duplicate session ids to fail closed.
- `demo/video-chat/frontend-vue/tests/contract/call-access-direct-join-rights-contract.mjs`
  and
  `demo/video-chat/backend-king-php/tests/call-guest-list-direct-join-contract.php`
  keep direct join limited to platform admin, tenant/admin boundary, call
  owner, or active internal guest-list participant. They also prove normal
  users, cross-tenant users, declined entries, and external-only participant
  rows do not become direct-join grants.
- `demo/video-chat/backend-king-php/tests/call-access-anonymous-temp-rights-contract.php`
  proves anonymous temporary users do not inherit organization admin rights,
  cannot administer the call, cannot direct join, and do not create invited or
  allowed guest-list rows during session issuance.
- `demo/video-chat/frontend-vue/tests/contract/call-access-kicked-rejoin-denial-contract.mjs`
  proves cached sessions, copied links, queue joins, and direct room joins do
  not revive cancelled or declined participant rows. Current prod is stricter
  than the old temporary-kick source branch: `lobby/kick` normalizes to
  `lobby/remove`, and the websocket persistence path writes `cancelled` for
  removed users instead of restoring an `invited` stale-link path.
- `demo/video-chat/frontend-vue/tests/contract/call-access-owner-transfer-temp-moderator-extract-contract.mjs`
  proves temporary moderators gain only assigned-call moderation, cannot manage
  ownership, cannot moderate another call or tenant, cannot rely on forged
  client state, and lose moderation immediately after revocation.
- `demo/video-chat/backend-king-php/tests/call-access-admin-prevention-contract.php`
  and `demo/video-chat/backend-king-php/tests/call-access-session-contract.php`
  preserve session binding, non-admin temporary identity, and explicit
  mismatch/duplicate-session fail-closed behavior.

## Source-Only Notes

The following branch-side details were inspected and intentionally not ported as
product changes in this IAM5-12 evidence branch:

- `local/iam-e2e-anonymous-link-org-admin-rights` models logged-in open-link
  sessions that keep the authenticated account for some org-admin and
  guest-list cases. Current prod keeps the stronger isolated temporary-open-link
  boundary: logged-in open-link use does not promote the logged-in account into
  the call and does not grant tenant/admin/call moderation rights.
- `codex/iam-lane-61-temporary-call-link-account-proof` additionally binds an
  email-only personal link to the generated temporary account and proves a
  later session reuses that same generated account. Current prod already
  preserves the critical safety parts for IAM5-12, including no registered
  same-email auto-login, no admin escalation, call/session binding, and invalid
  link privacy. The same-link/same-temporary-account reuse behavior is a
  backend product follow-up outside this doc/contract-only write scope.
- `local/iam-e2e-temp-user-kick-rejoin` allows a kicked temporary user to ask
  for renewed approval after the kick resets prior admission. Current prod's
  `cancelled` persistence is stricter and keeps stale direct rejoin denied; any
  future softer re-admission policy should be implemented as explicit backend
  product work, not by importing old branch wiring.

## Added Focused Proof

`demo/video-chat/frontend-vue/tests/contract/call-access-temp-access-remaining-extract-contract.mjs`
is a standalone static extraction contract for IAM5-12. It checks this evidence
document and the current maintained contracts listed above. It adds no package
script, shared CI wiring, product code, `SPRINT.md`, or `BACKLOG.md` changes.

## Extraction Decision

Safe extraction performed in this branch:

- Documented every requested temp-access source branch, observed head, clean
  worktree status, and reusable IAM5-12 value.
- Mapped temporary guest, direct-join, anonymous temporary rights, kicked
  temporary-user denial, rejoin/session binding, and temporary moderator value
  to current maintained contracts.
- Preserved source-only behavior as explicit follow-up evidence instead of
  silently changing the current product contract or importing stale broad E2E
  wiring.

No product code, package scripts, shared CI wiring, sprint planning files, or
protected Background/Gossip/SFU/MediaSecurity/BTGF files were edited.
