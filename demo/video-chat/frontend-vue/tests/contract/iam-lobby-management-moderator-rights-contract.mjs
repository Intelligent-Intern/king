import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const frontendRoot = path.resolve(__dirname, '../..');
const repoRoot = path.resolve(frontendRoot, '../../..');

function read(relativePath) {
  return fs.readFileSync(path.join(repoRoot, relativePath), 'utf8');
}

function readJson(relativePath) {
  return JSON.parse(read(relativePath));
}

const packageJson = readJson('demo/video-chat/frontend-vue/package.json');
const sprint = read('SPRINT.md');
const ciGate = read('demo/video-chat/scripts/iam-call-access-ci-gate.sh');
const anonymousLobbyContract = read('demo/video-chat/backend-king-php/tests/call-access-anonymous-lobby-contract.php');
const tempModeratorContract = read('demo/video-chat/backend-king-php/tests/call-temporary-moderator-contract.php');
const callManagementQuery = read('demo/video-chat/backend-king-php/domain/calls/call_management_query.php');
const realtimeCallRoles = read('demo/video-chat/backend-king-php/domain/realtime/realtime_call_roles.php');
const workspaceView = read('demo/video-chat/frontend-vue/src/domain/realtime/CallWorkspaceView.vue');
const workspaceTemplate = read('demo/video-chat/frontend-vue/src/domain/realtime/CallWorkspaceView.template.html');
const participantUi = read('demo/video-chat/frontend-vue/src/domain/realtime/workspace/callWorkspace/participantUi.ts');
const lobbyAdmissionSpec = read('demo/video-chat/frontend-vue/tests/e2e/lobby-admission.spec.js');

const iamContractScript = String(packageJson.scripts?.['test:contract:iam-call-access'] || '');

assert.match(
  anonymousLobbyContract,
  /host waiting snapshot[\s\S]*host admission[\s\S]*host admitted logged-in participant/s,
  'backend proof must show host sees, admits, and moves a waiting participant into the call',
);
assert.match(
  anonymousLobbyContract,
  /temporary moderator waiting snapshot[\s\S]*temporary moderator admission[\s\S]*temporary moderator admitted anonymous guest[\s\S]*temporary moderator rejection[\s\S]*temporary moderator rejected anonymous guest/s,
  'backend proof must show temporary moderators can see, admit, and reject lobby guests',
);
assert.match(
  anonymousLobbyContract,
  /organization admin waiting snapshot[\s\S]*organization admin admission[\s\S]*organization admin admitted anonymous guest[\s\S]*organization admin rejection[\s\S]*organization admin rejected anonymous guest/s,
  'backend proof must show organization admins can see, admit, and reject own-organization lobby guests',
);
assert.match(
  anonymousLobbyContract,
  /system admin should see waiting participants in lobby snapshot[\s\S]*system admin admission[\s\S]*system admin admitted anonymous guest[\s\S]*system admin rejection[\s\S]*system admin rejected anonymous guest/s,
  'backend proof must show system admins can see, admit, and reject lobby guests',
);
assert.match(
  anonymousLobbyContract,
  /unauthorized waiting-user lobby controls[\s\S]*queued participant must not authorize self admission[\s\S]*unauthorized self-admit denial should leave participant pending/s,
  'backend proof must deny unauthorized lobby admission and preserve pending state',
);
assert.match(
  anonymousLobbyContract,
  /function videochat_iam_anonymous_lobby_assert_waiting[\s\S]*should start in waiting room[\s\S]*function videochat_iam_anonymous_lobby_assert_direct_room[\s\S]*should enter the target call room/s,
  'backend proof must distinguish admitted users entering the call from rejected users staying in lobby',
);
assert.match(
  tempModeratorContract,
  /server-side moderator grant should authorize lobby controls[\s\S]*temporary moderator must not mutate guest list[\s\S]*temporary moderator must lose lobby authority after call end/s,
  'temporary moderator scope must stay call-bound and must not become organization-wide admin power',
);
assert.match(
  callManagementQuery,
  /OR cp\.user_id IS NOT NULL[\s\S]*return \$contextFromRow\(\$row, videochat_user_is_organization_admin_for_call/s,
  'call role context must keep inactive participant rows audit-visible instead of hiding their stored call_role',
);
assert.match(
  realtimeCallRoles,
  /\$scopedRoleActive =[\s\S]*videochat_call_invite_state_allows_scoped_role\(\$inviteState\)[\s\S]*\$canModerate =[\s\S]*\$scopedRoleActive && in_array\(\$callRole, \['owner', 'moderator'\], true\)/s,
  'realtime role context must preserve call_role while denying moderation for inactive invite states',
);

assert.match(
  workspaceView,
  /const canModerate = computed\(\(\) => \([\s\S]*viewerCanModerateCall\.value[\s\S]*viewerEffectiveCallRole\.value === 'owner'[\s\S]*viewerEffectiveCallRole\.value === 'moderator'[\s\S]*\)\);[\s\S]*const showLobbyTab = computed\(\(\) => canModerate\.value\);/s,
  'workspace must show the lobby tab only when server-derived moderation context allows it',
);
assert.match(
  workspaceTemplate,
  /<section v-if="showLobbyTab" class="tab-panel panel-lobby"[\s\S]*:disabled="!isSocketOnline \|\| !canModerate \|\| lobbyQueue\.length === 0"[\s\S]*@click="allowAllLobbyUsers"[\s\S]*:disabled="!canModerate \|\| row\.status !== 'queued' \|\| lobbyActionPending\(row\.user_id\)"[\s\S]*@click="allowLobbyUser\(row\.user_id\)"[\s\S]*:disabled="!canModerate \|\| lobbyActionPending\(row\.user_id\)"[\s\S]*@click="removeLobbyUser\(row\.user_id\)"/s,
  'lobby panel controls must be hidden with the tab and disabled unless moderation is allowed',
);
assert.match(
  participantUi,
  /function allowLobbyUser\(userId\) \{[\s\S]*if \(!canModerate\.value \|\| !Number\.isInteger\(normalizedUserId\) \|\| normalizedUserId <= 0\) return;[\s\S]*sendSocketFrame\(\{ type: 'lobby\/allow'/s,
  'allow action must fail closed client-side before sending a websocket frame',
);
assert.match(
  participantUi,
  /function removeLobbyUser\(userId\) \{[\s\S]*if \(!canModerate\.value \|\| !Number\.isInteger\(normalizedUserId\) \|\| normalizedUserId <= 0\) return;[\s\S]*sendSocketFrame\(\{ type: 'lobby\/remove'/s,
  'reject/remove action must fail closed client-side before sending a websocket frame',
);
assert.match(
  participantUi,
  /function allowAllLobbyUsers\(\) \{[\s\S]*if \(!canModerate\.value\) return;[\s\S]*sendSocketFrame\(\{ type: 'lobby\/allow_all' \}\)/s,
  'bulk admit must fail closed client-side before sending a websocket frame',
);
assert.match(
  lobbyAdmissionSpec,
  /plain waiting user cannot self-admit without an authorized grant[\s\S]*lobby\/allow[\s\S]*lobby_command_failed[\s\S]*unauthorized allow must not change pending participant state/s,
  'Playwright lobby admission proof must keep the malicious self-admit regression covered',
);

for (const line of [
  '- [x] Lobby shows waiting anonymous user according to privacy rules',
  '- [x] Host can admit anonymous user',
  '- [x] Temporary moderator can admit anonymous user',
  '- [x] Admin can admit anonymous user',
  '- [x] Unauthorized participant cannot admit anonymous user',
  '- [x] User without direct permission lands in lobby',
  '- [x] Anonymous not logged-in user lands in lobby',
  '- [x] Lobby entry informs host / authorized moderators',
  '- [x] Host sees waiting participant',
  '- [x] Temporary moderator sees waiting participant',
  '- [x] Organization admin sees waiting participant for own organization call',
  '- [x] Unauthorized user sees no lobby management controls',
  '- [x] Temporary moderator can admit participant',
  '- [x] Temporary moderator can reject participant',
  '- [x] Rejected participant cannot enter call',
  '- [x] Admitted participant enters call',
  '- [x] `e2e_anon_logged_out_005_temp_moderator_can_admit_anonymous_guest`',
  '- [x] `e2e_lobby_003_temp_moderator_sees_waiting_participant`',
  '- [x] `e2e_lobby_005_unauthorized_user_no_lobby_controls`',
]) {
  assert.ok(sprint.includes(line), `SPRINT.md must close: ${line}`);
}

assert.match(
  sprint,
  /iam-lobby-management-moderator-rights-contract\.mjs[\s\S]*call-access-anonymous-lobby-contract\.php/s,
  'SPRINT.md proof text must name this static contract and the backend lobby proof',
);
assert.match(
  iamContractScript,
  /iam-lobby-management-moderator-rights-contract\.mjs/,
  'IAM contract script must include the lobby-management moderator-rights static contract',
);
assert.match(
  iamContractScript,
  /call-access-anonymous-lobby-contract\.sh/,
  'IAM contract script must include the anonymous lobby backend proof',
);
assert.match(
  ciGate,
  /iam-lobby-management-moderator-rights-contract\.mjs/,
  'IAM CI gate must include the lobby-management moderator-rights static contract',
);
assert.match(
  ciGate,
  /tests\/call-access-anonymous-lobby-contract\.sh/,
  'IAM CI gate must include the anonymous lobby backend proof',
);

console.log('[iam-lobby-management-moderator-rights-contract] PASS');
