# IAM Sprint 04 Duplicate Cleanup Classification

Date: 2026-05-10

Scope: classification and evidence only. The dirty source worktree was inspected
read-only and was not reset, rebased, checked out, cleaned, or deleted.
Background, Gossip, SFU, MediaSecurity, and BTGF areas were not touched.

## Source Worktree

Worktree: `/home/jochen/projects/king.site/worktrees/codex-iam-duplicate-cleanup-reaudit-20260509`

Branch: `codex/iam-duplicate-cleanup-reaudit-20260509`

Head: `37b13ece6adb42bc77a9fd6739557f8c2190bcaa`

Observed status:

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

Unmerged index entries:

```text
100644 f1d3625c9d2a7709b0bcdde8ebeadbf3759a284e 1 demo/video-chat/frontend-vue/package.json
100644 d7fd47da71630db348f4144a714832b37d89d27b 2 demo/video-chat/frontend-vue/package.json
100644 36e3a354bc74441b90a4b5179a88ef145f30179d 3 demo/video-chat/frontend-vue/package.json
```

## Conflict Shape

`package.json` stage 2 keeps the broad direct `test:contract:iam-call-access`
command list and adds `tests/e2e/iam-lobby-admission-main.spec.js` to the broad
Call Access E2E script.

`package.json` stage 3 replaces the broad package scripts with two suite
runners:

```text
test:contract:iam-call-access = node tests/contract/iam-call-access-contract-suite.mjs
test:e2e:call-access = node tests/e2e/call-access-e2e-suite.mjs
```

The staged suite helper and runner concept is the only current unique value:
it tries to move long package-script command lists into source-controlled
runner modules.

## Current Integration Check

Current `prod-kingrt-do-not-push-to-github` already has the Sprint 03 stable
IAM call-access gate wired directly through `package.json`,
`iam-call-access-ci-wire-contract.mjs`, and
`ui-parity-acceptance.matrix.json`.

The dirty source suite cannot be safely extracted as-is:

- The suite references many legacy contract and E2E files that are not present
  in the current integration worktree, including
  `call-access-identity-mismatch-review-flow-contract.mjs`,
  `call-access-privacy-foreign-data-contract.mjs`,
  `call-access-safe-screen-final-contract.mjs`,
  `call-access-multi-session-device-safety-contract.mjs`,
  `call-access-link-invalidation-durability-contract.mjs`,
  `call-access-security-manipulation-contract.mjs`,
  `call-access-parallel-account-tabs-contract.mjs`,
  `call-access-cross-org-foreign-join-contract.mjs`,
  `iam-king-container-ci-contract.mjs`,
  `iam-lobby-management-moderator-rights-contract.mjs`,
  `call-access-strong-mismatch-host-verification.spec.js`,
  `call-access-duplicate-race.spec.js`, and
  `call-access-rejoin-kick-membership.spec.js`.
- The suite runner would bypass the current CI-wire contract's explicit path
  checks unless that contract were redesigned.
- The dirty source worktree also contains broad staged edits across many IAM
  proof files, so treating the suite runner as a clean mechanical extraction
  would risk losing local review intent.

## Classification

Recommendation: `manual`.

Do not delete, reset, rebase, or auto-supersede
`codex/iam-duplicate-cleanup-reaudit-20260509`. A human should first resolve the
unmerged package conflict and decide whether to recreate the suite-runner idea
against the current Sprint 03/Sprint 04 proof inventory.

Safe extraction performed in this branch: documentation plus a narrow
classification contract only. No dirty source files were copied into the stable
IAM gate, and no package script was changed to the unresolved suite-runner
shape.
