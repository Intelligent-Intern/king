import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import path from 'node:path';

const root = path.resolve(new URL('../..', import.meta.url).pathname);
const repoRoot = path.resolve(root, '../../..');

async function read(relativePath) {
  return readFile(path.join(repoRoot, relativePath), 'utf8');
}

function assertContains(source, needle, message) {
  assert.ok(source.includes(needle), message);
}

const [
  e2eSource,
  marketplaceViewSource,
  marketplaceTableSource,
  sidebarSource,
  sidebarStyles,
  grantButtonSource,
  packageJsonSource,
] = await Promise.all([
  read('demo/video-chat/frontend-vue/tests/e2e/call-app-whiteboard-install-sidebar.spec.js'),
  read('demo/video-chat/frontend-vue/src/modules/marketplace/pages/AdminMarketplaceView.vue'),
  read('demo/video-chat/frontend-vue/src/modules/marketplace/pages/AdminMarketplaceTable.vue'),
  read('demo/video-chat/frontend-vue/src/domain/realtime/callApps/CallAppsSidebarPanel.vue'),
  read('demo/video-chat/frontend-vue/src/domain/realtime/callApps/CallAppsSidebarPanel.css'),
  read('demo/video-chat/frontend-vue/src/domain/realtime/callApps/CallAppParticipantGrantButton.vue'),
  read('demo/video-chat/frontend-vue/package.json'),
]);

const packageJson = JSON.parse(packageJsonSource);
const sidebarCombinedSource = `${sidebarSource}\n${sidebarStyles}`;

assert.match(
  e2eSource,
  /Install for organization[\s\S]*Whiteboard installed and enabled for this organization/,
  'browser proof must start from a user-visible Whiteboard organization install action and installed state',
);

assert.match(
  e2eSource,
  /POST \/api\/marketplace\/call-apps\/whiteboard\/orders[\s\S]*POST \/api\/marketplace\/call-apps\/whiteboard\/installations/,
  'browser proof must place an order before installing Whiteboard through marketplace endpoints',
);

assert.match(
  e2eSource,
  /GET \/api\/calls\/\$\{CALL_ID\}\/call-apps\/available[\s\S]*POST \/api\/calls\/\$\{CALL_ID\}\/call-app-sessions/,
  'browser proof must verify installed Whiteboard appears through call app availability before attach',
);

assert.match(
  e2eSource,
  /\/api\/call-app-sessions\/session-whiteboard-install-proof\/participant-grants[\s\S]*retired_launch_tokens/,
  'browser proof must exercise backend-authoritative grant mutation and token retirement signal',
);

assert.match(
  e2eSource,
  /default_app_policy:\s*'allowed_by_default'[\s\S]*grant_state:\s*'denied'/,
  'browser proof must cover explicit default access and participant revoke payloads',
);

assert.match(
  e2eSource,
  /setViewportSize\(\{\s*width:\s*360[\s\S]*scrollWidth <= element\.clientWidth[\s\S]*gridTemplateColumns/,
  'browser proof must keep narrow sidebar responsiveness assertions',
);

assert.match(
  e2eSource,
  /Installed[\s\S]*Enabled[\s\S]*Healthy[\s\S]*Select[\s\S]*Default: allowed[\s\S]*Revoke[\s\S]*Blocked[\s\S]*Allow/,
  'browser proof must assert installed app availability and usable access control state transitions',
);

assert.match(
  e2eSource,
  /readWhiteboardAssets[\s\S]*whiteboardFrame[\s\S]*call_app\.launch[\s\S]*call_app\.crdt\.bootstrap\.response[\s\S]*call_app\.crdt\.ops\.response/s,
  'browser proof must run the real Whiteboard iframe beside the sidebar host instead of using a placeholder',
);

assert.match(
  e2eSource,
  /showRemoteCursor\('Owner'\)[\s\S]*remote-cursor-label'\)\)\.toHaveText\('Owner'\)[\s\S]*call-apps-access-row\[data-user-id="2"\][\s\S]*remote-cursor-label'\)\)\.toHaveText\('Owner'\)/s,
  'browser proof must show Whiteboard cursor labels do not collide with sidebar participant grant controls',
);

assert.match(
  e2eSource,
  /launchCountBeforeGrantToggle[\s\S]*frameSrcBeforeGrantToggle[\s\S]*whiteboardLaunchCount[\s\S]*toBe\(launchCountBeforeGrantToggle\)[\s\S]*whiteboardFrameSrc[\s\S]*frameSrcBeforeGrantToggle/s,
  'browser proof must prove sidebar access control updates do not reload the Call App iframe',
);

assert.doesNotMatch(
  e2eSource,
  /sqlite|PDO|INSERT\s+INTO|videochat_bootstrap|manual\s+DB/i,
  'browser proof must not rely on manual database edits or backend storage shortcuts',
);

assert.match(
  marketplaceViewSource,
  /\/api\/marketplace\/call-apps\/\$\{encodeURIComponent\(appKey\)\}\/orders[\s\S]*\/api\/marketplace\/call-apps\/\$\{encodeURIComponent\(appKey\)\}\/installations/,
  'marketplace UI must keep using backend order and installation endpoints for Call App install',
);

assert.match(
  marketplaceTableSource,
  /callAppStateLabel[\s\S]*marketplace\.call_app_state\.installed[\s\S]*marketplace\.call_app_state\.not_installed[\s\S]*installTitle[\s\S]*marketplace\.call_app_install\.install/s,
  'marketplace table must expose install and installed states for Call Apps',
);

assertContains(
  sidebarSource,
  'loadAvailableApps',
  'Call Apps sidebar must use backend availability before showing installed apps',
);

assertContains(
  sidebarSource,
  'attachSelectedApp',
  'Call Apps sidebar must create backend sessions before showing access controls',
);

assertContains(
  sidebarSource,
  'Call App participant access',
  'Call Apps sidebar must show participant access controls after session attach',
);

assert.match(
  sidebarCombinedSource,
  /\.call-apps-list-item[\s\S]*grid-template-columns:\s*minmax\(0,\s*1fr\)[\s\S]*@container\s*\(min-width:\s*380px\)[\s\S]*\.call-apps-list-item[\s\S]*grid-template-columns:\s*minmax\(0,\s*1fr\)\s*auto/s,
  'Call Apps sidebar must remain responsive at narrow and wider sidebar widths',
);

assert.match(
  grantButtonSource,
  /\/api\/call-app-sessions\/\$\{encodeURIComponent\(sessionId\.value\)\}\/participant-grants[\s\S]*grant_state/,
  'Call App grant controls must persist participant access through the backend grant endpoint',
);

assertContains(
  packageJson.scripts['test:e2e:call-app-whiteboard'],
  'tests/e2e/call-app-whiteboard-install-sidebar.spec.js',
  'focused Whiteboard E2E script must include install-to-sidebar browser proof',
);

assertContains(
  packageJson.scripts['test:contract:call-apps'],
  'tests/contract/call-app-whiteboard-install-browser-proof-contract.mjs',
  'Call Apps contract suite must include the Whiteboard install browser proof contract',
);

console.log('[call-app-whiteboard-install-browser-proof-contract] PASS');
