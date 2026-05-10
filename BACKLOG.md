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
3. [ ] Governance/User Management: finish breadcrumb/draft-create extraction and broader recursive-flow browser proof; relation navigator, picker table, row sectioning, shared Governance relationship payload normalizer, and User -> Group -> Module -> Permission contracts are now pinned.
4. [ ] Settings/Profile: extract settings registry and shared section frame; credentials and merged localization/date/time panels are now extracted and contract-pinned.
5. [ ] Call Join/Lobby: extract preview layout, media setup composable, audio test panel, background options, and mobile overlap proof without touching Pierre-owned MediaPipe internals.
6. [ ] Theme Editor: add persisted screenshot-card proof after save and keep future preview work under file-size guards; sidebar, palette, asset, preview-frame, and preview-navigation extraction is done.
7. [ ] Localization/Admin text: extract the remaining API/state composable and broader save proof; the two-locale editor matrix, locale pair selectors, entry matrix, CSV-free active path, and existing save route wiring are now contract-pinned.
8. [ ] Calendar/Booking: extract mobile day strip, slot list, details step, booking flow composable, and confirmation proof.
9. [ ] Refactor proof/cleanup: add file-size guard, options-object composable checks, Pierre-protected diff guard, Playwright smoke coverage, and per-checkbox proof notes.

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

## Parked From Sprint Cleanup 2026-05-09

### Parked From Sprint Reset 2026-05-10

1. [ ] Video Call Stabilization and Internal Diagnostics are paused as active
   sprint work. Reopen only concrete production defects with current evidence.
2. [ ] Room-bound Gossip relay, SFU disablement, MediaSecurity sender-key
   recovery, strict 720p30, and related media tests are parked/manual unless the
   user explicitly reopens them.
3. [ ] Planning Image multi-image controls and Guest Join-Link Admission are
   historical hotfixes. Reopen only new concrete defects, not the old sprint
   checklist.
4. [ ] Call App diagnostics/telemetry improvements remain available as future
   work, but IAM call-access test stabilization is now the active priority.

### Future IAM Sprint Queue

Sprint 03, `IAM Abuse, Runtime Proof, And Cleanup Stabilization 03`, and
Sprint 04, `IAM Cleanup, Proof Consolidation, And Browser Stability 04`,
completed and moved to `READYNESS_TRACKER.md` on 2026-05-10.

The next active IAM cleanup/proof batch is now in `SPRINT.md` as
`IAM Backlog Sweep, Proof Extraction, And Branch Cleanup 05`.

### Completed/Parked Call App Integration Detail

1. [ ] Prior `Collaborative Office Call Apps And Operator Feedback` sprint detail
   was removed from `SPRINT.md`. Keep follow-up defects here, not in the active
   sprint: collaborative text, presentation, spreadsheet, planning-image,
   operator-feedback, Call App removal, availability, and Call Diagnostics
   package work are represented by local commits on the active integration
   branch.
2. [ ] Prior `Call Workspace Sidebars, Call Apps, And Media Stability` detail was
   removed from `SPRINT.md`. Reopen only concrete defects that affect the active
   Video Call Stabilization sprint.
3. [ ] Prior Whiteboard Call App hardening and production integration detail was
   removed from `SPRINT.md`. Reopen only concrete runtime defects.
4. [ ] Prior IAM E2E sprint text was removed from `SPRINT.md`. Any remaining IAM
   proof work should be restored as focused backlog tickets, not as a large
   embedded checklist.

### Migrated Call App Contract Anchors

- Prior sprint: Whiteboard Call App Hardening And Production Integration.
- [x] WCA-01 Sprint/backlog hygiene and package contract.
- [x] WCA-02 Whiteboard runtime tool completeness first pass.
- [ ] WCA-08 Observability and acceptance form.
- [ ] WCA-09 Production deployment, subdomain, and Mothernode registration:
  Call App iframe host and Mothernode host.
- Package roots stay `demo/call-app/<app-key>/`; the first concrete package was
  `demo/call-app/whiteboard/`.
- Whiteboard can be discovered from the package metadata and Marketplace/Call
  App catalog path.
- Collaborative whiteboard state remains synchronized through King CRDT envelopes.
- revoked participants cannot submit CRDT ops.
- Call App grant hardening and revocation proof remain preserved by contracts,
  but are not the active sprint.
- `CallWorkspaceView.vue` must not grow with app-specific logic; Call App work
  belongs in focused host/bridge/sidebar modules.
- Migrated Call App capability anchors:
  - `call_apps.discover`
  - `call_apps.marketplace.order`
  - `call_apps.marketplace.install`
  - `call_apps.marketplace.disable`
  - `call_apps.call.attach`
  - `call_apps.call.remove`
  - `call_apps.call.view`
  - `call_apps.permissions.manage`
  - `call_apps.permissions.use`
  - `call_apps.permissions.revoke`
  - `call_apps.launch`
  - `call_apps.launch.validate`
  - `call_apps.crdt.read`
  - `call_apps.crdt.append`
  - `call_apps.crdt.replay`
  - `call_apps.presence.publish`
  - `call_apps.export.request`
  - `call_apps.export.download`
- Migrated Call App route anchors:
  - `GET /api/admin/marketplace/apps`
  - `POST /api/admin/marketplace/apps`
  - `GET /api/admin/marketplace/apps/{app_id}`
  - `PATCH /api/admin/marketplace/apps/{app_id}`
  - `DELETE /api/admin/marketplace/apps/{app_id}`
  - `GET /api/marketplace/call-apps`
  - `GET /api/marketplace/call-apps/{app_key}`
  - `POST /api/marketplace/call-apps/{app_key}/orders`
  - `POST /api/marketplace/call-apps/{app_key}/installations`
  - `PATCH /api/marketplace/call-apps/{app_key}/installations/{installation_id}`
  - `GET /api/calls/{call_id}/call-apps/available`
  - `GET /api/calls/{call_id}/call-app-sessions`
  - `POST /api/calls/{call_id}/call-app-sessions`
  - `PATCH /api/call-app-sessions/{session_id}`
  - `DELETE /api/call-app-sessions/{session_id}`
  - `GET /api/call-app-sessions/{session_id}/participant-grants`
  - `PATCH /api/call-app-sessions/{session_id}/participant-grants`
  - `POST /api/call-app-sessions/{session_id}/launch-token`
  - `POST /api/call-app-sessions/{session_id}/launch-token/validate`
  - `GET /api/call-app-sessions/{session_id}/crdt/bootstrap`
  - `GET /api/call-app-sessions/{session_id}/crdt/ops`
  - `POST /api/call-app-sessions/{session_id}/crdt/ops`
  - `POST /api/call-app-sessions/{session_id}/crdt/snapshots`
  - `POST /api/call-app-sessions/{session_id}/exports`
  - `GET /api/call-app-exports/{job_id}`
  - `GET /api/call-app-exports/{job_id}/download`
- Migrated MCP metadata method anchors:
  - `call_app.describe`
  - `call_app.capabilities`
  - `call_app.crdt_schema`
  - `call_app.launch_contract`
  - `call_app.health`
  - `call_app.export_formats`
  - `call_app.marketplace_listing`

### Manual/Parked Media Work

1. [ ] Background Replacement, BTGF-07 browser proof, and background tests remain
   manual/parked. Do not let active stabilization work mutate these files.
2. [ ] Gossip and SFU work remain manual/parked. Video-call stabilization may
   observe diagnostics around these systems, but must not change their internals
   in the active sprint.
3. [ ] Dirty BGF worktrees and the dirty `codex/bgf-06-background-diagnostics`
   checkout must be preserved or explicitly classified before cleanup; do not
   auto-discard them.

### Branch/Worktree Cleanup Holding Area

1. [ ] Classify dirty worktree `agent/call-app-remove-session`; likely redundant
   with the integrated Call App removal flow, but do not discard until compared.
2. [ ] Classify dirty worktree `agent/planning-image-call-app`; compare the
   uncommitted `image-planning` package/test diff with the integrated package.
3. [ ] Classify dirty worktree `agent/l15-org-admin-join-proof`; only integrate
   the realtime org-admin role diff if it is required for call stabilization.
4. [ ] Classify dirty worktree `codex/iam-call-access-e2e-foundation`; keep it
   outside the active sprint unless needed for deploy smoke stability.
5. [ ] Clean up `codex/iam-duplicate-cleanup-reaudit-20260509` only after its
   conflict state is proven redundant with `iam-e2e-integration`.

## Cleanup Notes

- Old batch items from the previous backlog were removed because they were either completed, replaced by the new active sprint, or too stale to keep as live backlog entries.
- If a removed item still matters, restore it with a current problem statement and evidence instead of reintroducing old checklist archaeology.
