import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const frontendRoot = path.resolve(__dirname, '../..');
const repoRoot = path.resolve(frontendRoot, '..', '..', '..');

function read(relativePath) {
  return fs.readFileSync(path.join(repoRoot, relativePath), 'utf8');
}

function readJson(relativePath) {
  return JSON.parse(read(relativePath));
}

const sessionDomain = read('demo/video-chat/backend-king-php/domain/calls/call_access_session.php');
const reviewDomain = read('demo/video-chat/backend-king-php/domain/calls/call_access_review.php');
const auditDomain = read('demo/video-chat/backend-king-php/domain/audit/audit_events.php');
const backendContract = read('demo/video-chat/backend-king-php/tests/call-access-identity-mismatch-review-flow-contract.php');
const backendContractShell = read('demo/video-chat/backend-king-php/tests/call-access-identity-mismatch-review-flow-contract.sh');
const backendAggregate = read('demo/video-chat/backend-king-php/tests/iam-call-access-sqlite-runtime-proof.sh');
const joinView = read('demo/video-chat/frontend-vue/src/domain/calls/access/JoinView.vue');
const safeScreenContract = read('demo/video-chat/frontend-vue/tests/contract/call-access-safe-screen-final-contract.mjs');
const verifiedContextContract = read('demo/video-chat/frontend-vue/tests/contract/call-access-verified-context-ui-contract.mjs');
const packageJson = readJson('demo/video-chat/frontend-vue/package.json');
const matrix = readJson('demo/video-chat/contracts/v1/ui-parity-acceptance.matrix.json');
const sprint = read('SPRINT.md');

assert.match(
  sessionDomain,
  /function videochat_call_access_record_session_context_mismatch[\s\S]*videochat_call_access_record_identity_mismatch_review[\s\S]*videochat_audit_record_call_access_strong_mismatch[\s\S]*videochat_audit_record_call_access_account_compared/,
  'session guard must create review and audit evidence before failing verified/authenticated context mismatches',
);
assert.match(
  sessionDomain,
  /\(\$verifiedUserId > 0 \|\| \$verifiedSessionId !== ''\) && \(\$authenticatedUserId <= 0 \|\| \$authenticatedSessionId === ''\)[\s\S]*verified_context_without_bearer[\s\S]*'auth' => 'session_context_changed'/,
  'verified context without bearer must remain fail-closed with stable session_context_changed auth code',
);
assert.match(
  sessionDomain,
  /\$verifiedSessionId !== '' && \$authenticatedSessionId !== '' && !hash_equals\(\$verifiedSessionId, \$authenticatedSessionId\)[\s\S]*verified_session_changed[\s\S]*'auth' => 'session_context_changed'/,
  'verified/authenticated session mismatch must remain fail-closed with stable session_context_changed auth code',
);
assert.match(
  sessionDomain,
  /\$verifiedUserId > 0 && \$authenticatedUserId > 0 && \$verifiedUserId !== \$authenticatedUserId[\s\S]*verified_user_changed[\s\S]*'auth' => 'session_context_changed'/,
  'verified/authenticated user mismatch must remain fail-closed with stable session_context_changed auth code',
);

assert.match(
  reviewDomain,
  /function videochat_call_access_record_identity_mismatch_review[\s\S]*'identity_mismatch_review'[\s\S]*'mismatch' => 'strong_personalized_link'[\s\S]*'raw_link_identifier_logged' => false[\s\S]*'raw_session_identifier_logged' => false[\s\S]*'foreign_account_data_logged' => false/,
  'identity mismatch review flags must use a dedicated reason and redacted payload markers',
);
assert.match(
  reviewDomain,
  /event_type' => 'call_access_identity_mismatch_review'[\s\S]*'session_fingerprint' => \$sessionId === '' \? '' : videochat_audit_fingerprint\(\$sessionId\)/,
  'identity mismatch review audit must fingerprint the session and avoid raw session ids',
);
assert.match(
  reviewDomain,
  /function videochat_call_access_record_host_verification_attempt[\s\S]*host_name_fingerprint[\s\S]*call_access_host_name_verified[\s\S]*call_access_host_name_rejected[\s\S]*'host_name_logged' => false/,
  'host verification attempts must be fingerprinted, audited, and never log the raw host name',
);

assert.match(
  auditDomain,
  /function videochat_audit_record_call_access_strong_mismatch[\s\S]*call_access_strong_mismatch_denied[\s\S]*'foreign_account_data_logged' => false[\s\S]*'raw_link_identifier_logged' => false[\s\S]*'raw_session_identifier_logged' => false/,
  'strong personalized-link mismatch denials must have a redacted audit helper',
);
assert.match(
  auditDomain,
  /is_bool\(\$entry\)[\s\S]*\^raw_\[a-z0-9_-\]\+_logged\$[\s\S]*\$sanitized\[\$key\] = \$entry/,
  'audit sanitizer must preserve boolean raw_*_logged=false markers while still dropping sensitive values',
);

for (const sentinel of [
  'verified/authenticated user mismatch should conflict',
  'session-context mismatch should create one identity review flag',
  'wrong host mismatch should be forbidden',
  'second host attempt should be rate-limited',
  'identity mismatch review audit missing',
  'host-name rejection audit missing',
  'audit must state raw access ids are not logged',
  'audit must state raw session ids are not logged',
  'audit must state foreign account data is not logged',
]) {
  assert.ok(backendContract.includes(sentinel), `backend contract must prove: ${sentinel}`);
}

assert.match(
  backendContractShell,
  /call-access-identity-mismatch-review-flow-contract\.php/,
  'backend shell wrapper must run the identity mismatch review-flow contract',
);
assert.ok(
  backendAggregate.includes('"call-access-identity-mismatch-review-flow-contract.sh"'),
  'IAM SQLite aggregate must include the identity mismatch backend contract',
);
assert.match(
  joinView,
  /state\.joinError = localizedApiErrorMessage\(errorPayload, t\('public\.join\.start_session_failed'\)\);/,
  'safe-screen frontend proof must continue rendering session denials from stable error codes',
);
assert.ok(
  safeScreenContract.includes('call-access-safe-screen-final-contract'),
  'safe-screen contract must stay wired as neighboring proof',
);
assert.match(
  verifiedContextContract,
  /session_context_changed/,
  'verified-context frontend proof must keep session_context_changed as the stable UI state',
);

const iamScript = String(packageJson.scripts?.['test:contract:iam-call-access'] || '');
assert.ok(
  iamScript.includes('node tests/contract/call-access-identity-mismatch-review-flow-contract.mjs'),
  'IAM package contract script must run the identity mismatch review-flow contract',
);

const iamCommandPaths = new Set(matrix.commands?.['frontend:contract:iam-call-access']?.paths || []);
for (const pathName of [
  'frontend-vue/tests/contract/call-access-identity-mismatch-review-flow-contract.mjs',
  'backend-king-php/tests/call-access-identity-mismatch-review-flow-contract.php',
  'backend-king-php/tests/call-access-identity-mismatch-review-flow-contract.sh',
]) {
  assert.ok(iamCommandPaths.has(pathName), `IAM release metadata must list ${pathName}`);
}

assert.match(
  sprint,
  /- \[x\] IAM7-14 Extract or prove identity mismatch review flow/,
  'SPRINT.md must mark IAM7-14 checked only after implementation and proof',
);

process.stdout.write('[call-access-identity-mismatch-review-flow-contract] PASS\n');
