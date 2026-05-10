# IAM4-03 Proof-3 Inventory

Date: 2026-05-10

Scope: evidence/classification only. No branch or worktree cleanup was performed. Background, Gossip, SFU, MediaSecurity, and BTGF areas were not modified.

Base used for containment checks: local `prod-kingrt-do-not-push-to-github`.

## Method

- Enumerated `refs/heads/local/iam-e2e-*proof-3`.
- Classified a branch as non-contained when `git merge-base --is-ancestor <branch> prod-kingrt-do-not-push-to-github` returned non-zero.
- Mapped each branch to its worktree from `git worktree list --porcelain`.
- Checked each mapped worktree with `git status --porcelain=v1 -uall`.
- Ranked unique proof value against the current Sprint 03 proof set and contracts already present on prod.

All 19 matching `local/iam-e2e-*proof-3` branches are non-contained. All 19 mapped worktrees are clean at scan time.

## Ranking

### High Unique Value

| Rank | Branch | Head | Worktree | Evidence | Recommendation |
| --- | --- | --- | --- | --- | --- |
| 1 | `local/iam-e2e-public-copy-followup-proof-3` | `91d8a4fd` | `/home/jochen/projects/king.site/worktrees/iam-e2e-public-copy-followup-proof-3` | Tip changes preserve backend `call_access_not_found` codes in `JoinView.vue` before localization and pin exact "This call link does not exist." E2E copy. Current prod still overwrites failed join payloads with `call_access_validation_failed`, so this carries concrete unfused behavior. | Port focused fix/proof; highest unique value. |
| 2 | `local/iam-e2e-abuse-logout-login-switch-proof-3` | `29620bd4` | `/home/jochen/projects/king.site/worktrees/iam-e2e-abuse-logout-login-switch-proof-3` | Adds `call-access-duplicate-logout-login-switch.spec.js` and extends duplicate-review backend proof for same-browser logout/login switch review flags, no second-account session, no participant attach, no raw access/email audit leak. Sprint 03 covers login-switch fail-closed generally, but not this duplicate-review/audit variant. | Port as focused duplicate-review/login-switch contract or E2E if still desired. |
| 3 | `local/iam-e2e-seed-matrix-copy-proof-3` | `e2b032d6` | `/home/jochen/projects/king.site/worktrees/iam-e2e-seed-matrix-copy-proof-3` | Adds stable `call_access_not_found` copy assertions and broadens invalid-link E2E accepted copy. Partially overlapped by current localization strings, but supports the higher-ranked public-copy follow-up. | Keep as supporting evidence for rank 1; avoid direct merge without reconciling with current public-copy fix. |

### Medium Value, Mostly Mined By Sprint 03

| Rank | Branch | Head | Worktree | Evidence | Recommendation |
| --- | --- | --- | --- | --- | --- |
| 4 | `local/iam-e2e-duplicate-abuse-device-browser-proof-3` | `2cd67944` | `/home/jochen/projects/king.site/worktrees/iam-e2e-duplicate-abuse-device-browser-proof-3` | Adds parallel duplicate-device E2E and backend duplicate session-id conflict hardening. Current prod has `call-access-duplicate-device-browser-contract.mjs`, `call-access-duplicate-invite-replay-contract.mjs`, and duplicate-abuse contracts referencing this behavior. | Treat as mined; preserve until Sprint 03 merge audit confirms no missing E2E runtime gap. |
| 5 | `local/iam-e2e-audit-event-compat-proof-3` | `daf6277d` | `/home/jochen/projects/king.site/worktrees/iam-e2e-audit-event-compat-proof-3` | Updates audit event alias/canonicalization code and proof. Current prod has `call-access-audit-event-compatibility-contract.mjs` and strong audit redaction coverage. | Mined by Sprint 03; keep only for historical comparison. |
| 6 | `local/iam-e2e-audit-alias-followup-proof-3` | `9c80b101` | `/home/jochen/projects/king.site/worktrees/iam-e2e-audit-alias-followup-proof-3` | Follow-up contract assertions for canonical/redacted IAM audit aliases. Current Sprint 03 inventory already names it as IAM3-12 primary source. | Mined; no direct merge recommended. |
| 7 | `local/iam-e2e-registered-invitee-logged-in-proof-3` | `a2682e84` | `/home/jochen/projects/king.site/worktrees/iam-e2e-registered-invitee-logged-in-proof-3` | Adds backend logged-in invitee contract/wrapper and E2E join changes. Current prod has `call-access-registered-logged-in-invitee-contract.mjs` and route-guard coverage. | Mined; revisit only if a runtime PHP wrapper is still missing from CI expectations. |
| 8 | `local/iam-e2e-invite-registered-logged-out-proof-3` | `f1601b97` | `/home/jochen/projects/king.site/worktrees/iam-e2e-invite-registered-logged-out-proof-3` | Adds backend logged-out invitee contract/wrapper and CI gate entry. Current prod has `call-access-registered-logged-out-handoff-contract.mjs`. | Mined into frontend contract; manually check if backend wrapper value is still needed. |
| 9 | `local/iam-e2e-remaining-deleted-disabled-user-proof-3` | `fbe5e2fd` | `/home/jochen/projects/king.site/worktrees/iam-e2e-remaining-deleted-disabled-user-proof-3` | Adds `call-access-terminal-join-contract` and changes terminal access behavior. Current prod has `call-access-terminal-browser-flows-contract.mjs`, `call-access-terminal-states-contract.mjs`, and invalidation terminal contracts. | Mostly mined; use as fallback if terminal backend runtime coverage is questioned. |
| 10 | `local/iam-e2e-deleted-ended-disabled-followup-proof-3` | `c1716ddb` | `/home/jochen/projects/king.site/worktrees/iam-e2e-deleted-ended-disabled-followup-proof-3` | Extends deleted/ended/disabled join denials. Current prod has broader terminal browser-flow and terminal-state contracts. | Mined; low merge pressure. |
| 11 | `local/iam-e2e-system-admin-deleted-ended-proof-3` | `4cabdf6b` | `/home/jochen/projects/king.site/worktrees/iam-e2e-system-admin-deleted-ended-proof-3` | Documents system-admin terminal join proof. Current admission-boundary and terminal contracts cover system-admin boundaries and terminal safe states. | Mined; retain only as source evidence. |
| 12 | `local/iam-e2e-owner-transfer-lifecycle-proof-3` | `32a4df25` | `/home/jochen/projects/king.site/worktrees/iam-e2e-owner-transfer-lifecycle-proof-3` | Closes owner-transfer journey proof leaves. Current prod has `owner-transfer-lifecycle-contract.mjs` and `call-access-owner-transfer-main-contract.mjs`. | Mined; no direct merge recommended. |
| 13 | `local/iam-e2e-remaining-sprint-gaps-proof-3` | `aed76659` | `/home/jochen/projects/king.site/worktrees/iam-e2e-remaining-sprint-gaps-proof-3` | Tightens guest-list direct join contract. Current prod has direct-join rights, removed-members, and guest-list direct-join backend wrapper coverage. | Mined; compare only if guest-list direct-join runtime proof regresses. |
| 14 | `local/iam-e2e-review-warning-modal-policy-proof-3` | `bdd29ffd` | `/home/jochen/projects/king.site/worktrees/iam-e2e-review-warning-modal-policy-proof-3` | Adds duplicate-review warning modal policy assertions. Current duplicate-abuse and duplicate-device contracts cover conflict privacy, but warning-modal UI policy may not be first-class. | Medium-low; consider extracting a small UI-policy assertion if product still exposes the warning modal. |

### Low Unique Value / Documentation Or Superseded

| Rank | Branch | Head | Worktree | Evidence | Recommendation |
| --- | --- | --- | --- | --- | --- |
| 15 | `local/iam-e2e-review-abuse-cross-browser-proof-3` | `0e02e605` | `/home/jochen/projects/king.site/worktrees/iam-e2e-review-abuse-cross-browser-proof-3` | Tip commit changes only `SPRINT.md`; current prod already has duplicate-abuse/cross-browser contracts. | Documentation-only; superseded for code value. |
| 16 | `local/iam-e2e-guest-list-revocation-proof-3` | `cc020a0d` | `/home/jochen/projects/king.site/worktrees/iam-e2e-guest-list-revocation-proof-3` | Tip commit changes only `SPRINT.md`; current prod has invite invalidation and removed-member guest-list coverage. | Documentation-only; superseded. |
| 17 | `local/iam-e2e-registered-invitee-final-proof-3` | `e62ddc4e` | `/home/jochen/projects/king.site/worktrees/iam-e2e-registered-invitee-final-proof-3` | Tip commit changes only `SPRINT.md`; logged-in/logged-out registered invitee proofs are already represented by current contracts. | Documentation-only; superseded. |
| 18 | `local/iam-e2e-final-sprint-checkbox-proof-3` | `70c5e22e` | `/home/jochen/projects/king.site/worktrees/iam-e2e-final-sprint-checkbox-proof-3` | Tip commit changes only `SPRINT.md`. | Superseded by manager-owned sprint status. |
| 19 | `local/iam-e2e-final-static-gate-proof-3` | `a501a5ef` | `/home/jochen/projects/king.site/worktrees/iam-e2e-final-static-gate-proof-3` | Merge-style/static-gate branch with broad mixed paths in tip stat, including `SPRINT.md`, `package.json`, and CI gate script. Current prod has focused IAM CI wire contracts and Sprint 03 proof wiring. | Manual only if CI-gate archaeology is needed; otherwise superseded/no direct merge. |

## Handling Notes

- Do not delete or reset any branch/worktree from this inventory automatically.
- The high-value public-copy branches are small enough to port manually, but should be reconciled against current localization contracts first.
- The high-value duplicate logout/login switch branch should not be wholesale merged because its branch diff carries a large historical Sprint 03 base; extract only the duplicate-review/audit-specific proof if needed.
- The medium group appears largely mined into current Sprint 03 contracts; keep these branches until final Sprint 03 containment/cleanup review finishes.
