import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

function fail(message) {
  throw new Error(`[call-access-owner-transfer-main-contract] FAIL: ${message}`);
}

function readText(repoRoot, relativePath) {
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

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);
const frontendRoot = path.resolve(__dirname, '../..');
const repoRoot = path.resolve(frontendRoot, '../../..');

const callsModule = readText(repoRoot, 'demo/video-chat/backend-king-php/http/module_calls.php');
const callManagementEntrypoint = readText(repoRoot, 'demo/video-chat/backend-king-php/domain/calls/call_management.php');
const callManagement = readText(repoRoot, 'demo/video-chat/backend-king-php/domain/calls/call_management_owner_transfer.php');
const callAccessDecision = readText(repoRoot, 'demo/video-chat/backend-king-php/domain/calls/call_access_decision.php');
const realtimeContext = readText(repoRoot, 'demo/video-chat/backend-king-php/domain/realtime/realtime_call_context.php');
const realtimeRoleContext = readText(repoRoot, 'demo/video-chat/backend-king-php/domain/realtime/realtime_call_role_context.php');
const lobbySecurity = readText(repoRoot, 'demo/video-chat/backend-king-php/http/module_realtime_lobby_security.php');
const ownerModerationProof = readText(repoRoot, 'demo/video-chat/backend-king-php/tests/call-owner-moderation-contract.php');
const workspaceSource = readText(repoRoot, 'demo/video-chat/frontend-vue/src/domain/realtime/CallWorkspaceView.vue');
const roomStateSource = readText(repoRoot, 'demo/video-chat/frontend-vue/src/domain/realtime/workspace/callWorkspace/roomState.ts');
const participantUiSource = readText(repoRoot, 'demo/video-chat/frontend-vue/src/domain/realtime/workspace/callWorkspace/participantUi.ts');
const rosterPanelSource = readText(repoRoot, 'demo/video-chat/frontend-vue/src/domain/realtime/workspace/callWorkspace/RightRosterPanel.vue');

try {
  assert.match(
    callsModule,
    /preg_match\('#\^\/api\/calls\/\(\[A-Za-z0-9\._-\]\{1,200\}\)\/participants\/\(\\d\+\)\/role\$\#'[\s\S]*\$method !== 'PATCH'[\s\S]*\$targetRole = \(string\) \(\$payload\['role'\] \?\? \(\$payload\['call_role'\] \?\? ''\)\)[\s\S]*videochat_update_call_participant_role\(\$pdo, \$callId, \$targetUserId, \$targetRole, \$authenticatedUserId, \$authenticatedUserRole, videochat_tenant_id_from_auth_context\(\$apiAuthContext\)\)[\s\S]*'state' => 'participant_role_updated'/,
    'participant role endpoint must route PATCH role updates through the call-scoped owner-transfer domain function',
  );
  assert.match(
    callManagementEntrypoint,
    /require_once __DIR__ \. '\/call_management_owner_transfer\.php';/,
    'call management entrypoint must load the focused owner-transfer extraction',
  );

  const updateRoleBody = functionBody(callManagement, 'videochat_update_call_participant_role');
  assert.match(
    updateRoleBody,
    /\$isSystemAdmin = videochat_user_has_system_admin_call_rights\(\$pdo, \$authUserId, \$authRole\);[\s\S]*videochat_fetch_call_for_update\(\$pdo, \$callId, \$isSystemAdmin \? null : \$tenantId\)/,
    'owner transfer must load the call under the authenticated tenant unless the actor has system-admin call rights',
  );
  assert.match(
    updateRoleBody,
    /if \(\$normalizedTargetRole === 'owner'\) \{[\s\S]*if \(!\$isOwner && !\$isSystemAdmin\)[\s\S]*'owner_transfer_requires_current_owner'/,
    'owner transfer must require the current owner or a system admin',
  );
  assert.match(
    updateRoleBody,
    /\$targetParticipantQuery = \$pdo->prepare\([\s\S]*FROM call_participants[\s\S]*AND user_id = :user_id[\s\S]*AND source = 'internal'[\s\S]*must_reference_internal_participant/,
    'owner transfer target must already be an internal call participant',
  );
  assert.match(
    updateRoleBody,
    /UPDATE calls SET owner_user_id = :owner_user_id, updated_at = :updated_at WHERE id = :id[\s\S]*':owner_user_id' => \$targetUserId/,
    'owner transfer must update the canonical calls.owner_user_id field',
  );
  assert.match(
    updateRoleBody,
    /UPDATE call_participants\s+SET call_role = 'participant'\s+WHERE call_id = :call_id\s+AND source = 'internal'\s+AND user_id IS NOT NULL\s+AND user_id <> :target_user_id\s+AND call_role = 'owner'[\s\S]*':target_user_id' => \$targetUserId/,
    'owner transfer must demote every previous owner participant row to participant',
  );
  assert.match(
    updateRoleBody,
    /UPDATE call_participants\s+SET call_role = 'owner',[\s\S]*WHERE call_id = :call_id\s+AND user_id = :user_id\s+AND source = 'internal'[\s\S]*':user_id' => \$targetUserId/,
    'owner transfer must promote the new owner participant row',
  );
  assert.match(
    updateRoleBody,
    /\$resultTenantId = \$isSystemAdmin && is_numeric\(\$existingCall\['tenant_id'\] \?\? null\)[\s\S]*\$updatedCall = videochat_fetch_call_for_update\(\$pdo, \(string\) \(\$existingCall\['id'\] \?\? ''\), \$resultTenantId\)[\s\S]*videochat_build_call_payload\(\$pdo, \$updatedCall, \$authUserId\)/,
    'role update response must rebuild the call payload from the post-transfer call state',
  );

  assert.match(
    callAccessDecision,
    /\$ownerUserId = \(int\) \(\$call\['owner_user_id'\] \?\? 0\);[\s\S]*if \(\$authUserId > 0 && \$authUserId === \$ownerUserId\) \{[\s\S]*\$callRole = 'owner';[\s\S]*\}/,
    'call access must derive owner authority from the canonical call owner after transfer',
  );
  assert.match(
    callAccessDecision,
    /\$canAdminister = \$allowed && \(\$source === 'system_admin' \|\| in_array\(\$normalizedEffectiveRole, \['owner', 'moderator'\], true\)\);[\s\S]*\$canManageOwner = \$allowed && \(\$source === 'system_admin' \|\| \$normalizedEffectiveRole === 'owner'\);/,
    'call access must keep owner-management authority stricter than general moderation',
  );

  assert.match(
    realtimeContext,
    /require_once __DIR__ \. '\/realtime_call_role_context\.php'/,
    'realtime context must load the focused role resolver extraction',
  );
  assert.match(
    realtimeRoleContext,
    /if \(\(int\) \(\$row\['owner_user_id'\] \?\? 0\) === \$userId\) \{[\s\S]*\$callRole = 'owner';[\s\S]*\}[\s\S]*SELECT[\s\S]*calls\.owner_user_id,[\s\S]*cp\.call_role/,
    'realtime role context must recompute owner role from persisted call owner state',
  );
  assert.match(
    realtimeRoleContext,
    /\$scopedRoleActive =[\s\S]*videochat_call_invite_state_allows_scoped_role\(\$inviteState\)[\s\S]*'can_moderate' => \$isAdmin[\s\S]*\|\| \$isOrganizationAdmin[\s\S]*\|\| \(\$scopedRoleActive && in_array\(\$callRole, \['owner', 'moderator'\], true\)\),[\s\S]*'can_manage_owner' => \$isAdmin \|\| \(\$scopedRoleActive && \$callRole === 'owner'\),/,
    'realtime role context must not leave demoted previous owners with owner-management controls while preserving org-admin moderation',
  );
  assert.match(
    lobbySecurity,
    /videochat_realtime_lobby_server_role_for_user\(\$pdo, \$userId\)[\s\S]*videochat_realtime_call_role_context_for_room_user\([\s\S]*\$requestedCallId[\s\S]*\$serverRole[\s\S]*\$tenantId[\s\S]*if \(\$callId === '' \|\| !\(bool\) \(\$context\['can_moderate'\] \?\? false\)\)/,
    'lobby moderation must authorize through fresh DB-backed call context instead of stale connection roles',
  );

  assert.match(ownerModerationProof, /normal participant must not transfer ownership/, 'runtime owner proof must deny non-owner transfer');
  assert.match(ownerModerationProof, /current owner should transfer ownership/, 'runtime owner proof must cover the main current-owner transfer journey');
  assert.match(ownerModerationProof, /transfer should leave exactly one owner participant row/, 'runtime owner proof must prevent duplicate owner participant rows');
  assert.match(ownerModerationProof, /old owner should be demoted to participant/, 'runtime owner proof must assert old owner demotion');
  assert.match(ownerModerationProof, /old owner should lose call moderation controls/, 'runtime owner proof must assert old owner loses moderation authority');
  assert.match(ownerModerationProof, /old non-admin owner must not moderate after transfer/, 'runtime owner proof must reject old-owner lobby moderation after transfer');
  assert.match(ownerModerationProof, /new owner should moderate after transfer/, 'runtime owner proof must grant moderation to the new owner');

  assert.match(
    workspaceSource,
    /const canModerate = computed\(\(\) => \([\s\S]*viewerEffectiveCallRole\.value === 'owner'[\s\S]*viewerEffectiveCallRole\.value === 'moderator'[\s\S]*\)\);[\s\S]*const canManageOwnerRole = computed\(\(\) => \([\s\S]*viewerCanManageOwnerRole\.value[\s\S]*viewerEffectiveCallRole\.value === 'owner'[\s\S]*\)\);/,
    'workspace must keep owner-transfer authority as a separate gate from general moderation',
  );

  const viewerContextBody = functionBody(roomStateSource, 'applyViewerContext');
  assert.match(
    viewerContextBody,
    /viewerEffectiveCallRole\.value = normalizeCallRole\([\s\S]*viewer\.effective_call_role[\s\S]*viewer\.effectiveCallRole[\s\S]*viewer\.call_role[\s\S]*viewer\.callRole/,
    'viewer context must update effective call role from call-scoped backend data after transfer',
  );
  assert.match(
    viewerContextBody,
    /viewerCanManageOwnerRole\.value = Boolean\([\s\S]*viewer\.can_manage_owner[\s\S]*viewer\.canManageOwner[\s\S]*viewer\.can_manage_call_owner[\s\S]*viewer\.canManageCallOwner/,
    'viewer context must update owner-management permission from call-scoped backend data after transfer',
  );

  const roleUpdateBody = functionBody(participantUiSource, 'updateParticipantCallRole');
  assert.match(
    roleUpdateBody,
    /apiRequest\(endpoint,\s*\{[\s\S]*method:\s*'PATCH'[\s\S]*body:\s*\{\s*role:\s*normalizedRole\s*\}/,
    'frontend owner transfer must use the call participant role PATCH endpoint',
  );
  assert.match(
    roleUpdateBody,
    /requestRoomSnapshot\(\);/,
    'frontend owner transfer must request a room snapshot so call-scoped roles can be refreshed',
  );

  const transferOwnerBody = functionBody(participantUiSource, 'transferOwnerRole');
  assert.match(
    transferOwnerBody,
    /if \(!canManageOwnerRole\?\.value \|\| !Number\.isInteger\(normalizedUserId\) \|\| normalizedUserId <= 0\) return;/,
    'frontend transfer action must require owner-management authority, not only moderation authority',
  );
  assert.match(
    transferOwnerBody,
    /if \(normalizeCallRole\(row\?\.callRole \|\| 'participant'\) === 'owner'\) return;[\s\S]*updateParticipantCallRole\(row, 'owner', 'owner'\)/,
    'frontend transfer action must promote a non-owner participant through the owner role update',
  );
  assert.match(
    rosterPanelSource,
    /v-if="visibleActionSet\.has\('owner'\)"[\s\S]*:disabled="!canManageOwnerRole \|\| !activeCallId \|\| rowActionPending\(row\.userId\) \|\| !row\.isRoomMember \|\| row\.callRole === 'owner'"/,
    'roster owner-transfer button must be disabled unless the viewer has owner-management authority',
  );

  process.stdout.write('[call-access-owner-transfer-main-contract] PASS\n');
} catch (error) {
  if (error instanceof Error) {
    fail(error.message);
  }
  fail('unknown failure');
}
