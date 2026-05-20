import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

import { iamCallAccessContractSuiteText } from './helpers/iamCallAccessSuiteCoverage.mjs';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const frontendRoot = path.resolve(__dirname, '../..');
const repoRoot = path.resolve(frontendRoot, '../../..');

function read(relativePath) {
  return fs.readFileSync(path.join(repoRoot, relativePath), 'utf8');
}

const confirmationHelper = read('demo/video-chat/backend-king-php/domain/calls/call_access_account_confirmation.php');
const emailContract = read('demo/video-chat/backend-king-php/tests/call-access-email-confirmation-contract.php');
const packageJson = JSON.parse(read('demo/video-chat/frontend-vue/package.json'));
const ciGate = read('demo/video-chat/scripts/iam-call-access-ci-gate.sh');

const sendStart = confirmationHelper.indexOf('function videochat_send_call_access_account_update_confirmation_mail');
const sendEnd = confirmationHelper.indexOf('function videochat_call_access_account_confirmation_record_email_dispatch_failed');
assert.ok(sendStart >= 0 && sendEnd > sendStart, 'confirmation helper must expose the safe mail sender');
const sendFunction = confirmationHelper.slice(sendStart, sendEnd);

assert.match(
  sendFunction,
  /Hello \{\$displayName\}[\s\S]*\{\$confirmationUrl\}[\s\S]*\{\$expiresAtText\}/,
  'confirmation email text must contain only recipient greeting, secure URL, and expiry metadata',
);
for (const forbidden of ['accessLink', 'accessId', 'call_id', 'session_id', 'pendingPayload', 'manualData']) {
  assert.ok(!sendFunction.includes(forbidden), `confirmation email text must not use ${forbidden}`);
}

assert.match(
  confirmationHelper,
  /token_fingerprint TEXT NOT NULL UNIQUE[\s\S]*INSERT INTO call_access_account_update_confirmations[\s\S]*:token_fingerprint/,
  'confirmation storage must persist token fingerprints rather than raw tokens',
);
assert.match(
  confirmationHelper,
  /file_put_contents\(\$outboxPath[\s\S]*'queued' => \$queued/,
  'mail dispatch must report whether the fallback outbox write was actually queued',
);
assert.match(
  confirmationHelper,
  /videochat_call_access_account_confirmation_delivery_accepted\(\$delivery\)[\s\S]*email_delivery_failed/s,
  'account-update confirmation must fail closed when mail dispatch is not sent or queued',
);
assert.match(
  confirmationHelper,
  /DELETE FROM call_access_account_update_confirmations WHERE token_fingerprint = :token_fingerprint/,
  'mail dispatch failures must remove the pending confirmation row without using raw token row ids',
);
assert.match(
  confirmationHelper,
  /call_access_account_update_confirmation_email_dispatched[\s\S]*delivery_succeeded[\s\S]*delivery_queued/s,
  'successful dispatch audit must record safe delivery status without raw identifiers',
);
assert.match(
  confirmationHelper,
  /call_access_account_update_confirmation_email_dispatch_failed[\s\S]*delivery_succeeded' => false[\s\S]*delivery_queued' => false/s,
  'failed dispatch audit must record safe failure status without raw identifiers',
);
assert.match(
  confirmationHelper,
  /videochat_call_access_account_confirmation_record_account_data_changed\(\$pdo, \$row, \$userId, \['display_name'\], \$sessionFingerprint\)/,
  'confirmed account updates must emit an explicit account-data-change audit event',
);
for (const omissionFlag of [
  'confirmation_identifier_logged',
  'raw_link_identifier_logged',
  'recipient_email_logged',
  'session_identifier_logged',
]) {
  assert.match(confirmationHelper, new RegExp(`${omissionFlag}' => false`), `audit helper must pin ${omissionFlag}=false`);
}

for (const proofText of [
  'secure confirmation link must not expose raw call-access id',
  'outbox delivery should be recorded as queued',
  'confirmation email must describe link expiry',
  'confirmation email must be addressed to current account',
]) {
  assert.ok(emailContract.includes(proofText), `backend email contract must prove safe email text: ${proofText}`);
}
assert.match(
  emailContract,
  /mail delivery failure should reject the confirmation request[\s\S]*mail delivery failure must leave account data unchanged[\s\S]*mail delivery failure must not leave a confirmable pending payload/s,
  'backend email contract must prove mail dispatch failure leaves account data unchanged and no token confirmable',
);
for (const eventType of [
  'call_access_account_update_confirmation_email_dispatched',
  'call_access_account_update_confirmation_email_dispatch_failed',
  'call_access_account_update_confirmed',
  'call_access_account_update_confirmation_failed',
  'call_access_account_data_changed',
]) {
  assert.ok(emailContract.includes(eventType), `backend email contract must assert ${eventType}`);
}

const contractPath = 'tests/contract/call-access-email-safe-texts-dispatch-audit-contract.mjs';
assert.ok(iamCallAccessContractSuiteText.includes(contractPath), 'IAM contract helper must include the email-safe dispatch audit contract');
assert.ok(ciGate.includes(contractPath), 'IAM CI gate must include the email-safe dispatch audit contract');

process.stdout.write('[call-access-email-safe-texts-dispatch-audit-contract] PASS\n');
