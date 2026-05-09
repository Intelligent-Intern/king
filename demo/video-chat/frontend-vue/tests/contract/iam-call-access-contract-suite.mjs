import { spawnSync } from 'node:child_process';
import path from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const frontendRoot = path.resolve(__dirname, '../..');

export const IAM_CALL_ACCESS_CONTRACT_COMMANDS = Object.freeze([
  'node tests/contract/call-access-verified-context-ui-contract.mjs',
  'node tests/contract/call-access-strong-mismatch-privacy-contract.mjs',
  'node tests/contract/call-access-identity-mismatch-review-flow-contract.mjs',
  'node tests/contract/call-access-link-privacy-contract.mjs',
  'node tests/contract/call-access-privacy-foreign-data-contract.mjs',
  'node tests/contract/call-access-safe-screen-final-contract.mjs',
  'node tests/contract/call-access-multi-session-device-safety-contract.mjs',
  'node tests/contract/call-access-link-invalidation-durability-contract.mjs',
  'node tests/contract/call-access-security-manipulation-contract.mjs',
  'node tests/contract/call-access-parallel-account-tabs-contract.mjs',
  'node tests/contract/call-access-cross-org-foreign-join-contract.mjs',
  'node tests/contract/iam-cross-org-remaining-proof-contract.mjs',
  'node tests/contract/call-access-duplicate-review-email-contract.mjs',
  'node tests/contract/call-access-email-safe-texts-dispatch-audit-contract.mjs',
  'node tests/contract/call-access-edge-error-matrix-contract.mjs',
  'node tests/contract/iam-king-container-ci-contract.mjs',
  'node tests/contract/iam-king-participants-owner-timeout-contract.mjs',
  'node tests/contract/iam-call-access-e2e-foundation-contract.mjs',
  'node tests/contract/iam-system-admin-edge-cases-contract.mjs',
  'node tests/contract/iam-lobby-concurrency-remaining-contract.mjs',
  'node tests/contract/iam-lobby-timeout-consistency-contract.mjs',
  'node tests/contract/iam-lobby-management-moderator-rights-contract.mjs',
  'node tests/contract/iam-call-access-audit-events-contract.mjs',
  'node tests/contract/iam-guest-list-management-audit-proof-contract.mjs',
  'node tests/contract/iam-active-call-kick-contract.mjs',
  '../backend-king-php/tests/audit-call-access-privacy-minimization-contract.sh',
  '../scripts/king-participant-container-proof.sh',
  '../backend-king-php/tests/iam-core-org-session-journey-contract.sh',
  '../backend-king-php/tests/call-access-membership-removal-contract.sh',
  '../backend-king-php/tests/call-access-invited-user-org-removal-contract.sh',
  '../backend-king-php/tests/call-access-membership-stale-invite-rights-contract.sh',
  '../backend-king-php/tests/call-access-stale-organization-role-contract.sh',
  '../backend-king-php/tests/call-access-parallel-account-tabs-contract.sh',
  '../backend-king-php/tests/call-access-identity-mismatch-review-flow-contract.sh',
  '../backend-king-php/tests/call-access-duplicate-review-contract.sh',
  '../backend-king-php/tests/call-access-email-confirmation-contract.sh',
  '../backend-king-php/tests/call-access-edge-error-matrix-contract.sh',
  '../backend-king-php/tests/call-access-security-manipulation-contract.sh',
  '../backend-king-php/tests/call-access-anonymous-logged-in-rights-contract.sh',
  '../backend-king-php/tests/call-access-anonymous-disabled-link-contract.sh',
  '../backend-king-php/tests/call-access-anonymous-lobby-contract.sh',
  '../backend-king-php/tests/realtime-lobby-timeout-consistency-contract.sh',
  '../backend-king-php/tests/audit-call-access-events-contract.sh',
  'php ../backend-king-php/tests/system-admin-call-rights-contract.php',
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
