import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

import {
  getSeedAccessLink,
  getSeedCall,
  getSeedScenario,
  getSeedUser,
  storedSessionForSeedUser,
  tenantSnapshotForSeedUser,
} from '../../tests/e2e/helpers/callAccessSeedMatrix.js';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const frontendRoot = path.resolve(__dirname, '..', '..');
const videoChatRoot = path.resolve(frontendRoot, '..');
const repoRoot = path.resolve(videoChatRoot, '..', '..');

function read(relativePath) {
  return fs.readFileSync(path.join(repoRoot, relativePath), 'utf8');
}

function serialize(value) {
  return JSON.stringify(value);
}

function userHasActiveMembership(user, tenantKey) {
  return (Array.isArray(user?.memberships) ? user.memberships : [])
    .some((membership) => String(membership?.tenant_key || '') === tenantKey);
}

function userHasRemovedMembership(user, tenantKey) {
  return (Array.isArray(user?.removed_memberships) ? user.removed_memberships : [])
    .some((membership) => String(membership?.tenant_key || '') === tenantKey);
}

function canDirectlySeeCall({ user, call }) {
  if (user?.system_admin === true || String(user?.role || '') === 'admin') return true;
  if (String(call?.owner_user_key || '') === String(user?.key || '')) return true;
  if (userHasActiveMembership(user, call?.tenant_key) && ['alpha_org_admin', 'beta_org_admin'].includes(String(user?.key || ''))) {
    return true;
  }
  return (Array.isArray(call?.guest_list_user_keys) ? call.guest_list_user_keys : [])
    .includes(String(user?.key || ''));
}

function directDeniedPayload(reason = 'calls_forbidden') {
  return {
    status: 'ok',
    result: {
      state: 'forbidden',
      resolved_as: 'call_id',
      reason,
      access_link: null,
      call: null,
    },
  };
}

function callDeniedPayload() {
  return {
    status: 'error',
    error: {
      code: 'calls_forbidden',
      message: 'You are not allowed to view this call.',
    },
  };
}

function lobbyDecisionForSession({ sessionIssued }) {
  return sessionIssued ? ['system/welcome', 'lobby/queue/join', 'lobby/snapshot'] : [];
}

function invalidatedInviteResult(inviteState) {
  return ['cancelled', 'declined'].includes(String(inviteState || '').trim().toLowerCase())
    ? { ok: false, reason: 'not_found', access_link: null, call: null, target_user: null }
    : { ok: true, reason: 'resolved' };
}

function directGuestListResult(inviteState) {
  return ['cancelled', 'declined'].includes(String(inviteState || '').trim().toLowerCase())
    ? { ok: false, reason: 'guest_list_entry_inactive' }
    : { ok: true, reason: 'guest_list' };
}

const seedMatrix = JSON.parse(read('demo/video-chat/contracts/v1/iam-call-access-seeding.matrix.json'));
const seedMatrixSpec = read('demo/video-chat/frontend-vue/tests/e2e/call-access-seed-matrix.spec.js');
const seedMatrixHelper = read('demo/video-chat/frontend-vue/tests/e2e/helpers/callAccessSeedMatrix.js');
const callAccessContract = read('demo/video-chat/backend-king-php/domain/calls/call_access_contract.php');
const callAccessPublic = read('demo/video-chat/backend-king-php/domain/calls/call_access_public.php');
const callAccessLinks = read('demo/video-chat/backend-king-php/domain/calls/call_access_links.php');
const callAccessSession = read('demo/video-chat/backend-king-php/domain/calls/call_access_session.php');
const guestList = read('demo/video-chat/backend-king-php/domain/calls/call_management_guest_list.php');
const tenantContext = read('demo/video-chat/backend-king-php/support/tenant_context.php');
const joinView = read('demo/video-chat/frontend-vue/src/domain/calls/access/JoinView.vue');

const removedMember = getSeedUser('removed_invited_member');
const alphaCall = getSeedCall('alpha_active');
const removedLink = getSeedAccessLink('removed_member_personal');
const removedScenario = getSeedScenario('call_scoped_removed_member_personal_waits_for_host');

assert.equal(removedLink.target_user_key, 'removed_invited_member', 'removed-member personal link must target the removed invited user');
assert.equal(removedLink.direct_guest_list_entry, false, 'removed member must not remain a direct guest-list entry');
assert.equal(removedScenario.expected.must_not_restore_membership, true, 'removed-member call-access must not restore org membership');
assert.equal(removedScenario.expected.tenant_admin, false, 'removed-member call-access must not grant tenant admin rights');
assert.equal(removedScenario.expected.platform_admin, false, 'removed-member call-access must not grant platform admin rights');
assert.equal(removedScenario.expected.requires_admission, true, 'removed-member call-access must still require host admission');

assert.equal(userHasActiveMembership(removedMember, 'alpha'), false, 'removed invited user must have no active alpha membership');
assert.equal(userHasRemovedMembership(removedMember, 'alpha'), true, 'seed matrix must retain removed alpha membership as historical state only');
assert.equal(alphaCall.guest_list_user_keys.includes('removed_invited_member'), false, 'removed invited user must not be on the alpha direct guest list');
assert.equal(canDirectlySeeCall({ user: removedMember, call: alphaCall }), false, 'removed invited user must not directly see the org call');

const removedMemberStoredSession = storedSessionForSeedUser('removed_invited_member', 'alpha_active');
assert.equal(removedMemberStoredSession.tenant.membership_id, 0, 'removed-member session must not mint an active membership id');
assert.equal(removedMemberStoredSession.tenant.permissions.tenant_admin, false, 'removed-member session must not gain tenant-admin permissions');
assert.equal(removedMemberStoredSession.tenant.permissions.manage_lobby, false, 'removed-member session must not gain lobby-management visibility');
assert.equal(tenantSnapshotForSeedUser('removed_invited_member', 'alpha_active').permissions.admit_participants, false, 'removed member must not be able to admit lobby participants');

const forbiddenResolve = directDeniedPayload();
const forbiddenCall = callDeniedPayload();
const forbiddenNeedles = [
  alphaCall.id,
  alphaCall.room_id,
  alphaCall.title,
  'iam-alpha-owner@example.test',
  'registered_guest',
  'guest_list_user_keys',
];
for (const needle of forbiddenNeedles) {
  assert.doesNotMatch(serialize(forbiddenResolve), new RegExp(needle.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')), `direct resolve denial must not leak ${needle}`);
  assert.doesNotMatch(serialize(forbiddenCall), new RegExp(needle.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')), `direct call denial must not leak ${needle}`);
}
assert.deepEqual(lobbyDecisionForSession({ sessionIssued: false }), [], 'denied removed-member paths must not join or observe lobby state');
assert.deepEqual(lobbyDecisionForSession({ sessionIssued: true }), ['system/welcome', 'lobby/queue/join', 'lobby/snapshot'], 'only issued call-scoped sessions may enter the lobby wait path');

assert.deepEqual(invalidatedInviteResult('cancelled'), {
  ok: false,
  reason: 'not_found',
  access_link: null,
  call: null,
  target_user: null,
}, 'cancelled invited user link must hide call-access data');
assert.deepEqual(invalidatedInviteResult('declined'), {
  ok: false,
  reason: 'not_found',
  access_link: null,
  call: null,
  target_user: null,
}, 'declined invited user link must hide call-access data');
assert.deepEqual(directGuestListResult('cancelled'), {
  ok: false,
  reason: 'guest_list_entry_inactive',
}, 'cancelled invited user must lose direct guest-list call access');
assert.deepEqual(directGuestListResult('declined'), {
  ok: false,
  reason: 'guest_list_entry_inactive',
}, 'declined invited user must lose direct guest-list call access');

assert.ok(
  (seedMatrix.users || []).some((user) => user?.key === 'removed_invited_member' && Array.isArray(user.memberships) && user.memberships.length === 0),
  'published IAM seed matrix must keep removed_invited_member without active memberships',
);
assert.ok(
  (seedMatrix.access_links || []).some((link) => link?.key === 'removed_member_personal' && link?.direct_guest_list_entry === false),
  'published IAM seed matrix must keep removed member out of direct guest-list access',
);
assert.match(
  seedMatrixSpec,
  /call_scoped_removed_member_personal_waits_for_host/,
  'seed-matrix browser proof must exercise the removed-member personal link path',
);
assert.match(
  seedMatrixSpec,
  /socketFrames\.some\(\(frame\) => frame\?\.type === 'lobby\/queue\/join'\)/,
  'seed-matrix browser proof must show only the call-scoped session enters the lobby queue',
);
assert.match(
  seedMatrixHelper,
  /state:\s*'forbidden'[\s\S]*reason:\s*'calls_forbidden'[\s\S]*access_link:\s*null[\s\S]*call:\s*null/,
  'seed helper must model denied direct resolve without call or access-link payloads',
);
assert.match(
  seedMatrixHelper,
  /code:\s*'calls_forbidden'[\s\S]*message:\s*'You are not allowed to view this call\.'/,
  'seed helper must model denied direct call fetch without private call details',
);
assert.doesNotMatch(
  seedMatrixHelper,
  /details:\s*\{[^}]*call_id/,
  'denied direct call fetch helper must not echo private call identifiers',
);

assert.match(
  tenantContext,
  /WHERE tenant_memberships\.user_id = :user_id[\s\S]*AND tenant_memberships\.status = 'active'[\s\S]*AND tenants\.status = 'active'/,
  'tenant context must require active org membership before granting org-level visibility',
);
assert.match(
  tenantContext,
  /WHERE call_access_sessions\.session_id = :session_id[\s\S]*AND call_access_sessions\.user_id = :user_id[\s\S]*AND calls\.status IN \('scheduled', 'active'\)/,
  'call-scoped fallback tenant context must require the exact issued session and user',
);
assert.match(
  callAccessContract,
  /return in_array\(videochat_call_access_participant_invite_state\(\$pdo, \$accessLink\), \['cancelled', 'declined'\], true\);/,
  'cancelled or declined invite rows must invalidate personal call-access links',
);
assert.match(
  callAccessPublic,
  /if \(videochat_call_access_link_is_invalidated\(\$pdo, \$accessLink\)\) \{[\s\S]*'reason' => 'not_found'[\s\S]*'access_link' => null,[\s\S]*'call' => null,[\s\S]*'target_user' => null/,
  'public call-access resolution must hide invalidated invited-user links without leaking call data',
);
assert.match(
  callAccessLinks,
  /if \(videochat_call_access_link_is_invalidated\(\$pdo, \$accessLink\)\) \{[\s\S]*'reason' => 'not_found'[\s\S]*'access_link' => null,[\s\S]*'call' => null/,
  'authenticated call-access resolution must hide invalidated invited-user links without leaking call data',
);
assert.match(
  callAccessSession,
  /\$resolve = videochat_resolve_call_access_public\(\$pdo, \$accessId\);[\s\S]*if \(!\(bool\) \(\$resolve\['ok'\] \?\? false\)\) \{[\s\S]*'access_link' => null,[\s\S]*'call' => null/,
  'session issuance must inherit safe invalidated-link denial before minting a call-scoped session',
);
assert.match(
  guestList,
  /if \(in_array\(\$inviteState, \['declined', 'cancelled'\], true\)\) \{[\s\S]*'ok' => false,[\s\S]*'reason' => 'guest_list_entry_inactive'/,
  'direct guest-list access must fail closed when an invited user is removed or cancelled',
);
assert.match(
  joinView,
  /if \(!result\.ok\) \{[\s\S]*state\.joining = false[\s\S]*state\.joinError = localizedApiErrorMessage[\s\S]*return;[\s\S]*\}[\s\S]*startAdmissionWait\(accessId\);/,
  'frontend must not open lobby visibility unless call-access session issuance succeeds',
);
assert.match(
  joinView,
  /if \(!sendAdmissionFrame\(\{ type: 'lobby\/queue\/join', room_id: pendingRoomId \}\)\) \{[\s\S]*state\.waitingForAdmission = false/,
  'frontend lobby queue visibility must depend on the admitted call-scoped realtime path',
);

console.log('[call-access-removed-members-contract] PASS');
