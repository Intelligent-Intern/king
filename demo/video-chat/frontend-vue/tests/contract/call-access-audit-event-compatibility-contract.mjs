import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

function fail(message) {
  throw new Error(`[call-access-audit-event-compatibility-contract] FAIL: ${message}`);
}

function readText(repoRoot, relativePath) {
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

  fail(`${name} body must terminate`);
}

function phpLiteral(value) {
  return JSON.stringify(value).replace(/\$/g, '\\$');
}

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);
const frontendRoot = path.resolve(__dirname, '../..');
const repoRoot = path.resolve(frontendRoot, '../../..');
const auditEventsPath = path.join(repoRoot, 'demo/video-chat/backend-king-php/domain/audit/audit_events.php');

const auditEvents = readText(repoRoot, 'demo/video-chat/backend-king-php/domain/audit/audit_events.php');
const callAccessReview = readText(repoRoot, 'demo/video-chat/backend-king-php/domain/calls/call_access_review.php');
const auditRedactionContract = readText(repoRoot, 'demo/video-chat/frontend-vue/tests/contract/call-access-audit-redaction-contract.mjs');
const auditMembershipContract = readText(repoRoot, 'demo/video-chat/backend-king-php/tests/audit-call-access-membership-contract.php');
const strongMismatchContract = readText(repoRoot, 'demo/video-chat/backend-king-php/tests/call-access-strong-mismatch-privacy-contract.php');
const callAccessSessionFixationContract = readText(repoRoot, 'demo/video-chat/backend-king-php/tests/call-access-session-fixation-contract.php');

try {
  const canonicalBody = functionBody(auditEvents, 'videochat_audit_canonical_iam_event_type');
  const aliasMapBody = functionBody(auditEvents, 'videochat_audit_iam_event_alias_map');
  assert.match(
    canonicalBody,
    /videochat_audit_iam_event_alias_map\(\)/,
    'canonical IAM event type resolution must use the shared alias map',
  );
  for (const [legacy, canonical] of [
    ['call_access_invitation_opened', 'call_access_link_opened'],
    ['call_access_join_link_opened', 'call_access_link_opened'],
    ['call_access_session_created', 'call_scoped_access_continued'],
    ['call_access_session_issued', 'call_scoped_access_continued'],
    ['call_access_host_verification_succeeded', 'call_access_host_name_verified'],
    ['call_access_host_verification_failed', 'call_access_host_name_verification_failed'],
    ['call_access_host_name_rejected', 'call_access_host_name_verification_failed'],
    ['tenant_membership_removed', 'membership_removed'],
    ['organization_membership_removed', 'membership_removed'],
    ['call_access_forbidden', 'call_access_denied'],
    ['call_access_rejected', 'call_access_denied'],
    ['call_admission_denied', 'call_access_denied'],
    ['call_access_allowed', 'call_access_admitted'],
    ['call_access_admission_allowed', 'call_access_admitted'],
    ['call_admission_allowed', 'call_access_admitted'],
    ['participant_role_updated', 'call_access_role_changed'],
    ['call_participant_role_updated', 'call_access_role_changed'],
    ['call_owner_transferred', 'call_access_role_changed'],
  ]) {
    assert.match(aliasMapBody, new RegExp(`'${legacy}'\\s*=>\\s*'${canonical}'`), `${legacy} must canonicalize to ${canonical}`);
  }

  assert.match(
    auditEvents,
    /function videochat_audit_record_event[\s\S]*\$eventType = videochat_audit_canonical_iam_event_type\(\(string\) \(\$event\['event_type'\] \?\? ''\)\)[\s\S]*videochat_audit_sanitize_payload\(\$event\['payload'\] \?\? \[\]\)/,
    'audit writes must canonicalize legacy IAM event aliases before sanitizing and persisting payloads',
  );
  assert.match(
    auditEvents,
    /function videochat_audit_fetch_events[\s\S]*videochat_audit_iam_event_type_filter_values\(\(string\) \$filters\['event_type'\]\)[\s\S]*event_type IN/,
    'audit reads must include canonical and legacy IAM event aliases for filtered artifact queries',
  );

  for (const currentEvent of [
    'membership_removed',
    'call_access_link_opened',
    'call_scoped_access_continued',
    'call_access_denied',
    'call_access_admitted',
    'call_access_role_changed',
    'call_access_host_name_verified',
    'call_access_host_name_verification_failed',
  ]) {
    const php = `require ${phpLiteral(auditEventsPath)}; echo videochat_audit_canonical_iam_event_type(${phpLiteral(currentEvent)});`;
    const resolved = execFileSync('php', ['-r', php], { encoding: 'utf8' }).trim();
    assert.equal(resolved, currentEvent, `${currentEvent} must remain canonical`);
  }

  const probePhp = `
require ${phpLiteral(auditEventsPath)};
$aliases = [
  'CALL_ACCESS_FORBIDDEN',
  'call_access_rejected',
  'call_access_session_issued',
  'call_access_invitation_opened',
  'CALL_ACCESS_HOST_VERIFICATION_SUCCEEDED',
  'call_access_host_verification_failed',
  'call_access_host_name_rejected',
  'tenant_membership_removed',
  'call_owner_transferred',
  'call_access_allowed'
];
$canonical = [];
foreach ($aliases as $alias) {
  $canonical[$alias] = videochat_audit_canonical_iam_event_type($alias);
}
$artifact = [
  'authorization' => 'Bearer raw-token-should-not-persist',
  'cookie' => 'session=raw-cookie-should-not-persist',
  'invite_secret' => 'invite-secret-should-not-persist',
  'raw_access_link' => '/join/11111111-1111-4111-8111-111111111111',
  'access_link' => ['id' => '22222222-2222-4222-8222-222222222222'],
  'call' => ['id' => 'private-call-id', 'title' => 'Private Roadmap Call'],
  'private_call_data' => ['host_email' => 'host-private@example.test'],
  'network' => [
    'sdp' => "v=0\\r\\no=- 1 2 IN IP4 127.0.0.1",
    'ice_candidate' => 'candidate:1 1 udp 1 127.0.0.1 9 typ host',
  ],
  'message' => 'POST /api/call-access/33333333-3333-4333-8333-333333333333/session token=raw-message-token',
  'safe_outcome' => 'denied',
  'counts' => ['access_id_count' => 2, 'session_count' => 1],
];
echo json_encode([
  'canonical' => $canonical,
  'filter_values' => [
    'host_rejected' => videochat_audit_iam_event_type_filter_values('call_access_host_name_rejected'),
    'host_failed' => videochat_audit_iam_event_type_filter_values('call_access_host_verification_failed'),
  ],
  'artifact' => videochat_audit_redact_artifact_payload($artifact),
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
`;
  const probe = JSON.parse(execFileSync('php', ['-r', probePhp], { encoding: 'utf8' }));
  assert.deepEqual(probe.canonical, {
    CALL_ACCESS_FORBIDDEN: 'call_access_denied',
    call_access_rejected: 'call_access_denied',
    call_access_session_issued: 'call_scoped_access_continued',
    call_access_invitation_opened: 'call_access_link_opened',
    CALL_ACCESS_HOST_VERIFICATION_SUCCEEDED: 'call_access_host_name_verified',
    call_access_host_verification_failed: 'call_access_host_name_verification_failed',
    call_access_host_name_rejected: 'call_access_host_name_verification_failed',
    tenant_membership_removed: 'membership_removed',
    call_owner_transferred: 'call_access_role_changed',
    call_access_allowed: 'call_access_admitted',
  });
  assert.deepEqual(
    new Set(probe.filter_values.host_rejected),
    new Set([
      'call_access_host_name_verification_failed',
      'call_access_host_name_rejected',
      'call_access_host_verification_failed',
    ]),
    'rejected host-name alias filters must include current and legacy event names',
  );
  assert.deepEqual(
    new Set(probe.filter_values.host_failed),
    new Set([
      'call_access_host_name_verification_failed',
      'call_access_host_verification_failed',
      'call_access_host_name_rejected',
    ]),
    'failed host-verification alias filters must include current and legacy event names',
  );

  const artifactText = JSON.stringify(probe.artifact);
  for (const forbidden of [
    'raw-token-should-not-persist',
    'raw-cookie-should-not-persist',
    'invite-secret-should-not-persist',
    '11111111-1111-4111-8111-111111111111',
    '22222222-2222-4222-8222-222222222222',
    '33333333-3333-4333-8333-333333333333',
    'private-call-id',
    'Private Roadmap Call',
    'host-private@example.test',
    'v=0',
    'candidate:1',
    'raw-message-token',
  ]) {
    assert.equal(artifactText.includes(forbidden), false, `artifact redaction must remove ${forbidden}`);
  }
  assert.match(artifactText, /\[redacted:audit_artifact\]/, 'artifact redaction must leave explicit redaction markers');
  assert.equal(probe.artifact.safe_outcome, 'denied', 'artifact redaction must preserve safe outcome metadata');
  assert.deepEqual(probe.artifact.counts, { access_id_count: 2, session_count: 1 }, 'safe aggregate counts must survive artifact redaction');

  assert.match(auditEvents, /'event_type' => 'membership_removed'/, 'membership removal must keep the current canonical audit event name');
  assert.match(auditEvents, /'event_type' => 'call_access_link_opened'/, 'link-open admission must keep the current canonical audit event name');
  assert.match(auditEvents, /'event_type' => 'call_scoped_access_continued'/, 'call-scoped session admission must keep the current canonical audit event name');
  assert.match(
    callAccessReview,
    /'event_type' => \$canonicalEventType[\s\S]*'canonical_event_type' => \$canonicalEventType[\s\S]*'legacy_event_types' => \$legacyEventTypes[\s\S]*'host_name_logged' => false/s,
    'host-name verification audit must write the canonical event and preserve legacy alias markers without logging host names',
  );
  assert.match(
    auditRedactionContract,
    /audit sanitizer must cover raw call-access ids, session ids, and tokens/,
    'existing audit redaction contract must continue pinning persisted audit sensitive fields',
  );
  assert.match(
    auditMembershipContract,
    /audit_sanitizer_probe[\s\S]*session_id[\s\S]*raw-token-should-not-persist[\s\S]*ice_candidate[\s\S]*audit events must not leak sensitive text/s,
    'backend audit membership proof must exercise token, session, SDP/ICE, and media redaction',
  );
  assert.match(
    strongMismatchContract,
    /wrong-user join response[\s\S]*wrong-host session response[\s\S]*unverified-host session response/,
    'denied call-access journeys must be covered by safe mismatch response proofs',
  );
  assert.match(
    callAccessSessionFixationContract,
    /session_id_not_available[\s\S]*session_context_changed[\s\S]*not_bound_to_current_user[\s\S]*call_access_binding_mismatch/s,
    'denial compatibility must include fixation, context-switch, wrong-account, and binding-mismatch names',
  );

  process.stdout.write('[call-access-audit-event-compatibility-contract] PASS\n');
} catch (error) {
  if (error instanceof Error) {
    fail(error.message);
  }
  fail('unknown failure');
}
