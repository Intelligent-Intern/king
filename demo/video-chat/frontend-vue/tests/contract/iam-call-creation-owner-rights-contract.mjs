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
      if (depth === 0) {
        return source.slice(open, index + 1);
      }
    }
  }

  assert.fail(`unterminated body for ${name}`);
}

const backendProof = readText('demo/video-chat/backend-king-php/tests/call-creation-owner-rights-contract.php');
const roomState = readText('demo/video-chat/frontend-vue/src/domain/realtime/workspace/callWorkspace/roomState.ts');
const workspaceView = readText('demo/video-chat/frontend-vue/src/domain/realtime/CallWorkspaceView.vue');
const participantUi = readText('demo/video-chat/frontend-vue/src/domain/realtime/workspace/callWorkspace/participantUi.ts');
const rightRosterPanel = readText('demo/video-chat/frontend-vue/src/domain/realtime/workspace/callWorkspace/RightRosterPanel.vue');

assert.match(
  backendProof,
  /creator participant should have owner role[\s\S]*creator should have call-admin rights[\s\S]*owner should moderate own call[\s\S]*realtime context should allow moderation[\s\S]*creator should update own call through call-admin path/,
  'backend proof must preserve creator owner, admin, realtime moderation, and update rights',
);
assert.match(
  backendProof,
  /videochat_call_creation_owner_rights_assert_owner_moderation[\s\S]*non-owner must not admit lobby participants[\s\S]*owner should admit lobby participants[\s\S]*non-owner must not kick admitted participants[\s\S]*owner should kick admitted participants/,
  'backend proof must exercise owner moderation and non-owner denial through lobby commands',
);
assert.match(
  backendProof,
  /videochat_call_role_context_for_room_user[\s\S]*can_moderate[\s\S]*can_manage_owner[\s\S]*videochat_realtime_call_role_context_for_room_user[\s\S]*can_moderate[\s\S]*can_manage_owner/s,
  'backend proof must cover both domain and realtime owner-management contexts',
);

assert.match(
  functionBody(roomState, 'applyViewerContext'),
  /viewerCanModerateCall\.value = Boolean\(viewer\.can_moderate \?\? viewer\.canModerate \?\? false\);[\s\S]*viewerCanManageOwnerRole\.value = Boolean\([\s\S]*viewer\.can_manage_owner[\s\S]*viewer\.can_manage_call_owner[\s\S]*false[\s\S]*\);/,
  'room state must consume server-derived viewer moderation and owner-management rights',
);
assert.match(
  functionBody(roomState, 'applyCallDetails'),
  /currentCallRole === 'owner' \|\| currentCallRole === 'moderator'[\s\S]*viewerCanManageOwnerRole\.value = isAdmin \|\| currentCallRole === 'owner'[\s\S]*ownerUserId[\s\S]*viewerCanModerateCall\.value = true[\s\S]*viewerCanManageOwnerRole\.value = true/s,
  'call details fallback must make the creator owner able to moderate and manage owner role',
);

assert.match(
  workspaceView,
  /const canModerate = computed\(\(\) => \([\s\S]*viewerCanModerateCall\.value[\s\S]*viewerEffectiveCallRole\.value === 'owner'[\s\S]*viewerEffectiveCallRole\.value === 'moderator'[\s\S]*\)\);/,
  'workspace moderation gate must honor server-derived owner and moderator context',
);
assert.match(
  workspaceView,
  /const canManageOwnerRole = computed\(\(\) => \([\s\S]*viewerCanManageOwnerRole\.value[\s\S]*viewerEffectiveCallRole\.value === 'owner'[\s\S]*\)\);/,
  'workspace owner-transfer gate must stay separate from general moderation',
);

for (const [name, type] of [
  ['allowLobbyUser', 'lobby/allow'],
  ['removeLobbyUser', 'lobby/remove'],
]) {
  assert.match(
    functionBody(participantUi, name),
    new RegExp(`if \\(!canModerate\\.value[\\s\\S]*sendSocketFrame\\(\\{ type: '${type.replace('/', '\\/')}'`),
    `${name} must fail closed before sending ${type}`,
  );
}
assert.match(
  functionBody(participantUi, 'allowAllLobbyUsers'),
  /if \(!canModerate\.value\) return;[\s\S]*sendSocketFrame\(\{ type: 'lobby\/allow_all' \}\)/,
  'allow-all lobby action must require current moderation rights',
);
assert.match(
  functionBody(participantUi, 'toggleModeratorRole'),
  /if \(!canModerate\.value[\s\S]*return;[\s\S]*updateParticipantCallRole\(row, nextRole, 'role'\)/,
  'moderator assignment must require moderation rights',
);
assert.match(
  functionBody(participantUi, 'transferOwnerRole'),
  /if \(!canManageOwnerRole\?\.value[\s\S]*return;[\s\S]*updateParticipantCallRole\(row, 'owner', 'owner'\)/,
  'owner transfer must require owner-management rights, not only moderation',
);

assert.match(
  rightRosterPanel,
  /:disabled="!canModerate \|\| row\.status !== 'queued' \|\| lobbyActionPending\(row\.user_id\)"[\s\S]*:disabled="!canModerate \|\| lobbyActionPending\(row\.user_id\)"[\s\S]*:disabled="!canManageOwnerRole \|\| !activeCallId/,
  'roster controls must expose lobby moderation and owner transfer through separate gates',
);

process.stdout.write('[iam-call-creation-owner-rights-contract] PASS\n');
