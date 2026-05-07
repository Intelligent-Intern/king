# Video Chat Tenant Architecture

This document freezes the tenant foundation contract for the video-chat sprint.

## Tenant Context

Authenticated backend contexts carry:

- `tenant.id`: internal integer key used only server-side.
- `tenant.uuid` / `tenant.public_id`: public UUID exposed to clients.
- `tenant.slug`: stable label for system/default tenants.
- `tenant.label`: human-readable workspace label.
- `tenant.role`: `owner`, `admin`, or `member`.
- `tenant.permissions`: tenant-scoped booleans such as `tenant_admin`,
  `manage_users`, `manage_organizations`, `manage_groups`,
  `manage_permission_grants`, `edit_themes`, and `export_import`.

Global `users` remain identities. Tenant access is granted through
`tenant_memberships`. Session validation fails closed when the active tenant is
missing, archived, or no longer has an active membership.

## Default Tenant

Existing single-tenant data is migrated into one default tenant:

- public UUID: `00000000-0000-4000-8000-000000000001`
- slug: `default`
- label: `Default Workspace`

Migration backfills a root organization and default group, then attaches all
existing users to the default tenant, root organization, and default group.
Fresh demo bootstrap repeats the membership backfill after demo users are
seeded, so fresh and upgraded databases converge.

## Ownership Map

Platform-global:

- `schema_migrations`
- runtime health/version endpoints
- platform recovery/admin identity via global `roles.slug = admin`

Global identity:

- `roles`
- `users`
- `user_emails`
- `user_email_change_tokens`

Tenant-owned persistent resources:

- `sessions` through `active_tenant_id`
- `rooms`
- `calls`
- `call_participants` by joined call tenant
- `invite_codes`
- `call_access_links`
- `call_access_sessions`
- `call_chat_attachments`
- `call_chat_messages`
- `call_chat_acl`
- `call_layout_state`
- `call_participant_activity`
- `client_diagnostics`
- `appointment_blocks`
- `appointment_bookings`
- `appointment_calendar_settings`
- `workspace_administration_settings`
- `workspace_theme_presets`
- `website_leads`

Tenant access-control resources:

- `tenants`
- `tenant_memberships`
- `organizations`
- `organization_memberships`
- `groups`
- `group_memberships`
- `permission_grants`

Tenant data-portability audit resources:

- `tenant_export_jobs`
- `tenant_import_jobs`

## Public Resolution Rules

Public UUIDs and tokens resolve exactly one tenant before any write happens.
Internal tenant IDs are not exposed as public link inputs. Existing public
calendar IDs, call access IDs, and invite codes continue to resolve through the
default tenant after migration.

## Grants

Shareable resources use `permission_grants` with:

- subject types: `user`, `group`, `organization`
- resource types: initially `calendar`, extensible by domain contract
- actions: `create`, `read`, `update`, `delete`, `share`, `manage`
- time bounds: `valid_from`, `valid_until`
- revocation: `revoked_at`

Invalid timestamps, expired grants, revoked grants, wrong tenant, wrong group,
and wrong organization resolve as not granted.

## Export Bundle Manifest

Tenant export/import bundles start with:

```json
{
  "schema_version": "videochat-tenant-export-v1",
  "scope": { "type": "user|organization", "tenant_uuid": "..." },
  "created_at": "ISO-8601",
  "actor": { "user_id": 1 },
  "secrets": { "included": false },
  "resources": []
}
```

Imports must reject missing or unknown `schema_version`, foreign tenant
references, expired grants, and forbidden organization scope. SMTP passwords,
private keys, and credentials are excluded unless an approved encrypted-secret
contract is added later.
