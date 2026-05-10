# IAM Sprint 03 Dirty Worktree Classification

Date: 2026-05-10

Scope: classification only. No branch, worktree, or file cleanup was performed. Background, Gossip, SFU, MediaSecurity, and BTGF areas were not modified.

Base used for current-state comparisons: local `prod-kingrt-do-not-push-to-github` at `47290f9f621385ebda9a70bd733990832fa701ff`.

## Scan Summary

Current dirty IAM worktrees from `git worktree list --porcelain` plus `git status --porcelain=v1 -uall`:

| Recommendation | Worktree | Branch | Head | Dirty/conflict state |
| --- | --- | --- | --- | --- |
| superseded | `/home/jochen/projects/king.site/worktrees/king-domain-registry` | `codex/iam-call-access-e2e-foundation` | `fdf66140153d24e7a1917d1030911cbae767cbf8` | 4 modified files, no unmerged index entries |
| manual | `/home/jochen/projects/king.site/worktrees/codex-iam-duplicate-cleanup-reaudit-20260509` | `codex/iam-duplicate-cleanup-reaudit-20260509` | `37b13ece6adb42bc77a9fd6739557f8c2190bcaa` | unmerged `package.json`, broad staged IAM contract changes |
| keep | `/home/jochen/projects/king.site/worktrees/iam-s3-14-guest-list-membership-docker-proof` | `agent/iam-s3-14-guest-list-membership-docker-proof` | `47290f9f621385ebda9a70bd733990832fa701ff` | 2 untracked focused IAM3-14 proof files |

Note: `/home/jochen/projects/king.site/worktrees/iam-s3-12-strong-mismatch-audit-redaction` appeared dirty in an initial scan, but a rescan showed it clean with local commit `002a0e9d`; it is not a current dirty-worktree cleanup candidate.

## Evidence And Recommendations

### `codex/iam-call-access-e2e-foundation`

Worktree: `/home/jochen/projects/king.site/worktrees/king-domain-registry`

Dirty files:

```text
M demo/video-chat/contracts/v1/ui-parity-acceptance.matrix.json
M demo/video-chat/frontend-vue/package.json
M demo/video-chat/frontend-vue/tests/contract/iam-call-access-e2e-foundation-contract.mjs
M demo/video-chat/scripts/smoke.sh
```

Conflict state: none reported by `git ls-files -u`.

Evidence:

- The branch tracks `origin/codex/iam-call-access-e2e-foundation`, which is currently gone.
- The dirty diff removes `call-access-join.spec.js` from `test:e2e:matrix` and the matrix command paths, adds a focused `test:e2e:call-access` smoke step, and updates the foundation contract around that split.
- Current `prod-kingrt-do-not-push-to-github` already has the stronger current shape:
  - `test:e2e:matrix` is the chat/layout matrix and does not include `call-access-join.spec.js`.
  - `test:e2e:call-access` exists and runs focused Call Access Playwright specs with `PLAYWRIGHT_IAM_CALL_ACCESS_ARTIFACTS=1`.
  - `demo/video-chat/scripts/smoke.sh` already runs `npm run test:e2e:call-access -- --reporter=list --workers=1` before the chat/layout matrix, with container backend/ws/sfu origins and insecure WS allowance.
  - `iam-call-access-e2e-foundation-contract.mjs` already asserts the focused Call Access command and the matrix split.

Recommendation: `superseded`. Preserve the dirty worktree until a human confirms no local-only intent remains, but do not merge these dirty package/smoke changes as-is.

### `codex/iam-duplicate-cleanup-reaudit-20260509`

Worktree: `/home/jochen/projects/king.site/worktrees/codex-iam-duplicate-cleanup-reaudit-20260509`

Dirty files:

```text
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

Conflict state:

```text
100644 f1d3625c9d2a7709b0bcdde8ebeadbf3759a284e 1 demo/video-chat/frontend-vue/package.json
100644 d7fd47da71630db348f4144a714832b37d89d27b 2 demo/video-chat/frontend-vue/package.json
100644 36e3a354bc74441b90a4b5179a88ef145f30179d 3 demo/video-chat/frontend-vue/package.json
```

Evidence:

- `package.json` is unmerged. This is not safe for automatic classification cleanup.
- The branch has broad staged modifications across many IAM contract files plus a new suite helper and suite runner.
- The branch also has unstaged edits to `package.json` and `tests/e2e/call-access-e2e-suite.mjs`; the E2E suite adds `tests/e2e/iam-lobby-admission-main.spec.js`.
- Current prod already has a large explicit `test:contract:iam-call-access` script and CI wire contract coverage, so the suite-runner concept may be partially superseded. However, the unresolved conflict and broad staged edits could contain local review intent.

Recommendation: `manual`. Do not delete or auto-supersede. A human should resolve or extract any unique suite-helper/E2E-suite value, then decide whether the remaining branch is obsolete.

### `agent/iam-s3-14-guest-list-membership-docker-proof`

Worktree: `/home/jochen/projects/king.site/worktrees/iam-s3-14-guest-list-membership-docker-proof`

Dirty files:

```text
?? demo/video-chat/backend-king-php/tests/call-access-guest-list-membership-docker-proof.sh
?? demo/video-chat/frontend-vue/tests/contract/call-access-guest-list-membership-docker-proof-contract.mjs
```

Conflict state: none reported by `git status` for this worktree.

Evidence:

- The branch is at the current base and owns two untracked focused IAM3-14 files.
- The shell proof runs the existing `call-guest-list-direct-join-contract.sh` and `call-access-membership-removal-contract.sh`.
- The shell proof detects host `pdo_sqlite`; when host PHP lacks it, it uses a Docker `php:8.4-cli-trixie` fallback and installs/verifies `pdo_sqlite` before running both contracts.
- The paired frontend contract pins that behavior and proves the underlying guest-list direct-join and membership-removal contracts still carry the expected IAM semantics.
- Current prod already has the underlying wrappers and broad `iam-call-access-sqlite-runtime-proof.sh`, but it does not have this Docker fallback proof pair.

Recommendation: `keep`. This appears to be focused IAM3-14 user work and should be preserved for owner review or completion.

## Non-Destructive Handling Notes

- No branches or worktrees were deleted.
- No dirty worktree was reset, checked out, rebased, merged, or cleaned.
- Dirty files listed above should be treated as user work until their owners explicitly classify them for merge or deletion.
