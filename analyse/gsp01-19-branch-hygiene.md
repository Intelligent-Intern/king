# GSP01-19 Branch Hygiene

Snapshot: 2026-05-10 17:00:00 +0200

Scope:
- Local branch/worktree inventory only.
- No push, deploy, DNS, or certbot work performed.
- This file records the integration state after the GSP01-11 pixel proof,
  GSP01-18 active Gossip gate cleanup, GSP01-19 build unblock/preflight
  findings, and GSP01-20 diagnostics dry-run prep were merged locally.

## Current Integration State

`kingrt/prod-ready` points at `463cc631` (`Mark gossip pixel proof and gate
complete`) at this snapshot. The integration worktree is clean except for this
new branch-hygiene analysis file being staged for commit.

Completed local integrations in this loop:
- `50176df2` fixed the duplicate `hostName` build blocker.
- `d4840e45` merged the no-deploy diagnostics dry-run checklist.
- `f4d0fd38` merged the active Gossip v1 contract gate cleanup.
- `263da5ba` recorded updated preflight findings on the final integration
  branch.
- `30b1447a` merged the GSP01-11 browser pixel proof.
- `463cc631` marked GSP01-11 and GSP01-18 complete in `SPRINT.md`.

## Active Worker Worktrees

| Branch | Worktree | State |
| --- | --- | --- |
| `kingrt/gsp01-08-sfu-park` | `worktrees/gsp01-08-sfu-park` | active and dirty; do not remove until its owner finishes or parks it |
| `kingrt/gsp01-19-branch-hygiene` | `worktrees/gsp01-19-branch-hygiene` | completed source for this note; remove after this note is integrated |
| `kingrt/gsp01-19-preflight-contracts` | `worktrees/gsp01-19-preflight-contracts` | clean but superseded for code by `f4d0fd38`; findings were integrated manually in `263da5ba` |

## Already Merged And Removed

- `kingrt/gsp01-19-build-blocker` was fast-forward merged at `50176df2` and
  removed.
- `kingrt/gsp01-20-diagnostics-dryrun` was merged at `d4840e45` and removed.
- `kingrt/gsp01-sprint-sync` was fast-forward merged at `bda50d6e` and
  removed.
- `kingrt/gsp01-18-gate-cleanup` was merged at `f4d0fd38` and removed.
- `kingrt/gsp01-11-pixel-proof` was merged at `30b1447a` and removed.

## Deploy Readiness Conditions

Before any deploy:
- Re-run branch/worktree inventory against the final `kingrt/prod-ready` head.
- `kingrt/prod-ready` must be clean.
- The active `kingrt/gsp01-08-sfu-park` result must either be merged or
  explicitly parked.
- `npm run build`, `npm run test:contract:gossip`, the GSP01-11 Playwright
  pixel proof, and the GSP01-20 diagnostics dry-run checks must pass on the
  final head.
- The real deploy remains gated by explicit user authorization and must run
  without push, DNS automation, or certbot unless a new domain is explicitly
  introduced.
