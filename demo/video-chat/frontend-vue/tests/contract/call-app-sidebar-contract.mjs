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
  tabsComposableSource,
  shellSource,
  storeSource,
  sprintSource,
] = await Promise.all([
  read('demo/video-chat/frontend-vue/src/domain/realtime/callApps/CallAppsSidebarPanel.vue'),
  read('demo/video-chat/frontend-vue/src/layouts/CallWorkspaceLeftSidebar.vue'),
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
  /showTabs[\s\S]*callAppsCatalogStore\.hasAvailableApps[\s\S]*call_app_workspace/s,
  'Call left sidebar tabs must only show once Call Apps are available or active in the call',
);

assert.match(
  leftSidebarSource,
  /v-if="showTabs"[\s\S]*calls\.workspace\.call_apps[\s\S]*<CallAppsSidebarPanel[\s\S]*v-if="showCallAppsPanel"[\s\S]*:call-id="activeSidebarCallId"[\s\S]*@session-created="\$emit\('call-app-session-created', \$event\)"/,
  'CallWorkspaceLeftSidebar must expose Call Apps as a conditional left-sidebar tab and hand active call context to the panel',
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
  /type="search"[\s\S]*src="\/assets\/orgas\/kingrt\/icons\/send\.png"/,
  'Call Apps sidebar must provide searchable list UI with the standard submit icon',
);

assert.match(
  sidebarSource,
  /\.call-apps-search[\s\S]*flex-direction:\s*row-reverse[\s\S]*gap:\s*20px[\s\S]*padding:\s*20px/s,
  'Call Apps sidebar search must keep the submit icon right-aligned with 20px spacing',
);

assert.match(
  sidebarSource,
  /pagination\.has_prev[\s\S]*pagination\.has_next/s,
  'Call Apps sidebar must paginate the available app list',
);

assert.match(
  sidebarSource,
  /\.call-apps-pagination[\s\S]*justify-content:\s*flex-end[\s\S]*gap:\s*20px[\s\S]*padding:\s*20px/s,
  'Call Apps sidebar pagination must use the standard right-aligned 20px action spacing',
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
