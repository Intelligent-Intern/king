# IAM11-18 Branch Hygiene Proof

Date: 2026-05-10

Scope:
- Branch/worktree inventory and proof only.
- Analyse note plus `SPRINT.md` note only.
- No branch, worktree, or remote refs deleted.
- No Background, Gossip, SFU, MediaSecurity, or BTGF files touched.

## Rule

Do not delete unproven user changes.

A stale IAM worker branch is only a cleanup candidate when both checks pass:

- Ancestor: the branch tip is reachable from the chosen integration proof ref.
- Clean: the checked-out worktree has no `git status --porcelain` output, or
  the branch has no checked-out worktree.

For this pass, the release refs `main`, `develop/1.0.8-beta`, and the current
branch were also checked so the note separates strict release proof from local
IAM integration proof.

## Inventory

Inventory command:

```text
git branch --format='%(refname:short)|%(objectname:short)|%(upstream:short)|%(worktreepath)' \
  | awk -F'|' '$1 ~ /(^|\/)(iam|iam10|iam11|iam-e2e|iam-lane)/ {print}'
git worktree list --porcelain
git -C <worktree> status --porcelain=v1 --untracked-files=all
git merge-base --is-ancestor <branch> main
git merge-base --is-ancestor <branch> develop/1.0.8-beta
git merge-base --is-ancestor <branch> HEAD
git merge-base --is-ancestor <branch> iam-e2e-integration
```

Current counts:

```text
total IAM-like local branches: 192
checked-out IAM-like worktrees: 154
clean checked-out IAM-like worktrees: 152
IAM-like branches without worktrees: 38
dirty IAM-like worktrees: 2
ancestor of main: 0
ancestor of develop/1.0.8-beta: 0
ancestor of current HEAD: 2
ancestor of iam-e2e-integration: 136
safe by iam-e2e-integration ancestor + clean/no-worktree rule: 134
manual/non-safe by that rule: 58
```

Prefix inventory:

```text
agent: 13
codex: 51
iam-e2e-deploy-readiness-rescan-codex-20260509: 1
iam-e2e-integration: 1
local: 126
```

Strict release cleanup result:

- No IAM-like branch tip is an ancestor of `main`.
- No IAM-like branch tip is an ancestor of `develop/1.0.8-beta`.
- Only `codex/iam-e2e-foundation` is both an ancestor of current `HEAD` and
  clean/no-worktree. `codex/iam-call-access-e2e-foundation` is also an ancestor
  of current `HEAD`, but its worktree is dirty and therefore manual.

## Safe Cleanup Candidates

These are safe-to-consider candidates only under the local IAM integration proof
ref `iam-e2e-integration`; this note does not authorize deletion from release
refs because `main` and `develop/1.0.8-beta` prove zero IAM-like ancestors.

Candidate definition:

```text
ancestor_iam_integration=yes AND status in {clean, no-worktree}
```

Result:

```text
134 IAM-like branches match the ancestor+clean/no-worktree rule.
```

Notable included groups:

- All 7 `agent/iam-e2e-*` branches are ancestors of `iam-e2e-integration` and
  clean or no-worktree.
- 42 `codex/iam*` branches are ancestors of `iam-e2e-integration` and clean or
  no-worktree.
- `iam-e2e-deploy-readiness-rescan-codex-20260509` is an ancestor of
  `iam-e2e-integration` and clean.
- `iam-e2e-integration` itself is clean and is its own ancestor; keep it as the
  integration proof ref, not as a cleanup target.
- 83 `local/iam*` branches are ancestors of `iam-e2e-integration` and clean or
  no-worktree.

The proof file used for this run was generated at `/tmp/iam11-18-inventory.psv`
with columns:

```text
branch|sha|worktree|status|ancestor_main|ancestor_develop|ancestor_head|ancestor_iam_integration|upstream
```

## Dirty And Manual Branches

Dirty worktrees must not be deleted or pruned by automation:

```text
codex/iam-call-access-e2e-foundation
sha: fdf66140
worktree: /home/jochen/projects/king.site/worktrees/king-domain-registry
status: dirty:4
files:
 M demo/video-chat/contracts/v1/ui-parity-acceptance.matrix.json
 M demo/video-chat/frontend-vue/package.json
 M demo/video-chat/frontend-vue/tests/contract/iam-call-access-e2e-foundation-contract.mjs
 M demo/video-chat/scripts/smoke.sh

codex/iam-duplicate-cleanup-reaudit-20260509
sha: 37b13ece
worktree: /home/jochen/projects/king.site/worktrees/codex-iam-duplicate-cleanup-reaudit-20260509
status: dirty:24
files:
 UU demo/video-chat/frontend-vue/package.json
 M  demo/video-chat/frontend-vue/tests/contract/call-access-cross-org-foreign-join-contract.mjs
 M  demo/video-chat/frontend-vue/tests/contract/call-access-duplicate-review-email-contract.mjs
 M  demo/video-chat/frontend-vue/tests/contract/call-access-edge-error-matrix-contract.mjs
 M  demo/video-chat/frontend-vue/tests/contract/call-access-email-safe-texts-dispatch-audit-contract.mjs
 M  demo/video-chat/frontend-vue/tests/contract/call-access-identity-mismatch-review-flow-contract.mjs
 M  demo/video-chat/frontend-vue/tests/contract/call-access-link-invalidation-durability-contract.mjs
 M  demo/video-chat/frontend-vue/tests/contract/call-access-multi-session-device-safety-contract.mjs
 M  demo/video-chat/frontend-vue/tests/contract/call-access-parallel-account-tabs-contract.mjs
 M  demo/video-chat/frontend-vue/tests/contract/call-access-privacy-foreign-data-contract.mjs
 M  demo/video-chat/frontend-vue/tests/contract/call-access-safe-screen-final-contract.mjs
 M  demo/video-chat/frontend-vue/tests/contract/call-access-security-manipulation-contract.mjs
 M  demo/video-chat/frontend-vue/tests/contract/call-access-strong-mismatch-privacy-contract.mjs
 A  demo/video-chat/frontend-vue/tests/contract/helpers/iamCallAccessSuiteCoverage.mjs
 M  demo/video-chat/frontend-vue/tests/contract/iam-active-call-kick-contract.mjs
 A  demo/video-chat/frontend-vue/tests/contract/iam-call-access-contract-suite.mjs
 M  demo/video-chat/frontend-vue/tests/contract/iam-call-access-e2e-foundation-contract.mjs
 M  demo/video-chat/frontend-vue/tests/contract/iam-guest-list-management-audit-proof-contract.mjs
 M  demo/video-chat/frontend-vue/tests/contract/iam-king-container-ci-contract.mjs
 M  demo/video-chat/frontend-vue/tests/contract/iam-king-participants-owner-timeout-contract.mjs
 M  demo/video-chat/frontend-vue/tests/contract/iam-lobby-concurrency-remaining-contract.mjs
 M  demo/video-chat/frontend-vue/tests/contract/iam-lobby-management-moderator-rights-contract.mjs
 M  demo/video-chat/frontend-vue/tests/contract/iam-system-admin-edge-cases-contract.mjs
 AM demo/video-chat/frontend-vue/tests/e2e/call-access-e2e-suite.mjs
```

Branches that fail the ancestor proof, or are dirty, stay manual:

```text
58 manual/non-safe branches by iam-e2e-integration ancestor+clean rule.
```

Manual groups:

- `agent/iam10-*`: 6 branches, all at `5ee5db5b`, clean worktrees, not
  ancestors of `iam-e2e-integration`.
- `codex/iam-lane-*`: 6 branches, clean worktrees, not ancestors of
  `iam-e2e-integration`.
- `codex/iam-duplicate-cleanup*`: 3 manual branches; one is dirty, two are not
  ancestors.
- Additional `codex/iam-e2e-*`, `codex/iam-lobby-*`, and `local/iam-e2e-*`
  follow-up branches are not ancestors of `iam-e2e-integration` and require
  owner review before any cleanup.

Conclusion:

- Do not delete anything from this pass.
- The only mechanically safe cleanup class is
  `ancestor_iam_integration=yes` plus clean/no-worktree, and that proves 134
  safe-to-consider candidates.
- Dirty worktrees and non-ancestor branches remain manual because they may
  contain unmerged or user-owned work.
