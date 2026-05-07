import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

function fail(message) {
  throw new Error(`[admin-owner-rights-contract] FAIL: ${message}`);
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
const workspacePath = path.resolve(__dirname, '../../src/domain/realtime/CallWorkspaceView.vue');
const source = fs.readFileSync(workspacePath, 'utf8');
const participantUiSource = fs.readFileSync(
  path.resolve(__dirname, '../../src/domain/realtime/workspace/callWorkspace/participantUi.ts'),
  'utf8'
);
const roomStateSource = fs.readFileSync(
  path.resolve(__dirname, '../../src/domain/realtime/workspace/callWorkspace/roomState.ts'),
  'utf8'
);
const shellSource = fs.readFileSync(path.resolve(__dirname, '../../src/layouts/WorkspaceShell.vue'), 'utf8');

try {
  assert.match(source, /const viewerEffectiveCallRole = ref\('participant'\);/, 'workspace must track effective call role separately');
  assert.match(source, /const viewerCanManageOwnerRole = ref\(false\);/, 'workspace must track owner-management permission separately');

  assert.match(
    source,
    /const canModerate = computed\(\(\) => \([\s\S]*viewerEffectiveCallRole\.value === 'owner'[\s\S]*viewerEffectiveCallRole\.value === 'moderator'[\s\S]*\)\);/,
    'moderation gate must honor effective owner/moderator roles'
  );
  assert.match(
    source,
    /const canManageOwnerRole = computed\(\(\) => \([\s\S]*viewerCanManageOwnerRole\.value[\s\S]*viewerEffectiveCallRole\.value === 'owner'[\s\S]*\)\);/,
    'owner-management gate must honor explicit owner-equivalent permission'
  );

  const currentUserRowBody = functionBody(roomStateSource, 'currentUserParticipantRow');
  assert.match(
    currentUserRowBody,
    /normalizeCallRole\(viewerEffectiveCallRole\.value \|\| callParticipantRoles\[userId\]/,
    'current viewer row must use effective call role before stored participant role'
  );
  const userRowSnapshotBody = functionBody(participantUiSource, 'userRowSnapshot');
  assert.match(
    userRowSnapshotBody,
    /row\.userId === currentUserId\.value\s*\?\s*viewerEffectiveCallRole\.value\s*:\s*\(callParticipantRoles\[row\.userId\]/,
    'current viewer snapshot row must prefer the effective role over the stored participant role'
  );

  const viewerBody = functionBody(roomStateSource, 'applyViewerContext');
  assert.match(viewerBody, /viewerEffectiveCallRole\.value = 'participant';/, 'viewer reset must clear effective call role');
  assert.match(viewerBody, /viewerCanManageOwnerRole\.value = false;/, 'viewer reset must clear owner-equivalent permission');
  assert.match(viewerBody, /viewer\.effective_call_role[\s\S]*viewer\.effectiveCallRole/, 'viewer context must read backend effective_call_role');
  assert.match(viewerBody, /viewer\.can_manage_owner[\s\S]*viewer\.canManageOwner/, 'viewer context must read backend owner-management permission');

  const callDetailsBody = functionBody(roomStateSource, 'applyCallDetails');
  assert.match(callDetailsBody, /const isAdmin = normalizeRole\(sessionState\.role\) === 'admin';/, 'call details must detect admins');
  assert.match(callDetailsBody, /viewerEffectiveCallRole\.value = isAdmin \? 'owner' : currentCallRole;/, 'admin participant details must remain owner-equivalent');
  assert.match(callDetailsBody, /else if \(isAdmin\) \{[\s\S]*viewerEffectiveCallRole\.value = 'owner';[\s\S]*viewerCanManageOwnerRole\.value = true;/, 'admin non-participant details must remain owner-equivalent');

  assert.match(source, /canManageOwnerRole,\s*\n\s*canModerate,/, 'participant UI helpers must receive owner-management permissions');
  assert.match(participantUiSource, /function toggleModeratorRole\(row\)/, 'participant panel must implement moderator role toggling');
  assert.match(participantUiSource, /function transferOwnerRole\(row\)/, 'participant panel must implement owner transfer action');
  assert.match(participantUiSource, /apiRequest\(endpoint,\s*\{[\s\S]*method:\s*'PATCH'[\s\S]*body:\s*\{\s*role:\s*normalizedRole\s*\}/, 'role actions must call the participant role endpoint');
  assert.match(participantUiSource, /requestRoomSnapshot\(\);/, 'role actions must refresh call-scoped viewer rights after updates');
  assert.match(shellSource, /function callParticipantRoleForUser\(call, userId\)/, 'workspace shell must derive call-scoped participant role');
  assert.match(shellSource, /currentCallRole === 'owner' \|\| currentCallRole === 'moderator'/, 'workspace shell must expose owner sidebar to call moderators');
  assert.match(shellSource, /Boolean\(callLayoutSidebarState\.canModerate\)/, 'workspace shell must refresh owner sidebar when live moderator rights change');

  process.stdout.write('[admin-owner-rights-contract] PASS\n');
} catch (error) {
  if (error instanceof Error) {
    fail(error.message);
  }
  fail('unknown failure');
}
