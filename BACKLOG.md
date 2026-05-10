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

Moved into active Sprint 06 on 2026-05-10.

### Admin UX And Visual Standards

The action-bar, sidebar, token, create-action, and heading cleanup items moved
into active Sprint 06 on 2026-05-10.

1. [ ] Theme management still needs persisted screenshot previews after save; iframe mini-app preview cards and the main-content editor are now contract-pinned.
2. [ ] Localization admin still needs two-language side-by-side editing and removal of CSV/source/bundle/import-history UI from the active path.
3. [ ] App Configuration still needs dropzone-based background image upload/crop/filter flow and metadata-free UI without search.

### Calendar And Booking

1. [ ] Move Calendar tabs out of Video Call Management into the top-level Calendar route.
2. [ ] Support up to five calendars with colors, tabs, settings gear, sharing, sync options, and access levels.
3. [ ] Replace mobile public booking calendar grid with day strip + slot list + details/confirmation step.
4. [ ] Keep desktop calendar behavior intact while mobile uses the two-step booking flow.
5. [ ] Include correct logo, call link, iCal, Google Calendar, and confirmation details.

### Clean Refactoring With Composables And Components

The CRUD scaffold, right-sidebar/forms, and Governance/User Management
refactor items moved into active Sprint 06 on 2026-05-10.

1. [ ] Settings/Profile: extract settings registry and shared section frame; credentials and merged localization/date/time panels are now extracted and contract-pinned.
2. [ ] Call Join/Lobby: extract preview layout, media setup composable, audio test panel, background options, and mobile overlap proof without touching Pierre-owned MediaPipe internals.
3. [ ] Theme Editor: add persisted screenshot-card proof after save and keep future preview work under file-size guards; sidebar, palette, asset, preview-frame, and preview-navigation extraction is done.
4. [ ] Localization/Admin text: extract the remaining API/state composable and broader save proof; the two-locale editor matrix, locale pair selectors, entry matrix, CSV-free active path, and existing save route wiring are now contract-pinned.
5. [ ] Calendar/Booking: extract mobile day strip, slot list, details step, booking flow composable, and confirmation proof.
6. [ ] Refactor proof/cleanup: add file-size guard, options-object composable checks, Pierre-protected diff guard, Playwright smoke coverage, and per-checkbox proof notes.

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

Sprint 05, `IAM Backlog Sweep, Proof Extraction, And Branch Cleanup 05`,
completed and moved to readiness evidence on 2026-05-10.

Sprint 07, `IAM Remaining Proof And Branch Cleanup 07`, completed and moved to
readiness evidence on 2026-05-10.

Sprint 08, `IAM Session, Audit, Guestlist, And Terminal Proof 08`, completed
and moved to readiness evidence on 2026-05-10.

Sprint 09, `IAM Calendar, Edge States, And Call-App Boundary Proof 09`, moved
the next local IAM proof batch into active `SPRINT.md` on 2026-05-10. Do not
duplicate its active checkbox list here.

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

Moved into active Sprint 06 on 2026-05-10. Keep dirty worktrees intact until
their Sprint 06 classification tickets prove whether changes are merged,
preserved, or need explicit user approval before removal.

## Cleanup Notes

- Old batch items from the previous backlog were removed because they were either completed, replaced by the new active sprint, or too stale to keep as live backlog entries.
- If a removed item still matters, restore it with a current problem statement and evidence instead of reintroducing old checklist archaeology.
