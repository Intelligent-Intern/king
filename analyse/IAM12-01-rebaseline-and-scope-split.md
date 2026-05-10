# IAM12-01 Rebaseline And Scope Split

Date: 2026-05-10

Scope:
- Sprint planning and proof classification only.
- No production deploy.
- No push, DNS changes, certbot calls, or remote mutation.
- No Background, Gossip, SFU, MediaSecurity, BTGF, or VCAP implementation work.

## Current Integration State

Active local branch:

```text
prod-kingrt-do-not-push-to-github
```

IAM11 is closed as active sprint work. The current integration worktree contains
the IAM11 call-access runtime and proof changes, including:

- open-link temporary guests staying in `invited` until admission;
- lobby kick returning temporary guests to `invited` while reject/remove keep
  their stronger terminal semantics;
- owner-transfer lifecycle and audit proof;
- guest-list audit proof;
- database-backed org-admin/system-admin authority proof;
- strong personalized-link mismatch UI extraction proof;
- local deploy-gate script proof without push, deploy, DNS, or certbot.

## Scope Split Finding

The integration worktree is not safe to commit, merge, or deploy as a single
unit. It also contains parked-scope material from the Video Call v1 Capability
And Media Plan foundation:

- client capability and media session plan modules;
- websocket command/session-plan wiring;
- VCAP contract scripts and package wiring;
- analysis docs that discuss SFU, Gossip, MediaSecurity, Background, and
  related media-plan decisions.

Those files are parked for the current IAM12 sprint unless the user explicitly
reopens them. The next local commit/merge candidate must therefore be split into
an IAM/call-access-only patch and a quarantined VCAP/media-plan patch.

Do not blanket-add `analyse/` or `documentation/archive/`; both contain a mix
of allowed IAM evidence and parked media/architecture evidence.

## Visible Root Markdown State

The integration checkout root contains only:

```text
BACKLOG.md
README.md
SPRINT.md
```

The separate visible main checkout at `/home/jochen/projects/king.site/king`
also now contains only those three root Markdown files. The obsolete root docs
were moved there to:

```text
documentation/archive/root-md-2026-05-10/
```

That move did not touch the parked Background implementation files in the main
checkout. Its existing dirty Background and IAM diffs remain preserved.

## IAM12 First Gate

Before any commit, deploy, or branch cleanup:

1. Split allowed IAM/call-access changes from parked VCAP/media-plan changes.
2. Fix stale IAM proof text that still contradicts closed IAM11 checkboxes.
3. Rerun the smallest local gate:

```bash
cd demo/video-chat/frontend-vue
npm run test:contract:iam-call-access
npm run build
```

```bash
git diff --check
```

Host PHP may lack `pdo_sqlite`; Docker-capable IAM wrappers must be used for
runtime proof instead of treating host skips as success.
