# King Active Issues

Purpose:
- `SPRINT.md` contains only the active top-priority sprint.
- Completed sprint detail is intentionally removed from this file.
- Parked or deferred work lives in `BACKLOG.md`.
- Completion evidence belongs in commit history, contracts, and readiness docs.

Rules:
- Work one checkbox at a time unless the user explicitly expands scope.
- A checkbox is only closed after implementation and proof.
- Do not weaken King v1 contracts to make the sprint smaller.
- Do not grow `CallWorkspaceView.vue` or other oversized files; extract focused
  helpers/components when adding behavior.
- Use the local branch `prod-kingrt-do-not-push-to-github` for integration.
- Do not push. Deploy only when the active sprint proof is green.
- Do not run DNS or certbot automation unless a new domain is explicitly added.

## Sprint: Backlog Cleanup, Admin UX, And Governance Refactor 06

Branch:
- `prod-kingrt-do-not-push-to-github`

Status:
- Active as of 2026-05-10 after Sprint 05 deploy.
- Local-only integration branch. Do not push to GitHub.
- Worker branches/worktrees must use short-lived non-`codex` names and merge
  back into the local no-push branch after proof.
- Background, Gossip, SFU, MediaSecurity, BTGF, and their tests remain parked
  unless the user explicitly reopens them.

User-facing problem:
- The previous IAM sprint closed, but several dirty historical worktrees still
  need classification before cleanup.
- Governance/Admin UX backlog items remain broad and inconsistent: action bars,
  authorization semantics, relation flows, sidebars, token styling, and page
  headings need focused implementation/proof slices.

Sprint goal:
- Classify the remaining dirty worktree/branch cleanup candidates without
  losing user work.
- Move the next Governance/Admin UX backlog batch into an active 20-checkbox
  sprint and implement it in focused, testable slices.
- Keep video media internals and parked background work untouched.
- Close exactly 20 tickets, then build/deploy locally without push/DNS/certbot.

Execution boundary:
- No pushes.
- No DNS or certbot automation.
- No Background/Gossip/SFU/MediaSecurity/BTGF implementation or tests.
- Do not discard dirty worktrees unless their changes are proven merged or the
  user explicitly approves removal.
- Use worker branches/worktrees that do not start with `codex/`.

Proof anchors:
- `BACKLOG.md`
- `READYNESS_TRACKER.md`
- `documentation/`
- `demo/video-chat/frontend-vue/package.json`
- `demo/video-chat/frontend-vue/tests/contract/`
- `demo/video-chat/frontend-vue/tests/e2e/`
- Governance/Admin frontend modules under `demo/video-chat/frontend-vue/src/`
- Governance/Admin backend modules under `demo/video-chat/backend-king-php/`

Sprint Checkboxen:
- [x] UX6-01 Classify dirty worktree `agent/call-app-remove-session`; prove
  whether its uncommitted remove-session UI diff is already integrated,
  extract only missing non-media value, or preserve it with evidence.
- [ ] UX6-02 Classify dirty worktree `agent/planning-image-call-app`; compare
  its uncommitted `image-planning` package/test diff with the integrated
  package and preserve any still-relevant non-media value.
- [ ] UX6-03 Classify dirty worktree `agent/l15-org-admin-join-proof`; extract
  only current org-admin realtime role proof that is needed for Admin/Governance
  correctness, otherwise preserve it as parked evidence.
- [ ] UX6-04 Classify dirty worktree `codex/iam-call-access-e2e-foundation`;
  keep it outside active implementation unless it contains deploy-smoke proof
  needed by the current branch.
- [ ] UX6-05 Reconcile `codex/iam-duplicate-cleanup-reaudit-20260509`; clean
  it up only if the conflict state is proven redundant with already integrated
  IAM evidence.
- [ ] UX6-06 Implement descriptor-driven page action bars for the first
  Governance/Admin proof surfaces: create/edit/delete/import/export/save actions
  must be described, permission-filtered, and locally named per entity.
- [ ] UX6-07 Wire backend route authorization proof for tenant/resource grant
  evaluation on Governance/Admin resource actions beyond simple role/path
  checks.
- [ ] UX6-08 Normalize Governance entity semantics for Groups, Organizations,
  Roles, Grants, Policies, Export/Import, Audit Log, Compliance, Modules, and
  Permissions with entity-specific fields, validation, and action names.
- [ ] UX6-09 Implement the first recursive relation flow proof for User ->
  Group -> Module -> Permission and similar entity references without stacked
  modals.
- [ ] UX6-10 Replace row-by-row relation label fetching on the proof surfaces
  with normalized rows, included summaries, batch summary endpoints, and
  frontend entity caches.
- [ ] UX6-11 Harden Navigation/i18n descriptors so localized keys and structured
  localized fields are the source of truth instead of concatenated English
  descriptions.
- [ ] UX6-12 Add onboarding tour registry/persistence proof for per-area `?`
  entry points, completed-tour badges, and profile display.
- [ ] UX6-13 Add profile expansion proof through the intended settings/profile
  architecture for about, social, and contact fields.
- [ ] UX6-14 Keep CRUD search/action bars right-aligned with exactly 20px
  spacing and the standard submit icon across the first migrated Admin
  surfaces.
- [ ] UX6-15 Remove redundant cancel/close buttons where a right-sidebar or
  modal already has the standard close affordance.
- [ ] UX6-16 Normalize right-sidebar forms: no border radius, no top/bottom
  border, non-resizable body, and sticky bottom-right submit.
- [ ] UX6-17 Normalize inputs/selects to the 12 King styleguide color tokens and
  remove hard-coded non-token colors on the migrated surfaces.
- [ ] UX6-18 Replace wrong generic create actions on readonly/system catalog
  pages with correct entity-specific actions or no action.
- [ ] UX6-19 Prove page headings use the standard `h1` size and avoid
  unreachable/overlapping content on the migrated Admin/Governance routes.
- [ ] UX6-20 Run final proof, build, deploy without push/DNS/certbot, collect
  post-deploy diagnostics, and update readiness evidence.
