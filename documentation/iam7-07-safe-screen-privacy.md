# IAM7-07 Safe-Screen Privacy

Scope:
- Extracted the current value from `local/iam-e2e-call-access-safe-screen-final`
  without merging the historical branch wholesale.
- The branch remains source evidence only because it carries broad stale call-app,
  background, SFU, gossip, deploy, and old IAM churn outside this ticket.

Proof:
- Invalid, expired, terminal, disabled-target, and wrong-account call-access
  failures render code-driven safe screens.
- Denied responses do not expose raw access ids, session ids, denied tokens,
  SDP/ICE/TURN/media sentinels, foreign user names/emails, call ids, room ids,
  call titles, or call-app launch values.
- Denied paths do not start or persist call-access sessions.

Verification:
- `node tests/contract/call-access-safe-screen-final-contract.mjs`
- `node tests/contract/call-access-link-privacy-contract.mjs`
- `node tests/contract/call-access-mismatch-no-leak-states-contract.mjs`
- `node tests/contract/call-access-strong-mismatch-privacy-contract.mjs`
- `php -l demo/video-chat/backend-king-php/tests/call-access-safe-screen-privacy-contract.php`
- `bash -n demo/video-chat/backend-king-php/tests/call-access-safe-screen-privacy-contract.sh`
- `IAM_SQLITE_CONTRACTS="call-access-safe-screen-privacy-contract.sh call-access-privacy-contract.sh call-access-strong-mismatch-privacy-contract.sh call-access-session-route-guard-contract.sh" demo/video-chat/backend-king-php/tests/iam-call-access-sqlite-runtime-proof.sh`
- `PLAYWRIGHT_IAM_CALL_ACCESS_ARTIFACTS=1 npx playwright test tests/e2e/call-access-join.spec.js --grep "stale and denied call-access links render safe screens without private payload data" --workers=1`
