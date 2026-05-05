# King Active Issues

## Sprint: Video Chat Multi-Tenancy Foundation

Sprint branch:
- `develop/1.0.8-beta`

Target:
- `demo/video-chat`
- KingRT production video chat deployment.

Goal:
- Convert the video-chat application from implicit single-tenant state to
  explicit tenant isolation across auth, persistence, realtime, public links,
  administration, organization hierarchies, groups, permissions, themes,
  calendars, leads, tenant-scoped export/import, and deployment smoke checks.
- Preserve the current single-tenant production data by migrating it into one
  default tenant instead of resetting or narrowing existing behavior.
- Make cross-tenant reads, writes, websocket events, SFU routing, public
  booking access, and admin operations fail closed by contract.

Current single-tenant assumptions to remove:
- `users.email` and account administration are globally scoped.
- `sessions` carry only a user, not an active tenant.
- `workspace_administration_settings` has one global row with `id = 1`.
- Theme presets, logos, lead settings, website leads, appointment calendars,
  calls, rooms, invites, access sessions, diagnostics, chat, and SFU state are
  not consistently tenant-bound.
- Primary admin `user_id = 1` is treated as the operational owner of global
  workspace settings.
- Frontend session state has role/user context but no tenant context.
- Administration is flat: there are no tenant-level organizations,
  suborganizations, groups, or time-boxed rights.
- Calendars and other resources are owner-scoped only; they cannot be shared to
  a group through explicit permission grants.
- Users and organizations have no scoped export/import path for their own data.

Architecture decision:
- Add tenants as first-class workspaces with an internal `tenant_id` and a
  public UUID.
- Keep users as global identities and attach them to tenants through
  `tenant_memberships`.
- Move role and operational permissions to tenant membership scope:
  `owner`, `admin`, `member`, plus per-tenant feature flags such as theme
  editor access.
- Model organizations as a tenant-owned tree with root organizations and nested
  suborganizations. Users can create and administer organizations or
  suborganizations only when their tenant permissions allow it.
- Add tenant-owned groups that can include users and organization members.
- Authorize shared resources through explicit permission grants over users,
  groups, and organizations. Grants must support `valid_from`, `valid_until`,
  and revocation metadata so rights can be time-limited.
- Calendars, calls, settings surfaces, and future shareable resources must use
  the same grant resolver instead of one-off owner checks.
- Provide canonical export bundles for user-owned data and organization-owned
  data. Imports must validate tenant scope, remap IDs, preserve audit metadata,
  and fail closed on unknown schema versions or foreign-tenant references.
- Export bundles must be versioned, resumable for large data, and explicit
  about secrets: credentials and private keys are excluded unless a later
  approved encrypted-secret export contract is added.
- Keep platform administration separate from tenant administration. The
  primary platform admin can create and recover tenants, but tenant data access
  must still go through an explicit tenant scope.
- Store the active tenant on sessions and expose it in auth snapshots.
- Every repository/query path that touches tenant data must receive tenant
  context from auth or from a validated public token resolver.
- Existing public UUIDs and invite links remain valid after migration by
  backfilling them into the default tenant.

Out of scope for this sprint:
- Billing, plan limits, invoicing, and subscription management.
- Tenant-owned custom domains.
- Physical database-per-tenant sharding.
- Self-service public tenant signup.
- Billing-driven permission packages or seat accounting.
- Changing the core media codec, SFU quality policy, or appointment UX beyond
  tenant isolation needs.
- Cross-product data import from systems outside KingRT video chat.

## Active Issues

1. [ ] `[tenant-inventory-and-contract-map]` Map every tenant-owned surface before schema work.

   Scope:
   - Audit tables, REST modules, websocket/SFU handlers, frontend stores, and
     public endpoints for tenant-owned data.
   - Classify each table as platform-global, global identity, tenant-owned, or
     derived/transient.
   - Classify organization, group, permission-grant, and resource-share
     surfaces separately from simple tenant-owned records.
   - Identify all places still assuming `user_id = 1` or one global settings
     row.

   Done when:
   - [ ] `SPRINT.md` or a focused tenant map documents the ownership class for
     each video-chat table.
   - [ ] Every public endpoint has a tenant-resolution rule.
   - [ ] Every authenticated endpoint has an active-tenant requirement or a
     platform-admin exception.
   - [ ] Cross-tenant leak risks are listed before implementation starts.

2. [ ] `[tenant-schema-and-backfill]` Add tenant schema and migrate current data.

   Scope:
   - Add `tenants`, `tenant_memberships`, `organizations`,
     `organization_memberships`, `groups`, `group_memberships`, and
     `permission_grants`.
   - Add export/import job metadata tables for user and organization data
     bundles.
   - Backfill one default tenant for the current production workspace.
   - Backfill a root organization and default group for the default tenant.
   - Add tenant references to tenant-owned tables.
   - Convert global settings tables to tenant-owned settings rows.
   - Preserve existing users, calls, rooms, invites, calendars, bookings,
     themes, leads, diagnostics, and public IDs.

   Done when:
   - [ ] Fresh databases create a default tenant with deterministic seed data.
   - [ ] Existing databases migrate without dropping production data.
   - [ ] Existing users become members of the default tenant root organization
     and default group.
   - [ ] Foreign keys and indexes support tenant-scoped lookup paths.
   - [ ] Existing public booking and call links still resolve after migration.

3. [ ] `[tenant-organization-group-permissions]` Add hierarchical organizations, groups, and time-limited grants.

   Scope:
   - Let tenant admins create, edit, archive, and nest organizations.
   - Let permitted users create suborganizations inside organizations they can
     administer.
   - Let tenant admins create groups, add/remove users, and optionally bind
     groups to organization scopes.
   - Add a grant resolver for resource permissions with subject types `user`,
     `group`, and `organization`, resource types such as `calendar`, and
     time-boxed validity.
   - Make appointment calendars grantable to groups without exposing unrelated
     calendars or tenant settings.

   Done when:
   - [ ] Organization trees support parent/child constraints and cannot cross
     tenants.
   - [ ] Group memberships cannot reference users outside the tenant.
   - [ ] Permission grants support create/read/update/delete/share-style
     actions, `valid_from`, `valid_until`, and revocation.
   - [ ] Calendar access can be granted to a group and expires at the configured
     time.
   - [ ] The grant resolver has negative tests for expired, revoked, wrong
     tenant, wrong group, and wrong organization access.

4. [ ] `[tenant-auth-session-context]` Carry active tenant through authentication.

   Scope:
   - Add active tenant to `sessions`.
   - Return tenant ID, tenant UUID, tenant label, membership role, and
     tenant-scoped permissions in login/session/refresh responses.
   - Add a tenant switch endpoint for users with multiple memberships.
   - Reject sessions whose user is not active in the requested tenant.

   Done when:
   - [ ] Session validation returns both global user identity and tenant
     membership context.
   - [ ] Login selects the user's default tenant or returns a typed
     tenant-selection response when needed.
   - [ ] Session refresh preserves or revalidates the active tenant.
   - [ ] Deactivating a membership revokes or blocks affected sessions.

5. [ ] `[tenant-rbac-and-admin-split]` Split platform admin from tenant admin.

   Scope:
   - Replace global `admin` behavior with explicit platform-admin and
     tenant-admin checks.
   - Scope user management to the active tenant.
   - Allow tenant admins to invite, activate, deactivate, and permission users
     inside their tenant only.
   - Apply group-derived and time-limited grants after tenant membership checks.
   - Keep platform admin recovery routes narrowly scoped and audited.

   Done when:
   - [ ] Tenant admins cannot see or mutate users outside their tenant.
   - [ ] Platform admin routes are distinct from tenant admin routes.
   - [ ] Theme editor permission is tenant-scoped.
   - [ ] Time-limited grants are enforced consistently in REST and websocket
     admission.
   - [ ] RBAC contract tests include forbidden cross-tenant attempts.

6. [ ] `[tenant-scoped-repositories]` Tenant-bind all REST data access.

   Scope:
   - Calls, rooms, participants, invite codes, call access links, chat archive,
     attachments, diagnostics, marketplace/admin data, appointment blocks,
     bookings, settings, leads, and themes must use tenant predicates.
   - Resource-level access must consult direct, group, and organization grants
     where a resource is shareable.
   - Add small helper APIs for tenant-required query parameters instead of
     repeating ad hoc SQL snippets.
   - Keep public responses minimal and tenant-safe.

   Done when:
   - [ ] Every tenant-owned query includes tenant scope at the SQL boundary.
   - [ ] Missing tenant context fails with a typed auth/authorization error.
   - [ ] Cross-tenant ID guessing returns not found or forbidden without data.
   - [ ] Calendar reads/writes honor group grants and grant expiry.
   - [ ] Existing single-tenant contract tests still pass under the default
     tenant.

7. [ ] `[tenant-public-link-resolution]` Tenant-bind public booking and access links.

   Scope:
   - Resolve public calendar UUIDs, call access tokens, invite codes, website
     lead submissions, and confirmation flows to exactly one tenant.
   - Keep public IDs unguessable and never expose internal tenant IDs.
   - Ensure booking-generated calls/access links inherit the tenant from the
     resolved public calendar.

   Done when:
   - [ ] Public calendar reads work without authentication but resolve a tenant.
   - [ ] Booking writes cannot cross from one tenant calendar into another
     tenant's calls or settings.
   - [ ] Existing public UUID links remain stable after default-tenant
     migration.
   - [ ] Negative tests prove wrong-tenant IDs do not leak owner metadata.

8. [ ] `[tenant-realtime-and-sfu-isolation]` Tenant-bind websocket, room presence, and SFU routing.

   Scope:
   - Include tenant scope in websocket auth context, room joins, presence
     snapshots, signaling, chat fanout, SFU admission, SFU store keys, recovery
     messages, and diagnostics.
   - Treat `(tenant_id, room_id, call_id)` as the routing identity, not just
     room/call IDs.
   - Re-evaluate time-limited room/call grants during join, reconnect, and
     sensitive moderation actions.
   - Keep reconnection/backfill tenant-safe.

   Done when:
   - [ ] A socket cannot join a room from another tenant.
   - [ ] Presence, chat, reactions, typing, layout, and moderation events are
     isolated by tenant.
   - [ ] `/sfu` admission requires tenant-bound call/room membership.
   - [ ] Expired group grants stop new websocket/SFU admission.
   - [ ] SFU recovery logs and counters cannot mix tenants with equal room IDs.

9. [ ] `[tenant-workspace-settings]` Convert administration, email, logos, and themes to tenant settings.

   Scope:
   - Replace the singleton workspace settings row with per-tenant settings.
   - Add platform defaults that tenants can inherit from or override.
   - Scope SMTP sender, lead recipients, lead templates, appointment mail
     templates, sidebar logo, modal logo, and theme presets to the active
     tenant.
   - Decide which settings are tenant-wide and which can be delegated to
     organization or group administrators.
   - Preserve current Admin/Administration UX while showing tenant context.

   Done when:
   - [ ] Tenant admins edit only their tenant settings.
   - [ ] Platform admin can manage platform defaults without reading tenant
     private data unnecessarily.
   - [ ] Theme CRUD is tenant-aware and pagination stays local to the tenant.
   - [ ] Website leads and booking mails use the tenant's mail settings.

10. [ ] `[tenant-data-export-import]` Add scoped user and organization data export/import.

   Scope:
   - Let users export their own account data, memberships, calendar data they
     own, bookings, call metadata, chat/archive records they are allowed to
     receive, files/attachments they own, and personal settings.
   - Let authorized organization admins export organization data, including
     suborganizations, organization users, groups, group memberships,
     permission grants, calendars, bookings, calls, settings, leads, themes,
     and permitted attachments.
   - Add import for previously exported user and organization bundles with
     schema version checks, tenant validation, ID remapping, conflict handling,
     and dry-run validation.
   - Treat import as a privileged operation that can create or update data only
     inside the active tenant and only inside organizations the importer can
     administer.
   - Store export/import audit records with actor, tenant, organization/user
     scope, timestamps, result, and failure reason.

   Done when:
   - [ ] A user can export their own data without receiving another user's or
     organization's private data.
   - [ ] An organization admin can export only the organization/suborganization
     tree they administer.
   - [ ] Import rejects bundles with missing schema version, unknown schema
     version, wrong tenant, invalid references, expired grants, or forbidden
     organization scope.
   - [ ] Import dry-run reports created, updated, skipped, and conflicting
     records without mutating data.
   - [ ] Successful import remaps internal IDs and preserves public UUIDs only
     when they are safe and non-conflicting.
   - [ ] Export bundles exclude secrets such as SMTP passwords by default.

11. [ ] `[tenant-frontend-context]` Add tenant context to frontend state and navigation.

   Scope:
   - Store active tenant in session state.
   - Add a tenant switcher for users with multiple memberships.
   - Add one clear administration menu area for Organizations, Suborganizations,
     Groups, Users, Permissions, and Data Export/Import instead of scattering
     those screens.
   - Ensure Calls, User Management, Settings, Calendar, Public Booking, and
     Call Workspace use the active tenant snapshot.
   - Keep public booking pages free from authenticated tenant switching UI.

   Done when:
   - [ ] Login/session recovery hydrates tenant context before protected views
     render.
   - [ ] Switching tenant reloads tenant-owned stores and clears stale state.
   - [ ] The administration menu groups organizations, groups, users, and
     permission grants plus export/import in one discoverable place.
   - [ ] Admin/user dashboards show the current tenant unambiguously.
   - [ ] Browser tests prove no stale data remains after switching tenants.

12. [ ] `[tenant-contract-tests]` Add cross-tenant backend contract coverage.

    Scope:
    - Add two-tenant fixtures for auth, users, calls, invites, appointment
      calendars, bookings, workspace settings, themes, leads, chat, diagnostics,
      organization trees, groups, permission grants, export/import bundles,
      realtime admission, and SFU admission.
    - Prove negative access paths, not just happy paths.

    Done when:
    - [ ] Contract tests fail if any tenant-owned query omits tenant scope.
    - [ ] Cross-tenant reads, updates, deletes, invite redemption, booking, and
      websocket joins are denied.
    - [ ] Group-granted calendar access works while valid and fails after
      expiry or revocation.
    - [ ] User and organization export/import tests prove scoped data,
      rejected foreign references, dry-run behavior, and ID remapping.
    - [ ] Migration tests prove default-tenant backfill.
    - [ ] The existing appointment calendar duplicate-booking and recurring-slot
      contracts still pass.

13. [ ] `[tenant-browser-smoke]` Add browser coverage for tenant UX.

    Scope:
    - Cover tenant switching, organization/suborganization management, group
      management, time-limited permission grants, scoped user management,
      scoped export/import, scoped settings/theme editing, public booking, and
      call entry.
    - Run desktop and mobile smoke paths.

    Done when:
    - [ ] Tenant A and Tenant B data never appear together in the UI.
    - [ ] A calendar shared to a group is visible only to group members while
      the grant is valid.
    - [ ] Switching tenants reloads calls, users, settings, and themes.
    - [ ] User and organization export/import UI is visible only to authorized
      users and shows dry-run results before import.
    - [ ] Public booking selects slots from the correct tenant calendar.
    - [ ] A tenant-bound call can be joined and remains realtime-isolated.

14. [ ] `[tenant-deploy-and-rollout-proof]` Deploy with explicit migration and rollback proof.

    Scope:
    - Add deploy preflight backup/checks for tenant migration.
    - Run production health, auth, public booking, and call smoke checks after
      deploy.
    - Verify the default tenant contains the existing production data.

    Done when:
    - [ ] Deploy applies tenant migrations once and idempotently.
    - [ ] Production health and version probes pass.
    - [ ] A production smoke proves default tenant calls, calendars, settings,
      and public links.
    - [ ] Rollback notes identify which migration steps are non-reversible.

15. [ ] `[appointment-calendar-test-carryover]` Keep appointment calendar verification alive during tenant work.

    Scope:
    - Preserve owner slot creation, recurring weekly public slots, public
      week-only calendar, booking form validation, privacy consent, booking
      confirmation, and duplicate-booking prevention.
    - Add the missing browser smoke that was still open in the previous sprint.

    Done when:
    - [ ] Owner drag-select and form-entry slot creation work inside one tenant.
    - [ ] Public slot selection works for that tenant only.
    - [ ] Duplicate booking prevention still runs server-side.
    - [ ] Desktop and mobile public booking smoke tests pass.

## Execution Order

1. [ ] Inventory tables, endpoints, realtime paths, and frontend stores.
2. [ ] Add tenant schema, default-tenant migration, organization tree, groups,
   and permission-grant model.
3. [ ] Implement the grant resolver for user, group, and organization subjects
   with time-limited validity.
4. [ ] Carry tenant context through auth/session/RBAC.
5. [ ] Tenant-bind REST repositories and public link resolvers.
6. [ ] Tenant-bind websocket, presence, chat, layout, diagnostics, and SFU.
7. [ ] Convert workspace administration, email, logos, themes, leads, and
   appointment settings to tenant scope.
8. [ ] Add scoped user/organization export and import with dry-run validation.
9. [ ] Add frontend tenant context, switcher, Organizations/Groups/Users menu,
   permission UI, export/import UI, and stale-state clearing.
10. [ ] Add backend and browser cross-tenant, group-grant, expiry, and
   export/import tests.
11. [ ] Deploy with migration backup, smoke checks, and default-tenant proof.

## Subagent Execution Plan

The sprint must be executed as staged, disjoint workstreams. Subagents may read
outside their owned paths, but they must not edit another subagent's owned paths
unless the coordinator explicitly moves ownership between phases.

### Phase 0: Contract Freeze

1. [ ] `Tenant Contract Coordinator`

   Owns:
   - `SPRINT.md`
   - `documentation/dev/video-chat/tenant-architecture.md`
   - `demo/video-chat/contracts/v1/api-ws-contract.catalog.json`
   - any new shared tenant contract fixture under `demo/video-chat/contracts/**`

   Delivers:
   - Table/resource ownership map.
   - Canonical tenant context shape.
   - Public link tenant-resolution rules.
   - Permission grant action names and resource type names.
   - Export bundle schema version and top-level manifest shape.

   Gate:
   - No implementation agent starts tenant persistence or API payload changes
     until this shape is documented.

### Phase 1: Backend Foundations

These agents can run in parallel after Phase 0, because their write sets are
separate.

1. [ ] `Schema And Migration Agent`

   Owns:
   - `demo/video-chat/backend-king-php/support/database_migrations.php`
   - `demo/video-chat/backend-king-php/support/database_demo_seed.php`
   - new migration helpers under `demo/video-chat/backend-king-php/support/tenant_*.php`
   - migration/backfill tests only under
     `demo/video-chat/backend-king-php/tests/tenant-migration-*.php`

   Reads:
   - all current domain modules for table ownership.

   Delivers:
   - `tenants`, memberships, organization tree, groups, permission grants, and
     export/import job metadata.
   - Default-tenant backfill preserving existing production data.
   - Idempotent fresh-install and upgrade behavior.

2. [ ] `Auth And Tenant Session Agent`

   Owns:
   - `demo/video-chat/backend-king-php/support/auth.php`
   - `demo/video-chat/backend-king-php/support/auth_request.php`
   - `demo/video-chat/backend-king-php/support/auth_session_cache.php`
   - `demo/video-chat/backend-king-php/http/module_auth_session.php`
   - auth/session/RBAC tests under `demo/video-chat/backend-king-php/tests/session-*`,
     and new `tenant-auth-*.php`

   Reads:
   - schema contract from the coordinator.
   - user membership helpers from the Org/Permissions agent once available.

   Delivers:
   - Active tenant on login/session/refresh.
   - Tenant switch endpoint.
   - Tenant membership role and tenant permissions in auth snapshots.

3. [ ] `RBAC Router Boundary Agent`

   Owns:
   - `demo/video-chat/backend-king-php/support/auth_rbac.php`
   - `demo/video-chat/backend-king-php/http/router.php`
   - route-order, protected API, and RBAC tests under
     `demo/video-chat/backend-king-php/tests/router-*`,
     `protected-api-*`, `rbac-*`, and new `tenant-router-*.php`

   Reads:
   - all `demo/video-chat/backend-king-php/http/module_*.php` files.

   Delivers:
   - Additive tenant-context module contract.
   - Public route classification: tenant-resolving public route,
     authenticated tenant route, or platform-global route.
   - Stable route/module registration points for later backend agents.

   Gate:
   - No later backend agent edits `router.php`; new routes are queued for this
     owner or added in a coordinator-controlled merge.

4. [ ] `Organizations Groups Permissions Agent`

   Owns:
   - new `demo/video-chat/backend-king-php/domain/tenancy/**`
   - new `demo/video-chat/backend-king-php/http/module_tenancy.php`
   - tenant organization/group/permission tests under
     `demo/video-chat/backend-king-php/tests/tenant-organization-*.php`,
     `tenant-group-*.php`, and `tenant-permission-*.php`

   Reads:
   - auth context from the Auth agent.
   - schema from the Schema agent.

   Delivers:
   - Organization/suborganization CRUD.
   - Group CRUD and memberships.
   - Shared grant resolver with `valid_from`, `valid_until`, and revocation.
   - Calendar grant proof through resolver-level tests, not calendar UI.

### Phase 2: Tenant-Scoped Domain Agents

These agents start only after Phase 1 contracts are stable. They share tenant
helpers read-only and own separate domain directories.

1. [ ] `Calls Invites Public Links Agent`

   Owns:
   - `demo/video-chat/backend-king-php/domain/calls/call_*`
   - `demo/video-chat/backend-king-php/domain/calls/invite_*`
   - `demo/video-chat/backend-king-php/http/module_calls.php`
   - `demo/video-chat/backend-king-php/http/module_invites.php`
   - `demo/video-chat/backend-king-php/http/module_calls_access.php`
   - calls/invites/access tests under `demo/video-chat/backend-king-php/tests/call-*`,
     `calls-*`, `invite-*`, and `call-access-*`

   Does not edit:
   - appointment calendar files.
   - realtime/SFU files.

   Delivers:
   - Tenant-bound call, invite, access link, and call-access session behavior.
   - Public link resolution that inherits tenant safely.

2. [ ] `Users Membership Agent`

   Owns:
   - `demo/video-chat/backend-king-php/domain/users/**`
   - `demo/video-chat/backend-king-php/http/module_users.php`
   - `demo/video-chat/backend-king-php/http/module_users_admin_accounts.php`
   - user/admin/avatar/email tests under
     `demo/video-chat/backend-king-php/tests/user-*`,
     `admin-user-*`, and `avatar-*`

   Reads:
   - tenant auth context.
   - organization/group helpers.
   - workspace theme permission rules.

   Delivers:
   - Tenant-scoped user directory, account administration, settings, avatar,
     email identity/change flows, and tenant-local theme editor permission.
   - Primary-admin behavior replaced by explicit platform-admin or tenant-admin
     contracts.

3. [ ] `Appointment Calendar Agent`

   Owns:
   - `demo/video-chat/backend-king-php/domain/calls/appointment_*`
   - `demo/video-chat/backend-king-php/http/module_appointment_calendar.php`
   - appointment tests under
     `demo/video-chat/backend-king-php/tests/appointment-calendar-*`

   Does not edit:
   - generic call management files except through coordinator-approved helper
     changes.

   Delivers:
   - Tenant-bound appointment blocks, settings, bookings, recurring slots, mail
     templates, and public calendar UUID resolution.
   - Group-granted calendar access via the shared grant resolver.

4. [ ] `Workspace Settings Themes Leads Agent`

   Owns:
   - `demo/video-chat/backend-king-php/domain/workspace/**`
   - `demo/video-chat/backend-king-php/http/module_workspace_administration.php`
   - workspace/theme/lead tests under
     `demo/video-chat/backend-king-php/tests/workspace-*.php`,
     `tenant-workspace-*.php`, and `tenant-theme-*.php`

   Delivers:
   - Tenant-owned administration settings, mail server settings, logos, themes,
     leads, and platform defaults.

5. [ ] `Export Import Agent`

   Owns:
   - new `demo/video-chat/backend-king-php/domain/tenant_export_import/**`
   - new `demo/video-chat/backend-king-php/http/module_tenant_export_import.php`
   - export/import tests under
     `demo/video-chat/backend-king-php/tests/tenant-export-*.php` and
     `tenant-import-*.php`

   Reads:
   - all tenant-scoped domain repositories through public helper functions only.

   Delivers:
   - User data export.
   - Organization tree export.
   - Dry-run import, ID remapping, conflict reporting, and audit records.
   - No raw database dump format.

6. [ ] `Realtime Tenant Context Presence Agent`

   Owns:
   - new `demo/video-chat/backend-king-php/domain/realtime/realtime_tenant_context.php`
   - `demo/video-chat/backend-king-php/http/module_realtime_websocket.php`
   - `demo/video-chat/backend-king-php/domain/realtime/realtime_presence.php`
   - `demo/video-chat/backend-king-php/domain/realtime/realtime_room_snapshot.php`
   - presence/tenant context tests under
     `demo/video-chat/backend-king-php/tests/realtime-tenant-*.php`,
     `realtime-presence-*`, and `realtime-websocket-*`

   Reads:
   - auth/session tenant context.
   - calls/access admission helpers.

   Delivers:
   - Tenant-scoped realtime room/call keys.
   - Tenant-carrying websocket welcome and connection descriptors.
   - Tenant-partitioned room presence and snapshots.

7. [ ] `Realtime Commands Agent`

   Owns:
   - `demo/video-chat/backend-king-php/http/module_realtime_websocket_commands.php`
   - `demo/video-chat/backend-king-php/domain/realtime/realtime_chat.php`
   - `demo/video-chat/backend-king-php/domain/realtime/realtime_typing.php`
   - `demo/video-chat/backend-king-php/domain/realtime/realtime_reaction.php`
   - `demo/video-chat/backend-king-php/domain/realtime/realtime_reaction_broker.php`
   - `demo/video-chat/backend-king-php/domain/realtime/realtime_signaling.php`
   - command/broker tests under
     `demo/video-chat/backend-king-php/tests/realtime-chat-*`,
     `realtime-typing-*`, `realtime-reaction-*`, and
     `realtime-signaling-*`

   Does not edit:
   - `module_realtime_websocket.php`
   - SFU files.

   Delivers:
   - Tenant-safe chat, typing, reaction, signaling, activity, layout, and
     moderation command routing using tenant-scoped room/call keys.

8. [ ] `Realtime Diagnostics Agent`

   Owns:
   - `demo/video-chat/backend-king-php/domain/realtime/client_diagnostics.php`
   - diagnostics HTTP/module tests under
     `demo/video-chat/backend-king-php/tests/client-diagnostics-*` and
     `tenant-diagnostics-*`

   Reads:
   - schema migration for diagnostics tenant columns.
   - auth tenant context.

   Delivers:
   - Tenant-bound diagnostic writes and tenant-default query filters.

9. [ ] `Realtime SFU Agent`

   Owns:
   - `demo/video-chat/backend-king-php/domain/realtime/realtime_sfu_gateway.php`
   - `demo/video-chat/backend-king-php/domain/realtime/realtime_sfu_store.php`
   - `demo/video-chat/backend-king-php/domain/realtime/realtime_sfu_frame_buffer.php`
   - `demo/video-chat/backend-king-php/domain/realtime/realtime_sfu_broker_replay.php`
   - `demo/video-chat/backend-king-php/domain/realtime/realtime_sfu_recovery_requests.php`
   - `demo/video-chat/backend-king-php/domain/realtime/realtime_sfu_subscriber_budget.php`
   - SFU/gateway/media tests under
     `demo/video-chat/backend-king-php/tests/realtime-sfu-*`,
     `gateway-*`, and `media-security-*`

   Reads:
   - realtime tenant context helper.
   - calls/access admission helper.

   Delivers:
   - Tenant-bound `/sfu` admission, static room maps, broker rows, frame
     buffers, recovery routing, publisher/subscriber cursors, and budget
     tracking.

### Phase 3: Frontend Agents

Frontend agents start after Phase 0 API contracts are frozen. They may mock
pending backend responses in contract tests, but backend integration is gated on
Phase 1 and Phase 2.

1. [ ] `Frontend Session Shell Agent`

   Owns:
   - `demo/video-chat/frontend-vue/src/domain/auth/**`
   - `demo/video-chat/frontend-vue/src/http/router.js`
   - `demo/video-chat/frontend-vue/src/layouts/WorkspaceShell.vue`
   - tenant context API client under `demo/video-chat/frontend-vue/src/domain/tenant/**`
   - `demo/video-chat/frontend-vue/src/domain/tenant/TenantSwitcher.vue`
   - auth/navigation tests under `demo/video-chat/frontend-vue/tests/e2e/auth-*`
     and new `tenant-session-*`

   Delivers:
   - Tenant context in session state.
   - Tenant switcher.
   - Stale tenant state clearing on switch.

2. [ ] `Frontend Administration Agent`

   Owns:
   - new organization/group/permission views under
     `demo/video-chat/frontend-vue/src/domain/identity/**`
   - tenant administration API client files under
     `demo/video-chat/frontend-vue/src/domain/identity/**`

   Reads:
   - `WorkspaceShell.vue` but does not edit it after the Session Shell agent
     owns the shell integration.

   Delivers:
   - One administration menu area for orgs, groups, users, permissions, and
     export/import.
   - Route/nav requests for the Session Shell owner to wire.

3. [ ] `Frontend Settings Themes Agent`

   Owns:
   - `demo/video-chat/frontend-vue/src/layouts/settings/**`
   - `demo/video-chat/frontend-vue/src/domain/workspace/**`
   - `demo/video-chat/frontend-vue/src/styles/settings.css`

   Delivers:
   - Tenant-aware administration settings, logos, mail settings, lead settings,
     and theme CRUD without touching tenancy admin screens.

4. [ ] `Frontend Calls Calendar Agent`

   Owns:
   - `demo/video-chat/frontend-vue/src/domain/calls/**`
   - appointment/calendar frontend tests.

   Does not edit:
   - auth/session files.
   - tenant admin views.
   - realtime call workspace internals except API call adapters.

   Delivers:
   - Tenant-aware call lists, appointment configuration, public booking, and
     group-granted calendar visibility.

5. [ ] `Frontend Realtime Agent`

   Owns:
   - `demo/video-chat/frontend-vue/src/domain/realtime/workspace/**`
   - `demo/video-chat/frontend-vue/src/domain/realtime/sfu/**`
   - `demo/video-chat/frontend-vue/src/lib/sfu/**`
   - realtime frontend contract tests.

   Does not edit:
   - `CallWorkspaceView.vue` except removing lines through extraction if the
     coordinator explicitly assigns that cleanup.

   Delivers:
   - Tenant context in websocket/SFU setup and reconnect handling.

6. [ ] `Frontend Data Portability Agent`

   Owns:
   - new `demo/video-chat/frontend-vue/src/domain/data-portability/**`
   - export/import contract and e2e tests under
     `demo/video-chat/frontend-vue/tests/contract/tenant-export-*.mjs`,
     `tenant-import-*.mjs`, and
     `demo/video-chat/frontend-vue/tests/e2e/tenant-export-import*.spec.js`

   Reads:
   - tenant context state.
   - identity labels and settings shell.

   Delivers:
   - Export job UI, artifact links, import file selection, dry-run result UI,
     conflict display, and final import confirmation.
   - Route/nav requests for the Session Shell owner to wire.

### Phase 4: Verification And Rollout Agents

1. [ ] `Backend Contract Agent`

   Owns:
   - new cross-tenant backend test fixtures and runners under
     `demo/video-chat/backend-king-php/tests/tenant-*.php`

   Rule:
   - Test agents do not edit production code. They report failures to the owner
     agent for that domain.

2. [ ] `Browser Smoke Agent`

   Owns:
   - `demo/video-chat/frontend-vue/tests/e2e/tenant-*.spec.js`
   - `demo/video-chat/frontend-vue/tests/contract/tenant-*.mjs`

   Rule:
   - Browser smoke agent does not edit Vue production files.

3. [ ] `Deploy Rollout Agent`

   Owns:
   - `demo/video-chat/scripts/deploy.sh`
   - `demo/video-chat/scripts/deploy-smoke.sh`
   - `demo/video-chat/scripts/check-deploy-idempotency.sh`
   - deploy/rollout docs referenced by the sprint.

   Delivers:
   - Migration preflight backup checks.
   - Default-tenant production smoke.
   - Rollback notes for non-reversible migrations.

### Conflict Rules

- Shared helper files must have one owner per phase. If a second agent needs a
  helper change, it requests that change from the current owner instead of
  editing the file.
- No agent edits `database_migrations.php` except the Schema And Migration
  Agent.
- No agent edits auth/session files except the Auth And Tenant Session Agent.
- No agent edits `router.php` except the RBAC Router Boundary Agent.
- Realtime backend files are split by presence, commands, diagnostics, and SFU
  ownership. A realtime agent may read other realtime files, but it does not
  edit them.
- No agent edits frontend auth/session files except the Frontend Session Shell
  Agent.
- No agent edits `WorkspaceShell.vue` or `router.js` except the Frontend
  Session Shell Agent.
- Frontend feature agents request route/nav entries instead of editing the
  shell directly.
- No agent edits tests outside its assigned test family unless the coordinator
  moves ownership.
- Merge order is schema, auth/session, tenancy permissions, domain agents,
  frontend agents, tests, deploy. Realtime and export/import can merge after
  auth/session plus tenancy permissions are stable.

## Guardrails

- Do not remove or weaken current video-call, SFU, appointment, email, theme,
  or public booking behavior to make tenant isolation easier.
- Do not introduce a manual refresh workaround for realtime tenant state.
- Do not grow `CallWorkspaceView.vue`; tenant realtime changes must land in
  focused helpers under `callWorkspace/**`, SFU modules, or backend modules.
- Keep source files below 800 lines by extracting helpers while implementing.
- Treat public UUIDs and tokens as tenant resolvers, never as permission by
  themselves.
- Any query without tenant context must be classified as platform-global before
  it is accepted.
- Permission checks must use the shared grant resolver for shareable resources;
  do not add isolated per-screen ACL logic.
- Time-limited grants must fail closed when clocks, dates, or timezone parsing
  are invalid.
- Export/import must never be used to bypass tenant, organization, group, or
  grant checks. Imported references must be remapped or rejected, not trusted.
- Export bundles must be schema-versioned and deterministic enough to test; do
  not serialize raw database dumps as the user-facing export format.
- Cross-tenant failures must be tested as first-class contract behavior.

## Definition of Done

- [ ] Existing production data is migrated into a default tenant.
- [ ] Auth/session snapshots include tenant context and tenant role.
- [ ] Tenant admins can manage only their tenant's users, calls, settings,
  calendars, leads, themes, and bookings.
- [ ] Tenant admins can create organizations, suborganizations, groups, and
  time-limited permission grants.
- [ ] Calendars can be shared to groups and become unavailable when the grant
  expires or is revoked.
- [ ] Users can export their own data, and organization admins can export/import
  organization data inside the scopes they administer.
- [ ] Imports support dry-run validation, ID remapping, conflict reporting, and
  fail-closed tenant/organization reference checks.
- [ ] Platform admin behavior is explicit and separate from tenant admin
  behavior.
- [ ] The frontend has one administration menu area for organizations, groups,
  users, permissions, and export/import.
- [ ] Public booking and invite links resolve the correct tenant without
  exposing internal IDs.
- [ ] Websocket, room presence, chat, layout, moderation, diagnostics, and SFU
  routing are tenant-isolated.
- [ ] Backend contract tests prove denied cross-tenant access.
- [ ] Browser smoke proves tenant switching and public booking isolation.
- [ ] KingRT deploy runs tenant migrations, health checks, and production smoke
  without dropping existing data.

## Worker Handoff Status 2026-05-05

Implemented foundation in this worker pass:

- Tenant contract documentation and JSON fixture:
  `documentation/dev/video-chat/tenant-architecture.md` and
  `demo/video-chat/contracts/v1/tenant-context.contract.json`.
- SQLite migration `0028_tenant_foundation_default_backfill` with default
  tenant, tenant memberships, organizations, organization memberships, groups,
  group memberships, permission grants, and export/import job metadata.
- Default-tenant backfill helpers for fresh demo bootstrap and upgraded
  databases.
- Auth/session tenant context, active tenant storage on sessions, tenant switch
  endpoint, tenant-aware session recovery/refresh payloads, and frontend
  session hydration.
- Shared permission grant resolver for user, group, and organization subjects
  with expiry/revocation fail-closed behavior.
- Minimal tenant admin context module at `/api/admin/tenancy/context`.
- Backend contract tests for tenant migration, tenant auth/session switch, and
  permission grants.

Still open for later workstreams:

- Tenant predicates are not yet applied to every calls, appointment, workspace,
  user-admin, diagnostics, realtime, and SFU query path.
- Public booking, invite, call-access, and website-lead resolvers still need
  explicit tenant-bound write-through beyond default-tenant compatibility.
- Workspace settings still use the legacy singleton row shape with tenant
  backfill; full per-tenant settings rewrite remains open.
- Organization/group CRUD, export/import execution, browser tenant-switch smoke,
  and deploy rollout proof remain open.
- PHP SQLite contract tests could not be executed in this local environment
  because the active PHP has PDO but no `pdo_sqlite` driver.
