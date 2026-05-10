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

function functionBody(source, name) {
  const marker = `function ${name}`;
  const start = source.indexOf(marker);
  assert.notEqual(start, -1, `missing function ${name}`);
  let parenDepth = 0;
  let open = -1;
  for (let index = start; index < source.length; index += 1) {
    const char = source[index];
    if (char === '(') parenDepth += 1;
    if (char === ')') parenDepth = Math.max(0, parenDepth - 1);
    if (char === '{' && parenDepth === 0) {
      open = index;
      break;
    }
  }
  assert.notEqual(open, -1, `missing body for ${name}`);

  let depth = 0;
  for (let index = open; index < source.length; index += 1) {
    const char = source[index];
    if (char === '{') depth += 1;
    if (char === '}') {
      depth -= 1;
      if (depth === 0) return source.slice(open + 1, index);
    }
  }
  throw new Error(`unterminated function ${name}`);
}

const roomStateSource = readText('demo/video-chat/frontend-vue/src/domain/realtime/workspace/callWorkspace/roomState.ts');
const participantUiSource = readText('demo/video-chat/frontend-vue/src/domain/realtime/workspace/callWorkspace/participantUi.ts');
const rosterPanelSource = readText('demo/video-chat/frontend-vue/src/domain/realtime/workspace/callWorkspace/RightRosterPanel.vue');
const socketLifecycleSource = readText('demo/video-chat/frontend-vue/src/domain/realtime/workspace/callWorkspace/socketLifecycle.ts');
const workspaceSource = readText('demo/video-chat/frontend-vue/src/domain/realtime/CallWorkspaceView.vue');
const realtimeContextSource = readText('demo/video-chat/backend-king-php/domain/realtime/realtime_call_context.php');
const realtimeCallRoleContextSource = readText('demo/video-chat/backend-king-php/domain/realtime/realtime_call_role_context.php');
const realtimeSnapshotSource = readText('demo/video-chat/backend-king-php/domain/realtime/realtime_room_snapshot.php');
const realtimeWebsocketSource = readText('demo/video-chat/backend-king-php/http/module_realtime_websocket.php');
const lobbySecuritySource = readText('demo/video-chat/backend-king-php/http/module_realtime_lobby_security.php');
const ownerModerationContract = readText('demo/video-chat/backend-king-php/tests/call-owner-moderation-contract.php');
const lobbySecurityContract = readText('demo/video-chat/backend-king-php/tests/realtime-lobby-security-contract.php');
const callAppGrantButtonSource = readText('demo/video-chat/frontend-vue/src/domain/realtime/callApps/CallAppParticipantGrantButton.vue');

const applyViewerContextBody = functionBody(roomStateSource, 'applyViewerContext');
const applyRoomSnapshotBody = functionBody(roomStateSource, 'applyRoomSnapshot');
const applyLobbySnapshotBody = functionBody(roomStateSource, 'applyLobbySnapshot');
const userRowSnapshotBody = functionBody(participantUiSource, 'userRowSnapshot');
const allowLobbyUserBody = functionBody(participantUiSource, 'allowLobbyUser');
const removeLobbyUserBody = functionBody(participantUiSource, 'removeLobbyUser');
const allowAllLobbyUsersBody = functionBody(participantUiSource, 'allowAllLobbyUsers');
const updateParticipantCallRoleBody = functionBody(participantUiSource, 'updateParticipantCallRole');
const toggleModeratorRoleBody = functionBody(participantUiSource, 'toggleModeratorRole');
const transferOwnerRoleBody = functionBody(participantUiSource, 'transferOwnerRole');
const sendLayoutCommandBody = functionBody(participantUiSource, 'sendLayoutCommand');
const publishLayoutSelectionStateBody = functionBody(participantUiSource, 'publishLayoutSelectionState');
const setActiveTabBody = functionBody(participantUiSource, 'setActiveTab');
const connectionWithCallContextBody = functionBody(realtimeContextSource, 'videochat_realtime_connection_with_call_context');
const roleContextBody = functionBody(realtimeCallRoleContextSource, 'videochat_realtime_call_role_context_for_room_user');
const roomSnapshotPayloadBody = functionBody(realtimeSnapshotSource, 'videochat_realtime_room_snapshot_payload');
const lobbyAuthorizeBody = functionBody(lobbySecuritySource, 'videochat_realtime_authorize_lobby_moderation_command');

assert.match(
  roleContextBody,
  /\$contextFromRow = static function \(array \$row, bool \$isOrganizationAdmin\)[\s\S]*\$callRole = 'owner';[\s\S]*\$effectiveCallRole = \$isAdmin \? 'owner' : \(\$isOrganizationAdmin && \$callRole !== 'owner' \? 'moderator' : \$callRole\);[\s\S]*'can_moderate' => \$isAdmin \|\| \$isOrganizationAdmin \|\| in_array\(\$callRole, \['owner', 'moderator'\], true\),[\s\S]*'can_manage_owner' => \$isAdmin \|\| \$callRole === 'owner'[\s\S]*SELECT[\s\S]*calls\.owner_user_id,[\s\S]*cp\.call_role,[\s\S]*calls\.status IN \('active', 'scheduled'\)/,
  'backend realtime role context must recompute active-call owner, moderator, admin, and org-admin authority from current DB rows',
);
assert.match(
  connectionWithCallContextBody,
  /videochat_realtime_call_role_context_for_room_user\([\s\S]*\$roomId,[\s\S]*\$userId,[\s\S]*\$requestedCallId,[\s\S]*videochat_realtime_connection_tenant_id\(\$connection\)[\s\S]*\$connection\['call_role'\][\s\S]*\$connection\['effective_call_role'\][\s\S]*\$connection\['can_moderate_call'\][\s\S]*\$connection\['can_manage_call_owner'\]/,
  'active websocket connections must refresh call roles and moderation flags from the backend context',
);
assert.match(
  realtimeWebsocketSource,
  /\$pollNowMs >= \$nextLobbySnapshotPollMs[\s\S]*\$presenceConnection = videochat_realtime_connection_with_call_context\(\$presenceConnection, \$openDatabase\);[\s\S]*videochat_realtime_send_synced_lobby_snapshot_to_connection_if_changed[\s\S]*\$pollNowMs >= \$nextRoomSnapshotPollMs[\s\S]*\$presenceConnection = videochat_realtime_connection_with_call_context\(\$presenceConnection, \$openDatabase\);[\s\S]*videochat_realtime_send_room_snapshot_if_changed[\s\S]*'db_sync'/,
  'active calls must push refreshed lobby and room snapshots after role changes without requiring reconnect',
);
assert.match(
  roomSnapshotPayloadBody,
  /'viewer' => \[[\s\S]*'call_role' => videochat_normalize_call_participant_role\(\(string\) \(\$connection\['call_role'\][\s\S]*'effective_call_role' => videochat_normalize_call_participant_role\([\s\S]*'can_moderate' => \(bool\) \(\$connection\['can_moderate_call'\] \?\? false\),[\s\S]*'can_manage_owner' => \(bool\) \(\$connection\['can_manage_call_owner'\] \?\? false\),/,
  'room snapshots must carry the refreshed viewer role and action authority flags',
);
assert.match(
  socketLifecycleSource,
  /if \(type === 'room\/snapshot'\) \{[\s\S]*applyRoomSnapshot\(payload\);[\s\S]*return;/,
  'frontend websocket lifecycle must apply room snapshots in place',
);
assert.doesNotMatch(
  socketLifecycleSource,
  /if \(type === 'room\/snapshot'\)[\s\S]{0,300}(?:location\.reload|router\.replace|reconnectSocket|connectSocket)/,
  'room snapshot permission refresh must not be implemented as browser reload or websocket reconnect',
);
assert.match(
  applyRoomSnapshotBody,
  /applyViewerContext\(payload\?\.viewer \|\| null\);[\s\S]*const participantsChanged = applyParticipantsSnapshot\(payload\?\.participants\);[\s\S]*if \(typeof applyCallAppsRoomState === 'function'\) \{[\s\S]*applyCallAppsRoomState\(payload\?\.call_apps \|\| null\);/,
  'frontend room snapshots must refresh viewer permissions, participants, and Call App grant state together',
);
assert.match(
  applyViewerContextBody,
  /viewerCallRole\.value = normalizeCallRole\(viewer\.call_role \|\| viewer\.callRole \|\| 'participant'\);[\s\S]*viewerEffectiveCallRole\.value = normalizeCallRole\([\s\S]*viewerCanModerateCall\.value = Boolean\(viewer\.can_moderate \?\? viewer\.canModerate \?\? false\);[\s\S]*viewerCanManageOwnerRole\.value = Boolean\([\s\S]*viewer\.can_manage_owner[\s\S]*false/,
  'viewer context must overwrite stale owner/moderator/admin action flags from the latest snapshot',
);
assert.match(
  applyViewerContextBody,
  /if \(nextCallId !== activeCallId\.value\) \{[\s\S]*resetCallParticipantRoles\(\);[\s\S]*viewerCallRole\.value = 'participant';[\s\S]*viewerEffectiveCallRole\.value = 'participant';[\s\S]*viewerCanModerateCall\.value = false;[\s\S]*viewerCanManageOwnerRole\.value = false;/,
  'changing call scope must clear stale participant roles and revoke action flags before reloading details',
);
assert.match(
  applyLobbySnapshotBody,
  /lobbyQueue\.value = nextQueueRows;[\s\S]*for \(const key of Object\.keys\(lobbyActionState\)\) \{[\s\S]*if \(key\.startsWith\('allow:'\) \|\| key\.startsWith\('remove:'\)\) \{[\s\S]*delete lobbyActionState\[key\];/,
  'lobby snapshots must clear stale allow/remove pending actions after permission or queue changes',
);
assert.match(
  userRowSnapshotBody,
  /row\.userId === currentUserId\.value[\s\S]*viewerEffectiveCallRole\.value[\s\S]*canRemoveFromLobby: Boolean\(lobbyEntry\) && canModerate\.value,[\s\S]*canAllowFromLobby: Boolean\(lobbyEntry && lobbyEntry\.status === 'queued' && canModerate\.value\),/,
  'roster rows must derive current-user role and lobby actions from refreshed viewer moderation state',
);

for (const [name, body, expectedType] of [
  ['allowLobbyUser', allowLobbyUserBody, 'lobby/allow'],
  ['removeLobbyUser', removeLobbyUserBody, 'lobby/remove'],
  ['allowAllLobbyUsers', allowAllLobbyUsersBody, 'lobby/allow_all'],
]) {
  assert.match(
    body,
    new RegExp(`if \\(!canModerate\\.value[\\s\\S]*sendSocketFrame\\(\\{ type: '${expectedType.replace('/', '\\/')}'`),
    `${name} must fail closed when the latest snapshot revokes moderation authority`,
  );
}
assert.match(
  toggleModeratorRoleBody,
  /if \(!canModerate\.value \|\| !Number\.isInteger\(normalizedUserId\) \|\| normalizedUserId <= 0\) return;[\s\S]*if \(normalizedUserId === currentUserId\.value\) return;[\s\S]*if \(normalizeCallRole\(row\?\.callRole \|\| 'participant'\) === 'owner'\) return;[\s\S]*updateParticipantCallRole\(row, nextRole, 'role'\)/,
  'moderator grant/remove action must be gated by the refreshed canModerate flag and cannot target self or owner',
);
assert.match(
  transferOwnerRoleBody,
  /if \(!canManageOwnerRole\?\.value \|\| !Number\.isInteger\(normalizedUserId\) \|\| normalizedUserId <= 0\) return;[\s\S]*if \(normalizeCallRole\(row\?\.callRole \|\| 'participant'\) === 'owner'\) return;[\s\S]*updateParticipantCallRole\(row, 'owner', 'owner'\)/,
  'owner transfer action must be gated by refreshed owner-management authority',
);
assert.match(
  updateParticipantCallRoleBody,
  /apiRequest\(endpoint,\s*\{[\s\S]*method:\s*'PATCH'[\s\S]*body:\s*\{\s*role:\s*normalizedRole\s*\}[\s\S]*requestRoomSnapshot\(\);[\s\S]*catch \(error\) \{[\s\S]*clearRowAction\(moderationActionState, normalizedAction, normalizedUserId\);/,
  'role updates must request an authoritative room snapshot and clear stale row action state on failure',
);
assert.match(
  rosterPanelSource,
  /visibleActionSet\.has\('moderator'\)[\s\S]*:disabled="!canModerate \|\| !activeCallId \|\| row\.userId === currentUserId \|\| rowActionPending\(row\.userId\) \|\| !row\.isRoomMember \|\| row\.callRole === 'owner'"/,
  'moderator action button must disable immediately when canModerate is false',
);
assert.match(
  rosterPanelSource,
  /visibleActionSet\.has\('owner'\)[\s\S]*:disabled="!canManageOwnerRole \|\| !activeCallId \|\| rowActionPending\(row\.userId\) \|\| !row\.isRoomMember \|\| row\.callRole === 'owner'"/,
  'owner transfer button must disable immediately when owner-management authority is revoked',
);
assert.match(
  rosterPanelSource,
  /visibleActionSet\.has\('kick'\)[\s\S]*:disabled="!canModerate \|\| row\.userId === currentUserId \|\| rowActionPending\(row\.userId\) \|\| !row\.canRemoveFromLobby"/,
  'kick/remove action button must disable immediately when canModerate or row removal authority is false',
);
assert.match(
  rosterPanelSource,
  /CallAppParticipantGrantButton[\s\S]*:can-manage="canModerate"[\s\S]*permission-action="read"[\s\S]*CallAppParticipantGrantButton[\s\S]*:can-manage="canModerate"[\s\S]*permission-action="write"[\s\S]*CallAppParticipantGrantButton[\s\S]*:can-manage="canModerate"[\s\S]*permission-action="delete"/,
  'Call App grant/read/write/delete controls must inherit refreshed canModerate authority',
);
assert.match(
  callAppGrantButtonSource,
  /const canToggle = computed\(\(\) => \([\s\S]*&& props\.canManage[\s\S]*&& !pending\.value[\s\S]*async function toggleGrant\(\) \{[\s\S]*if \(!canToggle\.value\) return;/,
  'Call App grant buttons must reject stale clicks after canModerate is revoked',
);
assert.match(
  lobbySecuritySource,
  /videochat_realtime_lobby_command_requires_moderation[\s\S]*\['lobby\/allow', 'lobby\/remove', 'lobby\/allow_all'\]/,
  'backend must keep lobby allow/remove/allow_all commands behind moderation authorization',
);
assert.match(
  lobbyAuthorizeBody,
  /videochat_realtime_lobby_server_role_for_user\(\$pdo, \$userId\)[\s\S]*videochat_realtime_call_role_context_for_room_user\([\s\S]*\$requestedCallId[\s\S]*\$serverRole[\s\S]*if \(\$callId === '' \|\| !\(bool\) \(\$context\['can_moderate'\] \?\? false\)\)/,
  'backend lobby commands must use fresh DB-backed role context rather than stale websocket role claims',
);
assert.match(
  ownerModerationContract,
  /old owner should lose call moderation controls[\s\S]*old non-admin owner must not moderate after transfer[\s\S]*new owner should moderate after transfer/,
  'runtime owner-transfer contract must prove permission downgrade revokes stale owner moderation actions',
);
assert.match(
  lobbySecurityContract,
  /forged role\/call_role must not authorize lobby moderation[\s\S]*owner of another call must not moderate this room lobby[\s\S]*forged call id must be rebound to target room context/,
  'runtime lobby security contract must reject stale or forged realtime room authority',
);
assert.match(
  sendLayoutCommandBody,
  /if \(!canModerate\.value \|\| !isSocketOnline\.value\) return false;/,
  'layout moderation commands must stop after a permission downgrade',
);
assert.match(
  publishLayoutSelectionStateBody,
  /if \(!canModerate\.value\) return false;/,
  'published layout selection must stop after a permission downgrade',
);
assert.match(
  setActiveTabBody,
  /if \(isSocketOnline\.value && nextTab === 'users'\) \{[\s\S]*requestRoomSnapshot\(\);/,
  'returning to the users roster must ask for a fresh room snapshot instead of relying on stale UI state',
);

const permissionActionBodies = [
  allowLobbyUserBody,
  removeLobbyUserBody,
  allowAllLobbyUsersBody,
  updateParticipantCallRoleBody,
  toggleModeratorRoleBody,
  transferOwnerRoleBody,
  sendLayoutCommandBody,
  publishLayoutSelectionStateBody,
].join('\n');
assert.doesNotMatch(
  permissionActionBodies,
  /reconnect|reload|location|initSFU|sfuClient|setLocalScreenShareEnabled|reconfigureLocalTracksFromSelectedDevices|Background|background/i,
  'permission downgrade handling must not depend on reconnect, media, SFU, or background flows',
);
assert.match(
  workspaceSource,
  /function requestRoomSnapshotLocal\(\) \{[\s\S]*sendSocketFrame\(\{ type: 'room\/snapshot\/request' \}\)/,
  'workspace must have a direct room snapshot request path for permission refresh',
);

process.stdout.write('[call-access-permission-change-active-call-contract] PASS\n');
