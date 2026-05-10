import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);
const frontendRoot = path.resolve(__dirname, '../..');
const videoChatRoot = path.resolve(frontendRoot, '..');
const repoRoot = path.resolve(videoChatRoot, '../..');

function readText(relativePath) {
  return fs.readFileSync(path.join(repoRoot, relativePath), 'utf8');
}

function functionBody(source, name) {
  const start = source.indexOf(`function ${name}`);
  assert.notEqual(start, -1, `${name} must exist`);
  const next = source.indexOf('\nfunction ', start + 1);
  return source.slice(start, next === -1 ? source.length : next);
}

const auditEvents = readText('demo/video-chat/backend-king-php/domain/audit/audit_events.php');
const callAccessPublic = readText('demo/video-chat/backend-king-php/domain/calls/call_access_public.php');
const callAccessSession = readText('demo/video-chat/backend-king-php/domain/calls/call_access_session.php');
const moduleCallsAccess = readText('demo/video-chat/backend-king-php/http/module_calls_access.php');
const auditMembershipContract = readText('demo/video-chat/backend-king-php/tests/audit-call-access-membership-contract.php');
const strongMismatchContract = readText('demo/video-chat/backend-king-php/tests/call-access-strong-mismatch-privacy-contract.php');
const sessionFixationContract = readText('demo/video-chat/backend-king-php/tests/call-access-session-fixation-contract.php');
const membershipRemovalAudit = functionBody(auditEvents, 'videochat_audit_record_membership_removal');
const linkOpenAudit = functionBody(auditEvents, 'videochat_audit_record_call_access_link_open');
const callScopedContinuedAudit = functionBody(auditEvents, 'videochat_audit_record_call_scoped_access_continued');
const forbiddenRawPayloadKeys = /'payload'\s*=>\s*\[[\s\S]*'(participant_email|email|display_name|title|access_id|session_id|token)'\s*=>/s;

const sensitiveAuditKeys = [
  'access_id',
  'authorization',
  'cookie',
  'password',
  'secret',
  'session_id',
  'token',
  'sdp',
  'ice',
  'candidate',
  'media',
  'frame',
  'webrtc',
];

for (const key of sensitiveAuditKeys) {
  assert.match(
    auditEvents,
    new RegExp(key.replace('_', '[_-]?')),
    `audit sanitizer must classify ${key} as sensitive`,
  );
}

assert.match(
  auditEvents,
  /videochat_audit_record_event[\s\S]*videochat_audit_sanitize_payload\(\$event\['payload'\][\s\S]*payload_json/s,
  'all audit event payload JSON must pass through the shared sanitizer before persistence',
);
assert.match(
  auditEvents,
  /access\[_-\]\?id[\s\S]*session\(\[_-\]\?id\)\?[\s\S]*token/s,
  'audit sanitizer must cover raw call-access ids, session ids, and tokens',
);
assert.match(
  membershipRemovalAudit,
  /resource_fingerprint' => videochat_audit_fingerprint[\s\S]*\$context\['access_id'\]/s,
  'membership-removal audit must fingerprint call-access ids instead of logging them raw',
);
assert.doesNotMatch(
  membershipRemovalAudit,
  /'payload'\s*=>\s*\[[\s\S]*access_id/s,
  'membership-removal audit payload must not include raw access ids',
);
assert.match(
  linkOpenAudit,
  /resource_fingerprint' => videochat_audit_fingerprint\(\$accessId\)[\s\S]*raw_link_identifier_logged' => false/s,
  'call-access link-open audit must fingerprint link ids and explicitly avoid raw link identifiers',
);
assert.doesNotMatch(
  linkOpenAudit,
  forbiddenRawPayloadKeys,
  'call-access link-open audit payload must not contain private call data or foreign person data',
);
assert.match(
  callScopedContinuedAudit,
  /resource_fingerprint' => videochat_audit_fingerprint[\s\S]*session_fingerprint' => videochat_audit_fingerprint\(\$sessionId\)[\s\S]*raw_session_identifier_logged' => false/s,
  'call-scoped continuation audit must fingerprint access/session ids and avoid raw session identifiers',
);
assert.doesNotMatch(
  callScopedContinuedAudit,
  forbiddenRawPayloadKeys,
  'call-scoped continuation audit payload must not contain private call data or foreign person data',
);

assert.match(
  callAccessPublic,
  /videochat_audit_record_call_access_link_open\(\$pdo,\s*\$freshLink,\s*\$call,\s*\$targetUser\)/,
  'public call-access resolution must use the compatible link-open audit helper',
);
assert.match(
  callAccessSession,
  /videochat_audit_record_call_scoped_access_continued\(\$pdo,\s*\$accessLink,\s*\$call,\s*\$targetUser,\s*\$sessionId\)/,
  'call-access session issuance must use the compatible call-scoped continuation audit helper',
);

assert.doesNotMatch(
  moduleCallsAccess,
  /videochat_audit_record_event|videochat_audit_record_call_access/s,
  'denied/auth/mismatch call-access routes must not hand-roll audit payloads outside the redaction helpers',
);

assert.match(
  auditMembershipContract,
  /audit_sanitizer_probe[\s\S]*session_id[\s\S]*raw-token-should-not-persist[\s\S]*ice_candidate[\s\S]*audit events must not leak sensitive text/s,
  'backend audit membership proof must exercise sanitizer redaction for raw sessions, tokens, media, and ICE payloads',
);
assert.match(
  auditMembershipContract,
  /videochat_audit_fingerprint\(\$accessId\)[\s\S]*videochat_audit_fingerprint\(\$sessionId\)/s,
  'backend audit membership proof must retain fingerprints instead of raw identifiers',
);
assert.match(
  strongMismatchContract,
  /wrong-user join response[\s\S]*wrong-host session response[\s\S]*unverified-host session response/s,
  'strong-mismatch proof must cover denied/auth/mismatch response redaction cases',
);
assert.match(
  strongMismatchContract,
  /secretNeedles[\s\S]*\$callId[\s\S]*\$callTitle[\s\S]*\$hostEmail[\s\S]*\$targetEmail[\s\S]*\$targetName[\s\S]*sess_strong_mismatch_wrong_host_should_not_issue/s,
  'strong-mismatch proof must forbid private call data, foreign person data, and unissued session tokens',
);
assert.match(
  sessionFixationContract,
  /session_id_not_available[\s\S]*session_context_changed[\s\S]*not_bound_to_current_user[\s\S]*call_access_binding_mismatch/s,
  'session-fixation proof must cover auth and binding-mismatch failure compatibility',
);

process.stdout.write('[call-access-audit-redaction-contract] PASS\n');
