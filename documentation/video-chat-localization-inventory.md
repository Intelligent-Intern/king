# Video Chat Localization Inventory

This is the first localization inventory for `demo/video-chat`. It maps the
translation surface before runtime/schema work starts, so later issues can
replace strings without changing routes, module contracts, or call behavior.

## Source Scan

- Frontend application: `demo/video-chat/frontend-vue/src`
- Backend application: `demo/video-chat/backend-king-php`
- Website locale source: `/home/jochen/projects/academy/intelligent-intern/services/websites/intelligent-intern.com/src/i18n`
- Website i18n runtime source: `/home/jochen/projects/academy/intelligent-intern/services/websites/intelligent-intern.com/src/i18n/index.ts`
- Frontend scan size: 185 `.vue` and `.js` source files
- Backend scan size: 173 `.php` and `.json` source files

Commands used for the first pass:

```sh
find /home/jochen/projects/academy/intelligent-intern/services/websites/intelligent-intern.com/src/i18n -maxdepth 2 -type f | sort
rg "localeCompare" demo/video-chat/frontend-vue/src demo/video-chat/backend-king-php -n
rg "Intl\\.(DateTimeFormat|NumberFormat|ListFormat|RelativeTimeFormat)|toLocale(DateString|TimeString|String)" demo/video-chat/frontend-vue/src demo/video-chat/backend-king-php -n
rg "gmdate|date\\(|strftime|DateTime" demo/video-chat/backend-king-php -n
rg -l "\\b(left|right|Left|Right|margin-left|margin-right|padding-left|padding-right|border-left|border-right|inset-left|inset-right|transform-origin:\\s*(left|right))\\b" demo/video-chat/frontend-vue/src -g '*.vue' -g '*.css' -g '*.js' | sort
```

## First Locale Set

The first supported locale set must match the locale files already used by the
Intelligent Intern website:

`am`, `ar`, `bn`, `de`, `en`, `es`, `fa`, `fr`, `ha`, `hi`, `it`, `ja`, `jv`,
`ko`, `my`, `pa`, `ps`, `pt`, `ru`, `sgd`, `so`, `th`, `tl`, `tr`, `uk`, `uz`,
`vi`, `zh`.

The website currently defaults to `de`. The video-chat sprint keeps the existing
backend migration rule that existing users default to `en` until the persisted
user preference is implemented.

RTL languages from the website runtime are `ar`, `fa`, `ps`, and `sgd`.
`demo/video-chat/frontend-vue/src/support/localizationOptions.js` already lists
the website locale files and now uses the same RTL metadata.

## Namespace Plan

| Namespace | Owns |
| --- | --- |
| `common` | Shared labels, buttons, empty states, pagination, modal controls, generic errors |
| `auth` | Login, logout, session refresh, credential and email-confirmation flows |
| `settings` | Avatar menu settings, about-me, credentials, email, theme selection, localization selection |
| `calls` | Call management, call lists, participants, invites, access links, call lifecycle labels |
| `call_workspace` | Live room UI, chat, lobby, layout, screen sharing, diagnostics, recovery state |
| `calendar` | Personal calendar, booking calendar, slot settings, recurring rules, date/time form labels |
| `users` | User management, user detail, account status, roles, user email identities |
| `tenancy` | Tenants, organizations, suborganizations, groups, permissions, timed grants |
| `marketplace` | Marketplace pages, module listing, status labels, installation controls |
| `public_booking` | Public booking page, join page, confirmation page, Google/iCal link text |
| `emails` | Appointment mails, lead mails, email-change confirmation, subject/body templates |
| `errors` | Backend response messages, validation messages, toast copy, status explanations |
| `diagnostics` | Client diagnostics, SFU/realtime recovery labels, deployment-visible diagnostics |

Additional module-local keys can live under `administration`, `theme_editor`,
`localization`, and `workspace_settings`, but they should compose the shared
namespaces above instead of creating duplicate words.

## Frontend Translation Surfaces

- App shell and navigation: `WorkspaceShell.vue`, `WorkspaceNavigation.vue`,
  route labels, sidebar group names, avatar menu labels, settings modal labels.
- Authentication: login form, validation hints, password/email labels,
  session-expired states, logout messages.
- Settings: about-me, credentials, email identities, theme selection, language
  selection, admin-only settings, upload/dropzone text, reset/default actions.
- Administration modules: Governance, Marketplace, Localization, App
  Configuration, Theme Editor, reusable admin page/table/modal controls.
- User management: user table, filters, pagination, user editor modal, role and
  status labels, email verification state.
- Tenant and governance management: tenant switcher, organizations,
  suborganizations, groups, permission grants, timed access metadata.
- Calls: call list, creation/edit/cancel dialogs, participant labels, invite
  code states, access-mode labels, dashboard labels.
- Calendar: personal calendar, appointment configuration, slot rows, week/day
  calendar labels, recurring-slot controls, booking modal and confirmation copy.
- Live call workspace: call stage labels, chat, lobby, participant controls,
  pinning, screen sharing, active-speaker/layout labels, recovery diagnostics.
- Public pages: join page, goodbye page, public booking page, public lead form.
- Shared components: page headers, icon-button aria labels, modals, selects,
  table empty states, pagination, upload controls, theme preview labels.

Representative hard-coded frontend string files from the scan:

- `src/domain/auth/LoginView.vue`
- `src/domain/calls/access/JoinView.vue`
- `src/domain/calls/appointment/AppointmentBookingModal.vue`
- `src/domain/calls/appointment/AppointmentConfigPanel.vue`
- `src/domain/calls/appointment/AppointmentSettingsModal.vue`
- `src/domain/calls/appointment/AppointmentSlotRowsForm.vue`
- `src/domain/realtime/CallWorkspaceView.vue`
- `src/domain/tenant/TenantAdminView.vue`
- `src/layouts/WorkspaceShell.vue`
- `src/modules/administration/pages/AppConfigurationView.vue`
- `src/modules/governance/pages/GovernanceCrudView.vue`
- `src/modules/localization/pages/AdministrationLocalizationView.vue`
- `src/modules/marketplace/pages/AdminMarketplaceView.vue`
- `src/modules/theme_editor/pages/ThemeEditorView.vue`
- `src/modules/users/pages/admin/UsersView.vue`
- `src/modules/users/pages/components/UserEditorModal.vue`

## Backend And Email Translation Surfaces

- HTTP response envelopes and validation messages in `http/module_*.php`.
- Domain validation error codes and fallback text in `domain/calls`,
  `domain/users`, `domain/tenancy`, `domain/marketplace`, `domain/workspace`,
  and `domain/realtime`.
- Global error envelope fallback copy in `support/error_envelope.php`.
- Auth/session/account errors in `support/auth.php`, `support/auth_request.php`,
  `http/module_auth_session.php`, and `http/module_users.php`.
- Email-change confirmation subject/body in `http/module_users.php`.
- Appointment mail subject/body templates, Google Calendar details, and date
  formatting in `domain/calls/appointment_calendar_mail.php`.
- Public lead mail SMTP settings, lead templates, recipients, and default
  subject/body copy in `domain/workspace/workspace_administration.php`.
- Deployment-visible and operator-facing messages in `support/config_hardening.php`,
  `server.php`, runtime bootstrap logs, and admin infrastructure responses.

Backend localization must keep machine-readable `code` fields stable. Human
messages can be localized through locale-aware message resolution without
breaking clients that already key off error codes.

## Locale Assumptions Found

- `src/layouts/WorkspaceShell.vue` still keeps
  `ii_videocall_v1_workspace_language` as a compatibility fallback, but the
  settings save now sends `locale` to the backend and the session snapshot owns
  the selected locale.
- `src/support/localizationOptions.js` has the right locale list and uses the
  same RTL metadata as the website runtime.
- `src/modules/governance/pages/GovernanceCrudView.vue` formats dates with
  `Intl.DateTimeFormat('de-DE', ...)`.
- `src/modules/marketplace/pages/AdminMarketplaceTable.vue`,
  `src/modules/users/pages/components/UsersTable.vue`, and
  `src/domain/realtime/workspace/utils.js` format dates with
  `Intl.DateTimeFormat('en-GB', ...)`.
- `src/support/dateTimeFormat.js` contains manual date formats for existing
  user date preferences plus locale-aware date-time and weekday display
  helpers.
- `src/modules/navigationBuilder.js` sorts labels through the shared
  locale-aware collation helper.
- Realtime participant ordering uses `localeCompare(..., 'en', ...)` in
  `src/domain/realtime/workspace/callWorkspace/roomState.js`,
  `src/domain/realtime/layout/strategies.js`, and
  `src/domain/realtime/workspace/callWorkspace/participantUi.js`.
- Backend date/time output is mostly UTC ISO through `gmdate`, `strftime`, and
  `DateTimeImmutable`; display formatting should stay in locale-aware
  presentation layers except for email templates, which need recipient locale.

## RTL Risk Map

The following files contain physical `left`/`right` layout, direction-sensitive
names, or directional ordering and need logical CSS or explicit RTL review:

- Shell/navigation/settings:
  `src/layouts/WorkspaceShell.vue`,
  `src/layouts/WorkspaceNavigation.vue`,
  `src/layouts/settings/WorkspaceAdministrationSettings.vue`,
  `src/layouts/settings/WorkspaceThemePreview.vue`,
  `src/layouts/settings/WorkspaceThemeSettings.vue`,
  `src/styles/shell.css`,
  `src/styles/settings.css`,
  `src/styles/responsive.css`,
  `src/styles/workspace-shared.css`.
- Shared/admin components:
  `src/components/AppModalShell.vue`,
  `src/components/AppPageHeader.vue`,
  `src/components/admin/AdminPageFrame.vue`,
  `src/components/admin/AdminTableFrame.vue`,
  `src/modules/administration/pages/AppConfigurationView.vue`,
  `src/modules/governance/pages/GovernanceCrudModal.vue`,
  `src/modules/marketplace/pages/AdminMarketplaceView.vue`,
  `src/modules/marketplace/pages/AdminMarketplaceView.css`,
  `src/modules/theme_editor/pages/ThemeEditorView.vue`,
  `src/modules/users/pages/admin/UsersView.css`,
  `src/modules/users/pages/components/UserEditorModal.vue`,
  `src/modules/users/pages/overview/OverviewView.vue`,
  `src/modules/users/pages/overview/OverviewView.css`.
- Auth and public access:
  `src/styles/auth.css`,
  `src/domain/calls/access/JoinView.vue`,
  `src/domain/calls/access/JoinView.css`,
  `src/domain/calls/access/GoodbyeView.vue`.
- Call management and calendar:
  `src/domain/calls/admin/CallsView.vue`,
  `src/domain/calls/admin/CallsView.css`,
  `src/domain/calls/admin/CallsViewResponsive.css`,
  `src/domain/calls/appointment/AppointmentBookingModal.vue`,
  `src/domain/calls/appointment/AppointmentConfigPanel.vue`,
  `src/domain/calls/dashboard/UserDashboardView.vue`,
  `src/domain/calls/dashboard/UserDashboardView.css`,
  `src/styles/call-settings.css`.
- Live call and realtime:
  `src/domain/realtime/CallWorkspacePanels.css`,
  `src/domain/realtime/CallWorkspaceStage.css`,
  `src/domain/realtime/layout/strategies.js`,
  `src/domain/realtime/local/screenSharePublisher.js`,
  `src/domain/realtime/media/security.js`,
  `src/domain/realtime/workspace/callWorkspace/mediaSecurityParticipantSet.js`,
  `src/domain/realtime/workspace/callWorkspace/orchestration.js`,
  `src/domain/realtime/workspace/callWorkspace/participantUi.js`,
  `src/domain/realtime/workspace/callWorkspace/roomState.js`,
  `src/domain/realtime/workspace/callWorkspace/socketLifecycle.js`.
- Low-level/render helpers:
  `src/domain/realtime/background/backendTfjs.js`,
  `src/lib/wasm/wlvc.js`.

Resolved non-call RTL pass:
- Shared admin/table frames, marketplace/user admin table wrappers,
  localization errors, auth split panes, settings theme previews, reusable theme
  preview chrome, shared workspace table/list spacing, shell sidebar overlays,
  navigation submenu indentation, and shared pagination direction icons now use
  logical inline CSS or explicit `html[dir="rtl"]` mirroring.
- Canvas and video content are intentionally not mirrored by the shared RTL
  rules; remaining canvas/video review belongs to the call-specific RTL pass.

Remaining documented physical-coordinate cases:
- `src/styles/responsive.css` and `src/modules/users/pages/overview/OverviewView.vue`
  still contain calendar/sidebar placement coordinates that need responsive
  calendar proof before conversion.
- `src/styles/settings.css` crop preview and `src/styles/workspace-shared.css`
  hand-drawn icon offsets keep physical coordinates because they are geometric,
  not text-flow direction.
- `src/styles/call-settings.css`, public join/booking, call management,
  dashboard, and realtime files remain open for the call-specific RTL pass.

The live call surface is included in this inventory because strings and RTL
risks must be known, but the current non-call module/refactor sprint must not
modify `domain/calls` or `domain/realtime` runtime behavior.

## Public Booking And Website Copy

The public booking flow must stop assuming demo-only copy. Booking labels,
confirmation copy, calendar integration text, email body placeholders, and lead
form messages need namespace coverage under `public_booking`, `calendar`, and
`emails`. Owner and guest email templates need recipient-specific locale
resolution because both parties may use different languages.

## Next Implementation Order

1. Add backend locale tables, locale metadata, and persistent user preference.
2. Add CSV import/export for translation resources with superadmin-only writes.
3. Add frontend i18n runtime and connect it to the session/user-settings state.
4. Replace strings namespace by namespace, starting with shell, settings,
   administration modules, public booking, and email templates.
5. Apply document `lang`/`dir`, convert physical layout rules to logical
   properties, and add LTR/RTL browser smoke tests.
