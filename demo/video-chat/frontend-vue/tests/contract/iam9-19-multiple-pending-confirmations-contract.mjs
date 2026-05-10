import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);
const frontendRoot = path.resolve(__dirname, '../..');
const repoRoot = path.resolve(frontendRoot, '../../..');

function read(relativePath) {
  return fs.readFileSync(path.join(repoRoot, relativePath), 'utf8');
}

function requireMatch(source, pattern, message) {
  assert.match(source, pattern, message);
}

const helper = read('demo/video-chat/backend-king-php/domain/calls/call_access_account_confirmation.php');
const phpContract = read('demo/video-chat/backend-king-php/tests/call-access-email-confirmation-contract.php');
const sqliteGate = read('demo/video-chat/backend-king-php/tests/iam-call-access-sqlite-runtime-proof.sh');

requireMatch(
  helper,
  /CREATE TABLE IF NOT EXISTS call_access_account_update_confirmations[\s\S]*token_fingerprint TEXT NOT NULL UNIQUE[\s\S]*superseded_at TEXT[\s\S]*superseded_by_fingerprint TEXT NOT NULL DEFAULT ''/s,
  'IAM9-19 storage must support multiple pending confirmations with token fingerprints and supersession metadata',
);
requireMatch(
  helper,
  /function videochat_call_access_account_confirmation_invalidate_older_enabled[\s\S]*return true;/s,
  'IAM9-19 newer account-update requests must invalidate older pending confirmations by default',
);
requireMatch(
  helper,
  /UPDATE call_access_account_update_confirmations[\s\S]*SET superseded_at = :superseded_at,[\s\S]*superseded_by_fingerprint = :superseded_by_fingerprint[\s\S]*token_fingerprint <> :token_fingerprint[\s\S]*\(consumed_at IS NULL OR trim\(consumed_at\) = ''\)[\s\S]*\(superseded_at IS NULL OR trim\(superseded_at\) = ''\)/s,
  'IAM9-19 request path must supersede only older unconsumed pending confirmations for the same account/access pair',
);
requireMatch(
  helper,
  /'event_type' => 'call_access_account_update_confirmation_superseded'[\s\S]*'superseded_pending_count' => \$supersededPendingCount[\s\S]*'confirmation_identifier_logged' => false[\s\S]*'recipient_email_logged' => false/s,
  'IAM9-19 supersession audit must count pending confirmations without logging raw confirmation identifiers or recipient email',
);
requireMatch(
  helper,
  /'errors' => \['token' => 'superseded'\]/s,
  'IAM9-19 superseded confirmation tokens must fail closed instead of applying stale account data',
);
requireMatch(
  helper,
  /'superseded_pending_count' => \$supersededPendingCount/s,
  'IAM9-19 request result must expose the focused superseded pending count for callers and tests',
);

requireMatch(
  phpContract,
  /older pending confirmation request should be accepted[\s\S]*older pending token should be distinct[\s\S]*newer request should supersede exactly one pending token[\s\S]*newer pending token should be distinct/s,
  'IAM9-19 PHP runtime contract must prove distinct pending tokens and single older-token supersession',
);
requireMatch(
  phpContract,
  /older pending token should be marked superseded[\s\S]*superseded row must point to newer token fingerprint[\s\S]*superseded row must not store raw newer token/s,
  'IAM9-19 PHP runtime contract must prove fingerprint-only supersession linkage',
);
requireMatch(
  phpContract,
  /superseded confirmation should fail closed[\s\S]*superseded field mismatch[\s\S]*superseded confirmation must not update data[\s\S]*newer pending confirmation should confirm[\s\S]*newer pending confirmation should apply latest payload/s,
  'IAM9-19 PHP runtime contract must prove stale pending tokens fail and the latest pending payload wins',
);
requireMatch(
  phpContract,
  /\['account_bound', 'already_consumed', 'superseded', 'expired'\][\s\S]*failed confirmation audit should include \{\$expectedFailureReason\}/s,
  'IAM9-19 audit coverage must include the superseded-token failure reason alongside existing confirmation failures',
);

assert.ok(
  sqliteGate.includes('call-access-email-confirmation-contract.sh'),
  'IAM9-19 PHP proof must remain wired through the SQLite IAM runtime proof gate',
);

process.stdout.write('[iam9-19-multiple-pending-confirmations-contract] PASS\n');
