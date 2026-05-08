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
  shellSource,
  storeSource,
  sprintSource,
] = await Promise.all([
  read('demo/video-chat/frontend-vue/src/domain/realtime/callApps/CallAppsSidebarPanel.vue'),
  read('demo/video-chat/frontend-vue/src/layouts/WorkspaceShell.vue'),
  read('demo/video-chat/frontend-vue/src/stores/callAppsCatalogStore.js'),
  read('SPRINT.md'),
]);

assert.match(
  shellSource,
  /import CallAppsSidebarPanel from ['"]\.\.\/domain\/realtime\/callApps\/CallAppsSidebarPanel\.vue['"]/,
  'WorkspaceShell must import the dedicated Call Apps sidebar panel',
);

assert.match(
  shellSource,
  /callLeftPanel\s*=\s*ref\(['"]settings['"]\)/,
  'WorkspaceShell must keep the left call sidebar mode as shell state',
);

assert.match(
  shellSource,
  /Call Apps[\s\S]*<CallAppsSidebarPanel[\s\S]*:call-id="activeSidebarCallId"[\s\S]*@session-created="handleCallAppSessionCreated"/,
  'WorkspaceShell must expose a Call Apps tab and hand active call context to the panel',
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
  /pagination\.has_prev[\s\S]*pagination\.has_next/s,
  'Call Apps sidebar must paginate the available app list',
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
  /- \[x\] CAP-09 Left sidebar Call Apps browser/,
  'SPRINT.md must mark CAP-09 complete',
);

console.log('[call-app-sidebar-contract] PASS');
