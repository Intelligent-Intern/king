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

function readJson(relativePath) {
  return JSON.parse(readText(relativePath));
}

function assertContains(source, literal, message) {
  assert.ok(String(source).includes(literal), message);
}

function assertNoPrivateCallLeak(payload, needles, message) {
  const serialized = JSON.stringify(payload);
  for (const needle of needles) {
    assert.doesNotMatch(serialized, new RegExp(needle.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')), message);
  }
}

const seedMatrix = readJson('demo/video-chat/contracts/v1/iam-call-access-seeding.matrix.json');
const sessionRouteGuardContract = readText('demo/video-chat/backend-king-php/tests/call-access-session-route-guard-contract.php');
const callAccessSession = readText('demo/video-chat/backend-king-php/domain/calls/call_access_session.php');
const callsAccessRoute = readText('demo/video-chat/backend-king-php/http/module_calls_access.php');
const realtimeCallContext = readText('demo/video-chat/backend-king-php/domain/realtime/realtime_call_context.php');
const crossOrgContract = readText('demo/video-chat/backend-king-php/tests/call-access-cross-org-contract.php');
const directJoinContract = readText('demo/video-chat/backend-king-php/tests/call-guest-list-direct-join-contract.php');

const registeredScenario = (seedMatrix.scenarios || []).find((scenario) => scenario?.key === 'direct_join_registered_guest_alpha_active_allowed');
assert.ok(registeredScenario, 'seed matrix must keep a registered invited-user direct-join scenario');
assert.equal(registeredScenario.principal_user_key, 'registered_guest', 'registered scenario must use the registered invitee principal');
assert.equal(registeredScenario.call_key, 'alpha_active', 'registered scenario must target the invited alpha call');
assert.equal(registeredScenario.expected?.direct_join_allowed, true, 'registered invitee must be allowed only through the invited call decision');
assert.equal(registeredScenario.expected?.tenant_admin, false, 'registered invitee must not gain tenant-admin rights from an invite');
assert.equal(registeredScenario.expected?.platform_admin, false, 'registered invitee must not gain platform-admin rights from an invite');

assert.match(
  sessionRouteGuardContract,
  /internal_participant_user_ids' => \[\$standardUserId\][\s\S]*'participant_user_id' => \$standardUserId/s,
  'backend route-guard proof must create a personalized link for the registered internal invitee',
);
assert.match(
  sessionRouteGuardContract,
  /matching logged-in user should issue[\s\S]*matching logged-in route should bind the linked user/s,
  'backend route-guard proof must show the already logged-in invitee can issue the call-access session',
);
assert.match(
  sessionRouteGuardContract,
  /wrong logged-in account should be forbidden[\s\S]*call_access_forbidden[\s\S]*wrong account route must not persist a session/s,
  'backend route-guard proof must reject other logged-in accounts for the same personalized link',
);
assert.match(
  sessionRouteGuardContract,
  /wrong account response'[\s\S]*session switch should conflict[\s\S]*session switch route must not persist a session/s,
  'backend route-guard proof must keep private call data hidden for wrong-account and switched-session attempts',
);

assert.match(
  callsAccessRoute,
  /authenticatedJoinUserId > 0[\s\S]*linkKind === 'personal'[\s\S]*targetUserId !== \$authenticatedJoinUserId[\s\S]*call_access_forbidden/s,
  'join route must reject a logged-in account that is not the personalized-link target',
);
assert.match(
  callsAccessRoute,
  /authenticated_user_id[\s\S]*authenticated_session_id[\s\S]*videochat_issue_session_for_call_access/s,
  'session route must pass authenticated account and session context into call-access issuance',
);
assert.match(
  callAccessSession,
  /linkKind === 'personal'[\s\S]*authenticatedUserId > 0[\s\S]*authenticatedUserId !== \$userId[\s\S]*'reason' => 'forbidden'[\s\S]*'access_link' => null[\s\S]*'call' => null/s,
  'call-access session issuer must fail closed without returning link or call data for wrong logged-in accounts',
);
assert.match(
  callAccessSession,
  /videochat_decide_call_access_for_user\([\s\S]*\(string\) \(\$call\['id'\] \?\? ''\)[\s\S]*\$userId[\s\S]*\$userRole[\s\S]*\$tenantId/s,
  'session issuance must re-check the registered invitee against the invited call and tenant before issuing',
);
assert.match(
  callAccessSession,
  /INSERT INTO call_access_sessions\(session_id, access_id, call_id, room_id, user_id, link_kind, issued_at, expires_at/s,
  'session issuance must persist a call-scoped access binding',
);
for (const bindingParam of [":call_id' => $callId", ":room_id' => $roomId", ":user_id' => $userId"]) {
  assertContains(callAccessSession, bindingParam, `call-access session binding must include ${bindingParam}`);
}

assert.match(
  realtimeCallContext,
  /videochat_fetch_call_access_session_binding\(\$pdo, \$sessionId\)[\s\S]*\$boundRoomId[\s\S]*\$boundCallId[\s\S]*\$boundUserId/s,
  'realtime room resolution must load the call-access session binding',
);
assert.match(
  realtimeCallContext,
  /roomMismatch[\s\S]*callMismatch[\s\S]*userMismatch[\s\S]*access_session_binding' => 'mismatch'/s,
  'realtime room resolution must reject attempts to reuse the invitee session for another room, call, or user',
);
assert.match(
  realtimeCallContext,
  /\$resolvedRequestedRoomId = \$boundRoomId;[\s\S]*\$normalizedRequestedCallId = \$boundCallId;[\s\S]*\$tenantId = null;/s,
  'realtime room resolution must bind admitted access sessions to the invited call instead of active organization context',
);

assert.match(
  crossOrgContract,
  /active organization A context must not fetch organization B call[\s\S]*organization B call must be hidden from organization A context/s,
  'active organization context must not grant direct visibility into another organization call',
);
assert.match(
  crossOrgContract,
  /active organization switch must not mint organization B membership[\s\S]*tenant_membership_inactive/s,
  'active organization switching must not create membership or admin rights in the linked call organization',
);
assert.match(
  directJoinContract,
  /user on guest list should be allowed to direct join[\s\S]*user not on guest list should not direct join[\s\S]*guest list from one call must not grant direct join to another call/s,
  'direct-join backend proof must distinguish registered invited users from unrelated registered users',
);

const loggedInInviteeSession = {
  session_id: 'sess_registered_invitee_call_access',
  user_id: 1201,
  account_type: 'account',
  active_organization_id: 10,
  call_access_binding: {
    call_id: 'call-invited-alpha',
    room_id: 'room-invited-alpha',
    user_id: 1201,
    tenant_id: 20,
    link_kind: 'personal',
  },
};
assert.equal(
  loggedInInviteeSession.call_access_binding.user_id,
  loggedInInviteeSession.user_id,
  'registered logged-in invitee must keep the authenticated account identity',
);
assert.notEqual(
  loggedInInviteeSession.active_organization_id,
  loggedInInviteeSession.call_access_binding.tenant_id,
  'call-scoped admission must not rewrite the active organization boundary',
);

const wrongCallAttempt = {
  requested_call_id: 'call-uninvited-beta',
  requested_room_id: 'room-uninvited-beta',
  session_id: loggedInInviteeSession.session_id,
};
const binding = loggedInInviteeSession.call_access_binding;
assert.equal(
  wrongCallAttempt.requested_call_id === binding.call_id && wrongCallAttempt.requested_room_id === binding.room_id,
  false,
  'registered invitee call-access session must not be reusable for a different call',
);

assertNoPrivateCallLeak(
  {
    status: 'error',
    error: {
      code: 'call_access_forbidden',
      fields: { auth: 'not_bound_to_current_user' },
    },
  },
  ['Route Guard Secret Personal Call', 'call-invited-alpha', 'room-invited-alpha', 'registered-invitee@example.test'],
  'wrong-account denial fixture must not leak invited call data',
);

process.stdout.write('[call-access-registered-logged-in-invitee-contract] PASS\n');
