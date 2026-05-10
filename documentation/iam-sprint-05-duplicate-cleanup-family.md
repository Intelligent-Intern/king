# IAM Sprint 05 Duplicate Cleanup Family

Date: 2026-05-10

Scope: IAM5-03 evidence and package-suite extraction only. The source
duplicate-cleanup worktrees were inspected read-only. No source worktree was
reset, checked out, cleaned, rebased, merged, conflict-resolved, or deleted.
Background, Gossip, SFU, MediaSecurity, and BTGF files/tests were not edited.

## Source Family

| Branch | Worktree | Head | State |
| --- | --- | --- | --- |
| `codex/iam-duplicate-cleanup` | `/home/jochen/projects/king.site/worktrees/codex-iam-duplicate-cleanup` | `a996e16ca0f6fc40819701760a0e3d45f6fe3142` | clean |
| `codex/iam-duplicate-cleanup-reaudit-20260509` | `/home/jochen/projects/king.site/worktrees/codex-iam-duplicate-cleanup-reaudit-20260509` | `37b13ece6adb42bc77a9fd6739557f8c2190bcaa` | dirty, unresolved `package.json` conflict |
| `codex/iam-duplicate-cleanup-current-reaudit-20260509` | `/home/jochen/projects/king.site/worktrees/codex-iam-duplicate-cleanup-current-reaudit-20260509` | `96049350692d37a255d7cc099609daf84058782a` | clean |
| `codex/iam-duplicate-cleanup-latest-reaudit-20260509` | `/home/jochen/projects/king.site/worktrees/codex-iam-duplicate-cleanup-latest-reaudit-20260509` | `b19651b566fa340ac135d925d185322afc5d8412` | clean |

## Containment

All four family branches merge-base with
`prod-kingrt-do-not-push-to-github` at
`79f57cc862896446edc2a3365a6a6264f67461a4`. None of the four family tips is
contained in the Sprint 05 base, and the Sprint 05 base is not an ancestor of
any family tip.

Ahead/behind from `prod-kingrt-do-not-push-to-github`:

| Branch | Base-only commits | Branch-only commits | `prod...branch` changed paths |
| --- | ---: | ---: | ---: |
| `codex/iam-duplicate-cleanup` | 383 | 208 | 229 |
| `codex/iam-duplicate-cleanup-reaudit-20260509` | 383 | 214 | 227 |
| `codex/iam-duplicate-cleanup-current-reaudit-20260509` | 383 | 217 | 231 |
| `codex/iam-duplicate-cleanup-latest-reaudit-20260509` | 383 | 225 | 236 |

Family containment:

- `codex/iam-duplicate-cleanup-reaudit-20260509` is an ancestor of
  `codex/iam-duplicate-cleanup-current-reaudit-20260509`.
- `codex/iam-duplicate-cleanup-reaudit-20260509` is an ancestor of
  `codex/iam-duplicate-cleanup-latest-reaudit-20260509`.
- `codex/iam-duplicate-cleanup` is not contained by the other three family
  branches.
- `codex/iam-duplicate-cleanup-current-reaudit-20260509` and
  `codex/iam-duplicate-cleanup-latest-reaudit-20260509` do not contain each
  other.

## Dirty Worktree Classification

`codex/iam-duplicate-cleanup-reaudit-20260509` remains manual/preserve. Its
index has one unresolved path:

```text
UU demo/video-chat/frontend-vue/package.json
100644 f1d3625c9d2a7709b0bcdde8ebeadbf3759a284e 1 demo/video-chat/frontend-vue/package.json
100644 d7fd47da71630db348f4144a714832b37d89d27b 2 demo/video-chat/frontend-vue/package.json
100644 36e3a354bc74441b90a4b5179a88ef145f30179d 3 demo/video-chat/frontend-vue/package.json
```

It also carries staged IAM contract edits and suite files:

```text
A  demo/video-chat/frontend-vue/tests/contract/helpers/iamCallAccessSuiteCoverage.mjs
A  demo/video-chat/frontend-vue/tests/contract/iam-call-access-contract-suite.mjs
A  demo/video-chat/frontend-vue/tests/e2e/call-access-e2e-suite.mjs
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
M  demo/video-chat/frontend-vue/tests/contract/iam-active-call-kick-contract.mjs
M  demo/video-chat/frontend-vue/tests/contract/iam-call-access-e2e-foundation-contract.mjs
M  demo/video-chat/frontend-vue/tests/contract/iam-guest-list-management-audit-proof-contract.mjs
M  demo/video-chat/frontend-vue/tests/contract/iam-king-container-ci-contract.mjs
M  demo/video-chat/frontend-vue/tests/contract/iam-king-participants-owner-timeout-contract.mjs
M  demo/video-chat/frontend-vue/tests/contract/iam-lobby-concurrency-remaining-contract.mjs
M  demo/video-chat/frontend-vue/tests/contract/iam-lobby-management-moderator-rights-contract.mjs
M  demo/video-chat/frontend-vue/tests/contract/iam-system-admin-edge-cases-contract.mjs
```

The unstaged part of the dirty worktree still modifies
`demo/video-chat/frontend-vue/package.json` and
`demo/video-chat/frontend-vue/tests/e2e/call-access-e2e-suite.mjs`. Those edits
were not copied or resolved here.

## Package-Suite Value

The current Sprint 05 base package scripts do not already carry the
duplicate-cleanup suite refactor. In
`prod-kingrt-do-not-push-to-github`, `test:contract:iam-call-access` is still
the explicit Sprint 03/Sprint 04 direct command list, and
`test:e2e:call-access` is still the focused artifact-retaining Playwright gate:

```text
test:e2e:call-access = PLAYWRIGHT_IAM_CALL_ACCESS_ARTIFACTS=1 playwright test tests/e2e/call-access-join.spec.js tests/e2e/call-access-seed-matrix.spec.js tests/e2e/call-access-calendar-unregistered-invite.spec.js tests/e2e/call-access-admin-join-boundaries.spec.js --workers=1
```

The clean `codex/iam-duplicate-cleanup`,
`codex/iam-duplicate-cleanup-current-reaudit-20260509`, and
`codex/iam-duplicate-cleanup-latest-reaudit-20260509` tips agree on the useful
package-suite script values:

```text
test:contract:iam-call-access = node tests/contract/iam-call-access-contract-suite.mjs
test:e2e:call-access = node tests/e2e/call-access-e2e-suite.mjs
```

Those two package script values are the only value extracted by IAM5-03. The
surrounding runner modules and broad historical target lists were not copied
because this lane is not allowed to edit package wiring or add the suite
runners themselves. The clean branches also contain CI aliases that invoke
`../scripts/iam-call-access-ci-gate.sh`, but that gate script is absent from the
Sprint 05 base and is outside this lane's write scope.

The `latest` suite runner content is newer than `current`: it adds
`tests/e2e/call-access-authorized-rejoin.spec.js`,
`tests/contract/iam-lobby-timeout-consistency-contract.mjs`, and
`../backend-king-php/tests/realtime-lobby-timeout-consistency-contract.sh`.
That target-list difference is not a package-script value and was not
extracted here.

## Classification

Recommendation: keep the family manual/preserve, do not delete anything, and
do not use any whole-branch merge from this family into the Sprint 05 base.

Only the suite package-script redirection value is current and separable. The
dirty/conflicted source worktree remains unresolved user work, while the clean
family tips are old divergent branches carrying hundreds of paths relative to
the Sprint 05 base. Any future implementation of the suite runner should be
rebuilt in a focused lane against the current Sprint 05 proof inventory rather
than copied wholesale from these branches.
