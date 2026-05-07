import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import path from 'node:path';

const root = path.resolve(new URL('../..', import.meta.url).pathname);
const repoRoot = path.resolve(root, '../../..');

async function read(relativePath) {
  return readFile(path.join(repoRoot, relativePath), 'utf8');
}

const [
  componentSource,
  stateSource,
  templateSource,
  workspaceSource,
  strategiesSource,
  uiOptionsSource,
  roomStateSource,
  participantUiSource,
  backendLayoutSource,
  migrationsSource,
] = await Promise.all([
  read('demo/video-chat/frontend-vue/src/domain/realtime/callApps/CallAppWorkspaceHost.vue'),
  read('demo/video-chat/frontend-vue/src/domain/realtime/callApps/callAppWorkspaceState.js'),
  read('demo/video-chat/frontend-vue/src/domain/realtime/CallWorkspaceView.template.html'),
  read('demo/video-chat/frontend-vue/src/domain/realtime/CallWorkspaceView.vue'),
  read('demo/video-chat/frontend-vue/src/domain/realtime/layout/strategies.ts'),
  read('demo/video-chat/frontend-vue/src/domain/realtime/layout/uiOptions.ts'),
  read('demo/video-chat/frontend-vue/src/domain/realtime/workspace/callWorkspace/roomState.ts'),
  read('demo/video-chat/frontend-vue/src/domain/realtime/workspace/callWorkspace/participantUi.ts'),
  read('demo/video-chat/backend-king-php/domain/realtime/realtime_activity_layout.php'),
  read('demo/video-chat/backend-king-php/support/database_migrations.php'),
]);

assert.match(strategiesSource, /CALL_LAYOUT_MODES\s*=\s*\[[^\]]*call_app_workspace/s, 'frontend layout modes must include call_app_workspace');
assert.match(uiOptionsSource, /mode:\s*['"]call_app_workspace['"]/, 'layout controls must expose Call App workspace mode');
assert.match(backendLayoutSource, /call_app_workspace/, 'backend layout mode normalization must accept call_app_workspace');
assert.match(migrationsSource, /0050_call_app_workspace_layout_mode/, 'SQLite migrations must widen the persisted layout mode check');

assert.match(templateSource, /<CallAppWorkspaceHost[\s\S]*currentLayoutMode\s*===\s*['"]call_app_workspace['"]/s, 'workspace template must render the dedicated Call App host for call_app_workspace mode');
assert.doesNotMatch(workspaceSource, /<iframe\b/i, 'CallWorkspaceView.vue must not own Call App iframe implementation');
assert.doesNotMatch(workspaceSource, /sandbox=/i, 'CallWorkspaceView.vue must not own iframe sandbox policy');

assert.match(roomStateSource, /applyCallAppsRoomState\(payload\?\.call_apps/, 'room snapshots must apply active Call App session state');
assert.match(participantUiSource, /\['main_mini',\s*'call_app_workspace'\]\.includes\(currentLayoutMode\.value\)/, 'Call App workspace mode must provide mini video participants to the video layout');
assert.match(strategiesSource, /mode === ['"]call_app_workspace['"][\s\S]*miniUserIds = clippedVisibleIds/s, 'Call App workspace mode must keep the five visible participants in the mini strip');

assert.match(stateSource, /CALL_APP_WORKSPACE_MINI_LIMIT\s*=\s*5/, 'Call App workspace must cap mini participants at five');
assert.match(componentSource, /<iframe[\s\S]*sandbox="allow-scripts allow-forms allow-pointer-lock allow-downloads"/, 'Call App iframe must be sandboxed');
assert.doesNotMatch(componentSource, /allow-same-origin/, 'Call App iframe sandbox must not include allow-same-origin');
assert.doesNotMatch(componentSource + stateSource, /sessionToken|Authorization|localStorage/, 'Call App workspace shell must not expose primary auth material to the iframe');
assert.match(componentSource, /referrerpolicy="no-referrer"/, 'Call App iframe must not leak referrer data');
assert.match(componentSource, /grid-template-rows:\s*112px\s*minmax\(0,\s*1fr\)[\s\S]*height:\s*112px/, 'Call App workspace must keep mini strip and iframe sizing stable');
assert.match(componentSource, /accessNoticeState[\s\S]*no-access[\s\S]*call_apps\.crdt\.read[\s\S]*call_apps\.crdt\.append[\s\S]*read-only/s, 'Call App workspace must show explicit no-access and read-only states from launch grant capabilities');

console.log('[call-app-workspace-view-contract] PASS');
