# IAM7-15 Invalid/Expired Anonymous Link Extraction

Source branch:
- `local/iam-e2e-invalid-anonymous-link-proof-20260509`

Classification:
- The historical branch was not merged wholesale. Its diff includes broad stale
  IAM integration, deleted current contracts, and parked Background/Gossip/SFU
  churn outside IAM7-15 scope.
- Current integration already had generic safe-screen and personalized-link
  expiry coverage, but lacked a focused runtime proof for open anonymous
  call-access links.

Extracted value:
- Added a backend SQLite proof for malformed anonymous ids, unknown anonymous
  UUIDs, and expired open anonymous links.
- Denied paths prove no session issuer call, no auth session, no call-access
  session, no temporary guest, no lobby/participant row, no `last_used_at`
  mutation, and no additional audit event.
- Denial payloads redact private call title/id, participant/owner data, raw
  access ids, guest names, and would-be session ids.

Verification anchor:
- `demo/video-chat/backend-king-php/tests/call-access-invalid-expired-anonymous-link-contract.sh`
- `demo/video-chat/frontend-vue/tests/contract/call-access-invalid-expired-anonymous-link-contract.mjs`
