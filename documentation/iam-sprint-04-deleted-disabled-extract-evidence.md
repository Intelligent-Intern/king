# IAM4-06 Deleted/Disabled Proof Extraction Evidence

Source branches reviewed:

- `local/iam-e2e-deleted-ended-disabled-followup-proof-3`
- `local/iam-e2e-remaining-deleted-disabled-user-proof-3`
- `local/iam-e2e-system-admin-deleted-ended-proof-3`
- Related checked-out branches `local/iam-e2e-deleted-ended-disabled-join` and `local/iam-e2e-disabled-anonymous-links`

Current Sprint 03 coverage already supersedes the frontend seed-matrix proof for terminal call states:

- `call-access-terminal-states-contract.mjs` pins ended, disabled, and deleted direct-join scenarios.
- `call-access-invite-invalidation-terminal-contract.mjs` pins public join/session terminal UI redaction.
- `call-access-disabled-links-fail-closed-contract.mjs` pins disabled open/personal links failing before session and lobby side effects.

Unique proof-3 value extracted here:

- Backend runtime now rejects inactive registered users before call role decisions.
- Backend runtime now rejects ended/disabled calls before owner, participant, system-admin, or free-for-all role grants.
- Public personalized links bound to a disabled registered user id fail as safe `not_found` instead of falling through to external guest behavior.
- A focused Docker-runnable backend proof, `call-access-terminal-join-contract.php`, covers disabled registered users, deleted user-id direct decisions, ended personal/open links, and deleted personal/open links with redacted denial payloads.

Not ported:

- Broad proof-3 branch rewrites, deleted files, unrelated E2E specs, call-app changes, realtime/media/background/SFU changes, and sprint metadata.
- The hard-deleted registered-user public-link assertion from `call-access-terminal-join-contract.php` in `local/iam-e2e-remaining-deleted-disabled-user-proof-3`: current SQLite schema uses `ON DELETE SET NULL` for `call_access_links.participant_user_id`, which makes a direct SQL-deleted registered-user link indistinguishable from an external email-only personalized link after deletion. The enforceable current proof covers disabled registered users and deleted user ids at the direct-decision boundary, and deleted calls at both personal and open-link boundaries.
