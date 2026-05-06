# Video Chat Module Boundaries

This inventory is the execution map for the first descriptor-based module wave.
It covers non-call workspace surfaces only. Video-call runtime, public booking,
SFU, screen sharing, call dashboards, and realtime call workspace code stay out
of this wave.

## No-Touch Paths

- `demo/video-chat/frontend-vue/src/domain/calls/**`
- `demo/video-chat/frontend-vue/src/domain/realtime/**`
- `demo/video-chat/frontend-vue/src/lib/sfu/**`
- `demo/video-chat/frontend-vue/src/lib/wasm/**`
- `demo/video-chat/frontend-vue/src/lib/wavelet/**`

## Current Route Ownership

| Current route | Current component owner | Target module |
| --- | --- | --- |
| `/admin/overview` | `domain/users/overview/OverviewView.vue` | `users` |
| `/admin/administration/marketplace` | `domain/marketplace/AdminMarketplaceView.vue` | `marketplace` |
| `/admin/administration/localization` | `domain/administration/AdministrationLocalizationView.vue` | `localization` |
| `/admin/administration/app-configuration` | `domain/administration/AppConfigurationView.vue` | `administration` |
| `/admin/administration/theme-editor` | `domain/administration/ThemeEditorView.vue` | `theme_editor` |
| `/admin/governance/users` | `domain/users/admin/UsersView.vue` | `users` |
| `/admin/governance/groups` | `domain/governance/GovernanceCrudView.vue` | `governance` |
| `/admin/governance/organizations` | `domain/governance/GovernanceCrudView.vue` | `governance` |
| `/admin/governance/modules` | `domain/governance/GovernanceCrudView.vue` | `governance` |
| `/admin/governance/permissions` | `domain/governance/GovernanceCrudView.vue` | `governance` |
| `/admin/governance/roles` | `domain/governance/GovernanceCrudView.vue` | `governance` |
| `/admin/governance/grants` | `domain/governance/GovernanceCrudView.vue` | `governance` |
| `/admin/governance/policies` | `domain/governance/GovernanceCrudView.vue` | `governance` |
| `/admin/governance/audit-log` | `domain/governance/GovernanceCrudView.vue` | `governance` |
| `/admin/governance/data-portability` | `domain/governance/GovernanceCrudView.vue` | `governance` |
| `/admin/governance/compliance` | `domain/governance/GovernanceCrudView.vue` | `governance` |

The compatibility redirects `/admin/administration`, `/admin/users`,
`/admin/marketplace`, and `/admin/tenancy` must remain stable while their final
targets become descriptor-owned.

## Direct Imports To Remove

- `src/http/router.js` directly imports non-call route components through
  inline dynamic imports. The module runtime should replace these with route
  records generated from descriptors.
- `src/layouts/WorkspaceNavigation.vue` hardcodes Administration and Governance
  navigation groups. Navigation should be generated from descriptor metadata.
- `src/layouts/WorkspaceShell.vue` and child settings panels currently own
  personal settings, administration settings, theme selection/editor wiring, and
  localization selection. Settings panels should move behind module descriptors.
- Feature modules may import shared components and support helpers. Shared
  components must not import feature modules.

## Target Modules

| Module key | Owns first | Notes |
| --- | --- | --- |
| `administration` | App configuration, admin shell metadata | Does not own Marketplace, Localization, or Theme Editor implementation. |
| `governance` | Groups, organizations, modules, permissions, roles, grants, policies, audit log, compliance, portability shell | Must align with tenancy/group permission sprint contracts. |
| `users` | Admin user management and overview | Existing user routes must keep URLs and role gates. |
| `marketplace` | Marketplace app administration | Keep current backend marketplace API contracts. |
| `workspace_settings` | About Me, Credentials + E-Mail, Theme selection, Localization selection | Personal settings only; no admin editor here. |
| `localization` | Language administration and CSV upload/import UI | Superadmin upload remains separate from personal language selection. |
| `theme_editor` | Admin theme CRUD, logos, editor wizard, preview | Normal users only select themes through `workspace_settings`. |

## Shared Component Candidates

- Admin page frame: repeated h1/header/action layout in Governance, Marketplace,
  User Management, Localization, Theme Editor.
- CRUD table frame: search, table, empty state, action column, pagination footer.
- CRUD modal shell: current modal patterns in Governance, Marketplace, User
  Management, and Theme Editor should consume one maximizable shell.
- Settings panel frame: personal settings tabs and administration panels should
  use one common panel contract.
- Upload/import frame: CSV localization import and future data portability
  import should share preview, validation, and commit states.

## Execution Checklist

- Add a frontend module registry before moving feature files.
- Preserve existing route URLs and redirects.
- Generate non-call route records from descriptors while leaving call routes
  hardcoded until the call-specific module sprint.
- Generate Administration and Governance navigation from descriptor metadata.
- Register settings panels from descriptor metadata.
- Add path guards proving this wave did not edit the no-touch paths.
