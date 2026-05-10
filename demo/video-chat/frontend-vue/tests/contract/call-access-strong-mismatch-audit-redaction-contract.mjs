import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
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

function phpLiteral(value) {
  return JSON.stringify(value).replace(/\$/g, '\\$');
}

function fail(message) {
  throw new Error(`[call-access-strong-mismatch-audit-redaction-contract] FAIL: ${message}`);
}

function assertNoNeedles(serialized, needles, label) {
  const haystack = serialized.toLowerCase();
  for (const needle of needles) {
    const text = String(needle).toLowerCase();
    assert.equal(haystack.includes(text), false, `${label} must not contain ${needle}`);
  }
}

const auditEventsPath = path.join(repoRoot, 'demo/video-chat/backend-king-php/domain/audit/audit_events.php');
const auditEvents = readText('demo/video-chat/backend-king-php/domain/audit/audit_events.php');
const moduleCallsAccess = readText('demo/video-chat/backend-king-php/http/module_calls_access.php');
const callAccessSession = readText('demo/video-chat/backend-king-php/domain/calls/call_access_session.php');
const strongMismatchPrivacyContract = readText('demo/video-chat/frontend-vue/tests/contract/call-access-strong-mismatch-privacy-contract.mjs');
const auditRedactionContract = readText('demo/video-chat/frontend-vue/tests/contract/call-access-audit-redaction-contract.mjs');
const auditCompatibilityContract = readText('demo/video-chat/frontend-vue/tests/contract/call-access-audit-event-compatibility-contract.mjs');

try {
  assert.match(
    auditEvents,
    /'call_access_forbidden'\s*=>\s*'call_access_denied'[\s\S]*'call_admission_denied'\s*=>\s*'call_access_denied'/,
    'forbidden and admission-denied aliases must canonicalize to call_access_denied',
  );
  assert.match(
    auditEvents,
    /function videochat_audit_record_event[\s\S]*videochat_audit_canonical_iam_event_type[\s\S]*videochat_audit_sanitize_payload[\s\S]*payload_json/s,
    'audit writes must canonicalize IAM event names before persisting sanitized payload JSON',
  );
  assert.match(
    auditEvents,
    /#\/join\/\[A-Za-z0-9\._-\]\{8,\}#i[\s\S]*#\/api\/call-access\/\[A-Za-z0-9\._-\]\{8,\}/,
    'artifact redaction must cover raw access links and call-access API paths',
  );
  assert.match(
    auditEvents,
    /\\bBearer\\s\+\[A-Za-z0-9\._~\+\\\/=-\]\{8,\}\\b[\s\S]*authorization\|cookie\|set-cookie[\s\S]*candidate:\[\^\\r\\n\]\+[\s\S]*\/v=0/,
    'artifact redaction must cover bearer tokens, cookies, ICE candidates, and SDP blobs',
  );

  assert.match(
    moduleCallsAccess,
    /mismatch'\s*=>\s*'strong_personalized_link'[\s\S]*'auth'\s*=>\s*'not_bound_to_current_user'[\s\S]*'host_name'\s*=>\s*'not_verified'/,
    'wrong-account public join denial must expose only canonical strong-mismatch fields',
  );
  assert.match(
    callAccessSession,
    /'auth'\s*=>\s*'not_bound_to_current_user'[\s\S]*'host_name'\s*=>\s*\$hostName === '' \? 'not_verified' : 'wrong_host_name'/,
    'session issuance denial must collapse wrong-host mismatch to safe host_name field states',
  );
  assert.match(
    strongMismatchPrivacyContract,
    /strong personalized-link mismatch wrong host denial gives no access[\s\S]*call_access_forbidden[\s\S]*wrong_host_name/,
    'existing strong-mismatch privacy proof must cover wrong-host forbidden UI responses',
  );
  assert.match(
    auditRedactionContract,
    /call-access link-open audit must fingerprint link ids[\s\S]*call-scoped continuation audit must fingerprint access\/session ids/s,
    'existing audit redaction proof must keep raw identifiers out of successful call-access audit events',
  );
  assert.match(
    auditCompatibilityContract,
    /CALL_ACCESS_FORBIDDEN:\s*'call_access_denied'/,
    'existing audit compatibility proof must keep forbidden aliases mapped to call_access_denied',
  );

  const probePhp = `
require ${phpLiteral(auditEventsPath)};
$accessId = '11111111-1111-4111-8111-111111111111';
$sessionId = 'sess_strong_mismatch_raw_session_should_not_persist';
$token = 'raw-token-strong-mismatch-should-not-persist';
$cookie = 'king_session=raw-cookie-strong-mismatch-should-not-persist';
$callTitle = 'Private Strong Mismatch Call';
$hostEmail = 'private-host@example.test';
$targetEmail = 'foreign-target@example.test';
$sdp = "v=0\\r\\no=- 1 2 IN IP4 127.0.0.1\\r\\ns=-\\r\\n";
$ice = 'candidate:1 1 udp 2122260223 127.0.0.1 9 typ host';
$artifact = videochat_audit_redact_artifact_payload([
  'report_name' => 'iam-strong-mismatch-audit-redaction',
  'status' => 'failed',
  'message' => 'GET /join/' . $accessId . ' Authorization: Bearer ' . $token . ' Cookie: ' . $cookie,
  'request' => [
    'url' => '/api/call-access/' . $accessId . '/session',
    'headers' => ['authorization' => 'Bearer ' . $token, 'cookie' => $cookie],
    'body' => ['host_name' => 'Wrong Host', 'sdp' => $sdp, 'ice_candidate' => $ice],
  ],
  'call' => ['id' => 'raw-call-id', 'title' => $callTitle],
  'foreign_person_data' => ['host_email' => $hostEmail, 'target_email' => $targetEmail],
  'safe_counts' => ['denied_total' => 3],
]);
if (!extension_loaded('pdo_sqlite')) {
  echo json_encode([
    'sqlite_skipped' => true,
    'canonical' => [
      'call_access_forbidden' => videochat_audit_canonical_iam_event_type('call_access_forbidden'),
      'call_access_rejected' => videochat_audit_canonical_iam_event_type('call_access_rejected'),
      'call_access_conflict' => videochat_audit_canonical_iam_event_type('call_access_conflict'),
    ],
    'artifact' => $artifact,
  ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
  exit(0);
}
$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$base = [
  'tenant_id' => 7,
  'actor_user_id' => 22,
  'target_user_id' => 33,
  'call_id' => 'safe-call-reference',
  'resource_type' => 'call_access_denial',
  'resource_fingerprint' => videochat_audit_fingerprint($accessId),
  'session_fingerprint' => videochat_audit_fingerprint($sessionId),
];
$events = [
  $base + [
    'event_type' => 'call_access_forbidden',
    'payload' => [
      'category' => 'forbidden',
      'outcome' => 'denied',
      'mismatch' => 'strong_personalized_link',
      'fields' => ['auth' => 'not_bound_to_current_user', 'host_name' => 'not_verified'],
      'raw_access_link_logged' => false,
      'raw_cookie_logged' => false,
      'raw_session_identifier_logged' => false,
      'raw_signaling_logged' => false,
      'access_id' => $accessId,
      'session_id' => $sessionId,
      'token' => $token,
      'cookie' => $cookie,
      'sdp' => $sdp,
      'ice_candidate' => $ice,
    ],
  ],
  $base + [
    'event_type' => 'call_access_rejected',
    'payload' => [
      'category' => 'forbidden',
      'outcome' => 'denied',
      'mismatch' => 'strong_personalized_link',
      'fields' => ['auth' => 'not_bound_to_current_user', 'host_name' => 'wrong_host_name'],
      'raw_access_link_logged' => false,
      'raw_cookie_logged' => false,
      'raw_session_identifier_logged' => false,
      'raw_signaling_logged' => false,
      'private_call_data' => ['title' => $callTitle],
      'foreign_person_data' => ['host_email' => $hostEmail, 'target_email' => $targetEmail],
    ],
  ],
  $base + [
    'event_type' => 'call_access_conflict',
    'payload' => [
      'category' => 'conflict',
      'outcome' => 'denied',
      'mismatch' => 'strong_personalized_link',
      'fields' => ['auth' => 'session_context_changed'],
      'raw_access_link_logged' => false,
      'raw_cookie_logged' => false,
      'raw_session_identifier_logged' => false,
      'raw_signaling_logged' => false,
      'access_token' => $token,
    ],
  ],
];
foreach ($events as $event) {
  $recorded = videochat_audit_record_event($pdo, $event);
  if (!(bool) ($recorded['ok'] ?? false)) {
    throw new RuntimeException('audit event did not record: ' . json_encode($recorded));
  }
}
echo json_encode([
  'sqlite_skipped' => false,
  'events' => videochat_audit_fetch_events($pdo, ['limit' => 10]),
  'denied_alias_count' => count(videochat_audit_fetch_events($pdo, ['event_type' => 'call_access_forbidden'])),
  'artifact' => $artifact,
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
`;

  const probe = JSON.parse(execFileSync('php', ['-r', probePhp], { encoding: 'utf8' }));
  const forbiddenNeedles = [
    '11111111-1111-4111-8111-111111111111',
    'sess_strong_mismatch_raw_session_should_not_persist',
    'raw-token-strong-mismatch-should-not-persist',
    'raw-cookie-strong-mismatch-should-not-persist',
    'Private Strong Mismatch Call',
    'private-host@example.test',
    'foreign-target@example.test',
    'candidate:1',
    'v=0',
    '/api/call-access/',
    '/join/',
    'raw-call-id',
  ];
  if (probe.sqlite_skipped) {
    assert.deepEqual(probe.canonical, {
      call_access_forbidden: 'call_access_denied',
      call_access_rejected: 'call_access_denied',
      call_access_conflict: 'call_access_conflict',
    }, 'canonical mismatch event aliases must resolve even when sqlite persistence is unavailable');
  } else {
    assert.equal(probe.denied_alias_count, 2, 'denied alias fetch must find both forbidden strong-mismatch denials');
    assert.equal(probe.events.length, 3, 'probe must record forbidden, wrong-host, and conflict mismatch audit events');

    const [wrongAccount, wrongHost, conflict] = probe.events;
    assert.equal(wrongAccount.event_type, 'call_access_denied', 'wrong-account denial must persist canonical event name');
    assert.equal(wrongHost.event_type, 'call_access_denied', 'wrong-host denial must persist canonical event name');
    assert.equal(conflict.event_type, 'call_access_conflict', 'conflict denial must keep canonical conflict event name');

    const allowedEventKeys = new Set([
      'id',
      'tenant_id',
      'event_type',
      'actor_user_id',
      'target_user_id',
      'call_id',
      'resource_type',
      'resource_id',
      'resource_fingerprint',
      'session_fingerprint',
      'payload',
      'created_at',
    ]);
    const allowedPayloadKeys = new Set([
      'category',
      'outcome',
      'mismatch',
      'fields',
      'raw_access_link_logged',
      'raw_cookie_logged',
      'raw_session_identifier_logged',
      'raw_signaling_logged',
    ]);
    const allowedFieldKeys = new Set(['auth', 'host_name']);

    for (const event of probe.events) {
      for (const key of Object.keys(event)) {
        assert.ok(allowedEventKeys.has(key), `audit event must not expose unexpected top-level key ${key}`);
      }
      assert.equal(event.resource_type, 'call_access_denial', 'strong mismatch audit must use a generic denial resource type');
      assert.equal(event.resource_id, '', 'strong mismatch audit must not persist raw access-link resource ids');
      assert.match(event.resource_fingerprint, /^sha256:[a-f0-9]{64}$/, 'access id must be retained only as a fingerprint');
      assert.match(event.session_fingerprint, /^sha256:[a-f0-9]{64}$/, 'session id must be retained only as a fingerprint');
      assert.equal(event.payload.outcome, 'denied', 'strong mismatch audit payload must preserve denied outcome');
      assert.equal(event.payload.mismatch, 'strong_personalized_link', 'strong mismatch audit payload must preserve canonical mismatch');
      assert.equal(event.payload.raw_access_link_logged, false, 'audit must explicitly state raw access links were not logged');
      assert.equal(event.payload.raw_cookie_logged, false, 'audit must explicitly state raw cookies were not logged');
      assert.equal(event.payload.raw_session_identifier_logged, false, 'audit must explicitly state raw session ids were not logged');
      assert.equal(event.payload.raw_signaling_logged, false, 'audit must explicitly state SDP/ICE were not logged');
      for (const key of Object.keys(event.payload)) {
        assert.ok(allowedPayloadKeys.has(key), `strong mismatch payload must not expose ${key}`);
      }
      for (const key of Object.keys(event.payload.fields || {})) {
        assert.ok(allowedFieldKeys.has(key), `strong mismatch field errors must not expose ${key}`);
      }
    }

    assert.equal(wrongAccount.payload.category, 'forbidden', 'wrong-account mismatch must be categorized forbidden');
    assert.deepEqual(wrongAccount.payload.fields, {
      auth: 'not_bound_to_current_user',
      host_name: 'not_verified',
    });
    assert.equal(wrongHost.payload.category, 'forbidden', 'wrong-host mismatch must be categorized forbidden');
    assert.deepEqual(wrongHost.payload.fields, {
      auth: 'not_bound_to_current_user',
      host_name: 'wrong_host_name',
    });
    assert.equal(conflict.payload.category, 'conflict', 'verified-context mismatch must be categorized conflict');
    assert.deepEqual(conflict.payload.fields, { auth: 'session_context_changed' });

    assertNoNeedles(JSON.stringify(probe.events), forbiddenNeedles, 'persisted audit events');
  }
  assertNoNeedles(JSON.stringify(probe.artifact), forbiddenNeedles, 'redacted audit artifacts');
  assert.match(
    JSON.stringify(probe.artifact),
    /\[redacted:audit_artifact\]/,
    'artifact/log redaction must leave explicit redaction markers',
  );
  assert.deepEqual(
    probe.artifact.safe_counts,
    { denied_total: 3 },
    'artifact/log redaction must preserve safe aggregate mismatch counts',
  );

  process.stdout.write('[call-access-strong-mismatch-audit-redaction-contract] PASS\n');
} catch (error) {
  if (error instanceof Error) {
    fail(error.message);
  }
  fail('unknown failure');
}
