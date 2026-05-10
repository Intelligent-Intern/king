# GSP01-19 Branch Hygiene

Snapshot: 2026-05-10 16:52:42 +0200

Scope:
- Local branch/worktree inventory only.
- No branches or worktrees removed.
- No push, deploy, DNS, or certbot work performed.
- This worker branch started at `cb3cdbca`, the requested local
  `kingrt/prod-ready` base. During this inventory, other workers advanced
  `kingrt/prod-ready` through `d4840e45`, `bda50d6e`, and `f4d0fd38`; this
  branch was not rebased or merged.

## Integration State

`kingrt/prod-ready` currently points at `f4d0fd38` (`Merge GSP01-18 active
gossip gate cleanup`). Its worktree is not clean: the
`analyse/gsp01-19-preflight-findings.md` file is staged/modified (`AM`) there
and must be resolved by its owner before deploy.

Current GSP01 branch state is mixed. Some worktrees were merged and removed
during this pass, while checked-out worker branches remain active. Do not remove
active checked-out worker branches without owner confirmation.

## Active Worker Worktrees

| Branch | Worktree | Head | State |
| --- | --- | --- | --- |
| `kingrt/gsp01-08-sfu-park` | `worktrees/gsp01-08-sfu-park` | `6275c398` | dirty, branch tip merged into current `kingrt/prod-ready`, 20 changed paths observed |
| `kingrt/gsp01-11-pixel-proof` | `worktrees/gsp01-11-pixel-proof` | `e83417ac` | clean, 1 commit ahead and 7 behind current `kingrt/prod-ready` |
| `kingrt/gsp01-19-branch-hygiene` | `worktrees/gsp01-19-branch-hygiene` | local note commit | this note branch, based on the requested `cb3cdbca` commit |
| `kingrt/gsp01-19-preflight-contracts` | `worktrees/gsp01-19-preflight-contracts` | `df906f74` | clean, 1 commit ahead and 7 behind current `kingrt/prod-ready` |

## Already Merged And Removed

`kingrt/gsp01-19-build-blocker` was already fast-forward merged into
`kingrt/prod-ready` at `50176df2` (`Fix duplicate call access host name
declaration`) according to the local reflog entry from 2026-05-10 16:43:54
+0200. The branch is no longer present in local refs and has no checked-out
worktree in `git worktree list`.

`kingrt/gsp01-20-diagnostics-dryrun` was active during the first inventory
snapshot, then merged into `kingrt/prod-ready` via `d4840e45`
(`Merge GSP01-20 diagnostics dry run`). By the latest snapshot, its local branch
and checked-out worktree were already removed.

`kingrt/gsp01-18-gate-cleanup` advanced during this pass, was merged into
`kingrt/prod-ready` via `f4d0fd38`, and by the latest snapshot its local branch
and checked-out worktree were already removed.

## Deploy Readiness Conditions

Before any deploy:
- Re-run branch/worktree inventory against the final `kingrt/prod-ready` head;
  the integration branch changed during this pass.
- Treat checked-out worker branches as active until their owners merge, park, or
  explicitly release them. Do not remove dirty `kingrt/gsp01-08-sfu-park`.
- Final `kingrt/prod-ready` must be clean. The current `AM
  analyse/gsp01-19-preflight-findings.md` state in the prod-ready worktree is a
  deploy blocker until resolved.
- Final `kingrt/prod-ready` must include or intentionally park every active
  GSP01 worker result needed for the release, including the clean but currently
  unmerged `kingrt/gsp01-11-pixel-proof` and
  `kingrt/gsp01-19-preflight-contracts` commits.
- GSP01-18/GSP01-19 predeploy gates must pass on that final head: focused
  contract/build checks, `prod-debug.sh` preflight, and HTTP/API/WS checks, with
  distinct failures fixed or explicitly waived.
- No deploy, DNS mutation, or certbot step belongs to this branch-hygiene pass.
