# IAM Sprint 05 System-Admin Lanes Extraction

Date: 2026-05-10

Scope: IAM5-16 extraction review for system-admin, organization-role bootstrap,
admin-join, owner-rights, and adjacent IAM lane proof value. Background,
Gossip, SFU, MediaSecurity, BTGF, deploy scripts, and `SPRINT.md` were not
touched. Source worktrees were inspected read-only; no source branch was
deleted, reset, rebased, merged, cleaned, or edited.

Base checked: local `prod-kingrt-do-not-push-to-github` at
`17c851ace650903f17b8b02776028d0d01a9b783`.

## Source Branches Inspected

| Branch | Head | Status | Extracted value |
| --- | --- | --- | --- |
| `local/iam-e2e-system-admin-edge-cases` | `434a3ec334b0` | clean, broad historical branch | System-admin cross-tenant call management, tenantless direct join, temporary/forged-admin denial, and system-admin review flag privacy proof. |
| `local/iam-e2e-system-admin-deleted-ended-proof-3` | `4cabdf6b06b3` | clean, broad historical branch | System-admin terminal-state denial for ended/deleted calls, safe not-found/conflict envelopes, no replacement session issuance, and no private payload leaks. |
| `codex/iam-e2e-anon-system-admin-proof-20260509` | `0d3e9e04103e` | branch-only source | Repeats system-admin edge-case static proof plus broader IAM suite wiring and anonymous/system-admin assertions. |
| `codex/iam-lane-54-organization-role-bootstrap-proof` | `528fb034e816` | clean, focused lane branch | Organization create route, user registration, organization User/Admin roles, login/logout, permission-grant evaluation, frontend role/session normalization, and a backend SQLite proof wrapper. |
| `codex/iam-lane-58-owner-transfer-rights-audit-proof` | `0092f3768eae` | clean, focused lane branch | Owner-transfer target boundary checks, exactly-one-owner invariant, old-owner/admin rights audit payload, and cross-tenant/cross-organization transfer denial. |
| `codex/iam-lane-59-admin-join-boundaries-proof` | `f9ad4bf14b15` | clean, focused lane branch | Backend system-admin/org-admin/owner join boundaries, disabled-user denial, terminal/deleted call denial, and realtime room-resolution checks. |
| `local/iam-e2e-call-owner-creation-rights` | `d3b3b18efecf` | clean, broad historical branch | Normal/admin call creators become canonical owners, get owner-management context, and can moderate their own lobby while non-owners cannot. |
| `local/iam-e2e-core-org-session-journey` | `9800a2f3ae42` | clean, broad historical branch | Core organization session journey for registered org users, organization admins, tenant-only users, same-organization call access, logout, and logged-in open-link behavior. |

The branch set is not a safe merge candidate. Several sources carry
`SPRINT.md`, `package.json`, broad IAM suite wiring, shared scripts, or runtime
domain changes outside IAM5-16 write scope.

## Current Covered Value

Current base keeps the reusable system-admin and admin-join behavior in smaller
maintained contracts:

- `demo/video-chat/frontend-vue/tests/contract/call-access-admission-boundaries-contract.mjs`
  pins system-admin lobby authority, own-organization admin authority,
  foreign-organization denial, owner/moderator boundaries, DB-backed lobby
  moderation, and the rule that organization-admin moderation does not imply
  owner-management.
- `demo/video-chat/frontend-vue/tests/contract/call-access-direct-join-rights-contract.mjs`
  and `demo/video-chat/frontend-vue/tests/e2e/call-access-seed-matrix.spec.js`
  keep direct join limited to system admin, own-organization admin, call owner,
  or active internal guest-list participant. The seed matrix also includes the
  tenantless system-admin row and terminal ended/disabled/deleted denial rows.
- `demo/video-chat/frontend-vue/tests/e2e/call-access-admin-join-boundaries.spec.js`
  is the current focused browser boundary proof for system admin, org admin,
  foreign org admin, call-scoped moderator, owner, and plain organization
  member direct-join behavior.
- `demo/video-chat/backend-king-php/tests/system-admin-call-rights-contract.php`
  proves system admins do not need foreign tenant membership or a guest-list
  row, can fetch/update/manage foreign-tenant calls, can transfer owner, retain
  rights after transfer, and cannot be simulated by a normal or temporary
  account with a forged `admin` role string.
- `demo/video-chat/backend-king-php/tests/org-admin-call-rights-contract.php`
  proves own-organization admins can fetch/update/manage same-organization
  calls and lobby context without guest-list insertion, but cannot cross
  organization boundaries and cannot receive owner-transfer rights.
- `demo/video-chat/frontend-vue/tests/contract/call-access-terminal-states-contract.mjs`
  and `demo/video-chat/frontend-vue/tests/contract/call-access-terminal-browser-flows-contract.mjs`
  preserve the system-admin terminal-call value from the deleted/ended source
  family. They require `direct_join_system_admin_alpha_ended_denied`, keep
  disabled/deleted terminal states closed, and prevent private call payloads
  from appearing in terminal resolve or call-fetch responses.

Current base keeps the owner-rights side of the lane set in existing
owner-management contracts:

- `demo/video-chat/backend-king-php/tests/call-creation-owner-rights-contract.php`
  proves normal and admin call creators become canonical owners, get persisted
  owner participant rows, own the room creator field, and receive
  owner-management context.
- `demo/video-chat/backend-king-php/tests/call-owner-moderation-contract.php`
  proves owners can admit/reject/kick, normal participants cannot, owner
  transfer leaves exactly one owner, old non-admin owners lose moderation, new
  owners gain moderation, and global admins retain moderation after owner
  transfer.
- `demo/video-chat/frontend-vue/tests/contract/call-access-owner-transfer-main-contract.mjs`
  and `demo/video-chat/frontend-vue/tests/contract/admin-owner-rights-contract.mjs`
  keep owner-transfer authority separated from general moderation and require a
  fresh room snapshot after role changes.

Current base also has the frontend/session floor needed for the organization
bootstrap lane:

- `demo/video-chat/frontend-vue/src/domain/auth/sessionNormalizers.js` keeps
  account roles limited to `admin` and `user`, account types limited to
  `account` and `guest`, and missing tenant permissions normalized to an empty
  permission set.
- `demo/video-chat/frontend-vue/src/domain/auth/session.ts` uses backend
  login/logout endpoints, clears local session state on logout and invalid
  recovery, and removes stale session tokens fail-closed.

## Source-Only Value Not Ported

The following source proof remains useful, but it is not present as a current
maintained runtime contract and was intentionally not copied into IAM5-16:

- `codex/iam-lane-54-organization-role-bootstrap-proof` adds the dedicated
  backend `organization-role-bootstrap-proof-contract.php` and wrapper, plus
  route-level proof that organization User/Admin relationships can be created,
  persisted, surfaced, used for permission grants, logged in, logged out, and
  rejected after logout. Current base has the session-normalization floor and
  org-admin call-rights proof, but not this end-to-end backend route proof.
- The lane-54 source also changes organization-user synchronization/exposure so
  relationship payloads carry organization User/Admin role intent. Current
  `governance_organization_memberships.php` still inserts organization users as
  `member` through the generic relationship sync path. Preserving lane-54
  exactly requires backend tenancy route work outside this doc/static-contract
  write scope.
- `codex/iam-lane-59-admin-join-boundaries-proof` adds a unified backend
  `admin-join-boundaries-contract.php` for system-admin, org-admin, owner,
  disabled-user, terminal/deleted, and realtime room-resolution behavior.
  Current base has focused system-admin/org-admin runtime contracts plus a
  browser admin-join boundary proof, but not the unified backend runtime proof.
- `local/iam-e2e-system-admin-edge-cases` and
  `codex/iam-e2e-anon-system-admin-proof-20260509` add system-admin review flag
  list/handle endpoints with fingerprint-minimized public payloads and audit
  records. Current base has duplicate/review privacy and audit-redaction floors
  elsewhere, but not these system-admin review flag routes.
- The system-admin edge branches also add a backend runtime tenantless
  system-admin direct-join proof. Current base keeps tenantless behavior in the
  deterministic seed matrix and direct-join static/browser proof; a backend
  tenantless system-admin runtime contract remains follow-up evidence.
- `codex/iam-lane-58-owner-transfer-rights-audit-proof` adds explicit
  owner-transfer target-boundary helpers, cross-tenant/cross-organization
  target denial, and a successful-transfer audit payload that records the
  exactly-one-owner invariant and old-owner retained-rights state. IAM5-10
  already documented the owner-transfer audit mutation gap; IAM5-16 records the
  lane branch as the focused source for that backend follow-up.
- `local/iam-e2e-call-owner-creation-rights` folds call-creator owner rights
  and lobby moderation into one backend proof. Current base keeps those values
  in separate maintained `call-creation-owner-rights` and
  `call-owner-moderation` contracts, so the broad branch was not imported.
- `local/iam-e2e-core-org-session-journey` includes logged-in open-link
  behavior that keeps the registered account active for the call link. That
  overlaps with later temp-access extraction decisions and should be handled as
  explicit product work if reopened; IAM5-16 only extracts the organization
  session and role-boundary evidence.

## Added Focused Proof

`demo/video-chat/frontend-vue/tests/contract/iam5-16-system-admin-lanes-extract-contract.mjs`
is a standalone static extraction contract. It checks this evidence document,
the current seed matrix, current frontend boundary specs, and the maintained
system-admin/org-admin/owner/session contracts listed above. It adds no package
script, shared CI wiring, product code, `SPRINT.md`, or `BACKLOG.md` changes.

## Extraction Decision

Safe extraction performed in this branch:

- documented every requested IAM5-16 source branch, observed head, clean/source
  status, and reusable proof value;
- mapped system-admin, organization-admin, admin-join, owner-creation, and
  terminal-state value to current maintained contracts;
- preserved organization-role bootstrap route proof, unified admin-join backend
  proof, system-admin review flags, tenantless backend runtime proof, and
  owner-transfer rights audit as source-only backend follow-up evidence;
- avoided importing broad branch runtime, package, CI, script, or protected-area
  changes.

No product code, package scripts, shared CI wiring, `SPRINT.md`, `BACKLOG.md`,
or protected Background/Gossip/SFU/MediaSecurity/BTGF/deploy files were edited.
