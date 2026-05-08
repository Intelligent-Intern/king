import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);
const frontendRoot = path.resolve(__dirname, '../..');
const repoRoot = path.resolve(frontendRoot, '../../..');

function read(relativePath) {
  return fs.readFileSync(path.join(repoRoot, relativePath), 'utf8');
}

const auditDomain = read('demo/video-chat/backend-king-php/domain/audit/audit_events.php');
const backendAuditContract = read('demo/video-chat/backend-king-php/tests/audit-call-access-events-contract.php');
const backendAuditWrapper = read('demo/video-chat/backend-king-php/tests/audit-call-access-events-contract.sh');
const liveFixtureHelper = read('demo/video-chat/frontend-vue/tests/e2e/helpers/iamCallAccessLiveFixtures.js');

for (const helper of [
  'videochat_audit_record_call_participant_joined',
  'videochat_audit_record_call_participant_left',
  'videochat_audit_record_call_participant_rejoined',
  'videochat_audit_record_call_participant_kicked',
  'videochat_audit_record_call_owner_transferred',
  'videochat_audit_record_call_access_strong_mismatch',
  'videochat_audit_record_membership_removal',
]) {
  assert.match(auditDomain, new RegExp(`function ${helper}\\(`), `audit domain must expose ${helper}`);
}

for (const eventType of [
  'call_access_link_opened',
  'call_access_duplicate_personalized_link_review',
  'call_access_strong_mismatch_denied',
  'call_participant_joined',
  'call_participant_left',
  'call_participant_rejoined',
  'call_participant_kicked',
  'call_owner_transferred',
  'membership_removed',
]) {
  assert.ok(backendAuditContract.includes(`'${eventType}'`), `backend audit contract must assert ${eventType}`);
  assert.ok(liveFixtureHelper.includes(`'${eventType}'`), `live audit probe must include ${eventType}`);
}

for (const actionProof of [
  'videochat_realtime_mark_call_participant_left',
  'videochat_lobby_apply_command',
  'videochat_update_call_participant_role',
  'videochat_issue_session_for_call_access',
  'videochat_iam_rejoin_contract_disable_tenant_membership',
]) {
  assert.ok(backendAuditContract.includes(actionProof), `backend audit contract must drive ${actionProof}`);
}
assert.match(
  backendAuditContract,
  /videochat_iam_rejoin_contract_connection\([\s\S]*audit-participant-join[\s\S]*videochat_audit_record_call_participant_joined/s,
  'backend audit contract must prove a real join before the join audit event',
);
assert.match(
  backendAuditContract,
  /videochat_iam_rejoin_contract_connection\([\s\S]*audit-participant-rejoin[\s\S]*videochat_audit_record_call_participant_rejoined/s,
  'backend audit contract must prove a real same-session rejoin before the rejoin audit event',
);
assert.match(
  backendAuditContract,
  /videochat_lobby_apply_command[\s\S]*lobby\/kick[\s\S]*videochat_audit_record_call_participant_kicked/s,
  'backend audit contract must prove kick semantics before the kick audit event',
);
assert.match(
  backendAuditContract,
  /videochat_update_call_participant_role[\s\S]*videochat_audit_events_contract_owner_count\(\$pdo, \$callId\) === 1[\s\S]*videochat_audit_record_call_owner_transferred/s,
  'backend audit contract must prove exactly one owner before the owner-transfer audit event',
);
assert.match(
  backendAuditContract,
  /strong mismatch should deny session issuance before audit[\s\S]*videochat_audit_record_call_access_strong_mismatch/s,
  'backend audit contract must prove strong mismatch denial before the strong-mismatch audit event',
);
assert.match(
  backendAuditContract,
  /!videochat_tenant_user_is_member\([\s\S]*videochat_audit_record_membership_removal/s,
  'backend audit contract must prove membership is removed before the membership audit event',
);

for (const sensitiveGuard of [
  'videochat_audit_fingerprint($accessId)',
  'videochat_audit_fingerprint($wrongSessionId)',
  '$wrongHostName',
  "'access_id', 'session_id', 'token', 'password', 'sdp', 'ice_candidate'",
]) {
  assert.ok(backendAuditContract.includes(sensitiveGuard), `backend audit contract must guard ${sensitiveGuard}`);
}
for (const source of [auditDomain, backendAuditContract]) {
  assert.doesNotMatch(
    source,
    /payload' => \[[\s\S]{0,300}'(?:access_id|session_id|token|password)' =>/m,
    'IAM audit payloads must not intentionally persist raw access/session/token/password keys',
  );
}
assert.match(
  auditDomain,
  /host_name_logged' => false[\s\S]*foreign_account_data_logged' => false[\s\S]*raw_link_identifier_logged' => false/s,
  'strong-mismatch audit helper must explicitly pin host, foreign account, and raw link data as omitted',
);
assert.match(
  liveFixtureHelper,
  /kick:\s*\{\s*type:\s*'lobby\/kick'/,
  'live fixture lobby probe must expose an explicit kick frame',
);
assert.match(
  backendAuditWrapper,
  /pdo_sqlite[\s\S]*audit-call-access-events-contract\.php/s,
  'backend audit shell wrapper must skip cleanly without local pdo_sqlite and run the PHP proof when available',
);

const liveFixtureModule = await import(pathToFileURL(path.join(
  repoRoot,
  'demo/video-chat/frontend-vue/tests/e2e/helpers/iamCallAccessLiveFixtures.js',
)).href);
const auditProbe = liveFixtureModule.callAccessAuditProbe({
  tenant: { id: 11 },
  call: { id: 'call-audit-contract' },
  accessLink: { id: 'access-audit-contract' },
  session: { token: 'sess-audit-contract' },
  targetUser: { id: 7 },
});
assert.ok(auditProbe.expected_event_types.includes('call_participant_kicked'));
assert.ok(auditProbe.expected_event_types.includes('call_owner_transferred'));
assert.ok(auditProbe.expected_event_types.includes('call_access_strong_mismatch_denied'));
assert.equal(
  auditProbe.fingerprints.session_id,
  liveFixtureModule.iamFixtureFingerprint('sess-audit-contract'),
  'live audit probe must fingerprint session ids instead of exposing raw session ids',
);

process.stdout.write('[iam-call-access-audit-events-contract] PASS\n');
