# IAM7-17 Lobby Management Moderator Rights

`local/iam-e2e-lobby-management-moderator-rights` was inspected at
`98c4b1d545b779d009da295b67af6d22c9d4b943` as historical source evidence, but
it was not merged wholesale. The current extraction keeps only the lobby
management moderator-rights proof that fits the Sprint 07 integration baseline
`1d0f11afa375b7c538f13c5d2075366104a60272`.

The current contract separates lobby management from owner/admin management:
owners, organization admins, system admins, and call moderators can perform
server-authorized lobby admission and rejection actions, while normal users and
guests cannot self-admit or view other queued users. Temporary moderators keep
the `moderator` effective call role for lobby actions, but they do not inherit
owner transfer or owner/admin-only privileges.

Runtime authority now fails closed at two boundaries. Participant-row moderator
authority requires a non-terminal participant state, so declined or cancelled
rows preserve audit role data without granting active lobby authority. The lower
lobby state gate accepts only server-derived `can_moderate_call` or global
admin context, so forged `raw_role` or `call_role` frames are ignored.

Verification targets:
- `call-access-anonymous-lobby-contract.php`
- `call-temporary-moderator-contract.php`
- `realtime-lobby-security-contract.php`
- `iam-lobby-management-moderator-rights-contract.mjs`
- `iam-call-access-sqlite-runtime-proof.sh`
