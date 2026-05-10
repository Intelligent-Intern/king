import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);
const frontendRoot = path.resolve(__dirname, '../..');
const repoRoot = path.resolve(frontendRoot, '../../..');

function readText(relativePath) {
  return fs.readFileSync(path.join(repoRoot, relativePath), 'utf8');
}

function readJson(relativePath) {
  return JSON.parse(readText(relativePath));
}

const sourceBranch = 'local/iam-e2e-email-confirmation-race-hardening';
assert.equal(sourceBranch, 'local/iam-e2e-email-confirmation-race-hardening');

const packageJson = readJson('demo/video-chat/frontend-vue/package.json');
const uiParityMatrix = readJson('demo/video-chat/contracts/v1/ui-parity-acceptance.matrix.json');
const staticGate = readText('demo/video-chat/scripts/iam-call-access-ci-gate.sh');
const confirmationHelper = readText('demo/video-chat/backend-king-php/domain/calls/call_access_account_confirmation.php');
const confirmationContract = readText('demo/video-chat/backend-king-php/tests/call-access-email-confirmation-contract.php');
const confirmationShell = readText('demo/video-chat/backend-king-php/tests/call-access-email-confirmation-contract.sh');
const sqliteAggregate = readText('demo/video-chat/backend-king-php/tests/iam-call-access-sqlite-runtime-proof.sh');
const extractionContract = readText('demo/video-chat/frontend-vue/tests/contract/call-access-email-confirmation-extract-contract.mjs');

assert.match(
  confirmationHelper,
  /CREATE TABLE IF NOT EXISTS call_access_account_update_confirmations[\s\S]*token_fingerprint TEXT NOT NULL UNIQUE[\s\S]*superseded_at TEXT[\s\S]*superseded_by_fingerprint TEXT NOT NULL DEFAULT ''/s,
  'account-update confirmation storage must use token fingerprints and superseded state',
);
assert.match(
  confirmationHelper,
  /function videochat_call_access_account_confirmation_invalidate_older_enabled[\s\S]*VIDEOCHAT_CALL_ACCESS_ACCOUNT_CONFIRMATION_INVALIDATE_OLDER[\s\S]*return true/s,
  'newer account-update confirmations must invalidate older pending confirmations by default',
);
assert.match(
  confirmationHelper,
  /UPDATE call_access_account_update_confirmations[\s\S]*SET superseded_at = :superseded_at,[\s\S]*superseded_by_fingerprint = :superseded_by_fingerprint[\s\S]*AND token_fingerprint <> :token_fingerprint/s,
  'new confirmation requests must supersede only older pending tokens for the same account and access link',
);
assert.match(
  confirmationHelper,
  /UPDATE call_access_account_update_confirmations[\s\S]*SET consumed_at = :consumed_at[\s\S]*WHERE token_fingerprint = :token_fingerprint[\s\S]*AND \(consumed_at IS NULL OR trim\(consumed_at\) = ''\)[\s\S]*AND \(superseded_at IS NULL OR trim\(superseded_at\) = ''\)[\s\S]*AND expires_at > :now/s,
  'confirmation consumption must be an atomic pending-token update guarded by consumed, superseded, and expiry predicates',
);
assert.match(
  confirmationHelper,
  /if \(\$consume->rowCount\(\) !== 1\)[\s\S]*videochat_call_access_confirmation_consumed_error\([\s\S]*true[\s\S]*function videochat_call_access_confirmation_consumed_error/s,
  'failed atomic consume must re-read state and classify the race deterministically',
);
assert.match(
  confirmationHelper,
  /'already_consumed'[\s\S]*'superseded'[\s\S]*'expired'[\s\S]*'consume_race'[\s\S]*'confirmation_raced'/s,
  'race fallback must distinguish replay, superseded, expired, and unresolved consume-race conflicts',
);
assert.match(
  confirmationHelper,
  /videochat_call_access_record_account_confirmation_failure[\s\S]*call_access_account_update_confirmation_failed[\s\S]*token_logged' => false[\s\S]*recipient_email_logged' => false/s,
  'failed confirmation attempts must be audit-logged without raw token or recipient email disclosure',
);
assert.doesNotMatch(
  confirmationHelper,
  /WHERE id = :id[\s\S]*\$trimmedToken/,
  'runtime must not resolve confirmations by raw token ids',
);

for (const marker of [
  'older pending token should be distinct',
  'newer pending token should be distinct',
  'expired pending-confirmation session must not consume the token',
  'newer request should supersede exactly one pending token',
  'older pending token should be marked superseded',
  'superseded row must point to newer token fingerprint',
  'superseded row must not store raw newer token',
  'superseded confirmation should fail closed',
  'duplicate concurrent confirmation should fail after first consume',
  'duplicate concurrent field mismatch',
  'expired confirmation must not consume token',
  'confirmation storage should keep token fingerprint',
  'confirmation storage should keep request session fingerprint',
]) {
  assert.ok(confirmationContract.includes(marker), `PHP runtime proof must include: ${marker}`);
}

assert.match(
  confirmationContract,
  /foreach \(\['account_bound', 'already_consumed', 'superseded', 'expired'\] as \$expectedFailureReason\)[\s\S]*failed confirmation audit should include/,
  'PHP runtime proof must require failure audit coverage for replay, superseded, and expired tokens',
);

assert.match(
  confirmationContract,
  /putenv\('VIDEOCHAT_CALL_ACCESS_ACCOUNT_CONFIRMATION_INVALIDATE_OLDER=1'\)[\s\S]*\$olderRequest[\s\S]*\$newerRequest[\s\S]*\$supersededConfirm[\s\S]*\$newerConfirm[\s\S]*\$newerReplay/s,
  'PHP contract must exercise configured newer-invalidates-older flow through replay',
);
assert.match(
  confirmationContract,
  /SELECT id, token_fingerprint, recipient_email_fingerprint, requesting_session_fingerprint, access_fingerprint, superseded_by_fingerprint, pending_payload_json FROM call_access_account_update_confirmations[\s\S]*videochat_call_access_email_confirmation_assert_no_needles/s,
  'PHP contract must prove stored confirmation rows expose fingerprints instead of raw secrets',
);
assert.match(
  confirmationShell,
  /call-access-email-confirmation-contract\.php/,
  'email confirmation shell wrapper must execute the PHP runtime proof',
);
assert.match(
  sqliteAggregate,
  /call-access-email-confirmation-contract\.sh/,
  'SQLite IAM aggregate must include the email-confirmation runtime proof',
);
assert.match(
  extractionContract,
  /local\/iam-e2e-email-confirmation-race-hardening[\s\S]*a87b0ba8144c4bddb2c34207c6d3a569217754a6/,
  'extraction contract must retain the historical branch and commit provenance',
);

const iamPackageGate = String(packageJson.scripts?.['test:contract:iam-call-access'] || '');
assert.ok(
  iamPackageGate.includes('node tests/contract/iam9-17-email-confirmation-race-contract.mjs'),
  'IAM package gate must run the IAM9-17 race proof',
);
assert.equal(
  packageJson.scripts?.['test:contract:iam9-17-email-confirmation-race'],
  'node tests/contract/iam9-17-email-confirmation-race-contract.mjs && ../backend-king-php/tests/call-access-email-confirmation-contract.sh',
  'focused IAM9-17 package script must run static and PHP runtime race proofs',
);
assert.match(
  staticGate,
  /STATIC_CONTRACTS=\([\s\S]*iam9-17-email-confirmation-race-contract\.mjs/,
  'IAM static CI gate must include the focused IAM9-17 race contract',
);

const iamMatrixPaths = new Set(uiParityMatrix.commands?.['frontend:contract:iam-call-access']?.paths || []);
assert.ok(
  iamMatrixPaths.has('frontend-vue/tests/contract/iam9-17-email-confirmation-race-contract.mjs'),
  'UI parity IAM gate must list the IAM9-17 static race proof',
);
assert.ok(
  iamMatrixPaths.has('backend-king-php/tests/call-access-email-confirmation-contract.sh'),
  'UI parity IAM gate must keep the email-confirmation PHP runtime proof',
);

process.stdout.write('[iam9-17-email-confirmation-race-contract] PASS\n');
