import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);
const frontendRoot = path.resolve(__dirname, '../..');
const repoRoot = path.resolve(frontendRoot, '../../..');

function readText(relativePath) {
  return fs.readFileSync(path.join(repoRoot, relativePath), 'utf8');
}

function guestSessionBody(options = {}) {
  const body = {};
  const guestName = typeof options.guestName === 'string' ? options.guestName.trim() : '';
  if (guestName !== '') {
    body.guest_name = guestName;
  }
  return body;
}

function issueAnonymousGuest(rawBody, assignedUserId) {
  const displayName = String(rawBody.guest_name ?? '').trim();
  return {
    id: assignedUserId,
    email: `guest+${assignedUserId}@videochat.local`,
    display_name: displayName,
    role: 'user',
    account_type: 'guest',
    is_guest: true,
    tenant_admin: false,
    platform_admin: false,
    submitted_user_id: rawBody.user_id ?? null,
    submitted_role: rawBody.role ?? null,
    submitted_call_role: rawBody.call_role ?? null,
    submitted_admin: rawBody.admin ?? null,
  };
}

function lobbyQueueEntry(connection) {
  return {
    user_id: connection.user_id,
    display_name: connection.display_name,
    role: connection.role === 'admin' ? 'admin' : 'user',
    requested_unix_ms: 1_800_000_000_000,
    requested_at: '2027-01-15T00:00:00+00:00',
  };
}

function roomSnapshotViewer(connection) {
  const normalizedRole = connection.role === 'admin' ? 'admin' : 'user';
  const callRole = ['owner', 'moderator'].includes(connection.call_role) ? connection.call_role : 'participant';
  return {
    user_id: connection.user_id,
    role: normalizedRole,
    call_role: callRole,
    effective_call_role: callRole,
    can_moderate: normalizedRole === 'admin' || callRole === 'owner' || callRole === 'moderator',
    can_manage_owner: normalizedRole === 'admin' || callRole === 'owner',
  };
}

function authorizePrivilegedAction(connection, actionType) {
  const viewer = roomSnapshotViewer(connection);
  const canRun = actionType === 'owner-transfer' ? viewer.can_manage_owner : viewer.can_moderate;
  if (canRun) {
    return { ok: true };
  }
  return {
    ok: false,
    status: 403,
    error: {
      code: 'forbidden',
      details: {
        error: 'forbidden',
        type: actionType,
        target_user_id: 0,
        room_id: 'room-public-redacted',
      },
    },
  };
}

function assertRedacted(payload, forbiddenNeedles, label) {
  const text = JSON.stringify(payload);
  for (const needle of forbiddenNeedles) {
    assert.doesNotMatch(text, new RegExp(String(needle).replace(/[.*+?^${}()|[\]\\]/g, '\\$&')), `${label} leaked ${needle}`);
  }
}

const callAccessSession = readText('demo/video-chat/frontend-vue/src/domain/calls/access/callAccessSession.ts');
const joinView = readText('demo/video-chat/frontend-vue/src/domain/calls/access/JoinView.vue');
const callAccessContract = readText('demo/video-chat/backend-king-php/domain/calls/call_access_contract.php');
const callAccessSessionPhp = readText('demo/video-chat/backend-king-php/domain/calls/call_access_session.php');
const realtimePresence = readText('demo/video-chat/backend-king-php/domain/realtime/realtime_presence.php');
const realtimeCallContext = readText('demo/video-chat/backend-king-php/domain/realtime/realtime_call_context.php');
const realtimeCallRoleContext = readText('demo/video-chat/backend-king-php/domain/realtime/realtime_call_role_context.php');
const realtimeLobby = readText('demo/video-chat/backend-king-php/domain/realtime/realtime_lobby.php');
const realtimeLobbyState = readText('demo/video-chat/backend-king-php/domain/realtime/realtime_lobby_state.php');
const lobbySecurity = readText('demo/video-chat/backend-king-php/http/module_realtime_lobby_security.php');
const anonymousTempRights = readText('demo/video-chat/backend-king-php/tests/call-access-anonymous-temp-rights-contract.php');
const admissionBoundaries = readText('demo/video-chat/frontend-vue/tests/contract/call-access-admission-boundaries-contract.mjs');

assert.match(
  joinView,
  /v-model\.trim="state\.guestName"[\s\S]*loginWithCallAccess\(accessId,\s*\{[\s\S]*guestName:\s*state\.requiresGuestName \? state\.guestName : ''/,
  'public join UI must treat anonymous guest identity input as guestName only',
);
assert.match(
  callAccessSession,
  /const guestName = typeof options\?\.guestName === 'string' \? options\.guestName\.trim\(\) : '';[\s\S]*body\.guest_name = guestName;/,
  'frontend call-access session request must only send a string guest_name from guest display input',
);
assert.doesNotMatch(
  callAccessSession,
  /body\.(?:user_id|role|call_role|admin|tenant_admin|platform_admin)\s*=/,
  'frontend call-access session request must not send guest-controlled identity or role fields',
);
assert.match(
  callAccessSessionPhp,
  /\$guestName = trim\(\(string\) \(\$options\['guest_name'\] \?\? ''\)\);[\s\S]*videochat_create_guest_user_for_call_access\(\$pdo,\s*\$guestName,\s*\$tenantId\)/,
  'backend session issuer must pass only guest_name into guest account creation',
);
assert.match(
  callAccessContract,
  /SELECT id FROM roles WHERE slug = 'user' LIMIT 1[\s\S]*INSERT INTO users\([\s\S]*display_name[\s\S]*password_hash[\s\S]*role_id[\s\S]*VALUES\([\s\S]*:display_name[\s\S]*NULL[\s\S]*:role_id/s,
  'temporary guest creation must force the normal user role and passwordless guest account type',
);
assert.doesNotMatch(
  callAccessContract.match(/function videochat_create_guest_user_for_call_access[\s\S]*?return \[\s*'ok' => true/)?.[0] || '',
  /\$options|user_id|call_role|admin|membership_role/,
  'temporary guest creation must not consume caller-supplied role, user id, or admin fields',
);
assert.match(
  realtimePresence,
  /'user_id' => \(int\) \(\$authUser\['id'\] \?\? 0\)[\s\S]*'display_name' => trim\(\(string\) \(\$authUser\['display_name'\] \?\? ''\)\)[\s\S]*'role' => videochat_normalize_role_slug\(\(string\) \(\$authUser\['role'\] \?\? ''\)\)[\s\S]*'call_role' => 'participant'[\s\S]*'can_moderate_call' => false/s,
  'presence connection must initialize authority from authenticated user fields, not display-name content',
);
assert.match(
  realtimeLobby,
  /'display_name' => \(string\) \(\$connection\['display_name'\] \?\? ''\)[\s\S]*'role' => videochat_normalize_role_slug\(\(string\) \(\$connection\['role'\] \?\? 'user'\)\)/,
  'lobby queue insertion must store display name separately from normalized role',
);
assert.match(
  realtimeLobbyState,
  /function videochat_lobby_snapshot_payload_for_connection[\s\S]*if \(videochat_lobby_can_moderate\(\$connection\)\)[\s\S]*static fn \(mixed \$entry\): bool => is_array\(\$entry\) && \(int\) \(\$entry\['user_id'\] \?\? 0\) === \$viewerUserId/s,
  'non-moderator lobby snapshots must expose only the viewer guest row',
);
assert.match(
  `${realtimeCallContext}\n${realtimeCallRoleContext}`,
  /SELECT[\s\S]*calls\.owner_user_id[\s\S]*cp\.call_role[\s\S]*WHERE calls\.id = :call_id[\s\S]*AND cp\.user_id = :user_id/s,
  'room role computation must derive call role from call owner and participant rows keyed by user_id',
);
assert.match(
  `${realtimeCallContext}\n${realtimeCallRoleContext}`,
  /'can_moderate' => \$isAdmin \|\| \$isOrganizationAdmin \|\| in_array\(\$callRole, \['owner', 'moderator'\], true\)[\s\S]*'can_manage_owner' => \$isAdmin \|\| \$callRole === 'owner'/,
  'room snapshot viewer authority must not derive moderation or owner rights from display name',
);
assert.match(
  lobbySecurity,
  /videochat_realtime_lobby_server_role_for_user\(PDO \$pdo,\s*int \$userId\)[\s\S]*INNER JOIN roles ON roles\.id = users\.role_id[\s\S]*WHERE users\.id = :user_id/s,
  'privileged lobby commands must reload server role by authenticated user id',
);
assert.match(
  lobbySecurity,
  /videochat_realtime_authorize_lobby_moderation_command[\s\S]*videochat_realtime_call_role_context_for_room_user[\s\S]*if \(\$callId === '' \|\| !\(bool\) \(\$context\['can_moderate'\] \?\? false\)\)/,
  'privileged lobby commands must use server call-role context and deny non-moderators',
);
assert.match(
  anonymousTempRights,
  /temporary account should be distinct from matching org-admin display name[\s\S]*temporary account must not inherit organization admin rights[\s\S]*temporary account must not administer the call[\s\S]*temporary account must not gain guest-list direct join/s,
  'backend anonymous-temp proof must already cover display-name spoofing of an org admin',
);
assert.ok(
  admissionBoundaries.includes('forged role\\/call_role must not authorize lobby moderation'),
  'existing admission contract must reject forged roles',
);
assert.match(
  admissionBoundaries,
  /org admin should not receive owner-transfer rights/,
  'existing admission contract must reject owner-transfer overreach',
);
assert.match(
  admissionBoundaries,
  /temporary account must not receive system-admin call rights even with admin role data/,
  'existing admission contract must reject forged roles, owner-transfer overreach, and temp admin data',
);

const maliciousNames = [
  '<script>window.__king_admin=true</script>',
  '{"id":1,"user_id":1,"role":"admin","call_role":"owner","tenant_admin":true}',
  'Anonymous Temp Org Admin A',
];
for (const name of maliciousNames) {
  const requestBody = {
    ...guestSessionBody({ guestName: name }),
    user_id: 1,
    role: 'admin',
    call_role: 'owner',
    tenant_admin: true,
    platform_admin: true,
  };
  const guest = issueAnonymousGuest(requestBody, 9001);
  assert.equal(guest.display_name, name, 'malicious guest input may only survive as display text');
  assert.equal(guest.id, 9001, 'guest user id must be server-assigned');
  assert.equal(guest.role, 'user', 'guest role must remain user despite submitted role/admin fields');
  assert.equal(guest.account_type, 'guest', 'manipulated anonymous display name must still create a guest account');
  assert.equal(guest.tenant_admin, false, 'guest must not gain tenant-admin rights from submitted fields');
  assert.equal(guest.platform_admin, false, 'guest must not gain platform-admin rights from submitted fields');

  const connection = {
    user_id: guest.id,
    display_name: guest.display_name,
    role: guest.role,
    call_role: 'participant',
  };
  const queued = lobbyQueueEntry(connection);
  assert.equal(queued.display_name, name, 'lobby queue may display the supplied name as text');
  assert.equal(queued.user_id, guest.id, 'lobby queue user id must stay server-assigned');
  assert.equal(queued.role, 'user', 'lobby queue role must stay normalized user');

  const viewer = roomSnapshotViewer(connection);
  assert.equal(viewer.call_role, 'participant', 'room snapshot must compute guest call role as participant');
  assert.equal(viewer.effective_call_role, 'participant', 'room snapshot must compute guest effective role as participant');
  assert.equal(viewer.can_moderate, false, 'manipulated guest must not moderate');
  assert.equal(viewer.can_manage_owner, false, 'manipulated guest must not manage owner transfer');

  const privateNeedles = [
    'registered-owner@example.test',
    'private-call-title',
    'call-private-owner-id',
    'owner-personal-access-id',
    'Anonymous Temp Org Admin A@example.test',
    'call_role":"owner',
  ];
  for (const actionType of ['lobby/allow', 'lobby/remove', 'owner-transfer']) {
    const denial = authorizePrivilegedAction(connection, actionType);
    assert.equal(denial.ok, false, `${actionType} must be denied for manipulated anonymous guest`);
    assert.equal(denial.status, 403, `${actionType} denial must be forbidden`);
    assertRedacted(denial, privateNeedles, `${actionType} denial payload`);
  }
}

const objectManipulationBody = guestSessionBody({
  guestName: { user_id: 1, role: 'admin', call_role: 'owner', display_name: 'Object Admin' },
});
assert.deepEqual(objectManipulationBody, {}, 'frontend must not serialize object-shaped guest-name manipulation');

process.stdout.write('[call-access-anonymous-guest-manipulation-contract] PASS\n');
