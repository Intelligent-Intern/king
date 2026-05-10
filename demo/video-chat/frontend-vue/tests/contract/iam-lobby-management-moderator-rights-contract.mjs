import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const frontendRoot = path.resolve(__dirname, '../..');
const repoRoot = path.resolve(frontendRoot, '../../..');

function readText(relativePath) {
  return fs.readFileSync(path.join(repoRoot, relativePath), 'utf8');
}

function readJson(relativePath) {
  return JSON.parse(readText(relativePath));
}

function functionBody(source, name) {
  const marker = `function ${name}`;
  const start = source.indexOf(marker);
  assert.notEqual(start, -1, `missing function ${name}`);
  let open = -1;
  let parenDepth = 0;
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

const packageJson = readJson('demo/video-chat/frontend-vue/package.json');
const matrix = readJson('demo/video-chat/contracts/v1/ui-parity-acceptance.matrix.json');
const sprint = readText('SPRINT.md');
const callManagementContract = readText('demo/video-chat/backend-king-php/domain/calls/call_management_contract.php');
const callManagementQuery = readText('demo/video-chat/backend-king-php/domain/calls/call_management_query.php');
const realtimeRoleContext = readText('demo/video-chat/backend-king-php/domain/realtime/realtime_call_role_context.php');
const realtimeLobbyState = readText('demo/video-chat/backend-king-php/domain/realtime/realtime_lobby_state.php');
const lobbySecurity = readText('demo/video-chat/backend-king-php/http/module_realtime_lobby_security.php');
const anonymousLobbyContract = readText('demo/video-chat/backend-king-php/tests/call-access-anonymous-lobby-contract.php');
const realtimeLobbySecurityContract = readText('demo/video-chat/backend-king-php/tests/realtime-lobby-security-contract.php');
const tempModeratorContract = readText('demo/video-chat/backend-king-php/tests/call-temporary-moderator-contract.php');
const sqliteRuntimeProof = readText('demo/video-chat/backend-king-php/tests/iam-call-access-sqlite-runtime-proof.sh');
const workspaceView = readText('demo/video-chat/frontend-vue/src/domain/realtime/CallWorkspaceView.vue');
const participantUi = readText('demo/video-chat/frontend-vue/src/domain/realtime/workspace/callWorkspace/participantUi.ts');

const iamContractScript = String(packageJson.scripts?.['test:contract:iam-call-access'] || '');
const matrixPaths = new Set(matrix.commands?.['frontend:contract:iam-call-access']?.paths || []);

assert.match(
  callManagementContract,
  /function videochat_call_invite_state_allows_scoped_role\(mixed \$value\): bool[\s\S]*return !in_array\(\$normalized, \['declined', 'cancelled'\], true\);/,
  'call-scoped owner/moderator roles must fail closed for declined or cancelled participant states',
);
assert.match(
  functionBody(callManagementQuery, 'videochat_user_is_call_moderator'),
  /SELECT call_participants\.call_role, call_participants\.invite_state, calls\.status[\s\S]*INNER JOIN calls ON calls\.id = call_participants\.call_id[\s\S]*\$row\['status'\][\s\S]*\['active', 'scheduled'\][\s\S]*videochat_call_invite_state_allows_scoped_role\(\$row\['invite_state'\] \?\? 'invited'\)[\s\S]*\$callRole === 'owner' \|\| \$callRole === 'moderator'/,
  'call administration must not treat stale inactive moderator rows as active call moderation',
);
assert.match(
  functionBody(realtimeRoleContext, 'videochat_realtime_call_role_context_for_room_user'),
  /\$scopedRoleActive =[\s\S]*videochat_call_invite_state_allows_scoped_role\(\$inviteState\)[\s\S]*'can_moderate' => \$isAdmin[\s\S]*\$isOrganizationAdmin[\s\S]*\$scopedRoleActive && in_array\(\$callRole, \['owner', 'moderator'\], true\)[\s\S]*'can_manage_owner' => \$isAdmin \|\| \(\$scopedRoleActive && \$callRole === 'owner'\)/,
  'realtime role context must preserve stored roles while revoking action flags for inactive participant rows',
);

const lobbyCanModerateBody = functionBody(realtimeLobbyState, 'videochat_lobby_can_moderate');
assert.match(
  lobbyCanModerateBody,
  /\$connection\['can_moderate_call'\][\s\S]*\$globalRole === 'admin'[\s\S]*return false;/,
  'lower lobby gate must accept only server-derived call moderation or global admin context',
);
assert.doesNotMatch(
  lobbyCanModerateBody,
  /raw_role|rawRole|call_role.*\['owner', 'moderator'\]/,
  'lower lobby gate must not trust forged raw_role/call_role strings',
);

assert.match(
  functionBody(lobbySecurity, 'videochat_realtime_authorize_lobby_moderation_command'),
  /videochat_realtime_lobby_server_role_for_user\(\$pdo, \$userId\)[\s\S]*videochat_realtime_call_role_context_for_room_user\([\s\S]*\$requestedCallId[\s\S]*\$serverRole[\s\S]*if \(\$requestedCallId !== ''[\s\S]*videochat_realtime_call_role_context_for_room_user\([\s\S]*'',[\s\S]*\$serverRole[\s\S]*\$effectiveCallRole[\s\S]*'effective_call_role' => \$effectiveCallRole/s,
  'lobby commands must rebind forged room/call/role frames to server-side DB context',
);
assert.match(
  realtimeLobbySecurityContract,
  /forged raw moderator\/call_role state must not pass the lower lobby gate[\s\S]*cancelled moderator participant row must lose lobby authority[\s\S]*forged role\/call_role must not authorize lobby moderation[\s\S]*forged call id must be rebound to target room context/s,
  'backend security contract must prove forged and inactive moderator contexts fail closed',
);
assert.match(
  anonymousLobbyContract,
  /host waiting snapshot[\s\S]*host admitted logged-in participant[\s\S]*temporary moderator waiting snapshot[\s\S]*temporary moderator admission[\s\S]*temporary moderator admitted anonymous guest[\s\S]*temporary moderator rejection[\s\S]*temporary moderator rejected anonymous guest[\s\S]*organization admin waiting snapshot[\s\S]*organization admin admission[\s\S]*organization admin rejected anonymous guest[\s\S]*system admin waiting snapshot[\s\S]*system admin admission[\s\S]*system admin rejected anonymous guest[\s\S]*unauthorized waiting-user lobby controls[\s\S]*queued participant must not authorize self admission/s,
  'anonymous lobby backend proof must separate owner/admin/moderator/user/guest lobby rights',
);
for (const tempModeratorNeedle of [
  'server-side moderator grant should authorize lobby controls',
  'temporary moderator must not manage owner transfer',
  'revoked moderator should lose controls',
  'forged moderator connection must be denied after revoke',
]) {
  assert.ok(
    tempModeratorContract.includes(tempModeratorNeedle),
    'temporary moderator proof must keep lobby management separate from owner/admin privileges',
  );
}
assert.match(
  workspaceView,
  /const canModerate = computed\(\(\) => \([\s\S]*viewerCanModerateCall\.value[\s\S]*viewerEffectiveCallRole\.value === 'owner'[\s\S]*viewerEffectiveCallRole\.value === 'moderator'[\s\S]*\)\);[\s\S]*const showLobbyTab = computed\(\(\) => canModerate\.value\);/,
  'workspace lobby controls must be driven by server-derived viewer moderation state',
);
for (const [name, expectedType] of [
  ['allowLobbyUser', 'lobby/allow'],
  ['removeLobbyUser', 'lobby/remove'],
  ['allowAllLobbyUsers', 'lobby/allow_all'],
]) {
  assert.match(
    functionBody(participantUi, name),
    new RegExp(`if \\(!canModerate\\.value[\\s\\S]*sendSocketFrame\\(\\{ type: '${expectedType.replace('/', '\\/')}'`),
    `${name} must fail closed before sending ${expectedType}`,
  );
}

for (const scriptNeedle of [
  'node tests/contract/iam-lobby-management-moderator-rights-contract.mjs',
  '../backend-king-php/tests/iam-call-access-sqlite-runtime-proof.sh',
]) {
  assert.ok(iamContractScript.includes(scriptNeedle), `IAM contract script must include ${scriptNeedle}`);
}
for (const matrixPath of [
  'frontend-vue/tests/contract/iam-lobby-management-moderator-rights-contract.mjs',
  'backend-king-php/tests/call-access-anonymous-lobby-contract.sh',
  'backend-king-php/tests/call-temporary-moderator-contract.sh',
  'backend-king-php/tests/realtime-lobby-security-contract.sh',
]) {
  assert.ok(matrixPaths.has(matrixPath), `IAM contract metadata must list ${matrixPath}`);
}
for (const backendProof of [
  'call-access-anonymous-lobby-contract.sh',
  'call-temporary-moderator-contract.sh',
  'realtime-lobby-security-contract.sh',
]) {
  assert.ok(sqliteRuntimeProof.includes(`"${backendProof}"`), `SQLite IAM proof must include ${backendProof}`);
}
assert.match(
  sprint,
  /- \[x\] IAM7-17 Extract or prove lobby management moderator rights from[\s\S]*`local\/iam-e2e-lobby-management-moderator-rights`/,
  'SPRINT.md must close IAM7-17 only when this proof is wired',
);

process.stdout.write('[iam-lobby-management-moderator-rights-contract] PASS\n');
