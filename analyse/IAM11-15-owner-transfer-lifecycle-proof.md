# IAM11-15 Owner-Transfer Lifecycle Proof

Scope:
- Backend lifecycle contract only.
- No Background, Gossip, SFU, MediaSecurity, or BTGF changes.
- No push.

Proof anchor:
- `demo/video-chat/backend-king-php/tests/call-owner-transfer-lifecycle-contract.php`
- Wrapper: `demo/video-chat/backend-king-php/tests/call-owner-transfer-lifecycle-contract.sh`

What the contract proves:
- A normal call owner can transfer ownership to another internal participant.
- The transfer leaves exactly one internal `owner` participant row.
- The new owner can administer the call after transfer by updating call settings.
- The new owner can moderate after transfer by admitting a lobby participant.
- The old non-admin owner cannot administer the call after transfer.
- The old non-admin owner cannot transfer ownership back to themself.
- The old non-admin owner cannot moderate lobby state after transfer.
- A cancelled call remains immutable after transfer for both call updates and owner-transfer attempts.
- An ended call remains immutable after transfer for both call updates and owner-transfer attempts.
- A deleted call remains unavailable after transfer for both call updates and owner-transfer attempts.

Runtime behavior pinned:
- `videochat_update_call_participant_role()` now rejects owner/participant role mutations when the call is `cancelled` or `ended`, matching the existing call-update immutable-state contract.
- Hard-deleted calls still resolve as `not_found`.
