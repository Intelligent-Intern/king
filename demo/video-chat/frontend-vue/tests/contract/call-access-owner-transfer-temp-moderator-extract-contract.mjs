import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);
const frontendRoot = path.resolve(__dirname, '../..');
const repoRoot = path.resolve(frontendRoot, '../../..');

function fail(message) {
  throw new Error(`[call-access-owner-transfer-temp-moderator-extract-contract] FAIL: ${message}`);
}

function readText(relativePath) {
  return fs.readFileSync(path.join(repoRoot, relativePath), 'utf8');
}

function functionBody(source, name) {
  const marker = `function ${name}`;
  const start = source.indexOf(marker);
  assert.notEqual(start, -1, `missing ${name}`);
  const open = source.indexOf('{', start);
  assert.notEqual(open, -1, `missing ${name} body`);

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

  fail(`unterminated ${name}`);
}

function normalizeRole(role) {
  return ['owner', 'moderator', 'participant'].includes(role) ? role : 'participant';
}

function viewerContext(state, userId) {
  const callRole = Number(state.ownerUserId) === Number(userId)
    ? 'owner'
    : normalizeRole(state.roles[userId] || 'participant');

  return {
    userId: Number(userId),
    callId: state.callId,
    tenantId: state.tenantId,
    callRole,
    effectiveCallRole: callRole,
    canModerate: callRole === 'owner' || callRole === 'moderator',
    canManageOwner: callRole === 'owner',
    tenantAdmin: false,
    platformAdmin: false,
  };
}

function canAdministerCall(state, userId, callId) {
  if (String(callId) !== state.callId) return false;
  const viewer = viewerContext(state, userId);
  return viewer.canModerate;
}

function updateRole(state, actorUserId, targetUserId, targetRole) {
  const role = normalizeRole(targetRole);
  const target = Number(targetUserId);
  if (!state.internalParticipants.has(target)) {
    return { ok: false, reason: 'validation_failed', field: 'must_reference_internal_participant' };
  }
  if (!canAdministerCall(state, actorUserId, state.callId)) {
    return { ok: false, reason: 'forbidden' };
  }
  const actor = viewerContext(state, actorUserId);
  if (role === 'owner' && !actor.canManageOwner) {
    return { ok: false, reason: 'forbidden', field: 'owner_transfer_requires_current_owner' };
  }
  if (target === state.ownerUserId && role !== 'owner') {
    return { ok: false, reason: 'validation_failed', field: 'cannot_change_current_owner_role' };
  }
  if (role === 'owner') {
    state.roles[state.ownerUserId] = 'participant';
    state.ownerUserId = target;
  }
  state.roles[target] = role;
  return { ok: true, state: 'participant_role_updated' };
}

function lobbyCommand(state, actorUserId, payload) {
  const commandRoomId = String(payload.roomId || state.roomId);
  const commandCallId = String(payload.callId || state.callId);
  const actor = viewerContext(state, actorUserId);
  const assignedCall = commandRoomId === state.roomId && commandCallId === state.callId;
  const allowed = actor.canModerate && assignedCall;
  state.commands.push({
    type: String(payload.type || ''),
    actorUserId: Number(actorUserId),
    roomId: commandRoomId,
    callId: commandCallId,
    targetUserId: Number(payload.targetUserId || 0),
    allowed,
  });
  return { ok: allowed, error: allowed ? '' : 'forbidden' };
}

try {
  const callManagementEntrypoint = readText('demo/video-chat/backend-king-php/domain/calls/call_management.php');
  const callManagementQuery = readText('demo/video-chat/backend-king-php/domain/calls/call_management_query.php');
  const callManagementOwnerTransfer = readText('demo/video-chat/backend-king-php/domain/calls/call_management_owner_transfer.php');
  const realtimeContext = readText('demo/video-chat/backend-king-php/domain/realtime/realtime_call_context.php');
  const realtimeLobby = readText('demo/video-chat/backend-king-php/domain/realtime/realtime_lobby.php');
  const realtimeLobbySecurity = readText('demo/video-chat/backend-king-php/http/module_realtime_lobby_security.php');
  const ownerModerationProof = readText('demo/video-chat/backend-king-php/tests/call-owner-moderation-contract.php');
  const realtimeScopeProof = readText('demo/video-chat/backend-king-php/tests/realtime-call-scope-contract.php');
  const adminPreventionProof = readText('demo/video-chat/backend-king-php/tests/call-access-admin-prevention-contract.php');
  const ownerTransferLifecycle = readText('demo/video-chat/frontend-vue/tests/contract/owner-transfer-lifecycle-contract.mjs');
  const ownerTransferMain = readText('demo/video-chat/frontend-vue/tests/contract/call-access-owner-transfer-main-contract.mjs');
  const participantUi = readText('demo/video-chat/frontend-vue/src/domain/realtime/workspace/callWorkspace/participantUi.ts');
  const roomState = readText('demo/video-chat/frontend-vue/src/domain/realtime/workspace/callWorkspace/roomState.ts');
  const rosterPanel = readText('demo/video-chat/frontend-vue/src/domain/realtime/workspace/callWorkspace/RightRosterPanel.vue');

  assert.match(
    ownerTransferLifecycle,
    /old owner should not regain moderation on rejoin[\s\S]*moderator should keep moderator role on rejoin[\s\S]*moderator must not gain owner-transfer rights on rejoin/s,
    'current lifecycle contract must already cover owner demotion and reconnect role separation',
  );
  assert.match(
    ownerTransferMain,
    /owner transfer must require the current owner or a system admin[\s\S]*lobby moderation must authorize through fresh DB-backed call context instead of stale connection roles/s,
    'current owner-transfer main contract must pin canonical owner authority and DB-backed lobby authority',
  );
  assert.match(
    ownerModerationProof,
    /normal participant must not admit lobby users[\s\S]*owner should admit lobby users[\s\S]*old non-admin owner must not moderate after transfer[\s\S]*new owner should moderate after transfer/s,
    'backend owner moderation proof must retain baseline participant, owner, old-owner, and new-owner moderation checks',
  );
  assert.match(
    realtimeScopeProof,
    /lobby management must reject a forged call room/,
    'backend realtime scope proof must reject lobby commands for forged room or call context',
  );
  assert.match(
    adminPreventionProof,
    /platform_admin must stay false[\s\S]*tenant_admin must stay false[\s\S]*moderation rights must stay false[\s\S]*owner-management rights must stay false/s,
    'call-access admin-prevention proof must keep link-issued users from gaining tenant/admin powers',
  );

  assert.match(
    callManagementEntrypoint,
    /require_once __DIR__ \. '\/call_management_owner_transfer\.php';/,
    'call management entrypoint must load the focused owner-transfer extraction',
  );

  const canAdministerBody = functionBody(callManagementQuery, 'videochat_can_administer_call');
  assert.match(
    canAdministerBody,
    /videochat_can_edit_call\(\$authRole, \$authUserId, \$ownerUserId, \$pdo\)[\s\S]*videochat_user_is_call_moderator\(\$pdo, \$callId, \$authUserId\)[\s\S]*videochat_user_is_organization_admin_for_call\(\$pdo, \$callId, \$authUserId, \$tenantId\)/,
    'call administration must include owner, call-moderator, and organization-admin sources without conflating them',
  );

  const updateRoleBody = functionBody(callManagementOwnerTransfer, 'videochat_update_call_participant_role');
  assert.match(
    updateRoleBody,
    /\$normalizedTargetRole = videochat_normalize_call_participant_role\(\$targetRole, ''\);[\s\S]*must_be_owner_or_moderator_or_participant/,
    'role update endpoint must preserve the explicit owner/moderator/participant role domain',
  );
  assert.match(
    updateRoleBody,
    /if \(\$normalizedTargetRole === 'owner'\) \{[\s\S]*if \(!\$isOwner && !\$isSystemAdmin\)[\s\S]*owner_transfer_requires_current_owner[\s\S]*\} elseif \(\$targetUserId === \$currentOwnerUserId\) \{[\s\S]*cannot_change_current_owner_role/s,
    'temporary moderators must not be able to transfer ownership or demote the current owner',
  );
  assert.match(
    updateRoleBody,
    /UPDATE call_participants\s+SET call_role = :call_role\s+WHERE call_id = :call_id\s+AND user_id = :user_id\s+AND source = 'internal'/,
    'moderator assignment and revocation must persist on the call participant row',
  );

  const realtimeRoleContext = readText('demo/video-chat/backend-king-php/domain/realtime/realtime_call_role_context.php');
  const realtimeContextBody = functionBody(realtimeRoleContext, 'videochat_realtime_call_role_context_for_room_user');
  assert.match(
    realtimeContextBody,
    /\$contextFromRow = static function \(array \$row, bool \$isOrganizationAdmin\)[\s\S]*\$callRole = 'owner';[\s\S]*'can_moderate' => \$isAdmin \|\| \$isOrganizationAdmin \|\| in_array\(\$callRole, \['owner', 'moderator'\], true\),[\s\S]*'can_manage_owner' => \$isAdmin \|\| \$callRole === 'owner'[\s\S]*calls\.owner_user_id,[\s\S]*cp\.call_role/s,
    'realtime role context must recompute moderator and org-admin rights from persisted call state while keeping owner-management stricter',
  );

  const lobbyAuthBody = functionBody(realtimeLobbySecurity, 'videochat_realtime_authorize_lobby_moderation_command');
  assert.match(
    lobbyAuthBody,
    /videochat_realtime_lobby_server_role_for_user\(\$pdo, \$userId\)[\s\S]*videochat_realtime_connection_call_id\(\$presenceConnection\)[\s\S]*videochat_realtime_call_role_context_for_room_user\([\s\S]*\$normalizedRoomId[\s\S]*\$requestedCallId[\s\S]*\$serverRole[\s\S]*\$tenantId[\s\S]*if \(\$callId === '' \|\| !\(bool\) \(\$context\['can_moderate'\] \?\? false\)\)/s,
    'lobby moderation must revalidate assigned room/call authority server-side instead of trusting client role frames',
  );
  assert.match(
    realtimeLobby,
    /function videochat_lobby_apply_command[\s\S]*if \(!videochat_lobby_can_moderate\(\$connection\)\)[\s\S]*'error' => 'forbidden'/,
    'lobby command application must still fail closed when the resolved connection has no moderation right',
  );

  assert.match(
    participantUi,
    /function toggleModeratorRole\(row\)[\s\S]*const nextRole = normalizeCallRole\(row\?\.callRole \|\| 'participant'\) === 'moderator'\s*\?\s*'participant'\s*:\s*'moderator';[\s\S]*updateParticipantCallRole\(row, nextRole, 'role'\)/,
    'frontend must expose moderator assignment and immediate revocation through the same call participant role endpoint',
  );
  assert.match(
    roomState,
    /viewerCanManageOwnerRole\.value = Boolean\([\s\S]*viewer\.can_manage_owner[\s\S]*viewer\.canManageOwner/,
    'room snapshots must carry owner-management separately from moderation after role changes',
  );
  assert.match(
    rosterPanel,
    /visibleActionSet\.has\('owner'\)[\s\S]*!canManageOwnerRole[\s\S]*row\.callRole === 'owner'/,
    'owner-transfer UI must remain disabled unless the viewer has owner-management rights',
  );

  const state = {
    tenantId: 10,
    callId: 'call-owner-transfer-temp-moderator',
    roomId: 'room-owner-transfer-temp-moderator',
    foreignTenantId: 20,
    foreignCallId: 'call-foreign-owner-transfer-temp-moderator',
    foreignRoomId: 'room-foreign-owner-transfer-temp-moderator',
    ownerUserId: 41,
    internalParticipants: new Set([41, 42, 43]),
    roles: {
      41: 'owner',
      42: 'participant',
      43: 'participant',
    },
    commands: [],
  };

  assert.deepEqual(updateRole(state, 41, 42, 'moderator'), { ok: true, state: 'participant_role_updated' }, 'owner should assign a temporary moderator');
  assert.deepEqual(
    viewerContext(state, 42),
    {
      userId: 42,
      callId: state.callId,
      tenantId: 10,
      callRole: 'moderator',
      effectiveCallRole: 'moderator',
      canModerate: true,
      canManageOwner: false,
      tenantAdmin: false,
      platformAdmin: false,
    },
    'temporary moderator should gain only assigned-call moderation, not tenant or owner-management powers',
  );
  assert.deepEqual(lobbyCommand(state, 42, { type: 'lobby/allow', targetUserId: 50 }), { ok: true, error: '' }, 'temporary moderator should admit lobby users for the assigned call');
  assert.deepEqual(lobbyCommand(state, 42, { type: 'lobby/reject', targetUserId: 50 }), { ok: true, error: '' }, 'temporary moderator should reject lobby users for the assigned call');
  assert.deepEqual(
    lobbyCommand(state, 42, {
      type: 'lobby/allow',
      roomId: state.foreignRoomId,
      callId: state.foreignCallId,
      targetUserId: 50,
    }),
    { ok: false, error: 'forbidden' },
    'temporary moderator must not moderate a different call or tenant',
  );
  assert.deepEqual(
    updateRole(state, 42, 43, 'owner'),
    { ok: false, reason: 'forbidden', field: 'owner_transfer_requires_current_owner' },
    'temporary moderator must not transfer ownership',
  );

  const forgedClientContext = { ...viewerContext(state, 43), callRole: 'moderator', canModerate: true };
  assert.equal(forgedClientContext.canModerate, true, 'fixture should model a forged client-side moderator snapshot');
  assert.deepEqual(updateRole(state, 43, 42, 'participant'), { ok: false, reason: 'forbidden' }, 'server-side role update must reject forged client moderator state');

  assert.deepEqual(updateRole(state, 41, 42, 'participant'), { ok: true, state: 'participant_role_updated' }, 'owner should revoke temporary moderator immediately');
  assert.equal(viewerContext(state, 42).canModerate, false, 'revoked temporary moderator must lose moderation on fresh context');
  assert.deepEqual(lobbyCommand(state, 42, { type: 'lobby/allow', targetUserId: 50 }), { ok: false, error: 'forbidden' }, 'revoked temporary moderator must fail the next lobby command');

  process.stdout.write('[call-access-owner-transfer-temp-moderator-extract-contract] PASS\n');
} catch (error) {
  if (error instanceof Error) {
    fail(error.message);
  }
  fail('unknown failure');
}
