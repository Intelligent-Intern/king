# King Active Issues

## Sprint: Video Chat Localization And RTL Foundation

Branch:
- `feature/videochat-localization-sprint`

Base context:
- Builds on the deployed video-chat tenant/workspace branch.
- Target application: `demo/video-chat`.
- Reference source for first supported locales:
  - `/home/jochen/projects/academy/intelligent-intern/services/websites/intelligent-intern.com/src/i18n/*.json`
  - `/home/jochen/projects/academy/intelligent-intern/services/websites/intelligent-intern.com/src/i18n/index.ts`

Initial supported languages:
- Source inventory found these locale files:
  - `am`, `ar`, `bn`, `de`, `en`, `es`, `fa`, `fr`, `ha`, `hi`,
    `it`, `ja`, `jv`, `ko`, `my`, `pa`, `ps`, `pt`, `ru`, `sgd`,
    `so`, `th`, `tl`, `tr`, `uk`, `uz`, `vi`, `zh`
- `en` remains the canonical fallback.
- RTL locales found in the website source metadata:
  - `ar`, `fa`, `ps`, `sgd`

Important mismatch to fix:
- Video chat currently has only a local settings language stub with
  `en/de/fr/es` in `WorkspaceShell.vue`.
- That stub only writes `document.documentElement.lang` and local storage.
- It does not persist to the backend, does not load translation resources, does
  not translate the application, and does not apply RTL layout.
- The Intelligent Intern website source already ships 28 locale JSON files.
  Those languages are the first required locale set for this sprint.

Sprint goal:
- Make the whole video-chat app multilingual across authenticated workspace,
  public booking/join pages, admin screens, realtime call UI, settings,
  emails, validation errors, diagnostics visible to users, and deployment
  smoke paths.
- Add a durable localization contract with user language selection under
  Settings.
- Let the primary superadmin, `user_id = 1`, upload and manage translation CSV
  files.
- Make RTL languages switch the complete app to RTL, not just translated
  labels.
- Keep tenant isolation: localization resources may be platform-provided or
  tenant-overridden, but tenants must not read or mutate each other's language
  resources.

Non-goals for this sprint:
- Machine translation generation.
- Translation marketplace or translator workflow approvals.
- Per-call live speech translation.
- Physical database-per-locale sharding.
- Adding new business languages beyond the locale files found in the existing
  Intelligent Intern website source.

## Architecture Decisions

1. Locale model
- Store each user's selected locale persistently in user settings.
- Normalize locale tags to a strict allow-list seeded from the Intelligent
  Intern website locale files.
- Allow future regional variants such as `de-DE` or `en-US`, but resolve them
  through base fallbacks until regional overrides exist.
- Derive text direction from locale metadata. The initial RTL set from the
  website source is `ar`, `fa`, `ps`, and `sgd`; all other discovered locale
  files default to LTR unless metadata says otherwise.

2. Translation resource model
- Use stable message keys, not raw UI text, as the source of truth.
- English is the canonical fallback and must be complete.
- Non-English locales may fall back to English per key only when explicitly
  allowed and reported by coverage tooling.
- Store translation bundles in backend persistence with:
  - locale
  - namespace
  - key
  - value
  - status
  - source
  - updated_by_user_id
  - updated_at
- Use tenant scope for tenant override bundles only after platform defaults are
  resolved.

3. CSV contract
- Superadmin `user_id = 1` can upload CSV language files.
- CSV upload must support preview, validation, and import.
- CSV encoding is UTF-8.
- Required columns:
  - `locale`
  - `namespace`
  - `key`
  - `value`
- Optional columns:
  - `description`
  - `context`
  - `status`
  - `updated_at`
- Invalid CSV must fail closed with row-level errors and no partial import
  unless an explicit `dry_run=false` import has passed validation.
- CSV import must reject unsupported locales, duplicate key rows in the same
  file, empty keys, empty English canonical values, malformed UTF-8, and cells
  exceeding configured limits.

4. Runtime delivery
- Login/session responses expose the selected locale and direction.
- Frontend loads the active locale bundle before rendering protected views.
- Public pages resolve language from URL/query/cookie/browser `Accept-Language`
  and then fall back to platform default.
- API error envelopes expose stable error codes; frontend maps codes to
  localized strings instead of localizing server-generated free text.
- Email templates can be localized by locale and must preserve placeholders.

5. RTL/LTR contract
- Apply `lang` and `dir` at the app root and document root.
- Use CSS logical properties where possible: inline/block start/end, margin,
  padding, border, inset.
- Sidebar/navigation must flip for RTL without breaking desktop, tablet, and
  mobile layouts.
- Icons that imply direction, such as back/forward, chevrons, and timeline
  arrows, must mirror or swap under RTL.
- Canvas/video content must not be mirrored accidentally. Screen sharing and
  camera video remain visually correct; only UI chrome mirrors.

## Active Issues

1. [ ] `[localization-inventory]` Inventory every user-visible string and locale assumption.

   Scope:
   - Scan Vue templates, JS modules, PHP response messages, email templates,
     public pages, validation errors, empty states, button labels, tooltips,
     aria labels, placeholders, page titles, route labels, realtime call UI,
     calendar UI, tenant administration, user management, marketplace, and
     deployment-visible messages.
   - Identify hard-coded locale sorting such as `localeCompare(..., 'en')`.
   - Identify CSS and layout rules using physical left/right assumptions.
   - Compare with Intelligent Intern website locale files and language
     metadata from the correct website source path.

   Done when:
   - [ ] Inventory document lists all frontend, backend, public, and email
     translation surfaces.
   - [ ] Initial namespace plan exists for `common`, `auth`, `settings`,
     `calls`, `call_workspace`, `calendar`, `users`, `tenancy`, `marketplace`,
     `public_booking`, `emails`, `errors`, and `diagnostics`.
   - [ ] All discovered Intelligent Intern website locale files are confirmed
     as the first supported locale set.
   - [ ] RTL risk map lists every screen with physical left/right layout.

2. [ ] `[localization-schema-and-settings]` Add backend locale schema and persistent user preference.

   Scope:
   - Add migrations for supported locales and translation resources.
   - Add `users.locale` or equivalent user-settings storage.
   - Return locale and direction in login/session/refresh/settings responses.
   - Accept locale updates through `/api/user/settings`.
   - Preserve existing users by defaulting to `en`.
   - Keep tenant-aware resource lookup for future tenant overrides.

   Done when:
   - [ ] Fresh DB seeds all discovered website locales with direction
     metadata.
   - [ ] Existing DB migration backfills user locale without data loss.
   - [ ] User settings validates locale values and rejects unsupported ones.
   - [ ] Session payload includes `locale`, `direction`, and supported locale
     metadata.
   - [ ] Backend contracts cover valid locale update, invalid locale rejection,
     and session persistence.

3. [ ] `[translation-csv-admin]` Build superadmin CSV upload and import.

   Scope:
   - Add backend CSV parser and validator.
   - Add preview endpoint for superadmin `user_id = 1`.
   - Add commit endpoint for validated imports.
   - Add list/detail endpoints for translation bundles and import history.
   - Add Settings / Administration UI for upload, preview, errors, and commit.
   - Restrict upload/import to primary superadmin only.

   Done when:
   - [ ] Non-admins and admins with IDs other than `1` cannot upload language
     CSV files.
   - [ ] CSV preview returns row-level errors without mutating data.
   - [ ] Import is atomic and audited.
   - [ ] Duplicate keys and unsupported locales fail validation.
   - [ ] Imported translations are visible after session refresh without
     redeploy.

4. [ ] `[frontend-i18n-runtime]` Add frontend translation runtime.

   Scope:
   - Create a video-chat i18n module with locale state, direction state,
     fallback merge, async bundle loading, and `t(key, params)` interpolation.
   - Load translation resources before protected views render.
   - Integrate with session state and settings save.
   - Apply `document.documentElement.lang` and `document.documentElement.dir`.
   - Replace the local-only language storage stub in `WorkspaceShell.vue`.

   Done when:
   - [ ] User language selection persists and survives reload/login refresh.
   - [ ] Locale switch updates visible UI without requiring logout.
   - [ ] Missing keys are detectable in dev/test and fall back to English in
     production.
   - [ ] Interpolation supports named placeholders and escapes values.
   - [ ] Frontend unit contracts cover fallback, missing keys, params, and RTL
     direction.

5. [ ] `[settings-language-ui]` Replace the current Regional stub with full language settings.

   Scope:
   - Add a clear Language/Localization area under Settings.
   - Keep Regional Time for date/time format but split language from date/time.
   - Show supported languages from backend metadata, not a hard-coded stale
     list.
   - Apply RTL immediately after saving an RTL language.
   - Make settings modal itself work in RTL and on mobile/tablet.

   Done when:
   - [ ] Settings lets each user select any supported website-source locale.
   - [ ] The old `en/de/fr/es` local-storage-only list is gone.
   - [ ] Language save calls backend settings and updates session/i18n state.
   - [ ] Settings modal can be maximized/restored in both LTR and RTL.
   - [ ] Mobile/tablet settings remain full-screen and scroll correctly.

6. [ ] `[frontend-string-coverage]` Convert app UI to translation keys.

   Scope:
   - Convert navigation, page titles, settings, tenant administration, user
     management, marketplace, call management, user dashboard, public booking,
     join flow, appointment configuration, call workspace, modals, toasts,
     form labels, placeholders, aria labels, and tooltips.
   - Do not grow `CallWorkspaceView.vue`; add keys through extracted helpers or
     child modules where needed.
   - Preserve existing behavior while changing display text.

   Done when:
   - [ ] No hard-coded user-visible English text remains in the targeted Vue
     surfaces except explicit test fixtures and non-user debug identifiers.
   - [ ] All strings have stable namespaced keys.
   - [ ] English fallback covers 100 percent of used keys.
   - [ ] Non-English seed/import files cover the key set or explicitly mark
     allowed fallback gaps.
   - [ ] Contract test fails on missing English keys.

7. [ ] `[rtl-layout-foundation]` Make shell, settings, admin, calendar, and call UI RTL-capable.

   Scope:
   - Add direction-aware shell classes and CSS logical-property cleanup.
   - Flip sidebar/nav placement in RTL.
   - Swap or mirror directional icons.
   - Ensure calendar week/day views render correctly under RTL.
   - Ensure call workspace controls, participant lists, chat, reactions,
     screen share drag UI, and mini videos do not overlap under RTL.
   - Ensure video/canvas content is not mirrored unless explicitly desired for
     self-preview behavior.

   Done when:
   - [ ] RTL locales set app `dir="rtl"` and UI direction changes across all
     major screens.
   - [ ] Left/right physical CSS audit is resolved or documented with a reason.
   - [ ] Navigation, modal headers, pagination, wizard steps, and form rows are
     coherent in RTL.
   - [ ] Canvas/video rendering remains visually correct.
   - [ ] Playwright screenshots prove desktop, tablet, and mobile RTL layouts.

8. [ ] `[backend-errors-and-email-localization]` Localize server-driven user text and email templates.

   Scope:
   - Keep machine contracts as error codes.
   - Add localized frontend mapping for API errors.
   - Add locale-aware appointment booking and lead email templates.
   - Preserve placeholders for booking mails and lead notifications.
   - Resolve recipient locale for owner and guest independently where possible.

   Done when:
   - [ ] API error payloads still expose stable codes and do not depend on
     translated text for logic.
   - [ ] Public booking confirmation email can be sent in the selected/public
     locale.
   - [ ] Owner notification email can use owner locale.
   - [ ] CSV import rejects translations that remove required placeholders.
   - [ ] Email contract tests cover placeholder preservation and locale
     fallback.

9. [ ] `[public-pages-localization]` Localize public booking and join routes.

   Scope:
   - Public calendar booking route.
   - Booking confirmation page.
   - Join/access-link route.
   - Waiting-room/lobby admission messages.
   - Public error states for expired or invalid tokens.
   - Language resolution without requiring authentication.

   Done when:
   - [ ] Public pages choose language from explicit route/query/cookie/browser
     fallback in a deterministic order.
   - [ ] Public pages support RTL locales.
   - [ ] Public booking slots and date/time formatting use the active locale.
   - [ ] Invalid/expired access paths show localized safe error text.
   - [ ] Browser smoke covers public booking in `en`, `de`, `ar`, and one
     additional RTL locale from the website source.

10. [ ] `[locale-aware-formatting]` Centralize date, time, number, list, and sorting behavior.

    Scope:
    - Add frontend formatting helpers for date/time/number/list display.
    - Stop hard-coded English collation for user-facing sorts.
    - Keep persisted date/time formats compatible with existing user settings.
    - Ensure calendar slot labels and call times respect locale and time format.

    Done when:
    - [ ] User-facing `localeCompare(..., 'en')` calls are replaced with active
      locale-aware helpers.
    - [ ] Date/time formatting uses user locale plus existing time/date
      preferences.
    - [ ] Calendar and dashboard sort order remains deterministic.
    - [ ] Contract tests cover representative LTR and RTL formatting examples,
      including `en`, `de`, `ar`, `fa`, and `ps`.

11. [ ] `[localization-contract-tests]` Add backend and frontend contracts.

    Scope:
    - Backend tests for migrations, user locale settings, CSV validation,
      translation lookup, superadmin authorization, email placeholders.
    - Frontend tests for fallback merge, missing keys, interpolation, language
      switch, RTL state, settings UI, and route-level loading.
    - Static key coverage test against source usage.

    Done when:
    - [ ] Tests fail if a used key is missing from English fallback.
    - [ ] Tests fail if CSV upload mutates data during preview.
    - [ ] Tests fail if non-`user_id=1` can import translations.
    - [ ] Tests fail if a configured RTL locale does not set RTL.
    - [ ] Tests fail if required email placeholders are missing.

12. [ ] `[localization-browser-smoke]` Add browser proof.

    Scope:
    - Desktop, tablet, mobile.
    - Representative LTR and RTL locales from the website source, with full
      matrix metadata coverage for all discovered locales.
    - Settings language switch.
    - Admin screens.
    - Public booking.
    - Join flow.
    - Live call workspace at least as a UI shell smoke without requiring real
      camera permission for every scenario.

    Done when:
    - [ ] Screenshots show no overlapping text in representative LTR and RTL
      locales.
    - [ ] RTL screenshots show sidebar/nav/modal direction flipped correctly.
    - [ ] Settings switch persists after reload.
    - [ ] Public booking and join pages render localized text.
    - [ ] Smoke can run in CI/local without external translation services.

13. [ ] `[deploy-and-rollout-proof]` Deploy localization safely.

    Scope:
    - Migration preflight.
    - Translation bundle seed check.
    - Production smoke with default `en`.
    - Production smoke after switching one test user to `de`, `ar`, and one
      additional RTL locale from the website source.
    - Rollback notes for schema additions.

    Done when:
    - [ ] Deploy applies localization migrations idempotently.
    - [ ] Existing users can log in with default English.
    - [ ] Superadmin can upload a CSV in production-like smoke.
    - [ ] Production health and deploy smoke pass.
    - [ ] Rollback notes identify which migration changes are additive.

## Subagent Execution Plan

Rules for all agents:
- Work on `feature/videochat-localization-sprint`.
- Do not push.
- Keep write ownership disjoint.
- Do not grow `CallWorkspaceView.vue`; extract into focused modules.
- Keep source files below 800 lines where possible; if touching an oversized
  file, move code downward into helpers.
- Use stable translation keys; do not use raw visible text as identifiers.
- Preserve existing behavior and tenant isolation.

Agent A: Backend schema, settings, and lookup
- Owns:
  - `demo/video-chat/backend-king-php/support/database_migrations.php`
  - new `support/localization*.php`
  - new `domain/localization/*.php`
  - locale seed inventory generated from
    `/home/jochen/projects/academy/intelligent-intern/services/websites/intelligent-intern.com/src/i18n`
  - user settings locale validation
  - session/auth locale payload
- Must not edit frontend view files.
- Delivers migrations, locale lookup API, user locale persistence, and backend
  contracts.

Agent B: CSV admin backend and API
- Owns:
  - new CSV parser/import domain files
  - new or extended HTTP module for localization administration
  - CSV import tests
- Depends on Agent A schema names only.
- Must enforce `user_id = 1` for CSV import.

Agent C: Frontend i18n runtime and settings language UI
- Owns:
  - new `demo/video-chat/frontend-vue/src/domain/localization/*`
  - session/i18n integration in auth state
  - settings language UI extraction
  - `WorkspaceShell.vue` only for wiring/removal of the local language stub
- Must not translate all application surfaces; it only provides runtime and
  settings plumbing.

Agent D: Frontend string key conversion
- Owns:
  - admin/user/calls/calendar/tenant/settings child components and templates
  - translation fallback seed files
- Must not edit backend CSV/schema.
- Must coordinate with Agent C key loader but can add keys.

Agent E: RTL layout and visual proof
- Owns:
  - CSS/layout files
  - direction-aware icon helpers
  - Playwright screenshots/smoke specs
- Must not change translation storage.
- Must prove RTL layout across desktop/tablet/mobile using at least `ar` and
  one of `fa`, `ps`, or `sgd`.

Agent F: Public pages, emails, and error mapping
- Owns:
  - public booking/join localization
  - localized email template handling
  - API error-code to UI-message mapping
  - placeholder validation tests
- Must coordinate with Agent A for locale lookup and with Agent D for keys.

Recommended order:
1. Agent A defines schema/contracts and locale/session payload.
2. Agent C builds frontend runtime against stub/fallback resources.
3. Agent B adds CSV admin upload/import.
4. Agent D converts strings by namespace.
5. Agent F localizes public pages/emails/errors.
6. Agent E runs RTL sweep and browser proof in parallel after C has direction
   plumbing.
7. Integration owner runs full lint/build/contracts/browser smoke, resolves
   merge conflicts, and deploys only after green checks.

## Acceptance Bar

- A user can choose language under Settings and the choice persists.
- Superadmin `user_id = 1` can upload translation CSV with preview and atomic
  import.
- The first supported locale set matches the Intelligent Intern website source
  inventory.
- Configured RTL locales switch the complete application to RTL.
- Public booking and join flows are localized.
- Emails and error text use locale-aware templates/messages.
- Missing English keys fail tests.
- Non-superadmin CSV upload is impossible.
- Existing deployed single-language behavior remains usable while localization
  rolls out.
