# IAM4-18 Sprint Worker Cleanup Evidence

Base checked: `prod-kingrt-do-not-push-to-github` at `1be19a19e9025ab3968c167861ad6153ee809096`.

Cleanup rule applied:

- Only Sprint 04 worker branches/worktrees were considered.
- Active worker lanes were excluded: `agent/iam-s4-15-*`, `agent/iam-s4-16-*`, `agent/iam-s4-17-*`,
  `agent/iam-s4-19-*`, and this `agent/iam-s4-18-cleanup-sprint-workers` worktree.
- Nothing was removed unless its HEAD was an ancestor of `prod-kingrt-do-not-push-to-github` and the worktree was clean.

Commands run:

- `git worktree prune --dry-run --verbose`
- `git for-each-ref --format='%(refname:short) %(objectname)' 'refs/heads/agent/iam-s4-*'`
- `git worktree list --porcelain`
- `find /home/jochen/projects/king.site/worktrees -maxdepth 1 -type d -name 'iam-s4-*' -printf '%f\n' | sort`
- `git branch --list '*iam-s4-*' --format='%(refname:short) %(objectname)'`
- `git branch --merged prod-kingrt-do-not-push-to-github --list 'agent/iam-s4-*' --format='%(refname:short) %(objectname)'`
- `git status --short --branch` for the registered Sprint 04 worker worktrees.

Result:

- `git worktree prune --dry-run --verbose` produced no stale administrative worktree entries, so no prune was needed.
- The only registered `agent/iam-s4-*` branches/worktrees are:
  - `agent/iam-s4-15-focused-contracts-wire`
  - `agent/iam-s4-16-e2e-call-access-proof`
  - `agent/iam-s4-17-browser-artifacts-redaction`
  - `agent/iam-s4-18-cleanup-sprint-workers`
  - `agent/iam-s4-19-ci-wire-matrix-proof`
- Those branches are ancestors of `prod-kingrt-do-not-push-to-github`, but they are explicitly protected by the IAM4-18
  task instructions.
- The protected active worktrees also include current worker state:
  - `iam-s4-16-e2e-call-access-proof` has untracked `demo/video-chat/frontend-vue/playwright-report/`.
  - `iam-s4-17-browser-artifacts-redaction` has a modified contract file.
  - `iam-s4-19-ci-wire-matrix-proof` has modified IAM CI wire files.

No Sprint 04 worker branch/worktree was safe and eligible to remove.
