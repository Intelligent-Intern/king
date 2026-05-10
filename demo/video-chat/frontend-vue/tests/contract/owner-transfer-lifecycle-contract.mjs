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

function fail(message) {
  throw new Error(`[owner-transfer-lifecycle-contract] FAIL: ${message}`);
}

function normalizeRole(role) {
  return ['owner', 'moderator', 'participant'].includes(role) ? role : 'participant';
}

function viewerContext(state, userId) {
  const currentOwner = Number(state.ownerUserId) === Number(userId);
  const role = currentOwner ? 'owner' : normalizeRole(state.roles[userId] || 'participant');
  return {
    userId,
    callRole: role,
    effectiveCallRole: role,
    canModerate: role === 'owner' || role === 'moderator',
    canManageOwner: role === 'owner',
  };
}

function transferOwner(state, actingUserId, targetUserId) {
  const actor = viewerContext(state, actingUserId);
  if (!actor.canManageOwner) {
    return { ok: false, reason: 'forbidden' };
  }
  state.roles[state.ownerUserId] = 'participant';
  state.ownerUserId = targetUserId;
  state.roles[targetUserId] = 'owner';
  return { ok: true };
}

function leave(state, userId) {
  state.connected.delete(userId);
}

function rejoin(state, userId) {
  state.connected.add(userId);
  return viewerContext(state, userId);
}

try {
  const ownerModerationContract = readText('demo/video-chat/backend-king-php/tests/call-owner-moderation-contract.php');
  const realtimeCallContext = readText('demo/video-chat/backend-king-php/domain/realtime/realtime_call_context.php');
  const realtimeCallRoleContext = readText('demo/video-chat/backend-king-php/domain/realtime/realtime_call_role_context.php');
  const roomState = readText('demo/video-chat/frontend-vue/src/domain/realtime/workspace/callWorkspace/roomState.ts');
  const participantUi = readText('demo/video-chat/frontend-vue/src/domain/realtime/workspace/callWorkspace/participantUi.ts');
  const socketLifecycle = readText('demo/video-chat/frontend-vue/src/domain/realtime/workspace/callWorkspace/socketLifecycle.ts');
  const joinSpec = readText('demo/video-chat/frontend-vue/tests/e2e/call-access-join.spec.js');

  assert.match(
    ownerModerationContract,
    /normal participant must not transfer ownership[\s\S]*current owner should transfer ownership[\s\S]*transfer should leave exactly one owner participant row/s,
    'backend owner-transfer proof must reject participant transfer, allow current owner transfer, and enforce one owner',
  );
  assert.match(
    ownerModerationContract,
    /old owner should be demoted to participant[\s\S]*old owner should lose call moderation controls[\s\S]*new owner should resolve owner role[\s\S]*new owner should gain call moderation controls/s,
    'backend proof must cover old-owner demotion and new-owner authority after transfer',
  );
  assert.match(
    ownerModerationContract,
    /old non-admin owner must not moderate after transfer[\s\S]*new owner should moderate after transfer/s,
    'backend proof must verify post-transfer moderation uses fresh owner state',
  );

  assert.match(
    realtimeCallContext,
    /require_once __DIR__ \. '\/realtime_call_role_context\.php'/,
    'realtime context must load the focused role resolver extraction',
  );
  assert.match(
    realtimeCallRoleContext,
    /videochat_realtime_call_role_context_for_room_user[\s\S]*calls\.owner_user_id[\s\S]*cp\.call_role[\s\S]*calls\.id = :call_id/s,
    'realtime rejoin context must recompute owner and participant roles from the call database row',
  );
  assert.match(
    realtimeCallRoleContext,
    /can_moderate' => \$isAdmin \|\| \$isOrganizationAdmin \|\| in_array\(\$callRole, \['owner', 'moderator'\], true\)/,
    'realtime rejoin context must allow moderators and same-organization admins to moderate after reconnect',
  );
  assert.match(
    realtimeCallRoleContext,
    /can_manage_owner' => \$isAdmin \|\| \$callRole === 'owner'/,
    'realtime rejoin context must keep owner-transfer rights stricter than moderator rights',
  );

  assert.match(
    roomState,
    /function applyViewerContext\(viewerPayload\)[\s\S]*viewerEffectiveCallRole\.value = normalizeCallRole[\s\S]*viewerCanManageOwnerRole\.value = Boolean/s,
    'room snapshots must refresh effective role and owner-management state after reconnect',
  );
  assert.match(
    roomState,
    /function applyRoomSnapshot\(payload\)[\s\S]*applyViewerContext\(payload\?\.viewer \|\| null\)[\s\S]*const participantsChanged = applyParticipantsSnapshot/s,
    'room snapshot handling must apply viewer rights from the server before participant presentation',
  );
  assert.match(
    participantUi,
    /function transferOwnerRole\(row\)[\s\S]*canManageOwnerRole\?\.value[\s\S]*updateParticipantCallRole\(row, 'owner', 'owner'\)/,
    'frontend owner transfer action must be gated by owner-management rights',
  );
  assert.match(
    participantUi,
    /await apiRequest\(endpoint,[\s\S]*method:\s*'PATCH'[\s\S]*body:\s*\{\s*role:\s*normalizedRole\s*\}[\s\S]*requestRoomSnapshot\(\)/,
    'role changes must ask the server for a fresh room snapshot after transfer',
  );
  assert.match(
    socketLifecycle,
    /previousSocket\.close\(1000, 'reconnect'\);/,
    'socket reconnect must not be treated as a room leave',
  );
  assert.match(
    socketLifecycle,
    /socket\.send\(JSON\.stringify\(\{ type: 'room\/leave' \}\)\);/,
    'explicit leave must remain distinct from reconnect lifecycle',
  );
  assert.match(
    joinSpec,
    /external guest join link requires display name, creates temporary guest, and waits in lobby until admitted[\s\S]*anonymousGuest\.is_guest[\s\S]*toBe\(true\)/,
    'guest path must continue to exercise temporary guest identity and lobby admission',
  );

  const state = {
    ownerUserId: 11,
    roles: {
      11: 'owner',
      12: 'participant',
      13: 'moderator',
      14: 'participant',
    },
    connected: new Set([11, 12, 13, 14]),
  };

  assert.deepEqual(
    transferOwner(state, 14, 12),
    { ok: false, reason: 'forbidden' },
    'guest/participant must not transfer ownership',
  );
  assert.deepEqual(transferOwner(state, 11, 12), { ok: true }, 'current owner should transfer ownership');
  assert.equal(Object.values(state.roles).filter((role) => role === 'owner').length, 1, 'transfer must leave exactly one owner role');

  for (const userId of [11, 12, 13, 14]) {
    leave(state, userId);
  }
  const oldOwner = rejoin(state, 11);
  const newOwner = rejoin(state, 12);
  const moderator = rejoin(state, 13);
  const guest = rejoin(state, 14);

  assert.equal(oldOwner.callRole, 'participant', 'old owner should rejoin as participant');
  assert.equal(oldOwner.canModerate, false, 'old owner should not regain moderation on rejoin');
  assert.equal(oldOwner.canManageOwner, false, 'old owner should not regain owner-transfer rights on rejoin');

  assert.equal(newOwner.callRole, 'owner', 'new owner should rejoin as owner');
  assert.equal(newOwner.canModerate, true, 'new owner should moderate on rejoin');
  assert.equal(newOwner.canManageOwner, true, 'new owner should transfer ownership on rejoin');

  assert.equal(moderator.callRole, 'moderator', 'moderator should keep moderator role on rejoin');
  assert.equal(moderator.canModerate, true, 'moderator should retain moderation on rejoin');
  assert.equal(moderator.canManageOwner, false, 'moderator must not gain owner-transfer rights on rejoin');

  assert.equal(guest.callRole, 'participant', 'guest should rejoin as participant');
  assert.equal(guest.canModerate, false, 'guest should not moderate on rejoin');
  assert.equal(guest.canManageOwner, false, 'guest should not gain owner-transfer rights on rejoin');

  process.stdout.write('[owner-transfer-lifecycle-contract] PASS\n');
} catch (error) {
  if (error instanceof Error) {
    fail(error.message);
  }
  fail('unknown failure');
}
