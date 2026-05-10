# IAM Sprint 05 Guest-List Extraction

Date: 2026-05-10

Scope: IAM5-11 extraction review for guest-list management, owner management,
guest-list revocation, and adjacent guest cleanup branches only. Background,
Gossip, SFU, MediaSecurity, and BTGF areas were not touched. Source worktrees
were inspected read-only; no source branch was deleted, reset, rebased, merged,
or edited.

Base checked: local `prod-kingrt-do-not-push-to-github` at
`49e5cdae858a3b572654ade6040f4b50d037632e`.

## Source Branches Inspected

| Branch | Head | Status | Extracted value |
| --- | --- | --- | --- |
| `local/iam-e2e-guest-list-management-proof` | `f40b2ce945bf` | Broad source branch | Guest-list add, duplicate merge, remove, restore, registered and temporary guest direct-join, forged admin denial, organization-admin/system-admin direct-join, and guest-list audit proof. |
| `local/iam-e2e-guest-list-management-audit-proof-2` | `1cc2e65c9e97` | Broad source branch | Same management runtime value plus a static audit proof for `guest_list_entry_added`, `guest_list_entry_merged`, `guest_list_entry_removed`, and `guest_list_entry_restored`. |
| `codex/iam-lane-57-guest-list-owner-management-proof` | `1243ac3892f4` | Narrowest owner-management branch | Owner add/remove/replace through call update, direct-join revocation after removal, forged admin denial, and DB-backed owner lobby allow/kick authority. |
| `local/iam-e2e-guest-list-harness-followup-3` | `61b2cc8a7b10` | Small harness follow-up | Adds the missing `auth_rbac.php` include to the direct-join PHP contract harness. Current base already contains that include. |
| `local/iam-e2e-guest-list-revocation-proof-3` | `cc020a0d0267` | Broad source branch | Carries management/audit work plus the documented guest-list revocation invariant. Current Sprint 04 extraction already captures the revocation value. |
| `local/iam-e2e-guest-owner-transfer-revocation` | `276c8e995194` | Focused tip on broad history | Adds active-call proof that stale old-owner and revoked moderator connection fields cannot bypass admission, rejoin the call room, or retain management authority. |
| `local/iam-e2e-guest-cleanup` | `1f44ef1cb0de` | Guest cleanup branch | Splits explicit guest cleanup proof for disabled guest accounts, stale links/sessions, registered-user preservation, and audit safety. |
| `local/iam-e2e-guest-cleanup-remaining` | `10d8a706cc01` | Broad cleanup branch | Adds remaining cleanup proof for delete/end with pending or admitted temporary guests, invitation-delete cleanup scope, restart durability, and no-op repeat cleanup. |
| `local/iam-e2e-guest-lifecycle-temp-cleanup-remaining` | `5703fb393acf` | Broad cleanup branch | Repeats guest cleanup lifecycle and temporary direct-join evidence in a broad IAM branch. |

## Current Covered Value

Current base already preserves the core guest-list revocation and direct-join
contract with smaller maintained proofs:

- `demo/video-chat/backend-king-php/tests/call-guest-list-direct-join-contract.php`
  proves a listed internal user can direct join, a non-listed user cannot, a
  guest-list row is call-scoped, declined entries fail closed with
  `guest_list_entry_inactive`, external-only participant rows do not count as
  internal guest-list grants, and tenant-scoped lookup does not leak the entry
  across tenants.
- `demo/video-chat/frontend-vue/tests/contract/call-access-direct-join-rights-contract.mjs`
  pins direct-join policy for system admin, own-organization admin, owner,
  registered guest-list participant, normal denied user, and cross-organization
  denied user through the maintained seed matrix.
- `demo/video-chat/frontend-vue/tests/contract/call-access-removed-members-contract.mjs`
  proves removed invited users have no active membership, are not on the direct
  guest list, cannot directly see the call, cannot regain tenant/admin/lobby
  powers, and cancelled or declined invited users lose direct guest-list access
  and personal call-access link visibility.
- `demo/video-chat/frontend-vue/tests/contract/iam-guest-list-revocation-extraction-contract.mjs`
  keeps the earlier Sprint 04 revocation extraction bound to current
  removed-member, direct-join, and Docker membership proofs.
- `demo/video-chat/frontend-vue/tests/contract/call-access-guest-list-membership-docker-proof-contract.mjs`
  pins the Docker fallback wrapper for guest-list direct join plus stale
  membership-removal runtime proofs when host PHP lacks `pdo_sqlite`.

Current base also preserves the owner-management side of the source proof with
focused maintained contracts:

- `demo/video-chat/backend-king-php/tests/call-owner-moderation-contract.php`
  proves owners can admit/reject/kick, normal participants cannot, ownership
  transfer leaves exactly one owner, old non-admin owners lose moderation, and
  new owners gain moderation.
- `demo/video-chat/frontend-vue/tests/contract/call-access-owner-transfer-main-contract.mjs`
  pins canonical owner-transfer authority, exactly-one-owner persistence,
  previous-owner demotion, and DB-backed lobby authorization after role changes.
- `demo/video-chat/frontend-vue/tests/contract/call-access-owner-transfer-temp-moderator-extract-contract.mjs`
  keeps temporary moderator authority scoped to the assigned call and separates
  owner-management from general moderation.
- `demo/video-chat/frontend-vue/tests/contract/call-access-admission-boundaries-contract.mjs`
  proves system admin, organization admin, owner, and moderator admission
  boundaries without relying on guest-list mutation.
- `demo/video-chat/frontend-vue/tests/contract/call-access-permission-change-active-call-contract.mjs`
  proves active-call UI and websocket actions refresh current backend role
  state, disable stale moderation and owner-transfer controls, and reject stale
  lobby or role actions after permission changes.

Adjacent guest cleanup files are present, but they are not used as IAM5-11
closure:

- `demo/video-chat/backend-king-php/tests/call-guest-lifecycle-contract.php`
  contains assertions for personal/open temporary guest cleanup disabling only
  scoped temporary guests, revoking stale guest sessions, blocking stale
  personalized links, preserving registered users, idempotent repeat cleanup,
  and sanitized cleanup audit rows.
- `demo/video-chat/backend-king-php/tests/call-guest-cleanup-sqlite-proof.sh`
  provides the deterministic host-PHP-or-Docker wrapper for that cleanup proof.

During this extraction, the host PHP wrapper skipped because `pdo_sqlite` is not
available locally, and the Docker fallback reached
`call-guest-lifecycle-contract.php` but failed on
`personal cleanup audit must not expose session-keyed counters`. Because
IAM5-11 write scope excludes backend cleanup runtime and tests, that cleanup
failure is recorded as an adjacent follow-up. It is not used as the closure
proof for guest-list management, owner management, or guest-list revocation.

## Source-Only Value Not Ported

The management and audit branches contain useful backend value that is not
present as a maintained current-base runtime contract:

- focused helper APIs
  `videochat_add_call_guest_list_entry` and
  `videochat_remove_call_guest_list_entry`;
- dedicated add, duplicate-merge, remove, and restore outcomes for registered
  and temporary guest-list entries;
- sanitized guest-list audit helper
  `videochat_audit_record_guest_list_entry_change` with
  `call_guest_list_entry` resource metadata and no raw guest identifiers;
- a dedicated backend owner-management contract
  `call-guest-list-owner-management-contract.php`;
- granular cleanup contracts for delete/end mixed with lobby or admitted
  temporary guests, invitation-delete cleanup scope, and restart durability.

Those files were not copied here because IAM5-11 write scope is limited to this
evidence document and an optional static contract. Importing the source-only
backend helpers or audit rows would require editing runtime domains, backend
tests, package scripts, or shared CI wiring, which this ticket explicitly
excludes. Copying the source static audit contract without the missing runtime
helpers would create a stale assertion against files that do not exist in the
current base.

## Extraction Decision

Safe extraction performed in this branch:

- documented the reusable current guest-list direct-join, removed-member,
  revocation, owner-management, active-permission-change, and guest cleanup
  proof set;
- preserved the unported guest-list add/remove/merge/restore audit helper and
  granular cleanup value as precise backend follow-up evidence, including the
  current cleanup-audit Docker failure noted above;
- added a narrow static contract that checks this evidence against current
  source files and maintained contracts.

No product code, package scripts, shared CI wiring, `SPRINT.md`, or
`BACKLOG.md` were edited. No broad source branch was imported.
