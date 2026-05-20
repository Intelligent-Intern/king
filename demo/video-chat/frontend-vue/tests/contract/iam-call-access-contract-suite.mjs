import { spawnSync } from 'node:child_process';
import path from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const frontendRoot = path.resolve(__dirname, '../..');

export const IAM_CALL_ACCESS_CONTRACT_COMMANDS = Object.freeze([
  'node tests/contract/iam-call-access-ci-wire-contract.mjs',
  'node tests/contract/iam-sprint-03-inventory-contract.mjs',
  'node tests/contract/iam-sprint-04-focused-wire-contract.mjs',
  'node tests/contract/call-access-ci-artifacts-contract.mjs',
  'node tests/contract/iam-local-run-docs-contract.mjs',
  'node tests/contract/call-access-forged-identifiers-contract.mjs',
  'node tests/contract/call-access-tampered-verified-context-contract.mjs',
  'node tests/contract/call-access-duplicate-device-browser-contract.mjs',
  'node tests/contract/call-access-logout-login-switch-contract.mjs',
  'node tests/contract/call-access-logout-switch-extract-contract.mjs',
  'node tests/contract/call-access-mismatch-no-leak-states-contract.mjs',
  'node tests/contract/call-access-anonymous-guest-manipulation-contract.mjs',
  'node tests/contract/call-access-temp-call-link-boundaries-contract.mjs',
  'node tests/contract/call-access-disabled-links-fail-closed-contract.mjs',
  'node tests/contract/call-access-kicked-rejoin-denial-contract.mjs',
  'node tests/contract/call-access-permission-change-active-call-contract.mjs',
  'node tests/contract/call-access-calendar-invite-join-contract.mjs',
  'node tests/contract/call-access-registered-logged-out-handoff-contract.mjs',
  'node tests/contract/call-access-registered-logged-in-invitee-contract.mjs',
  'node tests/contract/call-access-registered-invitee-extract-contract.mjs',
  'node tests/contract/call-access-personalized-temp-reuse-contract.mjs',
  'node tests/contract/call-access-invite-invalidation-terminal-contract.mjs',
  'node tests/contract/call-access-link-invalidation-active-state-contract.mjs',
  'node tests/contract/call-access-anonymous-disabled-link-contract.mjs',
  'node tests/contract/call-access-duplicate-invite-replay-contract.mjs',
  'node tests/contract/call-access-owner-transfer-main-contract.mjs',
  'node tests/contract/call-access-owner-transfer-temp-moderator-extract-contract.mjs',
  'node tests/contract/owner-transfer-lifecycle-contract.mjs',
  'node tests/contract/call-access-removed-members-contract.mjs',
  'node tests/contract/call-access-terminal-browser-flows-contract.mjs',
  'node tests/contract/call-access-stale-role-org-switch-contract.mjs',
  'node tests/contract/call-access-audit-event-compatibility-contract.mjs',
  'node tests/contract/iam-call-access-audit-events-contract.mjs',
  'node tests/contract/call-access-email-safe-texts-dispatch-audit-contract.mjs',
  'node tests/contract/call-access-verified-context-ui-contract.mjs',
  'node tests/contract/call-access-identity-mismatch-review-flow-contract.mjs',
  'node tests/contract/call-access-strong-mismatch-privacy-contract.mjs',
  'node tests/contract/call-access-strong-mismatch-audit-redaction-contract.mjs',
  'node tests/contract/call-access-guest-list-membership-docker-proof-contract.mjs',
  'node tests/contract/iam-guest-list-revocation-extraction-contract.mjs',
  'node tests/contract/call-access-link-privacy-contract.mjs',
  'node tests/contract/call-access-safe-screen-final-contract.mjs',
  'node tests/contract/iam-call-access-e2e-foundation-contract.mjs',
  'node tests/contract/call-access-direct-join-rights-contract.mjs',
  'node tests/contract/call-access-cross-org-contract.mjs',
  'node tests/contract/call-access-cross-org-foreign-join-contract.mjs',
  'node tests/contract/call-access-edge-error-matrix-contract.mjs',
  'node tests/contract/call-access-terminal-states-contract.mjs',
  'node tests/contract/iam9-12-deleted-ended-hardening-contract.mjs',
  'node tests/contract/call-access-admission-boundaries-contract.mjs',
  'node tests/contract/call-access-lobby-concurrency-contract.mjs',
  'node tests/contract/call-access-duplicate-abuse-contract.mjs',
  'node tests/contract/call-access-account-isolation-contract.mjs',
  'node tests/contract/call-access-audit-redaction-contract.mjs',
  'node tests/contract/call-access-callapp-revocation-contract.mjs',
  'node tests/contract/call-access-route-guard-ui-contract.mjs',
  'node tests/contract/call-access-realtime-scope-contract.mjs',
  'node tests/contract/call-access-authorized-rejoin-extract-contract.mjs',
  'node tests/contract/iam9-17-email-confirmation-race-contract.mjs',
  'node tests/contract/iam-ci-artifacts-contract.mjs',
  '../backend-king-php/tests/call-access-anonymous-temp-rights-docker-proof.sh',
  '../backend-king-php/tests/call-access-edge-error-matrix-contract.sh',
  '../backend-king-php/tests/call-access-terminal-join-contract.sh',
  '../backend-king-php/tests/call-access-deleted-ended-hardening-contract.sh',
  '../backend-king-php/tests/call-guest-list-direct-join-contract.sh',
  '../backend-king-php/tests/call-access-cross-org-contract.sh',
  '../backend-king-php/tests/realtime-lobby-concurrency-contract.sh',
  '../backend-king-php/tests/call-access-membership-removal-contract.sh',
  '../backend-king-php/tests/call-access-stale-organization-role-contract.sh',
  '../backend-king-php/tests/call-access-anonymous-disabled-link-contract.sh',
  '../backend-king-php/tests/iam-call-access-sqlite-runtime-proof.sh',
  '../backend-king-php/tests/iam-backend-docker-runtime-proof-wrapper.sh',
  'node tests/contract/call-access-foreign-link-review-audit-contract.mjs',
  'node tests/contract/call-access-invalid-expired-anonymous-link-contract.mjs',
  'node tests/contract/iam-lobby-management-moderator-rights-contract.mjs',
  'node tests/contract/iam-org-removal-active-privilege-downgrade-contract.mjs',
  'node tests/contract/iam-owner-absence-realtime-sync-contract.mjs',
  '../backend-king-php/tests/call-access-owner-absence-realtime-sync-contract.sh',
  'node tests/contract/call-access-duplicate-review-email-contract.mjs',
  'node tests/contract/iam-king-participants-owner-timeout-contract.mjs',
  'node tests/contract/iam-lobby-state-cleanup-proof-contract.mjs',
  'node tests/contract/iam-lobby-concurrency-remaining-contract.mjs',
  'node tests/contract/iam-lobby-timeout-consistency-contract.mjs',
  '../backend-king-php/tests/iam-core-org-session-journey-contract.sh',
  '../backend-king-php/tests/call-access-invited-user-org-removal-contract.sh',
  '../backend-king-php/tests/call-access-membership-stale-invite-rights-contract.sh',
  '../backend-king-php/tests/call-access-email-confirmation-contract.sh',
  '../backend-king-php/tests/call-access-anonymous-logged-in-rights-contract.sh',
  '../backend-king-php/tests/call-access-anonymous-lobby-contract.sh',
  '../backend-king-php/tests/realtime-lobby-timeout-consistency-contract.sh',
  '../backend-king-php/tests/realtime-lobby-state-cleanup-contract.sh',
  '../backend-king-php/tests/call-access-anonymous-temp-rights-contract.sh',
]);

export function iamCallAccessContractCommandText() {
  return IAM_CALL_ACCESS_CONTRACT_COMMANDS.join(' && ');
}

export function runIamCallAccessContractSuite() {
  for (const command of IAM_CALL_ACCESS_CONTRACT_COMMANDS) {
    const result = spawnSync(command, {
      cwd: frontendRoot,
      env: process.env,
      shell: true,
      stdio: 'inherit',
    });

    if (result.error) {
      throw result.error;
    }
    if (result.signal) {
      process.stderr.write(`[iam-call-access-contract-suite] ${command} terminated by ${result.signal}\n`);
      process.exit(1);
    }
    if (result.status !== 0) {
      process.exit(result.status ?? 1);
    }
  }
}

const invokedPath = process.argv[1] ? pathToFileURL(path.resolve(process.argv[1])).href : '';
if (import.meta.url === invokedPath) {
  runIamCallAccessContractSuite();
}
