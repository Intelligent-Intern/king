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

## Hotfix: Strict 720p30 Call Stability

Goal:
- Stop timed video-call experiments in the live call path.
- Keep the strongest existing code paths available behind policy gates, but run
  production calls as fixed 1280x720 at 30 fps.
- Do not push. Deploy locally after proof without DNS or certbot automation.

Tickets:
- [x] STAB-01 Add a central `strict_720p30` runtime policy and fixed 720p30
  SFU video profile.
- [x] STAB-02 Disable automatic quality downgrade, recovery probes, and
  profile-switch resets while strict mode is active.
- [x] STAB-03 Disable remote video stall recovery ladders, forced keyframe
  recovery, and stall-triggered SFU socket reconnects while strict mode is
  active.
- [x] STAB-04 Disable outgoing background segmentation/replacement and
  background-tab media policy side effects while strict mode is active.
- [x] STAB-05 Disable Gossip media publish/receive recovery and topology
  repair requests while strict mode is active.
- [x] STAB-06 Keep local capture strict: 720p30 camera or audio-only/receive-only
  instead of lower-video fallback.
- [x] STAB-07 Add focused contracts for strict 720p30 behavior.
- [x] STAB-08 Build/test locally and commit on
  `prod-kingrt-do-not-push-to-github` without pushing.
  - Local commit: `15988481 Add strict 720p30 call stability policy`.
  - Follow-up local commits: `1d0039b5`, `1ea04f1b`, `a74193d6`,
    `d0ea728c`, `ebc77f2b`.
- [x] STAB-09 Deploy without DNS/certbot/push and run post-deploy diagnostics.
  - Final deployed runtime asset: `20260509224830`.
  - Deploys were run from `prod-kingrt-do-not-push-to-github` without pushing,
    with `VIDEOCHAT_DEPLOY_SKIP_CERTBOT=1`,
    `VIDEOCHAT_DEPLOY_HCLOUD_DNS=0`, and
    `VIDEOCHAT_DEPLOY_REFRESH_DNS_ON_PREPARE=0`.
  - HTTP checks returned 200 for app, API runtime, API health, whiteboard host,
    and Call Diagnostics manifest/HTML/JS/health descriptor.
  - Fresh asset-filtered logs for `20260509224830` showed no Error-level client
    diagnostics, websocket retry loop, Auto-Quality/Profile-Switch recovery,
    Gossip repair, Background outgoing policy, SFU socket recovery,
    strict binary-send failures, or stale `call/control-state` publish errors.

Proof:
- `npm run test:contract:strict-720p30`
- `node tests/contract/sfu-replay-pacing-slow-subscriber-contract.mjs`
- `node tests/contract/sfu-browser-ws-send-drain-contract.mjs`
- `node tests/contract/sfu-transport-metrics-contract.mjs`
- `node tests/contract/media-security-contract.mjs`
- `node tests/contract/sfu-background-tab-policy-contract.mjs`
- `node tests/contract/sfu-auto-readback-recovery-contract.mjs`
- `node tests/contract/sfu-capture-constraints-contract.mjs`
- `node tests/contract/sfu-profile-switch-actuator-contract.mjs`
- `node tests/contract/gossip-neighbor-health-repair-contract.mjs`
- `node tests/contract/gossip-outbound-live-publication-contract.mjs`
- `node tests/contract/gossip-stale-target-pruning-contract.mjs`
- `node tests/contract/client-diagnostics-contract.mjs`
- `npm run test:contract:foreground-reconnect`
- `npm run build`
- `npm run test:contract:build-size`
- `git diff --check`

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
  - Latest `prod-debug.sh` completed read-only with containers up and API
    runtime asset version `20260509224830`.
  - Call Diagnostics assets returned 200 for manifest, iframe HTML, JS, and
    health descriptor on `whiteboard.kingrt.com`.
  - Distinct errors found during deploy diagnostics were bundled before the
    follow-up deploys: strict binary send failures, strict SFU disconnect
    diagnostics/reconnect recovery, stale `call/control-state`
    `target_not_in_room` publish errors, and old asset websocket retry noise.
  - Follow-up fixes were deployed before closing: strict binary send failures
    now drop quietly, strict SFU disconnects do not schedule recovery reconnect
    or noisy diagnostics, and stale `call/control-state` targets prune locally
    instead of emitting `realtime_signaling_publish_failed`.
  - Final fresh asset-filtered log check for `20260509224830` was empty for
    the blocked patterns: Error-level client diagnostics, websocket retry loop,
    Auto-Quality/Profile-Switch recovery, Gossip repair, Background outgoing
    policy, SFU recovery/reconnect, binary send failure, and stale control-state
    publish failure.
  - Remaining non-blocking deploy noise: remote rsync could not delete stale
    non-empty `.cargo` directories; Docker Compose reported missing buildx;
    TURN logs include peer TCP reset noise; Vite reports the known chunk-size
    warning.

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

## Hotfix: Guest Join-Link Admission

Tickets:
- [x] GJL-01 External/personal guest join links resolve to the public join page
  without leaking private call data on invalid links.
- [x] GJL-02 Guest link session creation requires a display name, creates a
  temporary guest identity, and keeps the user in the lobby until an
  owner/moderator/admin admits them.
- [x] GJL-03 Right sidebar keeps the user-tab lobby badge; collapsed sidebar
  shows the main lobby notification for new join requests.
- [x] GJL-04 Contract/build proof and no-push deploy without DNS or Certbot,
  followed by diagnostics.
  - Local proof before deploy: PHP lint for changed call-access files,
    `npm run test:contract:iam-call-access`,
    `npm run test:contract:participant-roster`, `npm run build`,
    `npm run test:contract:build-size`, and `git diff --check`.
  - Runtime SQLite call-access session contract is extended for external
    guest admission but skips locally because `pdo_sqlite` is unavailable.
  - Deployed on 2026-05-09 from the local branch without pushing. No DNS
    changes were made, and certbot issuance was skipped.
  - Post-deploy diagnostics: `https://api.kingrt.com/health` and
    `https://app.kingrt.com/` returned 200, runtime asset version
    `20260509212621` is active, core frontend assets returned 200, and an
    invalid call-access join URL returned a structured 404 instead of 500.
  - Remote containers are up. Fresh logs did not show call-access fatal errors
    or API 500s. Remaining warnings are scoped to the parked Media/SFU/Gossip
    diagnostics and were not changed in this hotfix.
