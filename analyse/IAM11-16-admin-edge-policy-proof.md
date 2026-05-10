# IAM11-16 Admin Edge Policy Proof

Source branches:

- `local/iam-e2e-org-admin-owner-transfer-policy` at `3ff99f1c`
- `local/iam-e2e-system-admin-edge-cases` at `434a3ec3`

Scope kept for this workspace:

- Tests and analysis docs only.
- No Background, Gossip, SFU, MediaSecurity, or BTGF files touched.
- No backend authority changes, because the current workspace already contains
  in-progress call-access and owner-transfer edits from other lanes.

## System Admin Boundary

The existing `system-admin-call-rights-contract.php` proves the platform-admin
boundary by constructing a foreign-tenant call where the system admin has no
tenant membership or call participant row. It asserts that the real seeded admin
can fetch, update, manage participants, transfer owner, and keep access after
owner transfer. It also asserts that a normal user with forged `admin` input and
a temporary admin-shaped guest cannot receive system-admin call rights.

The system-admin source branch extends this proof with tenantless-call direct
join and review-flag handling. Those are still stronger-contract requirements,
but they rely on backend review/domain routes that are not present in this
workspace. The current proof should therefore stay as a boundary proof and not
be weakened into role-string acceptance.

## Organization Admin Boundary

The existing `org-admin-call-rights-contract.php` proves organization scoping:

- an organization admin can fetch, update, and manage participants for own
  organization calls without a guest-list row;
- the same organization admin cannot fetch, update, or manage a foreign
  organization call;
- realtime context must elevate only the own-organization call to moderator
  authority.

The org-admin owner-transfer branch proves the intended next edge: when policy
allows it, organization admins keep organization-admin moderation and
owner-transfer authority after ownership changes, while foreign organizations
remain denied and exactly one stored owner remains. Current backend authority
does not yet expose that owner-transfer policy to organization admins, so the
SPRINT claim must not be marked complete from docs alone.

## Current Local Verification

Host PHP lacks `pdo_sqlite`, so focused PHP proof used the Docker PHP runtime.

- `docker run --rm -v "$PWD":/workspace -w /workspace php:8.4-cli-trixie php demo/video-chat/backend-king-php/tests/call-owner-transfer-contract.php`
  passed.
- `docker run --rm -v "$PWD":/workspace -w /workspace php:8.4-cli-trixie php demo/video-chat/backend-king-php/tests/call-access-cross-org-contract.php`
  passed.
- `docker run --rm -v "$PWD":/workspace -w /workspace php:8.4-cli-trixie php demo/video-chat/backend-king-php/tests/system-admin-call-rights-contract.php`
  failed at `system admin should manage foreign-tenant call participants`.
- `docker run --rm -v "$PWD":/workspace -w /workspace php:8.4-cli-trixie php demo/video-chat/backend-king-php/tests/org-admin-call-rights-contract.php`
  failed at `own realtime context call id mismatch`.

The failures are existing local behavior before IAM11-16 changes. They show why
this leaf should not close backend authority from analysis-only work.
