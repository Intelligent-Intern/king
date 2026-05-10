# GSP01-20 Diagnostics Dry Run

Stand: 2026-05-10

Scope: no-deploy preparation for the GSP01-20 deploy/debug loop. This document
was prepared from the dedicated `kingrt/gsp01-20-diagnostics-dryrun` worktree.
No deploy, push, DNS automation, certbot run, or production browser smoke is
part of this dry run.

## Script Contracts Reviewed

- `demo/video-chat/scripts/prod-debug.sh` is the safe diagnostics entrypoint.
  It has `VIDEOCHAT_PROD_DEBUG_DRY_RUN=1`, `VIDEOCHAT_PROD_DEBUG_SKIP_REMOTE=1`,
  inert `.env.local` parsing through an allowlist, redaction, public endpoint
  probes, optional read-only SSH `docker compose ps/logs`, and an explicit
  no-deploy/no-restart/no-DB-write/no-DNS-change/no-admin-action contract.
- `demo/video-chat/frontend-vue/tests/contract/prod-debug-observability-contract.mjs`
  enforces that `prod-debug.sh` stays read-only, keeps secrets/media redacted,
  does not import deploy secrets or provider tokens, and does not contain
  deploy, certbot, hcloud, mutating Docker, mutating HTTP, rsync/scp, or
  sqlite-write paths.
- `demo/video-chat/scripts/deploy.sh` remains the real operator deployment
  entrypoint. Its `deploy`, `production`, `prepare`, `public-http`,
  `http-preview`, `certonly`, `sync`, `credentials`, and `status` actions are
  out of scope for this dry run. In particular, `status` reads remote certbot
  state through `deploy-remote-status.sh`, so it is not part of the no-certbot
  diagnostics dry run.
- `demo/video-chat/scripts/deploy-smoke.sh` is a live online smoke, not a dry
  run. It performs DNS resolution checks and can run a remote certbot
  certificate/SAN inspection unless `VIDEOCHAT_DEPLOY_SMOKE_SKIP_REMOTE=1` is
  set. Keep it out of GSP01-20 dry-run diagnostics; use it only when the sprint
  explicitly enters a domain/certificate smoke phase.
- `demo/video-chat/scripts/local-deploy-gate.sh` is local by default and only
  syntax-checks deploy scripts plus local contracts/build. Its
  `--online-smoke` mode delegates to `deploy-smoke.sh`, so it is not part of
  this no-DNS/no-certbot dry run.

## Dry-Run Checklist

Run from the final integration worktree only when GSP01-18 and GSP01-19 have
been closed by their owners. For this preparation task, the commands below are
the exact dry-run proof and do not perform deploy work.

1. Confirm worktree and branch:

```bash
pwd
git branch --show-current
git status --short
git merge-base --is-ancestor 6275c398 HEAD
```

Expected: the target worktree is selected, the branch is the intended GSP01-20
or final integration branch, status is clean or contains only approved local
dry-run artifacts, and the base commit is in history.

2. Run script syntax and static deploy safety checks:

```bash
bash -n demo/video-chat/scripts/prod-debug.sh
bash -n demo/video-chat/scripts/deploy.sh
bash -n demo/video-chat/scripts/lib/deploy-hetzner.sh
bash -n demo/video-chat/scripts/lib/deploy-remote-status.sh
bash -n demo/video-chat/scripts/deploy-smoke.sh
bash -n demo/video-chat/scripts/local-deploy-gate.sh
demo/video-chat/scripts/check-deploy-idempotency.sh
```

Expected: syntax and marker checks pass. These commands do not run deploy,
push, DNS automation, certbot, SSH writes, or production smoke.

3. Prove the diagnostics contract:

```bash
cd demo/video-chat/frontend-vue
node tests/contract/prod-debug-observability-contract.mjs
```

Expected: the contract reports `PASS` and continues to forbid dangerous
diagnostics patterns in `prod-debug.sh`.

4. Execute the local no-network diagnostics dry run:

```bash
VIDEOCHAT_DEPLOY_DOMAIN=kingrt.com \
VIDEOCHAT_PROD_DEBUG_DRY_RUN=1 \
VIDEOCHAT_PROD_DEBUG_SKIP_REMOTE=1 \
demo/video-chat/scripts/prod-debug.sh
```

Expected: the output declares read-only diagnostics, prints the normalized
app/api/ws/sfu/turn/cdn/registry/whiteboard domain set, and marks all HTTP,
WebSocket, Call App CSP, and remote sections as `DRY-RUN` or skipped. This
command must not call DNS tooling, certbot, deploy scripts, SSH, Docker, or
push.

5. Check the repository diff:

```bash
git diff --check
git status --short
```

Expected: no whitespace errors; only the intended checklist/script/test
changes are present.

## Commands Explicitly Excluded From This Dry Run

Do not run these while the sprint is still in no-deploy preparation:

```bash
demo/video-chat/scripts/deploy.sh wizard
demo/video-chat/scripts/deploy.sh prepare
demo/video-chat/scripts/deploy.sh deploy
demo/video-chat/scripts/deploy.sh production
demo/video-chat/scripts/deploy.sh public-http
demo/video-chat/scripts/deploy.sh http-preview
demo/video-chat/scripts/deploy.sh certonly
demo/video-chat/scripts/deploy.sh sync
demo/video-chat/scripts/deploy.sh credentials
demo/video-chat/scripts/deploy.sh status
demo/video-chat/scripts/deploy-smoke.sh
demo/video-chat/scripts/local-deploy-gate.sh --online-smoke
demo/video-chat/scripts/bgf-production-browser-smoke.sh
```

Rationale: these commands either deploy, sync/write remote state, inspect
remote certbot state, run live DNS/domain smoke, or create app-level production
test data.

## Later Gated Deploy Loop

When the sprint owner explicitly authorizes the real deploy, use the existing
no-new-domain guard flags. This is not a dry-run command and was not executed
for this task:

```bash
VIDEOCHAT_DEPLOY_HCLOUD_DNS=0 \
VIDEOCHAT_DEPLOY_REFRESH_DNS_ON_PREPARE=0 \
VIDEOCHAT_DEPLOY_SKIP_CERTBOT=1 \
demo/video-chat/scripts/deploy.sh deploy
```

After that deploy, start diagnostics with the least-mutating read-only path:

```bash
VIDEOCHAT_DEPLOY_DOMAIN=<root-domain> \
VIDEOCHAT_PROD_DEBUG_SKIP_REMOTE=1 \
demo/video-chat/scripts/prod-debug.sh
```

Only when SSH read-only inspection is explicitly allowed, repeat without
`VIDEOCHAT_PROD_DEBUG_SKIP_REMOTE=1` to collect redacted container status and
recent logs. Run 5 to 10 loops, group unique failures by surface
(`domain/header`, `api/runtime`, `ws/sfu`, `call-app-csp`, `gossip-plan`,
`publisher`, `receiver`, `backpressure`, `security-parking`, `container-log`),
and prepare any second deploy only after grouped fixes are committed locally.

## Patch Decision

No script hardening patch was required in this worktree for the dry-run loop:
`prod-debug.sh` already has a local dry-run mode and the diagnostics contract
already forbids deploy, hcloud/DNS provider, certbot, push/sync, mutating
Docker, mutating HTTP, and DB-write patterns. The operational constraint is to
keep `deploy-smoke.sh`, `deploy.sh status`, and `local-deploy-gate.sh
--online-smoke` out of the GSP01-20 no-deploy diagnostics dry run.
