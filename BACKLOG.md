# King Backlog

Purpose:
- This file is the parked and future backlog only.
- `SPRINT.md` is the only list of active top-priority work.
- `READYNESS_TRACKER.md` is the completion log.
- Historical detail stays in git history, not in this file.

Rules:
- Do not duplicate active sprint items here.
- Do not keep completed items here.
- Do not weaken the strongest correct King v1 contract to simplify cleanup.
- If an item becomes release-critical, move it into `SPRINT.md` and remove it from this file.

## Parked After 1.0.7 SFU Media Closure

1. [ ] Decide whether topology observability (`#Q-31`) is still needed for `1.0.7-beta` or can stay parked until the next beta.
2. [ ] Selective tile/background transport survived the online HD gate; evaluate a second-pass ROI optimization after release instead of changing the current proven heuristics now.
3. [ ] The binary media envelope is proven by the online HD gate; revisit long-term packet/header compaction after `1.0.7-beta`, not during the current release closure.
4. [ ] The native King PHP IIBIN SFU control/metadata boundary is proven; plan deeper runtime integration only after the shipped media path remains stable.
5. [ ] Do a second cleanup pass over superseded experiment artifacts after the `1.0.7` closure is merged.

## Parked From Sprint Cleanup 2026-05-07

### Governance UX, Recursive CRUD, Permissions, And Onboarding

1. [ ] Descriptor-driven page action bars: create/edit/delete/import/export/save actions must be described, permission-filtered, and locally named per entity instead of generic page-local buttons.
2. [ ] Backend route authorization: wire tenant/resource grant evaluation into Governance/Admin resource actions beyond role/path checks.
3. [ ] Governance entity semantics: Groups, Organizations, Roles, Grants, Policies, Export/Import, Audit Log, Compliance, Modules, and Permissions need entity-specific fields, validation, and correct action names.
4. [ ] Recursive relation flow: implement linked `+1` selection/creation flow for User -> Group -> Module -> Permission and similar entity references without stacked modals.
5. [ ] Relation data loading: replace row-by-row relation label fetches with normalized rows, included summaries, batch summary endpoints, and frontend entity caches.
6. [ ] Navigation/i18n hardening: descriptors should use localization keys as the source of truth and render structured localized fields instead of concatenated English descriptions.
7. [ ] Onboarding tours: add per-area `?` tour entry points, persisted completion badges, and profile display for completed tours.
8. [ ] Profile expansion: add about/social/contact fields only through the intended settings/profile architecture, not one-off UI state.

### Admin UX And Visual Standards

1. [ ] Keep CRUD search/action bars right-aligned with exactly 20px spacing and the standard submit icon.
2. [ ] Remove redundant cancel/close buttons where a right-sidebar or modal already has the standard close affordance.
3. [ ] Normalize right-sidebar forms: no border radius, no top/bottom border, non-resizable, sticky bottom-right submit.
4. [ ] Normalize inputs/selects to the 12 King styleguide color tokens and remove hard-coded non-token colors.
5. [ ] Replace wrong generic create actions on readonly/system catalog pages with correct entity-specific actions or no action.
6. [ ] Keep page headings as the standard `h1` size and avoid unreachable/overlapping content.
7. [ ] Theme management still needs persisted screenshot previews after save; iframe mini-app preview cards and the main-content editor are now contract-pinned.
8. [ ] Localization admin still needs two-language side-by-side editing and removal of CSV/source/bundle/import-history UI from the active path.
9. [ ] App Configuration still needs dropzone-based background image upload/crop/filter flow and metadata-free UI without search.

### Calendar And Booking

1. [ ] Move Calendar tabs out of Video Call Management into the top-level Calendar route.
2. [ ] Support up to five calendars with colors, tabs, settings gear, sharing, sync options, and access levels.
3. [ ] Replace mobile public booking calendar grid with day strip + slot list + details/confirmation step.
4. [ ] Keep desktop calendar behavior intact while mobile uses the two-step booking flow.
5. [ ] Include correct logo, call link, iCal, Google Calendar, and confirmation details.

### Clean Refactoring With Composables And Components

1. [ ] CRUD scaffold: roll the shared list/search scaffold beyond Marketplace and extract remaining entity action-bar semantics; list controller, search toolbar, shared table frame, and one non-call CRUD migration are now contract-pinned.
2. [ ] Right-sidebar/forms: roll the shared side-panel form state/submit footer beyond the Governance, Marketplace, and User editor proof surfaces, then close any remaining route-specific close/cancel variants after contract review.
3. [ ] Governance/User Management: extract relation navigator, breadcrumb, picker table, payload normalizer, and prove recursive User -> Group -> Module -> Permission flow.
4. [ ] Settings/Profile: extract settings registry, section frame, verified emails composable, password form, and merged localization/date-format form.
5. [ ] Administration/App Configuration: extract email settings, email texts CRUD, background image dropzone, crop queue, and object-store diagnostics proof.
6. [ ] Call Join/Lobby: extract preview layout, media setup composable, audio test panel, background options, and mobile overlap proof without touching Pierre-owned MediaPipe internals.
7. [ ] Theme Editor: add persisted screenshot-card proof after save and keep future preview work under file-size guards; sidebar, palette, asset, preview-frame, and preview-navigation extraction is done.
8. [ ] Localization/Admin text: extract two-locale matrix, locale pair selector, entry matrix, remove CSV UI from active path, and prove save through intended API.
9. [ ] Calendar/Booking: extract mobile day strip, slot list, details step, booking flow composable, and confirmation proof.
10. [ ] Refactor proof/cleanup: add file-size guard, options-object composable checks, Pierre-protected diff guard, Playwright smoke coverage, and per-checkbox proof notes.

### #Q-19 Video-Chat Admin Operations And Production Deploy Readiness

- Compatibility anchor for existing smoke/deployment contracts.
- Active release work lives in `SPRINT.md`.
- Completion evidence and rollout history live in `READYNESS_TRACKER.md`.
- If new production-readiness work becomes active again, move it into `SPRINT.md` instead of expanding this parked section.
- Keep Hetzner-specific discovery behind provider abstractions.
- Correct live call and participant counts.
- Ensure a fresh production deploy is repeatable.

## AI / SLM / Fine-Tuning Platform (`#149`)

1. [ ] Distributed model placement and inference execution.
2. [ ] Prompt, cache, and checkpoint persistence.
3. [ ] Fine-tuning and training-data workflows.
4. [ ] Advanced model extensions.

## Future Product Work / MarketView (`#150`)

1. [ ] MarketView product boundary and data contract.
2. [ ] Market feed, aggregation, and fanout.
3. [ ] MarketView frontend UX.
4. [ ] Paper trading flow.
5. [ ] MarketView packaging and operations.

## Cleanup Notes

- Old batch items from the previous backlog were removed because they were either completed, replaced by the new active sprint, or too stale to keep as live backlog entries.
- If a removed item still matters, restore it with a current problem statement and evidence instead of reintroducing old checklist archaeology.
