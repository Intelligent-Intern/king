# IAM11-17 Call-Access Edge Proof

Scope:
- Focused backend/runtime proof for temporary-user kick/rejoin, disabled-user
  revocation, and reschedule stale-link safety.
- No Background, Gossip, SFU, MediaSecurity, BTGF, push, DNS, certbot, or
  production deploy work.

Runtime changes:
- Anonymous open-link temporary guests are inserted as `invited`, not `allowed`,
  so their first room resolution remains the waiting room until owner approval.
- `lobby/kick` persists the affected participant back to `invited`; default
  `lobby/remove` remains revoked as `cancelled`, and `lobby/reject` keeps the
  existing reject-state helper.
- Reschedule uses the existing central `call_lifecycle.php` implementation:
  temporary guests are invalidated, old call-access sessions are revoked, old
  links are deleted, lobby state is reset, and presence is cleared.

Proof anchors:
- `demo/video-chat/backend-king-php/tests/iam11-17-call-access-edge-proof-contract.php`
- `demo/video-chat/backend-king-php/tests/iam11-17-call-access-edge-proof-contract.sh`
- `demo/video-chat/backend-king-php/tests/iam-call-access-sqlite-runtime-proof.sh`

Verified locally:
- `docker run --rm -v "$PWD":/workspace -w /workspace php:8.4-cli-trixie bash demo/video-chat/backend-king-php/tests/iam11-17-call-access-edge-proof-contract.sh`
- `docker run --rm -v "$PWD":/workspace -w /workspace php:8.4-cli-trixie bash demo/video-chat/backend-king-php/tests/call-lifecycle-contract.sh`
- `npm run test:contract:iam-call-access`

Result:
- IAM11-17 focused Docker proof passed.
- The aggregate IAM/call-access contract gate passed with Docker fallback for
  host-missing `pdo_sqlite`.
