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

## Sprint: Video Call Stabilization And Internal Diagnostics

Branch:
- `prod-kingrt-do-not-push-to-github`

Status:
- Active as of 2026-05-09.
- Local-only integration branch. Do not push to GitHub.
- Deploy only after local merge/proof. Do not run DNS or certbot automation
  unless a new domain is explicitly introduced.
- Background Replacement, BTGF-07, Gossip, SFU, and tests for those areas are
  parked for manual work only and out of scope for this sprint.

User-facing problem:
- Running calls can still hit reload/reconnect loops and Call App availability
  failures.
- The Call Diagnostics Call App exists but needs to be internal/admin-only,
  readable, pausable, filterable, and expanded from a live log tail into a
  useful call/instance telemetry console.
- Branch/worktree leftovers make it hard to see what is actually merged,
  parked, or safe to delete.

Sprint goal:
- Stabilize the live video-call path without changing Background, Gossip, or
  SFU internals.
- Make Call Diagnostics an internal admin diagnostics surface fed by sanitized
  parent/Backend telemetry.
- Keep the repo clean: merge or park local work intentionally and remove only
  clean branches/worktrees that are already contained in the local sprint
  branch.

Execution boundary:
- No public diagnostics API for this sprint.
- No new service domains, DNS records, or certbot runs.
- No pushes.
- Do not add app-specific logic to `CallWorkspaceView.vue`; use focused
  Call App bridge/backend modules and extracted helpers.
- Do not expose tokens, SDP, raw ICE candidates, cookies, secrets, or media
  frame data in diagnostics.

Contract anchors:
- `demo/call-app/call-diagnostics/`
- `demo/video-chat/frontend-vue/src/domain/realtime/callApps/`
- `demo/video-chat/backend-king-php/http/module_call_apps.php`
- `demo/video-chat/backend-king-php/domain/call_apps/`
- `demo/video-chat/backend-king-php/domain/realtime/client_diagnostics.php`
- `demo/video-chat/scripts/prod-debug.sh`

Tickets:
- [x] VCS-00 Branch/worktree inventory in this sprint
  - Current main checkout remains dirty on `codex/bgf-06-background-diagnostics`
    with Background/IAM edits and is not the active sprint branch.
  - `bgf-sprint-integration` was clean and locally ahead of origin; it was used
    as the source for the active local sprint branch.
  - Dirty side worktrees requiring classification: `agent/call-app-remove-session`,
    `agent/planning-image-call-app`, `agent/l15-org-admin-join-proof`,
    `codex/iam-call-access-e2e-foundation`, and
    `codex/iam-duplicate-cleanup-reaudit-20260509`.

- [x] VCS-01 Create local no-push sprint branch
  - Created `prod-kingrt-do-not-push-to-github` from clean
    `bgf-sprint-integration`.
  - Keep all implementation and deploy work on this local branch.

- [x] VCS-02 Move old active sprint detail to backlog
  - Removed oversized historical sprint detail from this file.
  - Parked Background/Gossip/SFU/manual work and prior Call App sprint follow-up
    references in `BACKLOG.md`.

- [x] VCS-03 Classify dirty worktrees
  - Keep or integrate only diffs needed for video-call stabilization.
  - Mark Background/Gossip/SFU/BTGF dirty work as parked/manual.
  - Treat resolved conflicts or redundant dirty worktrees as cleanup candidates
    only after the contained branch is proven merged elsewhere.
  - Current classification: `agent/call-app-remove-session`,
    `agent/planning-image-call-app`, `agent/l15-org-admin-join-proof`,
    `codex/iam-call-access-e2e-foundation`, and
    `codex/iam-duplicate-cleanup-reaudit-20260509` remain dirty/parked and were
    not discarded. `agent/cws-l8-grant-runtime-enforcement` was a clean,
    older/conflicting permission-enforcement variant and was discarded after the
    current branch's later permission-action flow was preserved.

- [x] VCS-04 Clean merged side branches/worktrees
  - Delete only clean side branches whose HEAD is an ancestor of
    `prod-kingrt-do-not-push-to-github`.
  - Run `git worktree prune` after stale worktrees are removed.
  - Do not delete dirty worktrees until their diffs are classified.
  - Cleaned 50 merged Call Workspace, Office, Operator Feedback, Planning Image,
    and loop-proof side branches/worktrees. The old `bgf-sprint-integration`
    branch was removed after the new local no-push branch replaced it.

- [x] VCS-05 Fix Call App availability 500 and preserve removal flow
  - `GET /api/calls/{call_id}/call-apps/available` must not throw 500 for
    running calls.
  - Existing owner/moderator removal of active Call Apps must continue to clear
    sessions and broadcast a fresh room snapshot.
  - Implemented catalog/runtime fallback response for non-transient availability
    failures and kept transient storage lock failures as retryable 503.
  - Proof: `npm run test:contract:call-apps`, `npm run build`, PHP lint. SQLite
    backend runtime contracts are present but skipped locally because
    `pdo_sqlite` is unavailable.

- [x] VCS-06 Make Call Diagnostics internal/admin-only
  - Hide it from normal participant availability and public marketplace paths.
  - Allow platform/system admin use inside calls for diagnostics.
  - Enforce the restriction in backend availability and launch/attach paths, not
    only in frontend UI.
  - Implemented manifest/MCP internal visibility plus backend filtering and
    attach/grants/CRDT/launch-token enforcement.
  - Proof: `call-app-call-diagnostics-contract.mjs`,
    `npm run test:contract:call-apps`, PHP lint. Runtime SQLite contracts are
    included and will execute in an environment with `pdo_sqlite`.

- [x] VCS-07 Repair Call Diagnostics live-tail UX
  - Pause must stop appending/autoscroll without dropping buffered events.
  - Filters must apply to existing and incoming entries.
  - JSON/raw payloads must be formatted into human-readable output comparable
    to `jq`.
  - Implemented pause buffering, filter predicates for logs/telemetry, and
    pretty redacted JSON/raw rendering.
  - Proof: `call-app-call-diagnostics-contract.mjs`, worker browser smoke,
    `npm run test:contract:call-apps`.

- [x] VCS-08 Add diagnostics tabs
  - Tabs: Live Tail, Instances, Calls, Telemetry, Raw.
  - Keep the app responsive in iframe, sidebar, and fullscreen layouts.
  - Implemented the tabbed diagnostics views in the iframe package.
  - Proof: `call-app-call-diagnostics-contract.mjs`,
    `npm run test:contract:call-apps`, `npm run build`.

- [x] VCS-09 Add sanitized instance telemetry
  - Backend snapshot includes instance id, role, health, CPU/load, memory,
    container/service state, active calls, websocket counts, and recent error
    counters where available.
  - Parent fetches the admin-only snapshot and sends it into the Call App via
    `call_app.diagnostics.telemetry.snapshot`.
  - Implemented call-scoped admin telemetry snapshot route and parent bridge
    polling into the diagnostics iframe.
  - Proof: `call-app-call-diagnostics-contract.mjs`,
    `call-app-diagnostics-internal-contract.php` lint, `npm run build`. Runtime
    execution waits for a PHP environment with `pdo_sqlite`.

- [x] VCS-10 Stabilize focus/click reconnect loop
  - Clicking controls, switching focus, and interacting with Call Apps must not
    recreate websocket/media sessions unless auth or room membership really
    changed.
  - Add focused regression proof for the reconnect trigger path.
  - Implemented visibility/pagehide gating for workspace foreground recovery.
  - Proof: `npm run test:contract:foreground-reconnect`,
    `npm run test:contract:call-apps`, `npm run build`.

- [x] VCS-11 Deploy without push/DNS/certbot
  - Build and deploy from `prod-kingrt-do-not-push-to-github`.
  - Do not push the branch.
  - Do not run DNS or certbot unless a new domain is explicitly added.
  - Deployed on 2026-05-09 from the local branch without pushing.
  - Command used `VIDEOCHAT_DEPLOY_SKIP_CERTBOT=1`,
    `VIDEOCHAT_DEPLOY_HCLOUD_DNS=0`, and
    `VIDEOCHAT_DEPLOY_REFRESH_DNS_ON_PREPARE=0`.
  - Production endpoints after deploy:
    `https://app.kingrt.com/`, `https://api.kingrt.com/health`,
    `wss://ws.kingrt.com/ws`, `wss://sfu.kingrt.com/sfu`.

- [x] VCS-12 Post-deploy diagnostics and second-pass fix gate
  - Run `prod-debug.sh`, public asset/API checks, and relevant remote logs.
  - Collect all distinct deploy/runtime errors before preparing a second deploy.
  - Close this ticket only after diagnostics are recorded and no new 500/reload
    loop is visible in the checked path.
  - `prod-debug.sh` completed read-only with containers up and API runtime
    asset version `20260509181719`.
  - Call Diagnostics assets returned 200 for iframe HTML, JS, CSS, manifest,
    and MCP descriptor on `whiteboard.kingrt.com`.
  - Authenticated admin availability for call
    `ba3779f5-25a3-479f-874d-831903abdc63` returned 200 with six apps including
    `call-diagnostics`; unauthenticated availability returned 401 instead of
    500.
  - Normal user diagnostics search returned 200 with zero apps; normal user
    telemetry returned 403 `call_diagnostics_admin_required`.
  - Admin telemetry snapshot returned 200 with
    `king.call_diagnostics.telemetry.snapshot.v1`.
  - Distinct non-blocking diagnostics observed: remote rsync could not delete
    stale non-empty `.cargo` directories; recent logs still contain old
    pre-deploy websocket retry warnings for asset version `20260509122256`;
    TURN logs include normal peer TCP reset noise.
  - No second deploy needed for this pass.

## Hotfix: Planning Image Multi-Image Controls

Tickets:
- [x] PI-01 Multi-upload keeps each image as a UUID-addressed CRDT item.
- [x] PI-02 Top overlay thumbnail picker selects the active shared image.
- [x] PI-03 Selected image can be deleted by Delete key or toolbar button.
- [x] PI-04 Delete is allowed for the uploader's own image or participants
  with the per-app delete action.
- [x] PI-05 Planning Image uses existing per-participant read/write/delete
  Call App grants for view/upload/delete.
- [x] PI-06 Deployed without push, DNS changes, or Certbot; post-deploy
  diagnostics and Planning Image asset checks passed.
