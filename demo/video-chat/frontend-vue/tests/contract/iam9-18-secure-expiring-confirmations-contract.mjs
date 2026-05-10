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

function functionBody(source, name) {
  const marker = `function ${name}`;
  const start = source.indexOf(marker);
  assert.notEqual(start, -1, `${name} must exist`);
  const open = source.indexOf('{', start);
  assert.notEqual(open, -1, `${name} body must exist`);

  let depth = 0;
  for (let index = open; index < source.length; index += 1) {
    const char = source[index];
    if (char === '{') depth += 1;
    if (char === '}') {
      depth -= 1;
      if (depth === 0) {
        return source.slice(open + 1, index);
      }
    }
  }

  throw new Error(`${name} body must terminate`);
}

const confirmationRuntime = read('demo/video-chat/backend-king-php/domain/calls/call_access_account_confirmation.php');
const confirmationRoute = read('demo/video-chat/backend-king-php/http/module_calls_access.php');
const backendProof = read('demo/video-chat/backend-king-php/tests/call-access-email-confirmation-contract.php');

assert.match(
  confirmationRuntime,
  /CREATE TABLE IF NOT EXISTS call_access_account_update_confirmations[\s\S]*token_fingerprint TEXT NOT NULL UNIQUE/s,
  'IAM9-18 must keep confirmation tokens fingerprinted in storage, not raw',
);
assert.match(
  confirmationRuntime,
  /function videochat_call_access_account_confirmation_ttl_seconds\(\)[\s\S]*max\(300, min\(86_400, \$seconds\)\)/,
  'IAM9-18 must keep bounded expiring confirmation ttl',
);
assert.match(
  confirmationRuntime,
  /function videochat_call_access_account_confirmation_is_secure_origin[\s\S]*\$scheme === 'https'[\s\S]*videochat_call_access_account_confirmation_is_loopback_host/s,
  'IAM9-18 must allow HTTPS origins and only loopback HTTP origins',
);
assert.match(
  confirmationRuntime,
  /function videochat_build_call_access_account_confirmation_url[\s\S]*\/account-update-confirmation\?[\s\S]*call_access_account_update_confirmation_token/s,
  'IAM9-18 must build a dedicated account-update confirmation URL',
);
assert.doesNotMatch(
  functionBody(confirmationRuntime, 'videochat_build_call_access_account_confirmation_url'),
  /access_id|call_id|recipient_email/i,
  'IAM9-18 confirmation URL must not expose raw call, access, or recipient identifiers',
);
assert.match(
  functionBody(confirmationRuntime, 'videochat_call_access_request_account_update_confirmation'),
  /videochat_send_call_access_account_update_confirmation_mail[\s\S]*DELETE FROM call_access_account_update_confirmations WHERE token_fingerprint[\s\S]*confirmation_dispatch_failed/s,
  'IAM9-18 must delete unconsumed pending confirmations if dispatch fails',
);
assert.match(
  functionBody(confirmationRuntime, 'videochat_call_access_request_account_update_confirmation'),
  /call_access_account_update_confirmation_email_dispatched[\s\S]*secure_confirmation_link_sent[\s\S]*expires_in_seconds/s,
  'IAM9-18 must audit secure confirmation dispatch with expiry metadata',
);
assert.match(
  functionBody(confirmationRuntime, 'videochat_call_access_request_account_update_confirmation'),
  /'token' => \$token[\s\S]*'expires_at' => \$expiresAt[\s\S]*'expires_in_seconds' => \$ttlSeconds[\s\S]*'confirmation_url' => \$confirmationUrl/s,
  'IAM9-18 runtime result must expose token, expiry, ttl, and URL to the route/debug harness',
);
assert.match(
  functionBody(confirmationRuntime, 'videochat_call_access_confirm_account_update'),
  /WHERE token_fingerprint = :token_fingerprint[\s\S]*expires_at > :now/s,
  'IAM9-18 confirmation consume must be atomic and fail after expiry',
);
assert.match(
  confirmationRoute,
  /'expires_at' => \$requestResult\['expires_at'\][\s\S]*'expires_in_seconds'[\s\S]*'email_delivery_channel'/,
  'IAM9-18 route must expose expiry and delivery metadata without exposing production token',
);
assert.match(
  confirmationRoute,
  /debug_confirmation_token[\s\S]*VIDEOCHAT_KING_ENV[\s\S]*production[\s\S]*\? null/s,
  'IAM9-18 route must still suppress debug confirmation token in production',
);
assert.match(
  backendProof,
  /confirmation link expiry should follow configured ttl[\s\S]*confirmation email should contain a secure HTTPS confirmation link[\s\S]*confirmation email must describe link expiry/s,
  'IAM9-18 backend proof must exercise secure expiring email confirmation delivery',
);
assert.match(
  backendProof,
  /expired confirmation must not consume token[\s\S]*confirmation storage should keep token fingerprint/s,
  'IAM9-18 backend proof must preserve fingerprinted storage and expired-token non-consumption',
);

process.stdout.write('[iam9-18-secure-expiring-confirmations-contract] PASS\n');
