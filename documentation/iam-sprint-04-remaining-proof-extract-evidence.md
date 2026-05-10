# IAM4-12 Remaining Proof Extraction Evidence

Source branches reviewed:

- `local/iam-e2e-remaining-deleted-disabled-user-proof-3`
- `local/iam-e2e-remaining-sprint-gaps-proof-3`

Result: no code or test behavior was ported. The remaining proof value is already covered, and in several places the current Sprint 04 branch is stronger than the proof-3 sources.

## Deleted/Disabled User Proof

`local/iam-e2e-remaining-deleted-disabled-user-proof-3` carried the `call-access-terminal-join-contract` idea. IAM4-06 already extracted the enforceable value into current source:

- `demo/video-chat/backend-king-php/tests/call-access-terminal-join-contract.php`
- `demo/video-chat/backend-king-php/tests/call-access-terminal-join-contract.sh`
- `demo/video-chat/backend-king-php/tests/iam-call-access-sqlite-runtime-proof.sh`
- `documentation/iam-sprint-04-deleted-disabled-extract-evidence.md`

Current coverage proves disabled registered users fail closed, deleted user ids fail direct decisions, ended/deleted calls deny personal and open links with redacted payloads, and the proof is wired into the IAM SQLite runtime aggregate.

The hard-deleted public-link assertion remains intentionally unported for the reason documented in IAM4-06: SQLite `ON DELETE SET NULL` erases the bound user id, making that path indistinguishable from a valid external email-only personalized link after direct SQL deletion.

## Remaining Sprint Gaps Proof

`local/iam-e2e-remaining-sprint-gaps-proof-3` mostly reduced current coverage. Its guest-list direct-join proof is superseded by current `demo/video-chat/backend-king-php/tests/call-guest-list-direct-join-contract.php`, which already covers the source branch baseline plus stronger current assertions:

- normal guest-list allow and non-listed denial
- guest-list scoping to a single call
- declined guest-list entries denied as inactive
- external participant rows do not count as internal guest-list grants
- tenant-scoped guest-list allows and cross-tenant hidden denial without leaking the entry

The current IAM SQLite runtime aggregate also includes `call-guest-list-direct-join-contract.sh`, so the proof runs through the deterministic Docker fallback when host PHP lacks `pdo_sqlite`.

## Non-ported Source Changes

The proof-3 branch diffs also included broad deletion of current frontend contracts, documentation, docker wrappers, and package-era cleanup. Those changes were not ported because they would remove current Sprint 03/04 coverage rather than extract new IAM proof value.
