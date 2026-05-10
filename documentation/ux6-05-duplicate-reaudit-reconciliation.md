# UX6-05 Duplicate Reaudit Reconciliation

Date: 2026-05-10

Scope: reconcile
`/home/jochen/projects/king.site/worktrees/codex-iam-duplicate-cleanup-reaudit-20260509`
against current IAM integration evidence on
`prod-kingrt-do-not-push-to-github`. The source worktree was inspected
read-only. It was not reset, resolved, cleaned, removed, rebased, merged, or
deleted. Background, Gossip, SFU, MediaSecurity, BTGF, and their tests were not
edited.

## Source State

Source branch:
`codex/iam-duplicate-cleanup-reaudit-20260509`

Source head:
`37b13ece6adb42bc77a9fd6739557f8c2190bcaa`

Observed status remains conflicted:

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

The unmerged path still has three package stages:

```text
100644 f1d3625c9d2a7709b0bcdde8ebeadbf3759a284e 1 demo/video-chat/frontend-vue/package.json
100644 d7fd47da71630db348f4144a714832b37d89d27b 2 demo/video-chat/frontend-vue/package.json
100644 36e3a354bc74441b90a4b5179a88ef145f30179d 3 demo/video-chat/frontend-vue/package.json
```

The conflict is not a clean redundant cleanup state. Stage 2 keeps the broad
direct IAM package-script wiring, while stage 3 redirects the package scripts
through `iam-call-access-contract-suite.mjs` and
`call-access-e2e-suite.mjs`. The staged source edits also touch many IAM proof
files, so the worktree cannot be treated as a contained mechanical package
script cleanup.

## Current Integration Evidence

Current integration branch:
`prod-kingrt-do-not-push-to-github` at `f7a16054`.

The current branch has already preserved the prior IAM reconciliation evidence:

- `documentation/iam-sprint-04-duplicate-cleanup-classification.md`
- `documentation/iam-sprint-05-duplicate-cleanup-family.md`
- `documentation/iam-sprint-05-remaining-inventory.md`
- `READYNESS_TRACKER.md`

Focused contracts passed on the current branch:

```text
node tests/contract/iam-duplicate-cleanup-classification-contract.mjs
[iam-duplicate-cleanup-classification-contract] PASS

node tests/contract/iam-duplicate-cleanup-family-contract.mjs
[iam-duplicate-cleanup-family-contract] PASS
```

Those contracts prove the source conflict was classified without destructive
cleanup, and that the stable IAM gate must not adopt the unresolved
duplicate-cleanup suite runner.

Current integration does not contain the source suite-runner files:

```text
demo/video-chat/frontend-vue/tests/contract/helpers/iamCallAccessSuiteCoverage.mjs absent
demo/video-chat/frontend-vue/tests/contract/iam-call-access-contract-suite.mjs absent
demo/video-chat/frontend-vue/tests/e2e/call-access-e2e-suite.mjs absent
```

The current `package.json` still exposes direct, stable IAM gates instead of
the conflicted suite-runner redirect:

```text
test:contract:iam-call-access = node tests/contract/iam-call-access-ci-wire-contract.mjs && ...
test:e2e:call-access = PLAYWRIGHT_IAM_CALL_ACCESS_ARTIFACTS=1 playwright test tests/e2e/call-access-join.spec.js tests/e2e/call-access-seed-matrix.spec.js tests/e2e/call-access-calendar-unregistered-invite.spec.js tests/e2e/call-access-admin-join-boundaries.spec.js --workers=1
```

## Decision

UX6-05 is reconciled, but the source worktree must be preserved.

Cleanup is not safe because the source is still conflicted and dirty, and the
conflict state is not proven redundant with already integrated IAM code. The
already integrated IAM evidence proves classification and preservation, not
safe removal. Do not delete
`/home/jochen/projects/king.site/worktrees/codex-iam-duplicate-cleanup-reaudit-20260509`
unless a later worker or the user first resolves/preserves the conflicted
source changes and proves the worktree clean and contained.
