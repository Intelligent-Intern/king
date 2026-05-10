# IAM Sprint 05 Owner-Transfer Extraction

Date: 2026-05-10

Scope: IAM5-10 extraction of owner-transfer main journey, rejoin, and
permission-audit proof value from the remaining owner-transfer branches.
Background, Gossip, SFU, MediaSecurity, and BTGF areas were not touched. Source
worktrees were inspected read-only; no source branch was deleted, reset,
rebased, merged, or edited.

Base checked: local `prod-kingrt-do-not-push-to-github` at
`49e5cdae858a3b572654ade6040f4b50d037632e`.

## Source Branches Inspected

| Branch | Head | Status | Extracted value |
| --- | --- | --- | --- |
| `local/iam-e2e-owner-transfer-main-journey-proof-2` | `c4781b5c2c44fad16edb185ad5cfc200b133a847` | Clean | Adds the browser main journey proof for normal-owner transfer and organization-admin-owner transfer. |
| `codex/iam-owner-transfer-main-journey-followup` | `ff00ed3459b4abaae99f9cf9c023399541bcf2d3` | Clean | Repeats the same main-journey browser file and carries broader audit/follow-up history. |
| `local/iam-e2e-owner-transfer-rejoin-main` | `9656b4e7d8a660739a01d0122851394795db5efa` | Clean | Adds rejoin proof for demoted normal owners and demoted organization-admin owners. |
| `local/iam-e2e-owner-transfer-permission-audit` | `08c313ce8099484cddc2267a8f2a9d278b8ce0d1` | Branch only | Adds backend owner-transfer audit write proof and audit payload assertions. |
| `local/iam-e2e-org-admin-owner-transfer-policy` | `3ff99f1c890c015dc2d9bed98959882d5ff2e4c7` | Clean | Proposes a broader organization-admin owner-transfer policy. |
| `local/iam-e2e-guest-owner-transfer-revocation` | `276c8e9951947e8d96ba68beeb426614e3991e84` | Clean | Adds revoked old-owner and revoked moderator rejoin denial proof. |
| `local/iam-e2e-owner-transfer-lifecycle-proof-3` | `32a4df25a8d9b479e4fb49888f14f10a17308951` | Clean | Sprint-only closeout note for `e2e_journey_016` and `e2e_journey_017`; no product or contract files. |

## Main Journey

The reusable main-journey value is in
`demo/video-chat/frontend-vue/tests/e2e/call-access-owner-transfer-main-journeys.spec.js`
from `c4781b5c` and `ff00ed34`.

That source proof covers:

- `e2e_owner_003_owner_can_manage_guest_list`: a normal call owner creates an
  invite-only call, manages the guest list, transfers owner to another internal
  participant, then immediately loses moderation and owner-management rights.
- `e2e_journey_017_org_admin_create_call_transfer_owner_keeps_admin_rights`:
  an admin-created call can transfer owner while the admin actor retains admin
  moderation authority after the transfer.
- The browser sends the maintained participant-role `PATCH` endpoint and
  requests a fresh room snapshot after transfer instead of trusting stale local
  role state.

Current base already keeps the durable static/runtime contract in narrower,
maintained files:

```text
demo/video-chat/frontend-vue/tests/contract/call-access-owner-transfer-main-contract.mjs
demo/video-chat/backend-king-php/tests/call-owner-moderation-contract.php
demo/video-chat/backend-king-php/tests/call-owner-moderation-contract.sh
demo/video-chat/frontend-vue/tests/contract/call-access-admission-boundaries-contract.mjs
```

Those current contracts prove the participant role endpoint routes through the
call-scoped owner-transfer mutation, target users must be internal call
participants, canonical `calls.owner_user_id` is updated, the previous owner
participant row is demoted, the new owner row is promoted, the response is
rebuilt from post-transfer state, and lobby moderation revalidates against the
fresh DB-backed call context.

The old Playwright source was not imported or wired because IAM5-10 write scope
excludes package scripts and broader E2E wiring. The current extracted value is
the owner-transfer contract surface, not a wholesale browser-suite merge.

## Rejoin And Active Permission Refresh

The reusable rejoin value is in `9656b4e7` and `276c8e99`.

The source proof covers:

- a demoted normal owner leaves and rejoins as a participant, with no
  moderation, lobby, or owner-management controls;
- the new owner rejoins as owner with moderation and owner-management rights;
- a moderator may retain moderation on rejoin but must not gain
  owner-management rights;
- stale owner/moderator connection state is re-read from the backend before
  lobby commands, room joins, role changes, or settings changes;
- revoked old-owner and revoked moderator rows do not bypass admission or join
  the call-scoped room from stale connection state.

Current base keeps that extracted value in:

```text
demo/video-chat/frontend-vue/tests/contract/owner-transfer-lifecycle-contract.mjs
demo/video-chat/frontend-vue/tests/contract/call-access-permission-change-active-call-contract.mjs
demo/video-chat/frontend-vue/tests/contract/call-access-authorized-rejoin-extract-contract.mjs
demo/video-chat/frontend-vue/tests/contract/call-access-owner-transfer-temp-moderator-extract-contract.mjs
demo/video-chat/frontend-vue/tests/contract/call-access-kicked-rejoin-denial-contract.mjs
demo/video-chat/frontend-vue/tests/contract/call-access-realtime-scope-contract.mjs
```

The maintained contract is: owner-transfer permissions are not sticky. Room
snapshots and reconnect/rejoin paths must overwrite stale viewer role,
`can_moderate`, and `can_manage_owner` state from backend call context. Snapshot
refresh must not be implemented as browser reload, reconnect forcing, SFU/media
reset, or Background pipeline behavior.

## Permission Audit

The unique permission-audit source is
`local/iam-e2e-owner-transfer-permission-audit` at `08c313ce`. It adds a
backend mutation audit write for successful owner transfers:

```text
demo/video-chat/backend-king-php/domain/calls/call_management_owner_transfer.php
demo/video-chat/backend-king-php/tests/call-owner-transfer-contract.php
demo/video-chat/backend-king-php/tests/org-admin-call-rights-contract.php
```

The source proof requires one audit row per successful transfer, no transfer
audit row for failed cross-organization attempts, canonical call fingerprinting,
previous and new owner ids in sanitized payload metadata, an
`old_owner_admin_preserved` flag, and an explicit one-owner invariant marker.

Current base does not yet contain the source mutation helper
`videochat_audit_record_call_owner_transferred`. Current base does contain the
maintained audit compatibility/redaction floor:

```text
demo/video-chat/backend-king-php/domain/audit/audit_events.php
demo/video-chat/frontend-vue/tests/contract/call-access-audit-event-compatibility-contract.mjs
demo/video-chat/frontend-vue/tests/contract/call-access-audit-redaction-contract.mjs
```

Those current contracts canonicalize legacy `call_owner_transferred`,
`call_participant_role_updated`, and `participant_role_updated` aliases to
`call_access_role_changed`, sanitize persisted audit payloads, and redact
private call/access/session/media identifiers from audit artifacts.

The source mutation audit write was not ported here because doing so correctly
requires backend mutation and runtime contract edits outside IAM5-10 write
scope. It remains precise backend follow-up evidence, not a current-base claim.

## Org-Admin Policy Decision

`local/iam-e2e-org-admin-owner-transfer-policy` widens organization admins from
same-organization moderation to owner-transfer authority. That conflicts with
the current maintained owner-management contract:

```text
demo/video-chat/frontend-vue/tests/contract/call-access-owner-transfer-main-contract.mjs
demo/video-chat/frontend-vue/tests/contract/call-access-admission-boundaries-contract.mjs
demo/video-chat/backend-king-php/tests/org-admin-call-rights-contract.php
```

Current base intentionally keeps owner-transfer authority stricter than general
moderation: current owner and system-admin paths may transfer owner; same-org
organization admins may moderate their organization call but must not receive
owner-management rights. The broader org-admin policy branch was therefore not
ported as an IAM5-10 extraction. Changing that policy needs an explicit product
decision, not a proof-only merge.

## Extraction Decision

Safe extraction performed in this branch:

- Documented the owner-transfer main journey value against current maintained
  owner-transfer contracts.
- Documented rejoin and active-permission-refresh value against current
  lifecycle, permission-change, authorized-rejoin, temp-moderator, kicked
  rejoin, and realtime-scope contracts.
- Preserved the permission-audit mutation write as backend follow-up evidence
  while grounding current proof in existing audit compatibility/redaction
  contracts.
- Rejected the broader org-admin owner-transfer policy because it contradicts
  current owner-management separation.
- Added a narrow static extraction contract that checks this evidence against
  current source files.

No product code, package scripts, shared CI wiring, `SPRINT.md`, or
`BACKLOG.md` were edited. No broad source branch was imported.
