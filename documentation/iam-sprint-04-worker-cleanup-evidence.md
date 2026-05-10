# IAM4-18 Sprint Worker Cleanup Evidence

Base checked: `prod-kingrt-do-not-push-to-github` at `1be19a19e9025ab3968c167861ad6153ee809096`.
Final manager cleanup rechecked after IAM4-19 merge at `68d2280e`.

Cleanup rule applied:

- Only Sprint 04 worker branches/worktrees were considered.
- Active worker lanes were excluded during the worker pass: `agent/iam-s4-15-*`,
  `agent/iam-s4-16-*`, `agent/iam-s4-17-*`, `agent/iam-s4-19-*`, and this
  `agent/iam-s4-18-cleanup-sprint-workers` worktree.
- Nothing was removed unless its HEAD was an ancestor of `prod-kingrt-do-not-push-to-github` and the worktree was clean.
  Generated artifact-only IAM4-16 output was removed only after IAM4-17 pinned
  artifact retention/redaction behavior in source.

Commands run:

- `git worktree prune --dry-run --verbose`
- `git for-each-ref --format='%(refname:short) %(objectname)' 'refs/heads/agent/iam-s4-*'`
- `git worktree list --porcelain`
- `find /home/jochen/projects/king.site/worktrees -maxdepth 1 -type d -name 'iam-s4-*' -printf '%f\n' | sort`
- `git branch --list '*iam-s4-*' --format='%(refname:short) %(objectname)'`
- `git branch --merged prod-kingrt-do-not-push-to-github --list 'agent/iam-s4-*' --format='%(refname:short) %(objectname)'`
- `git status --short --branch` for the registered Sprint 04 worker worktrees.
- `git worktree remove /home/jochen/projects/king.site/worktrees/iam-s4-18-cleanup-sprint-workers`
- `git worktree remove /home/jochen/projects/king.site/worktrees/iam-s4-19-ci-wire-matrix-proof`
- `git worktree remove --force /home/jochen/projects/king.site/worktrees/iam-s4-16-e2e-call-access-proof`
- `git branch -d agent/iam-s4-18-cleanup-sprint-workers`
- `git branch -d agent/iam-s4-19-ci-wire-matrix-proof`
- `git branch -d agent/iam-s4-16-e2e-call-access-proof`
- `git worktree prune --dry-run --verbose`
- `git worktree prune --verbose`
- `git branch --list 'agent/iam-s4-*' --format='%(refname:short) %(objectname)'`
- `git worktree list --porcelain | rg -n "iam-s4-|agent/iam-s4-" -C 1 || true`

Result:

- The worker pass found no stale administrative worktree entries and deferred
  deletion while IAM4-15/16/17/19 were active.
- After IAM4-19 merged, the remaining contained worker worktrees/branches were
  removed:
  - `agent/iam-s4-16-e2e-call-access-proof`
  - `agent/iam-s4-18-cleanup-sprint-workers`
  - `agent/iam-s4-19-ci-wire-matrix-proof`
- `agent/iam-s4-16-e2e-call-access-proof` was removed with `--force` because
  it contained only generated `node_modules`, Playwright report, and test-result
  output from the completed IAM4-16 browser proof.
- Final `git branch --list 'agent/iam-s4-*'` returned no branches.
- Final worktree scan returned no `iam-s4-*` worktrees.
