# IAM Sprint 04 Guest-List Revocation Extraction

Date: 2026-05-10

Scope: read-only extraction review of
`local/iam-e2e-guest-list-revocation-proof-3`. The source worktree was not
deleted, reset, rebased, checked out, cleaned, or modified. Background, Gossip,
SFU, MediaSecurity, and BTGF areas were not touched.

## Source Worktree

Branch: `local/iam-e2e-guest-list-revocation-proof-3`

Worktree: `/home/jochen/projects/king.site/worktrees/iam-e2e-guest-owner-transfer-revocation`

Observed HEAD: `276c8e9951947e8d96ba68beeb426614e3991e84`

Observed status:

```text
## local/iam-e2e-guest-owner-transfer-revocation
```

The source branch is clean, but its diff against current
`prod-kingrt-do-not-push-to-github` is broad: backend call lifecycle,
guest-list, owner transfer, realtime, package scripts, frontend workspace UI,
and many E2E fixtures/specs. That branch is not a safe narrow cherry-pick.

## Guest-List Revocation Value Found

The guest-list revocation value in the source branch is the invariant that a
guest-list or invited participant who has been revoked, cancelled, declined, or
removed must not regain access through:

- direct guest-list call visibility,
- stale personalized call-access links,
- stale call-scoped sessions,
- lobby/realtime rejoin paths.

The source branch exercises adjacent runtime journeys through broad E2E and
backend proofs such as `call-access-rejoin-kick-membership.spec.js` and
`call-access-rejoin-kick-contract.php`, but those files also cover unrelated
network reconnect, hangup/rejoin, active-call kick, temporary guest, and owner
absence behavior.

## Current Coverage

Current Sprint 03/04 integration already covers the guest-list revocation value
with narrower stable proofs:

- `demo/video-chat/frontend-vue/tests/contract/call-access-removed-members-contract.mjs`
  proves removed invited users have no active membership, are not on the direct
  guest list, cannot directly see the call, cannot restore org/admin rights,
  cannot observe lobby state without an issued call-scoped session, and that
  `cancelled` or `declined` invite rows invalidate both direct guest-list access
  and personal call-access links.
- `demo/video-chat/backend-king-php/tests/call-guest-list-direct-join-contract.php`
  proves direct guest-list joins are call-scoped, tenant-scoped, internal-only,
  and fail closed with `guest_list_entry_inactive` for declined entries.
- `demo/video-chat/frontend-vue/tests/contract/call-access-guest-list-membership-docker-proof-contract.mjs`
  pins the Docker fallback wrapper that runs guest-list direct join and
  membership-removal runtime proofs when host PHP lacks `pdo_sqlite`.
- `demo/video-chat/frontend-vue/package.json`,
  `demo/video-chat/frontend-vue/tests/contract/iam-call-access-ci-wire-contract.mjs`,
  and `demo/video-chat/contracts/v1/ui-parity-acceptance.matrix.json` already
  wire those focused proofs into the stable IAM call-access gate.

## Classification

Recommendation: `superseded/documentation-only`.

No code or test extraction is required from
`local/iam-e2e-guest-list-revocation-proof-3` for guest-list revocation. The
current stable IAM proof set covers the unique guest-list revocation contract in
smaller, maintained proofs. The source branch should remain untouched until a
manager decides whether other non-guest-list work in that broad branch has any
remaining value.
