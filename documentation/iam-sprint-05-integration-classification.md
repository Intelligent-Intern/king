# IAM Sprint 05 Integration Branch Classification

Date: 2026-05-10

Worker: IAM5-02

Target branch: `agent/iam-s5-02-integration-classify`

Target worktree: `/home/jochen/projects/king.site/worktrees/iam-s5-02-integration-classify`

Source branch classified: `iam-e2e-integration`

Base branch: `prod-kingrt-do-not-push-to-github`

## Verdict

Classification: `cleanup anchor`.

`iam-e2e-integration` is not a merge candidate. It is also not simply
superseded evidence that can be deleted by this ticket. It is a broad historical
integration branch that still anchors many remaining Sprint 05 source families,
but the branch itself must not be merged wholesale into
`prod-kingrt-do-not-push-to-github`.

Use it as a comparison and cleanup anchor for later focused extraction lanes.
When a current IAM proof is still useful, extract it from the focused source
branch or focused file, not by merging this integration branch.

## Scope

- Evidence-only classification.
- No source code was copied from `iam-e2e-integration`.
- No branch, worktree, or cleanup operation was performed on
  `iam-e2e-integration`.
- Background, Gossip, SFU, MediaSecurity, BTGF, and their tests were not
  modified.
- `SPRINT.md`, `BACKLOG.md`, `package.json`, and shared CI wiring were not
  edited.

## Branch State

| Check | Result |
| --- | --- |
| Base HEAD | `prod-kingrt-do-not-push-to-github` at `7a593d46` |
| Source HEAD | `iam-e2e-integration` at `b5649e4c` |
| Merge base | `79f57cc8` |
| Source worktree status | Clean: `## iam-e2e-integration` |
| Source-only commits | 251 commits from `prod-kingrt-do-not-push-to-github..iam-e2e-integration` |
| Prod-only commits | 383 commits from `iam-e2e-integration..prod-kingrt-do-not-push-to-github` |
| Source HEAD contained by prod | No |
| Prod HEAD contained by source | No |

The source branch is therefore not eligible for contained-HEAD cleanup, and it
is not safe to treat the branch as already merged by ancestry.

## Diff Shape

`git diff --shortstat prod-kingrt-do-not-push-to-github..iam-e2e-integration`
reported:

```text
512 files changed, 53465 insertions(+), 37206 deletions(-)
```

Status counts:

| Status | Count |
| --- | ---: |
| Added | 163 |
| Modified | 209 |
| Deleted | 138 |
| Renamed | 2 |
| Total | 512 |

Representative path groups:

| Path group | Count | Classification |
| --- | ---: | --- |
| Backend IAM tests and wrappers | 116 | Source evidence for focused Sprint 05 extraction, not wholesale merge material. |
| Frontend IAM/call-access contracts | 77 | Mixed: some current value, many replaced by Sprint 03/04 focused contracts. |
| Frontend IAM/call-access E2E specs | 31 | Source evidence only; do not import the old E2E matrix as-is. |
| Backend IAM domain/http/support paths | 39 | Broad implementation history; compare per focused lane only. |
| Call App feature/package paths | 79 | Outside IAM5-02 merge scope except later Call App IAM boundary extraction. |
| Parked Background/Gossip/SFU/MediaSecurity paths | 39 | Merge blocker under current Sprint 05 boundaries. |
| Sprint 03/04 IAM evidence docs deleted by source branch | 14 | Merge blocker; current prod docs are newer evidence. |
| Top-level planning docs | 4 | Merge blocker; source has stale sprint state. |
| Other mixed paths | 113 | Too broad for safe classification merge. |

The source diff would delete current Sprint 03/04 evidence docs, rewrite active
sprint state, alter `package.json` and CI wiring, and touch parked media paths.
That shape alone rules out a direct merge.

## Sprint 03/04 Containment

Ancestry containment is false, but a large part of the old proof intent is
semantically contained by Sprint 03 and Sprint 04.

Already represented by current Sprint 03/04 evidence:

| Source area in `iam-e2e-integration` | Current prod containment |
| --- | --- |
| Foundation, focused IAM gate, route guard, direct join, cross-org, anonymous temporary rights, guest-list direct join, membership removal, and SQLite/runtime wrappers | Current `iam-call-access-e2e-foundation-contract.mjs`, `iam-call-access-ci-wire-contract.mjs`, backend wrappers, and Sprint 03 inventory evidence preserve the focused gate without the old branch matrix. |
| Registered invitee logged-in/logged-out, duplicate logout/login switch, terminal/deleted/disabled, guest-list revocation, audit alias compatibility, and proof-3 cleanup families | Sprint 04 extraction docs classify and wire focused replacements such as `call-access-registered-invitee-extract-contract.mjs`, `call-access-logout-switch-extract-contract.mjs`, `call-access-terminal-join-contract.sh`, and `iam-sprint-04-focused-wire-contract.mjs`. |
| Public not-found copy and low-risk proof-3 cleanup | Sprint 04 accepted focused public-copy extraction and retained proof-3 inventory rather than merging the historical branch. |
| Browser artifact redaction and final IAM proof wiring | Sprint 04 closed with current IAM CI wire matrix proof and artifact redaction guard. |

The current prod branch has 48 IAM/call-access frontend contracts with focused
Sprint 03/04 coverage. The source branch has 28 such frontend contracts, but 24
of those are branch-only historical proof files. The branch-only files are not
automatic merge candidates; they are source evidence for the active Sprint 05
checkboxes below.

## Current-Value Proof Still Worth Mining

These source commits and file families still carry proof value that Sprint 05
should evaluate in their own lanes. They are not integrated by this IAM5-02
ticket.

| Source evidence | Current handling |
| --- | --- |
| `d6197c02` and `1d31357f` authorized rejoin browser/backend proof | IAM5-04 extraction source. |
| `2b34babd`, `67a693b4`, `317757ce`, `37b13ece`, `f488d711` lobby state, audit, timeout, concurrency, and admission proof | IAM5-05/IAM5-06 extraction source. |
| `4f8159fd`, `3937d7da`, `0d3e9e04` duplicate review, light mismatch join audit, and anonymous-link system-admin proof | IAM5-07/IAM5-16 extraction source. |
| `6cd09066`, `bf396945`, `e9b02a81` cross-organization remaining and foreign/active-org proof | IAM5-08 extraction source. |
| `4e5a6f9c`, `927289e7`, `f2f82371`, `72dd4d81`, `755da3df` owner absence, owner timeout, realtime sync, and anonymous-link timeout invalidation proof | IAM5-09 extraction source. Current prod does not contain the branch `call-access-owner-timeout-contract.php` file. |
| `0539cf6d`, `ff00ed34`, `08c313ce`, `32a4df25` owner-transfer and temporary-moderator proof | IAM5-10 extraction source. |
| `f40b2ce9`, `1cc2e65c`, `61b2cc8a`, `cc020a0d` guest-list management, audit, harness, and revocation proof | IAM5-11 extraction source. |
| `c2f84b45`, `f36b2ccc`, `453ee854`, `f6748e36` temporary guest, temporary moderator, kicked temporary-user, and anonymous temporary-rights proof | IAM5-12 extraction source. |
| `393bef42`, `f2c702aa`, `a87b0ba8`, `29d94e72` account confirmation, reconciliation, expiry/race hardening, and safe dispatch/audit proof | IAM5-13 extraction source. |
| `b1b4b002`, `8b2a7fb7`, `4f8ebbad`, `79319d33` calendar invite, unregistered invitee, reschedule stale-link, and terminal main journey proof | IAM5-14 extraction source. |
| `dd21579f`, `a833db6f`, `4367d573` Call App entitlement revocation, launch-token reconnect validation, and whiteboard org-install proof | IAM5-15 extraction source only; broader Call App feature work remains out of IAM5-02 scope. |
| `434a3ec3`, `9b2bcc63`, `079f19a7`, `5f4a93c2` system-admin, King participant container, terminal admin, and forbidden-route proof | IAM5-16/IAM5-17 extraction source. |
| `bb4331ef`, `5101367b`, `47bf14a1` seed data hygiene, asset cache busting, and failure-artifact handling | IAM5-17 source if still useful against the current focused IAM gate. |

The strongest current rule remains: mine focused proof value, then prove it on
`prod-kingrt-do-not-push-to-github`; do not carry over stale branch wiring.

## Merge Risk

Wholesale merge risk is high because the branch would:

- Reintroduce stale `SPRINT.md` and `READYNESS_TRACKER.md` state.
- Delete current Sprint 03/04 IAM classification and extraction evidence docs.
- Modify `demo/video-chat/frontend-vue/package.json`, `.github/workflows/ci.yml`,
  release matrix metadata, and smoke scripts outside this ticket scope.
- Touch parked Background/Gossip/SFU/MediaSecurity files and tests.
- Delete Call App packages and diagnostics/operator-feedback files unrelated to
  focused IAM5-02 classification.
- Replace the current focused proof gate with a historical integration matrix
  whose source branches now need independent extraction decisions.

## Cleanup Rule

Do not delete `iam-e2e-integration` yet. It is not contained by prod and still
anchors source evidence for IAM5-04 through IAM5-17. After the focused Sprint 05
extraction lanes finish, IAM5-19 can recheck:

```sh
git merge-base --is-ancestor iam-e2e-integration prod-kingrt-do-not-push-to-github
git -C /home/jochen/projects/king.site/worktrees/iam-e2e-integration status --porcelain=v1 -uall
```

Only then should cleanup consider removal, and only under the clean-worktree and
contained-HEAD rules.

## Commands Used

```sh
git worktree add -b agent/iam-s5-02-integration-classify /home/jochen/projects/king.site/worktrees/iam-s5-02-integration-classify prod-kingrt-do-not-push-to-github
git -C /home/jochen/projects/king.site/worktrees/iam-e2e-integration status --short --branch
git merge-base prod-kingrt-do-not-push-to-github iam-e2e-integration
git rev-list --count prod-kingrt-do-not-push-to-github..iam-e2e-integration
git rev-list --count iam-e2e-integration..prod-kingrt-do-not-push-to-github
git merge-base --is-ancestor iam-e2e-integration prod-kingrt-do-not-push-to-github
git merge-base --is-ancestor prod-kingrt-do-not-push-to-github iam-e2e-integration
git diff --shortstat prod-kingrt-do-not-push-to-github..iam-e2e-integration
git diff --name-status prod-kingrt-do-not-push-to-github..iam-e2e-integration
git log --format='%h %s' --reverse prod-kingrt-do-not-push-to-github..iam-e2e-integration
```
