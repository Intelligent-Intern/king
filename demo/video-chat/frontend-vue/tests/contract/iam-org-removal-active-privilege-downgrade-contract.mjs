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

const runtimeProof = readText('demo/video-chat/backend-king-php/tests/call-access-org-removal-active-privilege-downgrade-contract.php');
const runtimeWrapper = readText('demo/video-chat/backend-king-php/tests/call-access-org-removal-active-privilege-downgrade-contract.sh');
const sqliteProof = readText('demo/video-chat/backend-king-php/tests/iam-call-access-sqlite-runtime-proof.sh');
const realtimeContext = readText('demo/video-chat/backend-king-php/domain/realtime/realtime_call_context.php');
const realtimeRoleContext = readText('demo/video-chat/backend-king-php/domain/realtime/realtime_call_role_context.php');
const callManagementQuery = readText('demo/video-chat/backend-king-php/domain/calls/call_management_query.php');
const roomSnapshot = readText('demo/video-chat/backend-king-php/domain/realtime/realtime_room_snapshot.php');

const connectionWithContextBody = functionBody(realtimeContext, 'videochat_realtime_connection_with_call_context');
const bypassBody = functionBody(realtimeContext, 'videochat_realtime_connection_can_bypass_admission_for_room');
const moderatorBody = functionBody(realtimeContext, 'videochat_realtime_is_user_moderator_for_room');
const roleContextBody = functionBody(realtimeRoleContext, 'videochat_realtime_call_role_context_for_room_user');
const orgAdminBody = functionBody(callManagementQuery, 'videochat_user_is_organization_admin_for_call');
const canAdministerBody = functionBody(callManagementQuery, 'videochat_can_administer_call');
const snapshotBody = functionBody(roomSnapshot, 'videochat_realtime_room_snapshot_payload');

assert.match(
  runtimeProof,
  /videochat_iam719_disable_organization_membership[\s\S]*stale org-admin connection must lose active call binding after removal[\s\S]*removed org admin must not moderate from current backend state[\s\S]*removed org admin direct-room bypass must fail closed against stale connection fields/s,
  'runtime proof must cover stale active org-admin websocket downgrade after organization removal',
);
assert.match(
  runtimeProof,
  /explicitly invited removed org member should keep active call binding[\s\S]*removed org admin should downgrade to participant when only call scope remains[\s\S]*snapshot should publish downgraded participant role[\s\S]*snapshot should remove stale org-admin controls/s,
  'runtime proof must keep explicit call-scoped admission while downgrading removed organization privileges',
);
assert.match(
  runtimeProof,
  /videochat_iam719_disable_tenant_membership[\s\S]*tenant membership removal should fail active websocket liveness[\s\S]*tenant removal close code should be policy violation[\s\S]*cached stale tenant token must not survive membership removal/s,
  'runtime proof must close active websocket sessions fail-closed after tenant membership removal',
);
assert.match(
  runtimeWrapper,
  /call-access-org-removal-active-privilege-downgrade-contract\.php/,
  'runtime wrapper must execute the IAM7-19 PHP contract',
);
assert.match(
  sqliteProof,
  /call-access-org-removal-active-privilege-downgrade-contract\.sh/,
  'SQLite IAM runtime proof must include the IAM7-19 org-removal downgrade contract',
);

assert.match(
  orgAdminBody,
  /organization_memberships admin_membership[\s\S]*organizations\.status = 'active'[\s\S]*owner_membership\.status = 'active'[\s\S]*admin_membership\.membership_role = 'admin'[\s\S]*admin_membership\.status = 'active'/,
  'organization-admin call powers must be re-read from active organization membership rows',
);
assert.match(
  canAdministerBody,
  /videochat_can_edit_call\([\s\S]*videochat_user_is_call_moderator\([\s\S]*videochat_user_is_organization_admin_for_call\(/,
  'call administration must combine current owner, call moderator, and organization-admin checks',
);
assert.match(
  roleContextBody,
  /\$scopedRoleActive = \$isAdmin[\s\S]*\$isOrganizationAdmin[\s\S]*videochat_call_invite_state_allows_scoped_role\(\$inviteState\)[\s\S]*'can_moderate' => \$isAdmin[\s\S]*\$isOrganizationAdmin[\s\S]*\$scopedRoleActive[\s\S]*'can_manage_owner' => \$isAdmin \|\| \(\$scopedRoleActive && \$callRole === 'owner'\)/,
  'realtime role context must derive owner/admin/moderator/user authority from current server-side role state',
);
assert.match(
  connectionWithContextBody,
  /videochat_realtime_call_role_context_for_room_user\([\s\S]*\$roomId,[\s\S]*\$userId,[\s\S]*\$requestedCallId,[\s\S]*videochat_realtime_connection_tenant_id\(\$connection\)[\s\S]*\$connection\['active_call_id'\][\s\S]*\$connection\['effective_call_role'\][\s\S]*\$connection\['can_moderate_call'\]/,
  'active websocket connections must refresh active call binding and privilege flags from the server',
);
assert.match(
  bypassBody,
  /videochat_realtime_call_role_context_for_room_user\([\s\S]*\$normalizedRoomId,[\s\S]*\$connectionUserId,[\s\S]*\$requestedCallId[\s\S]*return videochat_realtime_call_context_allows_admission_bypass\(\$context\);/,
  'room admission bypass must ignore stale connection fields and re-read current call context',
);
assert.match(
  moderatorBody,
  /videochat_realtime_call_role_context_for_room_user\([\s\S]*\$roomId,[\s\S]*\$userId,[\s\S]*\$requestedCallId[\s\S]*return \(bool\) \(\$context\['can_moderate'\] \?\? false\);/,
  'moderator checks must re-read current server role context',
);
assert.match(
  snapshotBody,
  /\$viewerConnection = videochat_realtime_connection_with_call_context\(\$connection, \$openDatabase\);[\s\S]*videochat_realtime_owner_absence_downgrade_absent_owner_connection[\s\S]*'viewer' => \[[\s\S]*'call_id' => \(string\) \(\$viewerConnection\['active_call_id'\][\s\S]*'effective_call_role' => videochat_normalize_call_participant_role\([\s\S]*'can_moderate' => \(bool\) \(\$viewerConnection\['can_moderate_call'\] \?\? false\)/,
  'room snapshots must publish downgraded active-call viewer privileges',
);

process.stdout.write('[iam-org-removal-active-privilege-downgrade-contract] PASS\n');
