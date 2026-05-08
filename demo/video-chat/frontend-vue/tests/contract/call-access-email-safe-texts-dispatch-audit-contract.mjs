import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const frontendRoot = path.resolve(__dirname, '../..');
const repoRoot = path.resolve(frontendRoot, '../../..');

function read(relativePath) {
  return fs.readFileSync(path.join(repoRoot, relativePath), 'utf8');
}

const confirmationHelper = read('demo/video-chat/backend-king-php/domain/calls/call_access_account_confirmation.php');
const confirmationAuditHelper = read('demo/video-chat/backend-king-php/domain/calls/call_access_account_confirmation_audit.php');
const emailContract = read('demo/video-chat/backend-king-php/tests/call-access-email-confirmation-contract.php');
const packageJson = JSON.parse(read('demo/video-chat/frontend-vue/package.json'));
const ciGate = read('demo/video-chat/scripts/iam-call-access-ci-gate.sh');

const sendFunction = confirmationHelper.slice(
  confirmationHelper.indexOf('function videochat_send_call_access_account_update_confirmation_mail'),
  confirmationHelper.indexOf('function videochat_call_access_account_confirmation_rate_limit'),
);

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
  /file_put_contents\(\$outboxPath[\s\S]*'queued' => \$queued/,
  'mail dispatch must report whether the fallback outbox write was actually queued',
);
assert.match(
  confirmationHelper,
  /videochat_call_access_account_confirmation_delivery_accepted\(\$delivery\)[\s\S]*email_delivery_failed/,
  'account-update confirmation must fail closed when mail dispatch is not sent or queued',
);
assert.match(
  confirmationHelper,
  /call_access_account_update_confirmation_email_dispatched[\s\S]*delivery_succeeded[\s\S]*delivery_queued/s,
  'successful dispatch audit must record safe delivery status without raw identifiers',
);
assert.match(
  confirmationHelper,
  /videochat_call_access_account_confirmation_record_account_data_changed\(\$pdo, \$row, \$userId, \['display_name'\]\)/,
  'confirmed account updates must emit an explicit account-data-change audit event',
);

assert.match(
  confirmationAuditHelper,
  /function videochat_call_access_account_confirmation_record_email_dispatch_failed[\s\S]*call_access_account_update_confirmation_email_dispatch_failed/s,
  'confirmation audit helper must expose a dedicated email-dispatch failure event',
);
assert.match(
  confirmationAuditHelper,
  /DELETE FROM call_access_account_update_confirmations WHERE id = :id/,
  'mail dispatch failures must remove the pending confirmation row instead of leaving a confirmable token',
);
assert.match(
  confirmationAuditHelper,
  /function videochat_call_access_account_confirmation_record_account_data_changed[\s\S]*call_access_account_data_changed/s,
  'confirmation audit helper must expose a dedicated account-data-change event',
);
for (const omissionFlag of [
  'confirmation_identifier_logged',
  'raw_link_identifier_logged',
  'recipient_email_logged',
]) {
  assert.match(confirmationAuditHelper, new RegExp(`${omissionFlag}' => false`), `audit helper must pin ${omissionFlag}=false`);
}

assert.match(
  emailContract,
  /confirmation email must describe link expiry[\s\S]*outbox delivery should be recorded as queued[\s\S]*\$confirmedName/,
  'backend email contract must prove confirmation email text omits pending account data and foreign link data',
);
assert.match(
  emailContract,
  /mail delivery failure should reject the confirmation request[\s\S]*mail delivery failure must leave account data unchanged[\s\S]*must not leave a confirmable pending payload/s,
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
assert.ok(packageJson.scripts['test:contract:iam-call-access'].includes(contractPath), 'IAM contract script must include the email-safe dispatch audit contract');
assert.ok(ciGate.includes(contractPath), 'IAM CI gate must include the email-safe dispatch audit contract');

process.stdout.write('[call-access-email-safe-texts-dispatch-audit-contract] PASS\n');
