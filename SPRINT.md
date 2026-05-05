# King Active Issues

## Sprint: Video Chat Localization, RTL, And Modular Workspace Foundation

Branch:
- `feature/videochat-localization-sprint`

Base context:
- Builds on the deployed video-chat tenant/workspace branch.
- Target application: `demo/video-chat`.
- Reference source for first supported locales:
  - `/home/jochen/projects/academy/intelligent-intern/services/websites/intelligent-intern.com/src/i18n/*.json`
  - `/home/jochen/projects/academy/intelligent-intern/services/websites/intelligent-intern.com/src/i18n/index.ts`
- Reference source for the module architecture:
  - `/home/jochen/projects/academy/intelligent-intern/services/app/src/modules/*/manifest.ts`
  - `/home/jochen/projects/academy/intelligent-intern/services/api/src/modules/README.md`
  - `/home/jochen/projects/academy/intelligent-intern/services/api/src/modules/_template/manifest.json`
- Intelligent Intern modules are descriptor-based. King should follow that
  descriptor contract instead of treating imported route files as the module
  source of truth.

Current execution boundary:
- The module/refactor track in this sprint must not touch video-call runtime
  code yet.
- Excluded paths for the module/refactor track:
  - `demo/video-chat/frontend-vue/src/domain/calls/**`
  - `demo/video-chat/frontend-vue/src/domain/realtime/**`
  - `demo/video-chat/frontend-vue/src/lib/sfu/**`
  - `demo/video-chat/frontend-vue/src/lib/wasm/**`
  - `demo/video-chat/frontend-vue/src/lib/wavelet/**`
- Calendar booking, public call join, SFU, screen sharing, call management,
  call dashboard, and call workspace stay outside the first module extraction.
- Existing localization stories that mention call surfaces remain planned, but
  they must not be used as permission to move or refactor call code in this
  module wave.

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
- Add a modular workspace foundation so localization is not hard-wired into one
  shell. Non-call workspace areas should move toward dynamically loaded modules
  with reusable admin/settings/CRUD components.
- Create a `modules` directory for the video-chat frontend, following the
  Intelligent Intern descriptor pattern, and split existing non-call areas into
  modules where they can be registered, loaded, and permission-gated
  independently.
- Add the King pipeline-orchestrator OO surface first. Backend module routes
  will call normal HTTP routes, the route handler will publish a backend event
  to the orchestrator, and the orchestrator will start the module pipeline.
  We are not making every frontend action event-based.

Non-goals for this sprint:
- Machine translation generation.
- Translation marketplace or translator workflow approvals.
- Per-call live speech translation.
- Physical database-per-locale sharding.
- Adding new business languages beyond the locale files found in the existing
  Intelligent Intern website source.
- Moving or refactoring video-call runtime code during the first module wave.
- Turning the module system into a plugin marketplace in this sprint.

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

6. King pipeline orchestrator OO contract
- The procedural orchestrator API already carries the native runtime:
  `king_pipeline_orchestrator_run`, `dispatch`, `register_tool`,
  `register_handler`, `worker_run_next`, `resume_run`, `get_run`, and
  `cancel_run`.
- Add an OO facade over the same native kernel before descriptor-driven backend
  modules depend on it:
  - `King\PipelineOrchestrator::run()`
  - `King\PipelineOrchestrator::dispatch()`
  - `King\PipelineOrchestrator::registerTool()`
  - `King\PipelineOrchestrator::registerHandler()`
  - `King\PipelineOrchestrator::configureLogging()`
  - `King\PipelineOrchestrator::workerRunNext()`
  - `King\PipelineOrchestrator::resumeRun()`
  - `King\PipelineOrchestrator::getRun()`
  - `King\PipelineOrchestrator::cancelRun()`
- The OO surface is not a weaker userland wrapper. It must bind to the same
  native orchestrator functions and preserve durable tool definitions,
  process-local handler registration, file-worker, remote-peer, recovery, and
  run-snapshot semantics.

7. Descriptor-based backend module contract
- Backend modules are descriptor-based.
- A backend module descriptor defines:
  - `module_key`
  - `version`
  - `permissions`
  - `routes`
  - `events`
  - `pipelines`
  - `tools`
  - `handlers`
  - `i18n_namespaces`
  - optional `settings`
- Routes remain the external ingress contract. A route is called over HTTP,
  validates/authenticates normally, creates a normalized backend module event,
  and submits the event to `King\PipelineOrchestrator`.
- The backend event includes module key, route key, event name, actor/session
  context, tenant context, request payload, query/path params, correlation ID,
  and idempotency key when available.
- The orchestrator starts the descriptor-selected pipeline as a backend module
  run. Tool handlers are still registered per process and must fail closed when
  a worker or remote peer cannot satisfy handler readiness.
- We do not turn every frontend click/action into an event stream. The route is
  the boundary; the backend emits one event for the accepted route command.

8. Frontend module contract
- Add `demo/video-chat/frontend-vue/src/modules`.
- Each module lives under `src/modules/<module_key>` and exposes a descriptor.
- The frontend descriptor follows the Intelligent Intern shape:
  - `module_key`
  - `version`
  - `permissions`
  - `routes` or `pages`
  - `navigation`
  - `settings_panels` when needed
  - `i18n_namespaces`
  - async component loaders
- Module views/widgets must be loaded through descriptor-driven dynamic imports
  such as `defineAsyncComponent` or route-level dynamic `import()`.
- The core router/navigation/settings shell may read module descriptors, but it
  must not directly import module view implementations.
- Modules may import shared components and support helpers. Shared components
  must not import feature modules.
- Module keys are stable contracts and are used by permissions, localization
  namespaces, navigation, and future marketplace/module administration.

9. Non-call module split
- First module extraction targets only non-call workspace areas:
  - `administration`
  - `governance`
  - `users`
  - `marketplace`
  - `workspace_settings`
  - `localization`
  - `theme_editor`
- The existing `domain/calls` and `domain/realtime` trees remain in place until
  a separate call-specific module sprint is approved.
- Moving a feature into a module must preserve route paths, permissions,
  persisted settings, and public contracts.

10. Reusable component contract
- Build reusable UI components before copying screen-specific layouts:
  - admin page frame
  - CRUD table frame
  - searchable toolbar
  - pagination footer
  - maximizable modal shell
  - settings panel frame
  - file upload/import preview frame
- Feature modules consume these shared components instead of duplicating
  header/table/modal/pagination code.
- Shared components stay feature-neutral and below the 800-line target.

## Active Issues

1. [x] `[localization-inventory]` Inventory every user-visible string and locale assumption.

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
   - [x] Inventory document lists all frontend, backend, public, and email
     translation surfaces.
   - [x] Initial namespace plan exists for `common`, `auth`, `settings`,
     `calls`, `call_workspace`, `calendar`, `users`, `tenancy`, `marketplace`,
     `public_booking`, `emails`, `errors`, and `diagnostics`.
   - [x] All discovered Intelligent Intern website locale files are confirmed
     as the first supported locale set.
   - [x] RTL risk map lists every screen with physical left/right layout.

   Proof:
   - `documentation/video-chat-localization-inventory.md`
   - `node demo/video-chat/frontend-vue/tests/contract/localization-inventory-contract.mjs`

2. [x] `[localization-schema-and-settings]` Add backend locale schema and persistent user preference.

   Scope:
   - Add migrations for supported locales and translation resources.
   - Add `users.locale` or equivalent user-settings storage.
   - Return locale and direction in login/session/refresh/settings responses.
   - Accept locale updates through `/api/user/settings`.
   - Preserve existing users by defaulting to `en`.
   - Keep tenant-aware resource lookup for future tenant overrides.

   Done when:
   - [x] Fresh DB seeds all discovered website locales with direction
     metadata.
   - [x] Existing DB migration backfills user locale without data loss.
   - [x] User settings validates locale values and rejects unsupported ones.
   - [x] Session payload includes `locale`, `direction`, and supported locale
     metadata.
   - [x] Backend contracts cover valid locale update, invalid locale rejection,
     and session persistence.

   Proof:
   - `demo/video-chat/backend-king-php/support/localization.php`
   - `demo/video-chat/backend-king-php/tests/localization-schema-contract.sh`
   - `demo/video-chat/backend-king-php/tests/user-settings-contract.sh`
   - `demo/video-chat/backend-king-php/tests/user-settings-endpoint-contract.sh`
   - `node demo/video-chat/frontend-vue/tests/contract/localization-settings-contract.mjs`

3. [x] `[translation-csv-admin]` Build superadmin CSV upload and import.

   Scope:
   - Add backend CSV parser and validator.
   - Add preview endpoint for superadmin `user_id = 1`.
   - Add commit endpoint for validated imports.
   - Add list/detail endpoints for translation bundles and import history.
   - Add Settings / Administration UI for upload, preview, errors, and commit.
   - Restrict upload/import to primary superadmin only.

   Done when:
   - [x] Non-admins and admins with IDs other than `1` cannot upload language
     CSV files.
   - [x] CSV preview returns row-level errors without mutating data.
   - [x] Import is atomic and audited.
   - [x] Duplicate keys and unsupported locales fail validation.
   - [x] Imported translations are visible after session refresh without
     redeploy.

   Proof:
   - `demo/video-chat/backend-king-php/http/module_localization.php`
   - `demo/video-chat/backend-king-php/domain/localization/translation_imports.php`
   - `demo/video-chat/backend-king-php/tests/localization-import-contract.sh`
   - `node demo/video-chat/frontend-vue/tests/contract/localization-import-ui-contract.mjs`

4. [x] `[frontend-i18n-runtime]` Add frontend translation runtime.

   Scope:
   - Create a video-chat i18n module with locale state, direction state,
     fallback merge, async bundle loading, and `t(key, params)` interpolation.
   - Load translation resources before protected views render.
   - Integrate with session state and settings save.
   - Apply `document.documentElement.lang` and `document.documentElement.dir`.
   - Replace the local-only language storage stub in `WorkspaceShell.vue`.

   Done when:
   - [x] User language selection persists and survives reload/login refresh.
   - [x] Locale switch updates visible UI without requiring logout.
   - [x] Missing keys are detectable in dev/test and fall back to English in
     production.
   - [x] Interpolation supports named placeholders and escapes values.
   - [x] Frontend unit contracts cover fallback, missing keys, params, and RTL
     direction.

   Proof:
   - `demo/video-chat/backend-king-php/http/module_localization.php`
   - `demo/video-chat/backend-king-php/tests/localization-resources-contract.sh`
   - `demo/video-chat/frontend-vue/src/modules/localization/i18nRuntime.js`
   - `node demo/video-chat/frontend-vue/tests/contract/frontend-i18n-runtime-contract.mjs`
   - `node demo/video-chat/frontend-vue/tests/contract/localization-settings-contract.mjs`
   - `npm run build` in `demo/video-chat/frontend-vue`

5. [x] `[settings-language-ui]` Replace the current Regional stub with full language settings.

   Scope:
   - Add a clear Language/Localization area under Settings.
   - Keep Regional Time for date/time format but split language from date/time.
   - Show supported languages from backend metadata, not a hard-coded stale
     list.
   - Apply RTL immediately after saving an RTL language.
   - Make settings modal itself work in RTL and on mobile/tablet.

   Done when:
   - [x] Settings lets each user select any supported website-source locale.
   - [x] The old `en/de/fr/es` local-storage-only list is gone.
   - [x] Language save calls backend settings and updates session/i18n state.
   - [x] Settings modal can be maximized/restored in both LTR and RTL.
   - [x] Mobile/tablet settings remain full-screen and scroll correctly.

   Proof:
   - `demo/video-chat/frontend-vue/src/layouts/WorkspaceShell.vue`
   - `demo/video-chat/frontend-vue/src/modules/workspace_settings/descriptor.js`
   - `node demo/video-chat/frontend-vue/tests/contract/localization-settings-contract.mjs`
   - `node demo/video-chat/frontend-vue/tests/contract/module-registry-contract.mjs`
   - `node demo/video-chat/frontend-vue/tests/contract/module-navigation-builder-contract.mjs`
   - `npm run build` in `demo/video-chat/frontend-vue`

6. [ ] `[frontend-string-coverage]` Convert app UI to translation keys.

   Scope:
   - Convert navigation, page titles, settings, tenant administration, user
     management, marketplace, call management, user dashboard, public booking,
     join flow, appointment configuration, call workspace, modals, toasts,
     form labels, placeholders, aria labels, and tooltips.
   - Do not grow `CallWorkspaceView.vue`; add keys through extracted helpers or
     child modules where needed.
   - Preserve existing behavior while changing display text.

   Progress:
   - [x] Theme settings/editor strings are keyed and the editor state/actions
     moved into an options-based `useWorkspaceThemeSettings` composable.
   - [x] User editor modal labels, placeholders, status text, and avatar text
     are keyed; modal computed state moved into `useUserEditorModal`.
   - [x] User management page, table, validation, notices, and destructive
     confirmations use localization keys.
   - [x] Tenant context/admin labels and Governance CRUD status/not-available
     text use localization keys.
   - [x] Login form labels, aria labels, submit state, and local validation
     errors use localization keys.
   - [x] Theme preview labels, sample navigation, sample calls, statuses, and
     pagination text use localization keys.
   - [x] Governance route metadata now carries translation keys for page
     titles and singular/plural entity labels.

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

14. [x] `[king-pipeline-orchestrator-oo-surface]` Add the King OO orchestrator facade.

    Scope:
    - Add `King\PipelineOrchestrator` as a native OO facade over the existing
      pipeline-orchestrator kernel.
    - Mirror the current procedural surface with static methods:
      `run`, `dispatch`, `registerTool`, `registerHandler`,
      `configureLogging`, `workerRunNext`, `resumeRun`, `getRun`, and
      `cancelRun`.
    - Keep all current durability semantics: tool definitions are durable,
      executable handlers are process-local, file-worker and remote-peer
      execution must re-register handlers, and missing handler readiness fails
      closed.
    - Update stubs, docs, and PHPT proof.

    Done when:
    - [x] `class_exists(King\PipelineOrchestrator::class)` is true.
    - [x] The OO methods map to the same native functions as the procedural
      API.
    - [x] OO `registerTool` + `registerHandler` + `run` can execute a local
      userland-backed pipeline.
    - [x] OO `getRun` reads the persisted run snapshot.
    - [x] Procedural and OO signatures stay documented in `stubs/king.php`.

15. [x] `[backend-module-descriptor-runtime]` Add descriptor-based backend module routing to orchestrator.

    Scope:
    - Add backend module descriptors for non-call modules.
    - Define descriptor fields for routes, events, pipelines, tools, handlers,
      permissions, localization namespaces, and settings.
    - Keep HTTP routes as the external contract.
    - Route handler normalizes an accepted request into one backend module
      event and submits the descriptor-selected pipeline through
      `King\PipelineOrchestrator`.
    - Do not make every frontend action event-based; events are emitted at the
      backend route boundary.

    Done when:
    - [x] A descriptor can define one route-to-event-to-pipeline mapping.
    - [x] The backend can resolve a route descriptor without direct hard-coded
      module handler imports.
    - [x] Submitted orchestrator run snapshots include module key, route key,
      event name, actor/session context, and correlation ID.
    - [x] Missing descriptor, unauthorized module, unknown tool, and missing
      handler readiness fail closed with stable error codes.
    - [x] No call-related excluded paths are edited.

16. [x] `[module-inventory-and-boundaries]` Inventory non-call modules and shared component candidates.

    Scope:
    - Inventory artifact:
      `documentation/video-chat-module-boundaries.md`.
    - Map current non-call frontend areas to target modules:
      `administration`, `governance`, `users`, `marketplace`,
      `workspace_settings`, `localization`, and `theme_editor`.
    - Identify all current direct imports from router, navigation, shell, and
      settings modal into non-call feature views.
    - Identify repeated page patterns: header, create button, search, table,
      pagination, modal, maximized modal, upload/import, settings panel.
    - Confirm excluded call-related paths remain untouched for this module
      wave.

    Done when:
    - [x] Inventory lists every non-call feature area and its target module.
    - [x] Shared component candidate list exists with current source owners.
    - [x] Route and navigation compatibility map exists.
    - [x] Explicit no-touch list for call-related paths is included in the
      execution checklist.

17. [x] `[frontend-module-runtime]` Add module registry and dynamic loading.

    Scope:
    - Add `src/modules` and a small module registry.
    - Initial registry scaffold:
      `demo/video-chat/frontend-vue/src/modules`.
    - Define a frontend module descriptor contract based on the Intelligent
      Intern descriptor pattern.
    - Add Pinia as the frontend module/runtime store foundation.
    - Support dynamic page/widget loading through descriptor loaders.
    - Let router/navigation/settings consume registered module metadata.
    - Keep existing routes stable while changing where route components are
      loaded from.

    Done when:
    - [x] Module descriptors can register routes, navigation entries,
      permissions, settings panels, and i18n namespaces.
    - [x] Router can consume module routes without direct feature imports.
    - [x] Navigation can consume module navigation metadata.
    - [x] Settings can consume module settings-panel metadata.
    - [x] Build proves module dynamic imports are valid.

18. [x] `[shared-admin-components]` Extract reusable non-call UI building blocks.

    Scope:
    - Add shared components for admin page frame, searchable toolbar,
      table/pagination frame, CRUD modal, maximizable modal shell, and settings
      panel frame.
    - Keep components feature-neutral and usable by governance, marketplace,
      user management, localization administration, and theme administration.
    - Move repeated CSS into shared admin/workspace CSS only where it is not
      call-specific.

    Done when:
    - [x] Governance CRUD and localization administration use the shared page
      and table frames.
    - [x] Maximizable modal behavior is centralized.
    - [x] Header/search/table/pagination duplication is reduced.
    - [x] No call CSS or call components are modified.

19. [x] `[non-call-module-extraction]` Move existing non-call features into modules.

    Scope:
    - Move Administration shell pages into `src/modules/administration`.
    - Move Governance CRUD scaffolding into `src/modules/governance`.
    - Move User Management into `src/modules/users` after shared components
      exist.
    - Move Marketplace administration into `src/modules/marketplace`.
    - Move Theme Editor and Localization Administration into module-owned
      entries.
    - Move personal Settings panels into `src/modules/workspace_settings` or a
      clearly named shared workspace module.

    Done when:
    - [x] All listed non-call feature areas expose module descriptors.
    - [x] Existing route URLs still resolve.
    - [x] Existing role checks still apply.
    - [x] Navigation is generated from module metadata for these areas.
    - [x] No files under excluded call-related paths are edited.

20. [x] `[module-permissions-and-governance-link]` Wire modules to permissions and Governance.

    Scope:
    - Define module keys for the new non-call modules.
    - Add module metadata suitable for future group rights and time-limited
      rights.
    - Connect module availability to Governance module/permission concepts
      without implementing the full tenancy permission engine in this story.
    - Keep the contract ready for organization and group assignment.

    Done when:
    - [x] Each non-call module has a stable key and permission namespace.
    - [x] Module metadata can be listed in Governance.
    - [x] Disabled modules do not appear in navigation.
    - [x] Unauthorized users cannot resolve module routes.
    - [x] Time-limited rights have an explicit metadata slot, even if full
      enforcement is finished in the tenancy sprint.

21. [x] `[module-contract-tests-and-smoke]` Prove dynamic modules and non-call refactor.

    Scope:
    - Add tests or smoke checks for module descriptor registration.
    - Add browser smoke for Administration, Governance, User Management,
      Marketplace, Settings, Localization, and Theme Editor.
    - Add a guard that module-refactor changes do not touch excluded call
      paths.
    - Keep localization and RTL proof compatible with module loading.

    Done when:
    - [x] Build passes with dynamic module imports.
    - [x] Browser smoke proves non-call navigation and settings pages still
      render.
    - [x] Route compatibility smoke covers old and new module-backed paths.
    - [x] Diff/path guard reports no edits to call-related excluded paths for
      this module wave.

## Subagent Execution Plan

Rules for all agents:
- Work on `feature/videochat-localization-sprint`.
- Do not push.
- Keep write ownership disjoint.
- Do not grow `CallWorkspaceView.vue`. For this module/refactor wave, do not
  edit it at all.
- Later call-localization work must extract into focused modules instead of
  adding code to `CallWorkspaceView.vue`, but that is outside the first
  non-call module wave.
- Keep source files below 800 lines where possible; if touching an oversized
  file, move code downward into helpers.
- Use stable translation keys; do not use raw visible text as identifiers.
- Preserve existing behavior and tenant isolation.
- For the module/refactor track, do not edit video-call-related paths:
  `src/domain/calls/**`, `src/domain/realtime/**`, `src/lib/sfu/**`,
  `src/lib/wasm/**`, or `src/lib/wavelet/**`.

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
  - admin/user/tenant/settings child components and templates during the
    non-call module wave
  - translation fallback seed files
- Must not edit backend CSV/schema.
- Must coordinate with Agent C key loader but can add keys.
- Calls/calendar string conversion remains deferred until the call-related
  paths are explicitly unblocked.

Agent E: RTL layout and visual proof
- Owns:
  - non-call CSS/layout files during the first module wave
  - direction-aware icon helpers
  - Playwright screenshots/smoke specs
- Must not change translation storage.
- Must prove RTL layout across desktop/tablet/mobile using at least `ar` and
  one of `fa`, `ps`, or `sgd`.
- Must not edit call CSS/layout files in this wave.

Agent F: Public pages, emails, and error mapping
- Owns:
  - localized non-call email template handling first
  - API error-code to UI-message mapping
  - placeholder validation tests
- Must coordinate with Agent A for locale lookup and with Agent D for keys.
- Public booking/join localization is deferred until call-related paths are
  explicitly unblocked.

Agent G: Frontend module runtime and registry
- Owns:
  - new `demo/video-chat/frontend-vue/src/modules/registry*`
  - new module descriptor contract helpers
  - router/navigation/settings metadata integration
- Must not edit non-registry feature pages except for import wiring.
- Must not edit call-related excluded paths.

Agent H: Shared non-call admin components
- Owns:
  - new shared admin/workspace components under
    `demo/video-chat/frontend-vue/src/components`
  - shared non-call admin CSS
  - reusable modal/table/pagination/settings panel frames
- Must not edit call CSS or call components.

Agent I: Administration, localization, theme, and marketplace modules
- Owns:
  - `src/modules/administration`
  - `src/modules/localization`
  - `src/modules/theme_editor`
  - `src/modules/marketplace`
  - migration of existing non-call administration pages into module descriptors
- Must preserve existing route URLs and admin-only access.

Agent J: Governance and users modules
- Owns:
  - `src/modules/governance`
  - `src/modules/users`
  - migration of Governance CRUD and User Management into module descriptors
  - module metadata for Governance module/permission listing
- Must use shared admin components from Agent H where available.

Agent K: Workspace settings module extraction
- Owns:
  - `src/modules/workspace_settings`
  - personal settings panels: About Me, Credentials + E-Mail, Theme selection,
    Localization selection
  - settings-panel registration through module metadata
- May touch `WorkspaceShell.vue` only for wiring/removal of direct panel
  imports.
- Must not change call workspace behavior.

Agent L: King orchestrator OO and backend descriptor bridge
- Owns:
  - `extension/**` changes for `King\PipelineOrchestrator`
  - `stubs/king.php` orchestrator OO declarations
  - `documentation/11-pipeline-orchestrator-tools/README.md`
  - new PHPT proof for the OO facade
  - backend descriptor-to-orchestrator bridge scaffolding after the OO facade
    is proven
- Must not weaken procedural orchestrator semantics.
- Must not edit call-related excluded paths in the module bridge work.

Recommended order:
1. Agent L adds and proves `King\PipelineOrchestrator`.
2. Agent A defines schema/contracts and locale/session payload.
3. Agent C builds frontend runtime against stub/fallback resources.
4. Agent B adds CSV admin upload/import.
5. Agent L adds backend descriptor-to-orchestrator bridge scaffolding.
6. Agent G adds module registry and dynamic loading contract.
7. Agent H extracts reusable non-call admin/settings components.
8. Agents I, J, and K move non-call features into modules with disjoint write
   scopes.
9. Agent D converts strings by namespace, starting with module-backed non-call
   surfaces.
10. Agent F localizes public pages/emails/errors.
11. Agent E runs RTL sweep and browser proof in parallel after C has direction
   plumbing.
12. Integration owner runs full lint/build/contracts/browser smoke, resolves
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
- `King\PipelineOrchestrator` exists as an OO facade over the native
  orchestrator kernel.
- Backend module descriptors can map an accepted HTTP route to one orchestrator
  event and pipeline run.
- Non-call workspace areas are moving behind module descriptors in
  `src/modules`.
- Module-backed routes/navigation/settings load dynamically and keep existing
  route URLs stable.
- Reusable admin/settings/CRUD components replace duplicated non-call page
  scaffolding.
- The first module-refactor wave does not modify video-call-related excluded
  paths.
