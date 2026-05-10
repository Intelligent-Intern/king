# IAM4-14 System Admin Terminal Proof Extraction Evidence

Source reviewed:

- Branch: `local/iam-e2e-system-admin-deleted-ended-proof-3`
- Worktree: `/home/jochen/projects/king.site/worktrees/iam-e2e-system-admin-deleted-ended-proof-3`
- Tip: `4cabdf6b06b3efa6adcf658e9031bb24f9a8cd0e`

The source branch tip only updates `SPRINT.md`. Its recorded proof value is that a Docker PHP 8.4 run of the old
`call-access-deleted-ended-disabled-join-contract.php` blocked system-admin normal direct decisions and
`/api/calls/resolve/{id}` for ended and deleted calls, kept deleted calls as safe `not_found`, returned conflict for ended
personalized join/session issuance before session replacement, and avoided leaking private call titles, participant emails,
or replacement sessions in those denials.

No code was ported from the source branch. The current Sprint 03/04 IAM coverage already contains the proof value without
bringing over stale broad branch changes.

Current coverage map:

- `demo/video-chat/frontend-vue/tests/contract/call-access-terminal-states-contract.mjs` pins
  `direct_join_system_admin_alpha_ended_denied`, proving a system admin does not bypass an ended call. It also pins disabled
  and deleted terminal call behavior, terminal resolve/call-fetch status expectations, and the absence of private call
  payloads.
- `demo/video-chat/frontend-vue/tests/contract/call-access-terminal-browser-flows-contract.mjs` requires the seed-matrix
  browser proof to exercise `direct_join_system_admin_alpha_ended_denied`,
  `direct_join_alpha_owner_alpha_disabled_denied`, and `direct_join_alpha_owner_alpha_deleted_hidden`, and verifies router,
  session recovery, deleted/disabled-user, and terminal-call redaction paths.
- `demo/video-chat/frontend-vue/tests/contract/call-access-invite-invalidation-terminal-contract.mjs` and
  `demo/video-chat/frontend-vue/tests/contract/call-access-disabled-links-fail-closed-contract.mjs` cover terminal public
  join/session denial surfaces so stale invite links do not mint replacement sessions or expose private call payloads.
- `demo/video-chat/backend-king-php/tests/call-access-terminal-join-contract.php`, added by the IAM4-06 extraction, is the
  current backend runtime proof for terminal joins. It verifies disabled registered users fail before direct admission,
  deleted registered user ids fail direct admission, ended personal/open links return safe conflict without payload/session
  data, and deleted personal/open links remain safe `not_found` without payload data.
- `documentation/iam-sprint-04-deleted-disabled-extract-evidence.md` records the IAM4-06 extraction from this same source
  family and states that backend runtime terminal rejection happens before owner, participant, system-admin, or free-for-all
  role grants. That covers the source branch's system-admin terminal-bypass concern without needing a duplicate deleted-call
  system-admin seed row.

Conclusion: `local/iam-e2e-system-admin-deleted-ended-proof-3` is superseded by current IAM4-06 and terminal-state coverage.
The source branch has no remaining unique code or fixture value to port for IAM4-14.
