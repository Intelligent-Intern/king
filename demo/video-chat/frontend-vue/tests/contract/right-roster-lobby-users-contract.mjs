import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import path from 'node:path';

const root = path.resolve(new URL('../..', import.meta.url).pathname);
const repoRoot = path.resolve(root, '../../..');

async function read(relativePath) {
  return readFile(path.join(repoRoot, relativePath), 'utf8');
}

const [
  templateSource,
  rosterSource,
  participantUiSource,
  cssSource,
  messagesSource,
  sprintSource,
] = await Promise.all([
  read('demo/video-chat/frontend-vue/src/domain/realtime/CallWorkspaceView.template.html'),
  read('demo/video-chat/frontend-vue/src/domain/realtime/workspace/callWorkspace/RightRosterPanel.vue'),
  read('demo/video-chat/frontend-vue/src/domain/realtime/workspace/callWorkspace/participantUi.ts'),
  read('demo/video-chat/frontend-vue/src/domain/realtime/CallWorkspacePanels.css'),
  read('demo/video-chat/frontend-vue/src/modules/localization/callWorkspaceMessages.js'),
  read('SPRINT.md'),
]);

assert.match(
  templateSource,
  /<RightRosterPanel[\s\S]*:lobby-page="lobbyPage"[\s\S]*:show-lobby="showLobbyTab"[\s\S]*:users-page="usersPage"/,
  'right sidebar must render lobby and present users through the focused roster component',
);

assert.match(
  templateSource,
  /activeTab === 'users'[\s\S]*showLobbyRequestBadge[\s\S]*lobbyRequestBadgeText/s,
  'right sidebar user tab must show pending lobby request badge when visible',
);

assert.match(
  templateSource,
  /v-if="showLobbyJoinToast"[\s\S]*workspace-lobby-toast[\s\S]*@click="openLobbyRequestsPanel"/s,
  'collapsed right sidebar must expose a main-stage lobby notification',
);

assert.doesNotMatch(
  templateSource,
  /class="tab tab-lobby"[\s\S]*setActiveTab\('lobby'\)/,
  'lobby must not remain a separate right-sidebar tab',
);

assert.match(
  rosterSource,
  /roster-section-lobby[\s\S]*roster-section-divider[\s\S]*roster-section-users/s,
  'roster component must place lobby above present users with a divider',
);

assert.match(
  rosterSource,
  /v-if="showLobbySearch"[\s\S]*calls\.workspace\.search_lobby[\s\S]*v-if="showUsersSearch"[\s\S]*calls\.workspace\.search_users/s,
  'search controls must be section-specific and conditionally rendered',
);

assert.match(
  participantUiSource,
  /const showUsersSearch = computed\(\(\) => usersUnfilteredPageCount\.value > 1 \|\| usersSearch\.value\.trim\(\) !== ''\);/,
  'user search visibility must be based on more than one unfiltered page or an active query',
);

assert.match(
  participantUiSource,
  /const showLobbySearch = computed\(\(\) => lobbyUnfilteredPageCount\.value > 1 \|\| String\(lobbySearch\.value \|\| ''\)\.trim\(\) !== ''\);/,
  'lobby search visibility must be based on more than one unfiltered page or an active query',
);

assert.match(
  participantUiSource,
  /const filteredLobbyRows = computed\(\(\) => \{[\s\S]*lobbySearch[\s\S]*lobbyRows\.value\.filter/s,
  'lobby must have its own filtered row source before pagination',
);

assert.match(
  rosterSource,
  /roster-options-toggle[\s\S]*roster-action-options[\s\S]*visibleActionSet/,
  'gear must open an options view that controls visible row actions',
);

assert.match(
  cssSource,
  /\.roster-options-toggle\.icon-mini-btn,[\s\S]*\.roster-action-btn\.icon-mini-btn[\s\S]*width: 38px;[\s\S]*height: 38px;/,
  'roster action icons must be larger than shared 30px mini buttons',
);

assert.match(
  cssSource,
  /\.roster-kick-btn[\s\S]*margin-left: 8px;[\s\S]*box-shadow:/,
  'kick/remove action must be visibly separated from non-destructive actions',
);

assert.match(messagesSource, /calls\.workspace\.action_option_call_app_read/, 'read permission action label must be localized');
assert.match(messagesSource, /calls\.workspace\.action_option_call_app_write/, 'write permission action label must be localized');
assert.match(messagesSource, /calls\.workspace\.action_option_call_app_delete/, 'delete permission action label must be localized');

assert.match(
  sprintSource,
  /GJL-03[\s\S]*Right sidebar keeps the user-tab lobby badge[\s\S]*collapsed sidebar[\s\S]*main lobby notification/s,
  'SPRINT.md must track the current guest-join lobby badge and notification requirement',
);

console.log('[right-roster-lobby-users-contract] PASS');
