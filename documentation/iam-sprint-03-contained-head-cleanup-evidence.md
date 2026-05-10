# IAM Sprint 03 Contained-HEAD Cleanup Evidence

Date: 2026-05-10

Scope: cleanup evidence plus contained-HEAD cleanup for clean Sprint 03 worker
branches. Background, Gossip, SFU, MediaSecurity, and BTGF areas were not
touched.

Base checked: local `prod-kingrt-do-not-push-to-github` at
`1b9d56095d38c6b423e74b732465f8896638cae9`.

## Actions Run

```text
git -C /home/jochen/projects/king.site/worktrees/bgf-sprint-integration status --short --branch
git -C /home/jochen/projects/king.site/worktrees/bgf-sprint-integration rev-parse --abbrev-ref HEAD
git -C /home/jochen/projects/king.site/worktrees/bgf-sprint-integration rev-parse HEAD
git -C /home/jochen/projects/king.site/worktrees/bgf-sprint-integration worktree list --porcelain
git status --short --branch
git branch --list '*iam-s3*' '*proof-3*' --format='%(refname:short) %(objectname:short)'
git branch --list 'agent/iam-s3-*' 'local/iam-e2e-*proof-3' --format='%(refname:short)|%(objectname)'
git branch --merged prod-kingrt-do-not-push-to-github --format='%(refname:short)' | rg '^(agent/iam-s3-|local/iam-e2e-.*proof-3)' || true
git worktree prune --verbose
git worktree list --porcelain | awk ... | git -C <worktree> status --porcelain=v1 --untracked-files=all
git worktree remove /home/jochen/projects/king.site/worktrees/iam-s3-17-proof-wiring
git branch -d agent/iam-s3-17-proof-wiring
```

`git worktree prune --verbose` produced no output and did not remove any checked
out worktree.

## Named Sprint 03 Worker Branches

| Branch | Local state | Contained in prod | Worktree clean | Decision |
| --- | --- | --- | --- | --- |
| `agent/iam-s3-05-logout-login-switch` | missing locally | n/a | n/a | No action available. |
| `agent/iam-s3-07-anonymous-guest-manipulation` | missing locally | n/a | n/a | No action available. |
| `agent/iam-s3-13-anonymous-temp-docker-proof` | missing locally | n/a | n/a | No action available. |
| `agent/iam-s3-17-proof-wiring` | `254d0048` before cleanup | yes | yes | Removed after merge under contained-HEAD and clean-worktree rules. |
| `agent/iam-s3-19-cleanup-evidence` | current evidence branch | no, until merge | yes | Current worker branch; remove only after manager merge. |

## Checked-Out `proof-3` IAM Worktrees

All checked-out `local/iam-e2e-*proof-3` worktrees below were clean, but their
HEADs were not ancestors of `prod-kingrt-do-not-push-to-github`. They were
therefore intentionally left in place under the contained-HEAD rule.

| Branch | Head | Clean |
| --- | --- | --- |
| `local/iam-e2e-abuse-logout-login-switch-proof-3` | `29620bd49b14bf52cb83e56d599e959f69f15a5f` | yes |
| `local/iam-e2e-audit-alias-followup-proof-3` | `9c80b10189c63c82cadd0c53bdc7d2554bc8e652` | yes |
| `local/iam-e2e-audit-event-compat-proof-3` | `daf6277d64b8caf554b03185e0b831d12afc5194` | yes |
| `local/iam-e2e-deleted-ended-disabled-followup-proof-3` | `c1716ddb5553a658c0f7d8094005892d991baec5` | yes |
| `local/iam-e2e-duplicate-abuse-device-browser-proof-3` | `2cd67944d703767871327c64df89f0d4005fcddc` | yes |
| `local/iam-e2e-final-sprint-checkbox-proof-3` | `70c5e22ec8311c0ed0330e1e630f85ce45abf06d` | yes |
| `local/iam-e2e-final-static-gate-proof-3` | `a501a5ef5d9635cace6510ed97b19fa672ebc752` | yes |
| `local/iam-e2e-guest-list-revocation-proof-3` | `cc020a0d02675661457b75ecab9859428bb62b8c` | yes |
| `local/iam-e2e-invite-registered-logged-out-proof-3` | `f1601b97cb99b1914a494f013e49af41eea2ca01` | yes |
| `local/iam-e2e-owner-transfer-lifecycle-proof-3` | `32a4df25a8d9b479e4fb49888f14f10a17308951` | yes |
| `local/iam-e2e-public-copy-followup-proof-3` | `91d8a4fd1b8a38e2fda5c9f5dcbeadf8f20dd9a1` | yes |
| `local/iam-e2e-registered-invitee-final-proof-3` | `e62ddc4e3b282129c4931dc5971760dfa781c83c` | yes |
| `local/iam-e2e-registered-invitee-logged-in-proof-3` | `a2682e8461b459bc3e6161f409b966d33f846221` | yes |
| `local/iam-e2e-remaining-deleted-disabled-user-proof-3` | `fbe5e2fd96b6e32941fa87a425196de1534a7c49` | yes |
| `local/iam-e2e-remaining-sprint-gaps-proof-3` | `aed766590f320fa42f21d2d00f3589e179951f05` | yes |
| `local/iam-e2e-review-abuse-cross-browser-proof-3` | `0e02e60542734f5221b1bd85fee7154e002e5077` | yes |
| `local/iam-e2e-review-warning-modal-policy-proof-3` | `bdd29ffd2bc2a7ba9cbec4711dfc043931639044` | yes |
| `local/iam-e2e-seed-matrix-copy-proof-3` | `e2b032d607fe9ce6ed14ec73730b980262990968` | yes |
| `local/iam-e2e-system-admin-deleted-ended-proof-3` | `4cabdf6b06b3efa6adcf658e9031bb24f9a8cd0e` | yes |

## Dirty IAM Worktrees Left Untouched

The broader IAM dirty scan found two dirty worktrees. Both were left untouched as
user work:

| Branch | Worktree | Dirty status lines |
| --- | --- | --- |
| `codex/iam-duplicate-cleanup-reaudit-20260509` | `/home/jochen/projects/king.site/worktrees/codex-iam-duplicate-cleanup-reaudit-20260509` | 24 |
| `codex/iam-call-access-e2e-foundation` | `/home/jochen/projects/king.site/worktrees/king-domain-registry` | 4 |

## Cleanup Result

`agent/iam-s3-17-proof-wiring` was merged into
`prod-kingrt-do-not-push-to-github`, verified clean, confirmed as a contained
HEAD, and removed with its worktree. The remaining checked-out Sprint 03 worker
is this cleanup-evidence branch; it is eligible for the same removal after its
manager merge.

All dirty IAM worktrees listed above remain untouched as user work.
