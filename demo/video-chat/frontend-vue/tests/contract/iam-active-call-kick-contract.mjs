import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const frontendRoot = path.resolve(__dirname, '../..');
const videoChatRoot = path.resolve(frontendRoot, '..');
const repoRoot = path.resolve(videoChatRoot, '../..');

function read(relativePath) {
  return fs.readFileSync(path.join(repoRoot, relativePath), 'utf8');
}

const packageJson = JSON.parse(read('demo/video-chat/frontend-vue/package.json'));
const ciGate = read('demo/video-chat/scripts/iam-call-access-ci-gate.sh');
const realtimeModule = read('demo/video-chat/backend-king-php/http/module_realtime.php');
const activeKickModule = read('demo/video-chat/backend-king-php/http/module_realtime_active_call_kick.php');
const websocketCommands = read('demo/video-chat/backend-king-php/http/module_realtime_websocket_commands.php');
const websocketRoute = read('demo/video-chat/backend-king-php/http/module_realtime_websocket.php');
const lobbyDomain = read('demo/video-chat/backend-king-php/domain/realtime/realtime_lobby.php');
const presenceDb = read('demo/video-chat/backend-king-php/domain/realtime/realtime_call_presence_db.php');
const callContext = read('demo/video-chat/backend-king-php/domain/realtime/realtime_call_context.php');
const backendContract = read('demo/video-chat/backend-king-php/tests/call-access-rejoin-kick-contract.php');
const callWorkspaceView = read('demo/video-chat/frontend-vue/src/domain/realtime/CallWorkspaceView.vue');
const e2eSpec = read('demo/video-chat/frontend-vue/tests/e2e/call-access-rejoin-kick-membership.spec.js');
const matrixHarness = read('demo/video-chat/frontend-vue/tests/e2e/helpers/videochatMatrixHarness.js');

assert.match(
  String(packageJson.scripts?.['test:contract:iam-call-access'] || ''),
  /iam-active-call-kick-contract\.mjs/,
  'IAM contract gate must include the active-call kick static contract',
);
assert.match(
  String(packageJson.scripts?.['test:e2e:call-access'] || ''),
  /call-access-rejoin-kick-membership\.spec\.js/,
  'Call Access Playwright gate must include the focused rejoin/kick browser spec',
);
assert.match(
  ciGate,
  /iam-active-call-kick-contract\.mjs/,
  'IAM CI static gate must run the active-call kick contract',
);

assert.match(
  realtimeModule,
  /module_realtime_active_call_kick\.php[\s\S]*module_realtime_websocket_commands\.php[\s\S]*module_realtime_websocket\.php/,
  'active-call kick helpers must load before websocket command and route modules',
);
assert.match(
  lobbyDomain,
  /videochat_lobby_user_present_in_room[\s\S]*active_target_user_ids/,
  'lobby remove/kick must treat an active room participant as an affected active target',
);
assert.match(
  websocketCommands,
  /videochat_realtime_lobby_remove_result_for_active_call_target/,
  'websocket command path must promote DB-backed active targets instead of returning target_not_found',
);
assert.match(
  websocketCommands,
  /videochat_realtime_apply_lobby_remove_result\([\s\S]*\$presenceState/,
  'websocket command path must pass presence state into the focused active-call removal applier',
);
assert.match(
  activeKickModule,
  /videochat_realtime_db_room_has_joined_user[\s\S]*active_target_user_ids/,
  'active-call kick helper must recognize joined DB participants across the realtime presence contract',
);
assert.match(
  activeKickModule,
  /videochat_realtime_mark_call_participant_removed_from_active_call[\s\S]*videochat_realtime_disconnect_removed_call_participants[\s\S]*participant_kicked/,
  'successful active removal must persist kick state, disconnect presence, and broadcast a room snapshot',
);
assert.match(
  activeKickModule,
  /videochat_realtime_remove_call_presence_for_room_user[\s\S]*videochat_presence_remove_connection[\s\S]*king_client_websocket_close/,
  'active-call kick helper must remove persistent/local presence and close target sockets',
);
assert.match(
  websocketRoute,
  /videochat_realtime_connection_removed_from_active_call[\s\S]*videochat_realtime_send_removed_from_call_notice[\s\S]*break/,
  'websocket loop must close stale active sockets after cross-worker kick state is observed',
);
assert.match(
  presenceDb,
  /function videochat_realtime_remove_call_presence_for_room_user[\s\S]*DELETE FROM realtime_presence_connections[\s\S]*user_id = :user_id/,
  'persistent realtime presence must support call/room/user deletion for active kicks',
);
assert.match(
  callContext,
  /function videochat_realtime_mark_call_participant_removed_from_active_call[\s\S]*invite_state = 'invited'[\s\S]*left_at = CASE/,
  'active kick must persist a left_at marker and remove direct admission',
);
assert.match(
  callContext,
  /function videochat_realtime_connection_removed_from_active_call[\s\S]*leftAt !== ''[\s\S]*allowed/,
  'revalidated sockets must detect kicked active-call state without weakening ordinary active membership rules',
);

assert.match(
  backendContract,
  /e2e_security_009_kick_during_active_call_removes_user[\s\S]*persistent presence[\s\S]*kicked user must not directly rejoin/,
  'backend contract must prove active removal, persistent presence cleanup, and kicked logged-in rejoin denial',
);
assert.match(
  callWorkspaceView,
  /allowLobbyUser,\s+removeLobbyUser,/,
  'Call workspace template must expose the participant helper used by active-call remove controls',
);
assert.match(
  e2eSpec,
  /e2e_security_009_kick_during_active_call_removes_user[\s\S]*button\[title="Remove user"\][\s\S]*participant_kicked/,
  'Playwright spec must prove the active-call UI sends removal and consumes the authoritative kicked snapshot',
);
assert.match(
  matrixHarness,
  /payload\.type === 'lobby\/remove' \|\| payload\.type === 'lobby\/kick'[\s\S]*participants\.splice[\s\S]*participant_kicked/,
  'matrix realtime harness must simulate the server-side active participant removal snapshot',
);

process.stdout.write('[iam-active-call-kick-contract] PASS\n');
