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
const lobbyAuditDomain = read('demo/video-chat/backend-king-php/domain/audit/audit_lobby_events.php');
const callManagementCreate = read('demo/video-chat/backend-king-php/domain/calls/call_management_create.php');
const callAccessLinks = read('demo/video-chat/backend-king-php/domain/calls/call_access_links.php');
const callAccessSession = read('demo/video-chat/backend-king-php/domain/calls/call_access_session.php');
const callAccessReview = read('demo/video-chat/backend-king-php/domain/calls/call_access_review.php');
const realtimeLobbyPersistence = read('demo/video-chat/backend-king-php/http/module_realtime_lobby_persistence.php');
const realtimeLobbySecurity = read('demo/video-chat/backend-king-php/http/module_realtime_lobby_security.php');
const backendAuditContract = read('demo/video-chat/backend-king-php/tests/audit-call-access-events-contract.php');
const backendAuditWrapper = read('demo/video-chat/backend-king-php/tests/audit-call-access-events-contract.sh');
const liveFixtureHelper = read('demo/video-chat/frontend-vue/tests/e2e/helpers/iamCallAccessLiveFixtures.js');

for (const helper of [
  'videochat_audit_record_call_created',
  'videochat_audit_record_call_access_invitation_created',
  'videochat_audit_record_call_participant_joined',
  'videochat_audit_record_call_participant_left',
  'videochat_audit_record_call_participant_rejoined',
  'videochat_audit_record_call_participant_kicked',
  'videochat_audit_record_call_owner_transferred',
  'videochat_audit_record_temporary_account_created',
  'videochat_audit_record_call_access_account_compared',
  'videochat_audit_record_call_access_host_verification',
  'videochat_audit_record_call_access_strong_mismatch',
  'videochat_audit_record_call_access_invitation_invalidated',
  'videochat_audit_record_membership_removal',
]) {
  assert.match(auditDomain, new RegExp(`function ${helper}\\(`), `audit domain must expose ${helper}`);
}

for (const helper of [
  'videochat_audit_record_call_lobby_entry',
  'videochat_audit_record_call_lobby_admission',
  'videochat_audit_record_call_lobby_rejection',
  'videochat_audit_record_call_lobby_moderation_denied',
]) {
  assert.match(lobbyAuditDomain, new RegExp(`function ${helper}\\(`), `lobby audit domain must expose ${helper}`);
}

for (const eventType of [
  'call_created',
  'call_access_invitation_created',
  'call_access_link_opened',
  'temporary_account_created',
  'call_access_account_compared',
  'call_access_duplicate_personalized_link_review',
  'call_access_strong_mismatch_denied',
  'call_access_host_verification_failed',
  'call_access_host_name_rejected',
  'call_access_host_name_verified',
  'call_access_host_name_verification_failed',
  'call_access_account_update_confirmation_requested',
  'call_access_invitation_invalidated',
  'call_lobby_entry_created',
  'call_lobby_admission_granted',
  'call_lobby_rejection_recorded',
  'call_lobby_moderation_denied',
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
  'videochat_create_call',
  'videochat_create_call_access_link_for_user',
  'videochat_iam_rejoin_contract_issue_open_guest_session',
  'videochat_call_access_record_host_verification_attempt',
  'videochat_call_access_request_account_update_confirmation',
  'videochat_realtime_mark_call_participant_left',
  'videochat_realtime_handle_lobby_websocket_command',
  'videochat_lobby_apply_command',
  'videochat_update_call_participant_role',
  'videochat_issue_session_for_call_access',
  'videochat_invalidate_call_access_invitation',
  'videochat_iam_rejoin_contract_disable_tenant_membership',
]) {
  assert.ok(backendAuditContract.includes(actionProof), `backend audit contract must drive ${actionProof}`);
}
assert.match(
  callManagementCreate,
  /videochat_create_call[\s\S]*videochat_audit_record_call_created/s,
  'backend call creation path must write call-created audit events',
);
assert.match(
  callAccessLinks,
  /videochat_create_call_access_link_for_user[\s\S]*videochat_audit_record_call_access_invitation_created/s,
  'backend invitation creation path must write invitation-created audit events',
);
assert.match(
  callAccessSession,
  /videochat_audit_record_temporary_account_created/s,
  'anonymous/open call-access session path must audit temporary account creation',
);
assert.match(
  callAccessReview,
  /videochat_call_access_record_host_verification_attempt[\s\S]*videochat_audit_record_call_access_host_verification/s,
  'host verification attempt path must write host-name verification audit events',
);
assert.match(
  auditDomain,
  /call_access_host_verification_failed[\s\S]*call_access_host_name_verification_failed[\s\S]*call_access_host_name_rejected/s,
  'host-name failure audit must retain legacy failed/rejected names and canonical host-name verification failure',
);
assert.match(
  auditDomain,
  /compatibility_event_type' => \$hostNameVerified[\s\S]*\? 'call_access_host_name_verified'[\s\S]*: 'call_access_host_name_rejected'[\s\S]*canonical_event_type' => \$canonicalEventType[\s\S]*legacy_event_types' => \$legacyEventTypes[\s\S]*host_name_logged' => false/s,
  'host-name verification compatibility payload must keep legacy compatibility, canonical, and alias markers without the submitted host name',
);
assert.match(
  backendAuditContract,
  /videochat_iam_rejoin_contract_issue_open_guest_session[\s\S]*temporary_account_created/s,
  'backend audit contract must prove anonymous/open link temporary account creation audit',
);
assert.match(
  backendAuditContract,
  /videochat_call_access_record_host_verification_attempt[\s\S]*correct_host_name[\s\S]*call_access_host_verification_failed[\s\S]*call_access_host_name_rejected[\s\S]*call_access_host_name_verified[\s\S]*call_access_host_name_verification_failed/s,
  'backend audit contract must prove canonical and legacy host-name verification audit names',
);
assert.match(
  backendAuditContract,
  /videochat_call_access_request_account_update_confirmation[\s\S]*call_access_account_update_confirmation_requested/s,
  'backend audit contract must prove account-update request audit',
);
assert.match(
  backendAuditContract,
  /comparisonOutcomes[\s\S]*strong_mismatch[\s\S]*matched/s,
  'backend audit contract must prove link-account comparisons for mismatch and matched outcomes',
);
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
assert.match(
  backendAuditContract,
  /videochat_invalidate_call_access_invitation\([\s\S]*invite invalidation should write an audit event[\s\S]*call_access_invitation_invalidated/s,
  'backend audit contract must prove invite invalidation before the invite-invalidation audit event',
);
assert.match(
  realtimeLobbyPersistence,
  /videochat_realtime_mark_call_participant_pending_for_queue[\s\S]*videochat_realtime_record_lobby_entry_audit/s,
  'lobby persistence path must persist pending state before lobby-entry audit',
);
assert.match(
  realtimeLobbyPersistence,
  /videochat_realtime_mark_call_participant_invite_state_by_user_id[\s\S]*'allowed'[\s\S]*\$auditPersistedUserIds\[\] = \$admittedUserId[\s\S]*videochat_realtime_record_lobby_admission_audit/s,
  'lobby admission path must record admission audit only after the allowed transition persists',
);
assert.match(
  realtimeLobbyPersistence,
  /videochat_realtime_apply_lobby_remove_result[\s\S]*videochat_realtime_record_lobby_rejection_audit/s,
  'lobby rejection path must persist removal before lobby-rejection audit',
);
assert.match(
  realtimeLobbySecurity,
  /videochat_realtime_reject_unauthorized_lobby_moderation_command[\s\S]*videochat_audit_record_call_lobby_moderation_denied/s,
  'unauthorized lobby moderation attempts must be denied and audited separately',
);
assert.match(
  backendAuditContract,
  /lobby\/queue\/join[\s\S]*lobby\/allow[\s\S]*lobby\/reject[\s\S]*call_lobby_entry_created[\s\S]*call_lobby_admission_granted[\s\S]*call_lobby_rejection_recorded[\s\S]*call_lobby_moderation_denied/s,
  'backend audit contract must prove lobby entry, admission, rejection, and unauthorized attempt audits',
);

for (const sensitiveGuard of [
  'videochat_audit_fingerprint($accessId)',
  'videochat_audit_fingerprint($wrongSessionId)',
  "videochat_audit_fingerprint('sess_audit_events_lobby_admit')",
  '$wrongHostName',
  "'access_id', 'session_id', 'token', 'password', 'sdp', 'ice_candidate', 'room_id', 'display_name', 'email'",
]) {
  assert.ok(backendAuditContract.includes(sensitiveGuard), `backend audit contract must guard ${sensitiveGuard}`);
}
for (const source of [auditDomain, lobbyAuditDomain, realtimeLobbyPersistence, backendAuditContract]) {
  assert.doesNotMatch(
    source,
    /payload' => \[[\s\S]{0,300}'(?:access_id|session_id|token|password)' =>/m,
    'IAM audit payloads must not intentionally persist raw access/session/token/password keys',
  );
}
assert.match(
  auditDomain,
  /call_created[\s\S]*title_logged' => false[\s\S]*raw_guest_identifiers_logged' => false/s,
  'call-created audit helper must omit call title and raw guest identifiers',
);
assert.match(
  auditDomain,
  /call_access_invitation_created[\s\S]*raw_link_identifier_logged' => false[\s\S]*raw_guest_identity_logged' => false/s,
  'invitation-created audit helper must omit raw access links and guest identity',
);
assert.match(
  auditDomain,
  /temporary_account_created[\s\S]*raw_guest_identity_logged' => false[\s\S]*raw_link_identifier_logged' => false/s,
  'temporary-account audit helper must omit raw guest identity and raw link identifiers',
);
assert.match(
  auditDomain,
  /call_access_account_compared[\s\S]*foreign_account_data_logged' => false/s,
  'account-comparison audit helper must omit foreign account data',
);
assert.match(
  auditDomain,
  /call_access_host_name_verified[\s\S]*host_name_logged' => false/s,
  'host-name verification audit helper must omit the submitted host name',
);
assert.match(
  auditDomain,
  /call_access_host_verification_succeeded[\s\S]*call_access_host_name_verified[\s\S]*call_access_host_verification_failed[\s\S]*call_access_host_name_verification_failed[\s\S]*call_access_host_name_rejected[\s\S]*compatibility_event_type' => \$hostNameVerified[\s\S]*\? 'call_access_host_name_verified'[\s\S]*: 'call_access_host_name_rejected'[\s\S]*canonical_event_type' => \$canonicalEventType[\s\S]*legacy_event_types' => \$legacyEventTypes[\s\S]*host_name_logged' => false/s,
  'host-name verification audit helper must pin canonical and legacy event aliases without logging host names',
);
assert.match(
  auditDomain,
  /host_name_logged' => false[\s\S]*foreign_account_data_logged' => false[\s\S]*raw_link_identifier_logged' => false/s,
  'strong-mismatch audit helper must explicitly pin host, foreign account, and raw link data as omitted',
);
assert.match(
  auditDomain,
  /call_access_invitation_invalidated[\s\S]*raw_link_identifier_logged' => false[\s\S]*raw_credential_identifier_logged' => false[\s\S]*raw_guest_identity_logged' => false/s,
  'invite-invalidation audit helper must explicitly pin raw link, session, and guest data as omitted',
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
assert.ok(auditProbe.expected_event_types.includes('call_access_invitation_invalidated'));
assert.ok(auditProbe.expected_event_types.includes('call_created'));
assert.ok(auditProbe.expected_event_types.includes('call_access_invitation_created'));
assert.ok(auditProbe.expected_event_types.includes('temporary_account_created'));
assert.ok(auditProbe.expected_event_types.includes('call_access_account_compared'));
assert.ok(auditProbe.expected_event_types.includes('call_access_host_verification_failed'));
assert.ok(auditProbe.expected_event_types.includes('call_access_host_name_rejected'));
assert.ok(auditProbe.expected_event_types.includes('call_access_host_name_verified'));
assert.ok(auditProbe.expected_event_types.includes('call_access_host_name_verification_failed'));
assert.ok(auditProbe.expected_event_types.includes('call_access_account_update_confirmation_requested'));
assert.ok(auditProbe.expected_event_types.includes('call_lobby_entry_created'));
assert.ok(auditProbe.expected_event_types.includes('call_lobby_admission_granted'));
assert.ok(auditProbe.expected_event_types.includes('call_lobby_rejection_recorded'));
assert.ok(auditProbe.expected_event_types.includes('call_lobby_moderation_denied'));
assert.equal(
  auditProbe.fingerprints.session_id,
  liveFixtureModule.iamFixtureFingerprint('sess-audit-contract'),
  'live audit probe must fingerprint session ids instead of exposing raw session ids',
);

process.stdout.write('[iam-call-access-audit-events-contract] PASS\n');
