# IAM11-17 Call-Access Edge Proof

Scope:
- Focused backend contract and analysis only.
- Covers temporary-user kick/rejoin, disabled-user session revocation, and reschedule stale-link safety.
- No Background, Gossip, SFU, MediaSecurity, or BTGF files touched.
- No push.

Proof anchor:
- `demo/video-chat/backend-king-php/tests/iam11-17-call-access-edge-proof-contract.php`
- Wrapper: `demo/video-chat/backend-king-php/tests/iam11-17-call-access-edge-proof-contract.sh`

Local branch evidence consulted:
- `local/iam-e2e-temp-user-kick-rejoin` / `453ee854` proved kicked temporary guests must lose direct call-room admission and require renewed lobby approval.
- `local/iam-e2e-disabled-user-session-revocation` / `5a3fb5c8` was implementation-heavy; IAM11-17 keeps this lane to focused revocation proof instead of importing shared runtime changes.
- `local/iam-e2e-reschedule-stale-link-safety` / `4f8ebbad` proved reschedule must invalidate stale links, revoke old access sessions, and require fresh post-reschedule links.

What the contract is intended to prove:
- Anonymous temporary guests enter the waiting room before approval.
- Owner approval changes the temporary guest call participant state to `allowed` and enables direct room resolution.
- Owner kick normalizes to `lobby/remove`, clears the previous `allowed` admission back to `invited`, and prevents direct rejoin.
- A kicked temporary guest can request renewed approval, but only renewed owner approval restores direct room entry.
- A registered call-access session authenticates and resolves into the room before account deactivation.
- Admin deactivation disables the registered user and stamps `revoked_at` on the active call-access session.
- The disabled user's old session fails through explicit `revoked_session` and cannot continue to call-room resolution.
- Reschedule invalidates the old open link, revokes the old access session, disables the old temporary guest, and denies late stale-link session issuance.
- A newly issued open link after reschedule allocates a fresh temporary guest and binds to the current call.

Safety notes:
- The proof is additive and does not redefine the call-access contract downward.
- The disabled-user case intentionally asserts session revocation metadata, not just inactive-user auth rejection.
- The reschedule case asserts both stale-link privacy behavior (`not_found`) and absence of persisted late sessions.

Current Docker PHP result:
- Command:
  `docker run --rm -v "$PWD":/workspace -w /workspace php:8.4-cli-trixie php demo/video-chat/backend-king-php/tests/iam11-17-call-access-edge-proof-contract.php`
- Result:
  `[iam11-17-call-access-edge-proof-contract] FAIL: temp guest should wait before approval`
- First failing assertion:
  `demo/video-chat/backend-king-php/tests/iam11-17-call-access-edge-proof-contract.php` checks the initial temporary guest room resolution before lobby approval.
