import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import path from 'node:path';

const root = path.resolve(new URL('../..', import.meta.url).pathname);
const repoRoot = path.resolve(root, '../../..');

async function read(relativePath) {
  return readFile(path.join(repoRoot, relativePath), 'utf8');
}

const [
  sidebarSource,
  leftSidebarSource,
  callWorkspaceSource,
  tabsComposableSource,
  shellSource,
  storeSource,
  sprintSource,
] = await Promise.all([
  read('demo/video-chat/frontend-vue/src/domain/realtime/callApps/CallAppsSidebarPanel.vue'),
  read('demo/video-chat/frontend-vue/src/layouts/CallWorkspaceLeftSidebar.vue'),
  read('demo/video-chat/frontend-vue/src/domain/realtime/CallWorkspaceView.vue'),
  read('demo/video-chat/frontend-vue/src/layouts/useCallLeftSidebarTabs.js'),
  read('demo/video-chat/frontend-vue/src/layouts/WorkspaceShell.vue'),
  read('demo/video-chat/frontend-vue/src/stores/callAppsCatalogStore.js'),
  read('SPRINT.md'),
]);

assert.match(
  shellSource,
  /import CallWorkspaceLeftSidebar from ['"]\.\/CallWorkspaceLeftSidebar\.vue['"]/,
  'WorkspaceShell must delegate the in-call left sidebar to a focused component',
);

assert.match(
  leftSidebarSource,
  /import CallAppsSidebarPanel from ['"]\.\.\/domain\/realtime\/callApps\/CallAppsSidebarPanel\.vue['"]/,
  'CallWorkspaceLeftSidebar must import the dedicated Call Apps sidebar panel',
);

assert.match(
  tabsComposableSource,
  /activePanel\s*=\s*ref\(['"]settings['"]\)/,
  'Call left sidebar tabs must default to the Settings panel',
);

assert.match(
  tabsComposableSource,
  /useCallAppsCatalogStore\(\)/,
  'Call left sidebar tabs must probe the real Call Apps catalog before showing the Call Apps tab',
);

assert.match(
  tabsComposableSource,
  /showTabs\s*=\s*computed\(\(\)\s*=>\s*normalizedCallId\.value\s*!==\s*['"]['"]\)/,
  'Call left sidebar tabs must stay visible for every resolved call so Call Apps can be attached even before catalog probe completion',
);

assert.match(
  shellSource,
  /callOwnerEditState\.callId[\s\S]*callOwnerEditState\.resolvedCallId[\s\S]*isCallWorkspace\.value \? route\.params\.callRef/s,
  'Call Apps sidebar must keep a route call reference while owner/moderator context is still resolving',
);

assert.match(
  leftSidebarSource,
  /v-if="showTabs"[\s\S]*calls\.workspace\.call_apps[\s\S]*<CallAppsSidebarPanel[\s\S]*v-if="showCallAppsPanel"[\s\S]*:call-id="activeSidebarCallId"[\s\S]*:active-session="callAppSidebarActiveSession"[\s\S]*:participants="callAppSidebarParticipants"[\s\S]*@session-created="\$emit\('call-app-session-created', \$event\)"/,
  'CallWorkspaceLeftSidebar must expose Call Apps as a conditional left-sidebar tab and hand active call context to the panel',
);

assert.match(
  shellSource,
  /const callAppSidebarState = reactive\([\s\S]*activeSession:\s*null[\s\S]*participants:\s*\[\][\s\S]*sendSocketFrame:\s*null[\s\S]*requestRoomSnapshot:\s*null/s,
  'WorkspaceShell must keep active Call App session and participant grant state for the left sidebar',
);

assert.match(
  shellSource,
  /provide\(['"]workspaceSidebarState['"][\s\S]*callLayoutControls:\s*callLayoutSidebarState[\s\S]*callAppControls:\s*callAppSidebarState/s,
  'WorkspaceShell must provide Call App sidebar controls to the call workspace route',
);

assert.match(
  callWorkspaceSource,
  /function syncCallAppSidebarControls\(\)[\s\S]*controls\.activeSession\s*=\s*activeCallAppSession\.value\s*\|\|\s*null[\s\S]*controls\.participants\s*=\s*Array\.isArray\(snapshotUsersRows\.value\)[\s\S]*controls\.sendSocketFrame\s*=\s*sendSocketFrame[\s\S]*controls\.requestRoomSnapshot\s*=\s*\(\.\.\.args\)\s*=>\s*requestRoomSnapshot\(\.\.\.args\)/s,
  'CallWorkspaceView must publish the active Call App session and participant rows to the left sidebar controls',
);

assert.match(
  shellSource,
  /function handleCallAppSessionCreated\(\)[\s\S]*applySidebarLayoutMode\(['"]call_app_workspace['"]\)/,
  'Adding a Call App must switch the workspace into call_app_workspace mode when controls are available',
);

assert.match(
  sidebarSource,
  /useCallAppsCatalog\(\)/,
  'Call Apps sidebar must reuse the shared catalog composable',
);

assert.match(
  sidebarSource,
  /import CallAppParticipantGrantButton from ['"]\.\/CallAppParticipantGrantButton\.vue['"]/,
  'Call Apps sidebar must reuse the backend-backed participant grant button',
);

assert.match(
  sidebarSource,
  /type="search"[\s\S]*src="\/assets\/orgas\/kingrt\/icons\/send\.png"/,
  'Call Apps sidebar must provide searchable list UI with the standard submit icon',
);

assert.match(
  sidebarSource,
  /\.call-apps-sidebar[\s\S]*container-type:\s*inline-size[\s\S]*\.call-apps-search[\s\S]*flex-direction:\s*row-reverse[\s\S]*gap:\s*clamp\([\s\S]*padding:\s*clamp\(/s,
  'Call Apps sidebar search must keep the submit icon right-aligned with responsive spacing',
);

assert.match(
  sidebarSource,
  /aria-label="Select Call App"[\s\S]*@update:model-value="selectAppByKey"[\s\S]*v-for="app in availableApps"/,
  'Call Apps sidebar must provide an explicit installed-app select control in addition to the button list',
);

assert.match(
  sidebarSource,
  /function reconcileSelectedAppAfterLoad\(\)[\s\S]*availableApps\.value\.length === 1[\s\S]*selectApp\(availableApps\.value\[0\]\)/,
  'Call Apps sidebar must auto-select the only available installed app after loading availability',
);

assert.match(
  sidebarSource,
  /emit\(['"]session-created['"],\s*payload\?\.result\s*\|\|\s*\{\}\)[\s\S]*props\.requestRoomSnapshot\(\)/,
  'Adding a Call App must immediately request a room snapshot so the workspace shows the active session without reload',
);

assert.match(
  sidebarSource,
  /pagination\.has_prev[\s\S]*pagination\.has_next/s,
  'Call Apps sidebar must paginate the available app list',
);

assert.match(
  sidebarSource,
  /\.call-apps-pagination[\s\S]*justify-content:\s*flex-end[\s\S]*flex-wrap:\s*wrap[\s\S]*gap:\s*clamp\([\s\S]*padding:\s*clamp\(/s,
  'Call Apps sidebar pagination must use right-aligned responsive action spacing',
);

assert.match(
  sidebarSource,
  /call-apps-status-badge[\s\S]*Installed[\s\S]*Enabled[\s\S]*Healthy/s,
  'Call Apps sidebar must show installed, enabled, and healthy app state clearly',
);

assert.match(
  sidebarSource,
  /data-call-app-attach-flow="inline"[\s\S]*Default participant access[\s\S]*default_app_policy/s,
  'Call Apps attach flow must choose default participant access inline without modal stacking',
);

assert.match(
  sidebarSource,
  /class="call-apps-access"[\s\S]*grantStateLabel\(participant\)[\s\S]*<CallAppParticipantGrantButton[\s\S]*:session="activeSessionForAccess"[\s\S]*:request-room-snapshot="requestRoomSnapshot"[\s\S]*@grant-updated="applyLocalGrantUpdate"/,
  'Call Apps sidebar must expose active session participant grants inside the left sidebar',
);

assert.match(
  sidebarSource,
  /localGrantOverrides[\s\S]*function applyLocalGrantUpdate\(event\)[\s\S]*localGrantOverrides\.value\s*=\s*\{/s,
  'Call Apps sidebar must immediately reflect local grant toggles while waiting for snapshot backfill',
);

assert.match(
  sidebarSource,
  /@container\s*\(min-width:\s*380px\)[\s\S]*\.call-apps-list-item[\s\S]*grid-template-columns:\s*minmax\(0,\s*1fr\)\s*auto[\s\S]*\.call-apps-detail-grid[\s\S]*grid-template-columns:\s*minmax\(0,\s*1fr\)\s*minmax\(0,\s*1fr\)/s,
  'Call Apps sidebar must adapt list and detail grids at wider container sizes',
);

assert.match(
  sidebarSource,
  /\/api\/calls\/\$\{encodeURIComponent\(normalizedCallId\.value\)\}\/call-app-sessions/,
  'Call Apps sidebar must create backend-backed Call App sessions',
);

assert.match(
  sidebarSource,
  /default_app_policy:\s*normalizeDefaultPolicy\(defaultPolicy\.value\)/,
  'Call Apps sidebar must send the selected default participant policy',
);

assert.doesNotMatch(
  sidebarSource,
  /sessionToken|Authorization|localStorage/,
  'Call Apps sidebar must not expose primary auth material directly',
);

assert.doesNotMatch(
  sidebarSource + storeSource,
  /hard.?code|fixture|mock/i,
  'Call Apps sidebar must use real catalog/session APIs instead of fixture data',
);

assert.match(
  sprintSource,
  /## Sprint: Whiteboard Call App Hardening And Production Integration/,
  'SPRINT.md must keep Whiteboard Call Apps as the active sprint',
);

console.log('[call-app-sidebar-contract] PASS');
