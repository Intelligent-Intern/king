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

const auditDomain = read('demo/video-chat/backend-king-php/domain/audit/audit_events.php');
const callManagementCreate = read('demo/video-chat/backend-king-php/domain/calls/call_management_create.php');
const callAccessLinks = read('demo/video-chat/backend-king-php/domain/calls/call_access_links.php');
const callAccessPublic = read('demo/video-chat/backend-king-php/domain/calls/call_access_public.php');
const callAccessReview = read('demo/video-chat/backend-king-php/domain/calls/call_access_review.php');
const callAccessSession = read('demo/video-chat/backend-king-php/domain/calls/call_access_session.php');
const callAccessAccountConfirmation = read('demo/video-chat/backend-king-php/domain/calls/call_access_account_confirmation.php');
const backendAuditContract = read('demo/video-chat/backend-king-php/tests/audit-call-access-events-contract.php');
const backendAuditWrapper = read('demo/video-chat/backend-king-php/tests/audit-call-access-events-contract.sh');
const sqliteAggregate = read('demo/video-chat/backend-king-php/tests/iam-call-access-sqlite-runtime-proof.sh');
const packageJson = JSON.parse(read('demo/video-chat/frontend-vue/package.json'));

for (const helper of [
  'videochat_audit_record_call_created',
  'videochat_audit_record_call_access_invitation_created',
  'videochat_audit_record_call_access_link_open',
  'videochat_audit_record_temporary_account_created',
  'videochat_audit_record_call_access_account_compared',
  'videochat_audit_record_call_access_strong_mismatch',
  'videochat_audit_record_call_scoped_access_continued',
]) {
  assert.match(auditDomain, new RegExp(`function ${helper}\\(`), `audit domain must expose ${helper}`);
}

assert.match(
  callManagementCreate,
  /videochat_audit_record_call_created\(\$pdo,\s*\$createdCall,\s*\(int\) \$owner\['id'\]\)/,
  'call creation must emit the call-created audit helper from the runtime path',
);
assert.match(
  callAccessLinks,
  /videochat_audit_record_call_access_invitation_created\(\s*\$pdo,\s*\$accessLink,\s*\$call,\s*\$authUserId,\s*\$targetUserForAudit\s*\)/,
  'call-access link creation must emit invitation-created audit events from the runtime path',
);
assert.match(
  callAccessPublic,
  /videochat_audit_record_call_access_link_open\(\$pdo,\s*\$freshLink,\s*\$call,\s*\$targetUser\)/,
  'public link resolution must emit link-open audit events from the runtime path',
);
assert.match(
  callAccessSession,
  /videochat_audit_record_temporary_account_created\(\$pdo,\s*\$targetUser,\s*\$tenantId,[\s\S]*'anonymous_call_access_link'/,
  'anonymous call-access session issuance must emit temporary-account audit events',
);
assert.match(
  callAccessSession,
  /videochat_audit_record_call_access_account_compared\(\$pdo,\s*\$accessLink,\s*\$call,\s*\$targetUser,\s*\$authenticatedUserId,\s*'matched'/,
  'matched personal account issuance must emit account-comparison audit events',
);
assert.match(
  functionBody(callAccessSession, 'videochat_call_access_record_session_context_mismatch'),
  /videochat_audit_record_call_access_strong_mismatch\([\s\S]*videochat_audit_record_call_access_account_compared\(/,
  'verified-context mismatch must emit strong-mismatch and account-comparison audit events',
);
assert.match(
  functionBody(callAccessReview, 'videochat_call_access_record_host_verification_attempt'),
  /call_access_host_name_verified[\s\S]*call_access_host_name_verification_failed[\s\S]*videochat_audit_record_event\([\s\S]*host_name_logged' => false/s,
  'host-name verification attempts must emit redacted canonical audit events from the runtime path',
);
assert.match(
  functionBody(callAccessAccountConfirmation, 'videochat_call_access_request_account_update_confirmation'),
  /call_access_account_update_confirmation_requested[\s\S]*manual_reentry_required[\s\S]*confirmation_identifier_logged' => false[\s\S]*session_identifier_logged' => false/s,
  'account-update confirmation requests must emit redacted audit events from the runtime path',
);

for (const eventType of [
  'call_created',
  'call_access_invitation_created',
  'call_access_link_opened',
  'temporary_account_created',
  'call_access_account_compared',
  'call_access_host_name_verified',
  'call_access_host_name_verification_failed',
  'call_access_account_update_confirmation_requested',
  'call_access_strong_mismatch_denied',
]) {
  assert.ok(backendAuditContract.includes(`'${eventType}'`), `backend audit contract must assert ${eventType}`);
}

for (const actionProof of [
  'videochat_create_call($pdo',
  'videochat_create_call_access_link_for_user',
  'videochat_issue_session_for_call_access',
  'videochat_call_access_record_host_verification_attempt',
  'videochat_call_access_request_account_update_confirmation',
  'switched verified account should be denied',
  'wrong account must not allocate a session id',
  'open guest should receive a temporary session',
]) {
  assert.ok(backendAuditContract.includes(actionProof), `backend audit contract must drive ${actionProof}`);
}

assert.match(
  backendAuditContract,
  /switched verified account should be denied[\s\S]*call_access_strong_mismatch_denied[\s\S]*strong mismatch audit stage mismatch/s,
  'backend audit contract must prove strong-mismatch denial before asserting the audit event',
);
assert.match(
  backendAuditContract,
  /matched account comparison audit missing[\s\S]*strong mismatch account comparison audit missing/s,
  'backend audit contract must prove matched and strong-mismatch account-comparison outcomes',
);
assert.match(
  backendAuditContract,
  /correct host-name verification attempt should audit[\s\S]*call_access_host_name_verified[\s\S]*host success alias must not log host name/s,
  'backend audit contract must prove successful host-name verification audit from the runtime path',
);
assert.match(
  backendAuditContract,
  /account-update confirmation request should audit[\s\S]*call_access_account_update_confirmation_requested[\s\S]*account-update audit must not log confirmation token/s,
  'backend audit contract must prove account-update request audit redaction',
);
assert.match(
  backendAuditContract,
  /call-created audit must not log titles[\s\S]*invitation audit must not persist raw access id[\s\S]*raw_guest_identity_logged[\s\S]*strong mismatch audit must not log raw session identifiers/s,
  'backend audit contract must pin redaction for titles, access ids, guest identity, and sessions',
);
assert.match(
  backendAuditContract,
  /videochat_audit_fingerprint\(\$personalAccessId\)[\s\S]*videochat_audit_fingerprint\(\$openAccessId\)[\s\S]*videochat_audit_fingerprint\(\$switchedAuthSessionId\)[\s\S]*videochat_audit_fingerprint\(\$wrongAuthSessionId\)/s,
  'backend audit contract must require fingerprints for link and session identifiers',
);
assert.match(
  backendAuditWrapper,
  /pdo_sqlite[\s\S]*audit-call-access-events-contract\.php/s,
  'backend audit wrapper must skip cleanly without local pdo_sqlite and run the PHP proof when available',
);

for (const [helper, proof] of [
  ['videochat_audit_record_call_access_invitation_created', /resource_fingerprint' => videochat_audit_fingerprint\(\$accessId\)[\s\S]*raw_link_identifier_logged' => false/],
  ['videochat_audit_record_call_access_link_open', /resource_fingerprint' => videochat_audit_fingerprint\(\$accessId\)[\s\S]*raw_link_identifier_logged' => false/],
  ['videochat_audit_record_temporary_account_created', /raw_guest_identity_logged' => false[\s\S]*raw_link_identifier_logged' => false/],
  ['videochat_audit_record_call_access_account_compared', /session_fingerprint' => \$sessionId === '' \? '' : videochat_audit_fingerprint\(\$sessionId\)[\s\S]*foreign_account_data_logged' => false/],
  ['videochat_audit_record_call_access_strong_mismatch', /host_name_logged' => false[\s\S]*foreign_account_data_logged' => false[\s\S]*raw_session_identifier_logged' => false/],
]) {
  assert.match(functionBody(auditDomain, helper), proof, `${helper} must keep fingerprint-only/redacted payload shape`);
}

const iamGate = String(packageJson.scripts?.['test:contract:iam-call-access'] || '');
assert.match(
  iamGate,
  /node tests\/contract\/iam-call-access-audit-events-contract\.mjs/,
  'IAM package gate must run the audit-events static proof',
);
assert.match(
  sqliteAggregate,
  /audit-call-access-events-contract\.sh/,
  'IAM SQLite aggregate must run the backend audit-events proof',
);

process.stdout.write('[iam-call-access-audit-events-contract] PASS\n');
