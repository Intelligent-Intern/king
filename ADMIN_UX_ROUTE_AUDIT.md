# Administration/Governance UX And Route Audit

Date: 2026-05-05
Branch: `feature/videochat-localization-sprint`

Scope:
- Focus on non-call Administration, Governance, Settings/Profile, Theme
  Management, Localization, Marketplace, User Management, shared admin shell,
  route descriptors, CRUD descriptors, relation stack, and text semantics.
- Video-call runtime, SFU, WASM, realtime media, and call workspace internals
  stay outside this audit except where visible navigation/text leaks into the
  admin shell.

Interpretation:
- Each finding is a visible or contract-level issue that should become a
  fixable backlog item.
- Expected behavior describes the product contract, not the current shortcut.

## 1. Design System Issues

1. DS-001: CRUD headers still vary by page density; expected one shared header rhythm with identical title/action spacing.
2. DS-002: Search/filter controls were page-owned and still risk drifting after the latest alignment fix; expected a reusable toolbar component.
3. DS-003: Theme cards show miniature app frames with cramped content at some widths; expected previews that scale without clipped labels.
4. DS-004: Tags use status colors without a consistent semantic palette contract per state; expected shared status token mapping.
5. DS-005: Empty table states use different visual treatments across Governance, Users, Marketplace, and Localization; expected one shared empty state primitive.
6. DS-006: Row action icon sizes vary between table rows, preview cards, and toolbar buttons; expected one action icon button scale.
7. DS-007: Sidebar active and submenu active colors compete visually with primary buttons; expected distinct navigation and command states.
8. DS-008: Pagination sits visually detached from table content on sparse pages; expected footer tied to table frame, not floating in empty space.
9. DS-009: Relation picker panels and CRUD side panels do not yet share the same visual hierarchy; expected identical panel shell treatment.
10. DS-010: Theme editor mixes live preview, editing surface, and notification text without a consistent grid baseline; expected fixed editor/preview grid with stable status area.

## 2. Layout And Responsiveness Issues

1. LR-001: Desktop admin content can leave huge empty table surfaces with pagination far below the data; expected compact empty/table layout with scroll only when needed.
2. LR-002: Right-aligned toolbar controls wrap abruptly on medium widths; expected controlled breakpoints that preserve search next to submit.
3. LR-003: Search fields use fixed desktop widths, but long localized placeholders can still overflow in compact languages; expected container-aware sizing.
4. LR-004: Relation stack nested views can become too tall when field forms and selection tables are both open; expected sticky footer and body-owned scroll.
5. LR-005: Theme editor live preview can dominate the main area while the left editor panel carries the real task; expected adjustable or responsive split.
6. LR-006: Localization admin preview tables can stack several table frames vertically without clear scroll ownership; expected one scroll owner per active mode.
7. LR-007: Admin page frame assumes action and toolbar bands separately, but pages with many actions can wrap into confusing rows; expected action overflow menu.
8. LR-008: Mobile admin toolbar puts submit on a separate line without preserving visual pairing to search; expected mobile search+submit group.
9. LR-009: Sidebar collapse restore button appears only in headers that use the shared header; expected universal behavior for all non-call admin routes.
10. LR-010: Full-height behavior was fixed piecemeal for several pages; expected a single viewport contract test for every admin module route.

## 3. Text, Copy, And I18n Issues

1. TX-001: Descriptor fallbacks still contain visible raw labels like `Overview`, `Marketplace`, and `Theme Editor`; expected keys as visible source of truth.
2. TX-002: Governance descriptor route titles mix German labels and English entity names (`Audit Entry`, `Export / Import Job`); expected localized keys only.
3. TX-003: Generic empty copy says "Create your first ..." even when creation is disabled or nonsensical; expected entity-specific empty actions.
4. TX-004: "Create related" in relation picker is vague; expected "Create group", "Create organization", or "Create role" based on target.
5. TX-005: "Search related records" hides the target entity; expected "Search permissions", "Search groups", etc.
6. TX-006: Theme chat placeholder implies broad design intelligence but current logic is deterministic keyword mapping; expected honest, bounded label.
7. TX-007: "Theme request applied to the preview" can imply saved state; expected "Preview updated" until Save is clicked.
8. TX-008: Marketplace form placeholder uses "Whiteboard" as a category example in the app name field; expected an app-name example.
9. TX-009: Localization source text hardcodes `intelligent-intern.com`; expected source metadata from the imported language inventory.
10. TX-010: Status strings like `running`, `ended`, and `scheduled` still appear from helper code in user overview; expected i18n keys.

## 4. Navigation And Information Architecture Issues

1. NAV-001: Administration and Governance are both broad buckets, but ownership boundaries are not explained by route structure; expected clear module grouping.
2. NAV-002: User Management exists under Governance while its descriptor lives in the Users module; expected ownership consistency or explicit bridge metadata.
3. NAV-003: Settings panels are separate from Administration routes, but theme selection and theme editing share concepts; expected clear personal/admin split.
4. NAV-004: Marketplace is under Administration but does not expose module-install semantics yet; expected either real marketplace workflow or renamed app catalog.
5. NAV-005: Localization appears both as admin route and settings panel concept; expected separate "Language" vs "Translation Management" naming.
6. NAV-006: Data portability sits inside Governance although it is a data lifecycle workflow; expected clearer Administration/Governance placement.
7. NAV-007: Compliance route is generic and currently behaves like CRUD; expected compliance dashboard semantics before create/update/delete.
8. NAV-008: Audit Log route shares CRUD chrome; expected inspect/filter/export chrome without CRUD mental model.
9. NAV-009: Modules and Permissions are readonly catalogs but still sit beside mutable governance entities without visual distinction; expected catalog grouping.
10. NAV-010: Overview route is owned by Users module but appears as platform admin overview; expected administration/overview ownership.

## 5. Route And Action Logic Issues

1. RA-001: Route descriptors carry actions, but page implementations still manually render many actions; expected descriptor-driven action bars everywhere.
2. RA-002: App Configuration descriptor defines a save action, but the page owns the visible save button locally; expected action metadata consumption.
3. RA-003: Marketplace descriptor defines create action, but page toolbar does not yet derive the create button from route action metadata.
4. RA-004: Theme Editor descriptor defines create action, but header calls a child ref directly; expected route-action dispatcher contract.
5. RA-005: Audit Log exposes export action metadata but visible export workflow is not consistently surfaced; expected action bar parity.
6. RA-006: Data Portability has export/import route actions but still shares generic CRUD table logic; expected workflow-specific route controller.
7. RA-007: Readonly catalog routes carry inspect actions but no visible inspect mode distinction; expected inspect affordance or no action at all.
8. RA-008: Route `roles: ['admin']` remains a broad gate alongside granular permissions; expected compatibility alias layer with explicit deprecation path.
9. RA-009: Tour actions are route actions but rendered through header tour metadata instead of the same action renderer; expected one action model.
10. RA-010: Some non-call routes still bypass `AdminPageFrame`; expected documented exception or migration to shared frame.

## 6. CRUD Field And Entity Semantics Issues

1. CRUD-001: Governance base fields assume `name`, `key`, `description`, `status` for many entities; expected entity-specific fields.
2. CRUD-002: Compliance rules use generic CRUD fields without rule condition/evidence/remediation fields; expected compliance-specific schema.
3. CRUD-003: Policies model relations but no policy condition, effect, priority, or evaluation mode; expected real policy semantics.
4. CRUD-004: Grants require subject type plus subject relation separately, allowing mismatched drafts; expected relation target filtered by subject type.
5. CRUD-005: Data portability job status is readonly but still displayed in create field list; expected system-managed status outside user form.
6. CRUD-006: Role descriptors allow modules and permissions but do not expose inheritance/conflict resolution; expected explicit role expansion semantics.
7. CRUD-007: Organization parent relation can select organizations without visible cycle prevention; expected recursive cycle validation and UI warning.
8. CRUD-008: User descriptor has status but not account type, locale, onboarding, or identity provider fields; expected user admin schema completeness.
9. CRUD-009: Module and permission catalogs use generic table columns that may not expose module version, owner, route count, or grant target clearly.
10. CRUD-010: Field labels do not always distinguish internal key from display name; expected "Internal key" vs "Name" semantics.

## 7. Relation Workflow Issues

1. REL-001: `+1` relation control is conceptually correct but not self-explanatory; expected icon plus entity label and selected count.
2. REL-002: Relation stack create-in-place can feel like a separate modal flow inside another panel; expected breadcrumb and current parent context.
3. REL-003: Nested group -> permission/module assignment from User Management can create hidden side effects; expected clear "will also update group grants" preview.
4. REL-004: Mass selection lacks bulk "select page", "clear page", and "selected only" controls; expected high-volume picker ergonomics.
5. REL-005: Back/apply/cancel wording can be ambiguous in nested relation flows; expected "Apply to group", "Back to user", etc.
6. REL-006: Relation search does not expose which fields it searches; expected target-specific placeholder and no invisible search fields.
7. REL-007: Relation picker pagination resets were added, but selected items on other pages are not visually summarized enough; expected persistent selected tray.
8. REL-008: Creating a related entity and selecting it in one flow risks stale summaries if backend enrichment lags; expected immediate summary hydration proof.
9. REL-009: Relation stack filters unsupported hops for User Management but does not explain hidden relations; expected descriptor-driven allowed-hop labels.
10. REL-010: Single-select and multi-select relation controls share too much UI; expected mode-specific affordances.

## 8. Permission And Security Issues

1. SEC-001: Frontend still contains broad admin role checks in descriptors; expected granular permission checks as source of truth.
2. SEC-002: UI permission hiding can diverge from backend evaluator decisions; expected denied-state handling for every mutation response.
3. SEC-003: Theme editing uses `sessionState.canEditThemes` alongside module permissions; expected compatibility bridge to action grants.
4. SEC-004: Primary-superadmin-only branding management is hardcoded to user id 1; expected explicit platform capability grant plus legacy alias.
5. SEC-005: Export/import result downloads need explicit per-job ownership checks; expected documented resource-id permission evaluation.
6. SEC-006: Audit log export action may leak cross-tenant metadata if backend filters are incomplete; expected tenant-scoped export contract.
7. SEC-007: Public IDs/UUIDs are used for many rows, but route logic must prove internal ids never reappear in UI payloads.
8. SEC-008: User create can assign groups/roles with broad side effects; expected backend proof that actor can grant each selected permission/module.
9. SEC-009: Localization CSV import is superadmin-only, but preview endpoint still processes arbitrary files; expected size/rate limits and audit.
10. SEC-010: Settings/admin email server fields need secret handling proof; expected write-only password payloads and redacted logs.

## 9. Tenancy And Data Boundary Issues

1. TEN-001: Default workspace remnants appear in prior screenshots/navigation context; expected no visible default tenant noise unless switching tenants.
2. TEN-002: Organization/suborganization semantics are not visible in navigation or forms; expected hierarchy-aware organization UI.
3. TEN-003: Group rights can be time-limited, but UI does not make validity windows first-class for all grant paths.
4. TEN-004: Tenant role vs governance role naming can confuse users; expected distinct labels and help text.
5. TEN-005: User-scoped and organization-scoped export/import share one Data Portability page; expected scope-specific task framing.
6. TEN-006: Relation pickers need active tenant context visible when selecting users/groups; expected tenant breadcrumb or tenant badge.
7. TEN-007: Cross-tenant wrong-id failures are backend-tested, but UI likely shows generic errors; expected localized boundary error.
8. TEN-008: Tenant override localization resources exist conceptually but are not surfaced clearly; expected platform vs tenant source indicator.
9. TEN-009: Theme resources are workspace-wide but card/editor UI does not show tenant/workspace scope; expected scope label.
10. TEN-010: Marketplace apps may be platform or tenant-specific, but UI does not expose scope; expected installation/scope model.

## 10. Data Integrity And Validation Issues

1. VAL-001: Text keys likely accept values that do not match slug/key constraints; expected normalized key validation with preview.
2. VAL-002: Date/time grant fields use native datetime inputs without timezone explanation; expected timezone-aware validity display.
3. VAL-003: URL fields in Marketplace and profile require host validation but not all surfaces show exact rules before submit.
4. VAL-004: Import bundle validation errors need row/object paths; expected actionable error locations.
5. VAL-005: Email template placeholders are shown but not validated against template type before save.
6. VAL-006: Theme color hex text accepts invalid drafts only by fallback normalization; expected inline invalid-state feedback.
7. VAL-007: Logo upload accepts image types but lacks dimension/size guidance; expected limits and preview crop behavior.
8. VAL-008: User email add-confirm flow may allow duplicate pending email rows until backend rejects; expected client-side duplicate guard.
9. VAL-009: Relation payloads can contain nested child relationships; expected schema validation before submit, not only backend failure.
10. VAL-010: Marketplace category is selected, but app capabilities/integration requirements are free text; expected structured capability fields.

## 11. Accessibility Issues

1. A11Y-001: Icon-only row actions rely on title/aria labels but lack visible focus descriptions in dense tables.
2. A11Y-002: Empty states with icon imagery may not announce the correct route/entity context; expected aria-live or descriptive text.
3. A11Y-003: Relation stack nested navigation needs explicit dialog/panel labels for each stack level.
4. A11Y-004: Theme preview mini-app is interactive but nested inside an editor; expected clear preview-only semantics or isolated landmark label.
5. A11Y-005: Sidebar collapse/expand buttons use icons that may not convey target group; expected aria expanded state and group name.
6. A11Y-006: Toolbar submit icon can be reached after filters but not necessarily announced as "apply filters" on every route.
7. A11Y-007: Native color inputs in theme editor need text equivalents and validation announcements.
8. A11Y-008: File upload labels in Localization and Theme images need selected filename announcements.
9. A11Y-009: Pagination icon buttons should announce current page and disabled reason.
10. A11Y-010: Tour overlays need focus trap and restore focus behavior after completion/cancel.

## 12. Keyboard And Focus Issues

1. KEY-001: Search Enter behavior differs between pages; expected one toolbar search submit contract.
2. KEY-002: Relation stack back/apply actions may not preserve focus target on parent return; expected focus restore to relation control.
3. KEY-003: Side panels and relation stack can contain nested focusable regions without a predictable tab order.
4. KEY-004: Theme editor tab buttons should support arrow-key tab navigation; expected roving tabindex or standard tablist behavior.
5. KEY-005: Mini-app preview navigation is clickable but should not steal workflow focus unexpectedly.
6. KEY-006: Table row actions require many tab stops; expected action menu or row-level shortcuts for dense tables.
7. KEY-007: Upload controls are styled as buttons but need keyboard-visible file selection state.
8. KEY-008: Modal/side panel maximize buttons need consistent keyboard order next to close.
9. KEY-009: Create buttons in empty state and header duplicate command focus targets; expected one primary focus path.
10. KEY-010: Form submit buttons in side panels can be distant from invalid fields; expected focus first invalid field on submit.

## 13. Error, Loading, And Empty-State Issues

1. ERR-001: Loading text often appears inline next to toolbar controls, shifting layout; expected reserved status region.
2. ERR-002: Governance empty copy does not distinguish "no data" from "filter returns no matches"; expected separate messages.
3. ERR-003: Users page loading empty state replaces the table; expected skeleton or stable table frame.
4. ERR-004: Backend 404/500 route failures in Governance pages can surface as generic fetch errors; expected route-specific recovery text.
5. ERR-005: Localization resource 404s were visible in console during prior work; expected graceful fallback and no noisy console for expected absence.
6. ERR-006: Admin user API 500s were visible in console during route changes; expected error banner with request id.
7. ERR-007: Relation picker backend failures can leave stale selectable rows; expected stale-state marker and retry.
8. ERR-008: Theme save failures show text but editor state may appear saved in preview; expected unsaved/dirty indicator.
9. ERR-009: Data portability job failures need downloadable validation report; expected failure details linked from job row.
10. ERR-010: WebSocket admin sync local 404s appear as console errors on non-call admin pages; expected disabled or quiet fallback when endpoint absent.

## 14. Frontend Performance Issues

1. PERF-001: `CallWorkspaceView` chunk warning is known but still included in admin build output; expected code-splitting boundary so admin routes do not carry call weight.
2. PERF-002: Theme Management renders multiple interactive mini-app previews; expected lightweight static/card mode until focused.
3. PERF-003: Relation stack can hydrate multiple entity summaries during nested flows; expected deduped cache batch per route transition.
4. PERF-004: Large localization message bundle loads many namespaces globally; expected route namespace splitting.
5. PERF-005: Governance CRUD generic renderer recomputes row descriptions and relation labels often; expected memoized row view models.
6. PERF-006: Search filtering in local relation stacks scans all current rows on each keypress; expected debounced search for larger datasets.
7. PERF-007: Admin overview metrics can run concurrently with navigation even when user immediately leaves; expected abortable requests.
8. PERF-008: Theme prompt changes can patch many CSS variables at once; expected batched state update.
9. PERF-009: File preview/import tables can render large CSV previews without virtualization; expected capped preview and paged errors.
10. PERF-010: Table frames use full-height surfaces even for tiny datasets; expected adaptive density to reduce layout work.

## 15. Backend/API Contract Issues

1. API-001: Governance endpoints are broad by entity but not all routes expose OpenAPI-like schemas; expected machine-readable endpoint contract.
2. API-002: Admin Users API and Governance Users summary API overlap; expected clear read/mutate split documentation.
3. API-003: Permission evaluator is wired, but UI does not show denial reasons from evaluator; expected stable denial codes.
4. API-004: Summary endpoint supports several entities but not every future relation target; expected descriptor-to-summary coverage check.
5. API-005: Data portability import dry-run and commit lifecycle is not a full two-step UI contract yet.
6. API-006: Audit log endpoint is readonly but export route semantics need pagination/filter parity with visible table filters.
7. API-007: Localization import preview/commit endpoints need payload size and row count limits exposed to frontend.
8. API-008: Theme save endpoint mixes theme colors and logos in one administration payload; expected separate resource/action contracts.
9. API-009: Marketplace API treats rows like CRUD apps, but future marketplace modules need install/uninstall/version routes.
10. API-010: Settings API combines profile, credentials, localization, theme, and administration data; expected panel-scoped endpoints or schema sections.

## 16. State, Sync, And Cache Issues

1. STATE-001: Session state carries many unrelated settings fields; expected scoped stores/composables per settings panel.
2. STATE-002: Theme editor edits local preview and persisted appearance in one composable; expected explicit draft vs persisted state.
3. STATE-003: Relation stack maintains unsaved nested drafts but no global unsaved-change guard on route leave.
4. STATE-004: Governance summary cache can outlive tenant switch unless explicitly cleared; expected tenant-keyed cache lifecycle.
5. STATE-005: User Management group/role options are preloaded separately from relation stack; expected one normalized entity source.
6. STATE-006: Localization runtime missing-key counters mutate diagnostic state; expected isolated dev diagnostics store.
7. STATE-007: Admin sync socket failures on non-call pages should not pollute page-specific error state.
8. STATE-008: Pagination state is local per page but route query does not preserve it; expected shareable/searchable route state where useful.
9. STATE-009: Toolbar search drafts and applied queries differ by page; expected one draft/applied search composable.
10. STATE-010: Onboarding completion badges live in session snapshot but need invalidation after completion; expected update path without full reload.

## 17. Test And Observability Issues

1. TEST-001: Contracts prove structure but not enough visual regressions; expected screenshot diff for key admin routes.
2. TEST-002: Existing Playwright auth navigation smoke has known login/session fragility; expected stable seeded auth fixture.
3. TEST-003: Backend PHP contracts skip locally without `pdo_sqlite`; expected dev runtime parity or containerized contract runner.
4. TEST-004: Toolbar alignment now has contracts but not viewport matrix e2e for every admin route.
5. TEST-005: Theme Management visual behavior has Playwright screenshots but no automated assertion for card clipping.
6. TEST-006: Route action metadata tests do not prove visible buttons consume metadata on every page.
7. TEST-007: Permission denied paths need e2e coverage with non-admin/grant-limited users.
8. TEST-008: Relation stack tests cover happy paths but need destructive/cancel/error scenarios with backend failure.
9. TEST-009: CSV import tests need large-file, malformed UTF-8, and duplicate-key browser paths.
10. TEST-010: Console error budgets should be route-specific so expected absent sockets do not hide real UI errors.

## 18. Product Workflow And Onboarding Issues

1. PROD-001: User onboarding tour exists but does not yet teach the recursive `+1` relation workflow; expected task-specific tours.
2. PROD-002: Governance pages expose many advanced entities at once; expected progressive disclosure or setup checklist.
3. PROD-003: Creating a user with groups/roles/modules/permissions is powerful but lacks a review step; expected final permission impact summary.
4. PROD-004: Theme Management chat input suggests conversational design but lacks real assistant history; expected either local command builder or real design chat.
5. PROD-005: Theme previews do not show real customer content density; expected preview scenarios for admin table, modal, form, and public page.
6. PROD-006: Marketplace does not yet answer "what happens when I add this app"; expected install lifecycle copy and actions.
7. PROD-007: Data export/import lacks a clear owner journey for "my data" vs "organization data"; expected separate workflows.
8. PROD-008: Localization admin shows language rows but not translation coverage, missing keys, or publish state; expected translation operations dashboard.
9. PROD-009: App Configuration is too generic for risky settings; expected sections, validation previews, and audit summary.
10. PROD-010: Governance/Administration naming needs a glossary/tour because users will confuse roles, groups, permissions, grants, and policies.

