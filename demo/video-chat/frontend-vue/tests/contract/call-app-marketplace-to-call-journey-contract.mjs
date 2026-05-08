import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import path from 'node:path';

const root = path.resolve(new URL('../..', import.meta.url).pathname);
const repoRoot = path.resolve(root, '../../..');

async function read(relativePath) {
  return readFile(path.join(repoRoot, relativePath), 'utf8');
}

function assertOrdered(source, needles, message) {
  let offset = -1;
  for (const needle of needles) {
    const next = source.indexOf(needle, offset + 1);
    assert(next > offset, `${message}: missing or out of order "${needle}"`);
    offset = next;
  }
}

const [
  lifecycleTestSource,
  sidebarSource,
  catalogStoreSource,
  adminMarketplaceSource,
  adminMarketplaceTableSource,
  crdtBridgeSource,
  whiteboardSource,
  whiteboardRuntimeSource,
  sprintSource,
] = await Promise.all([
  read('demo/video-chat/backend-king-php/tests/call-app-session-lifecycle-contract.php'),
  read('demo/video-chat/frontend-vue/src/domain/realtime/callApps/CallAppsSidebarPanel.vue'),
  read('demo/video-chat/frontend-vue/src/stores/callAppsCatalogStore.js'),
  read('demo/video-chat/frontend-vue/src/modules/marketplace/pages/AdminMarketplaceView.vue'),
  read('demo/video-chat/frontend-vue/src/modules/marketplace/pages/AdminMarketplaceTable.vue'),
  read('demo/video-chat/frontend-vue/src/domain/realtime/callApps/useCallAppCrdtBridge.js'),
  read('demo/call-app/whiteboard/public/index.html'),
  read('demo/call-app/whiteboard/public/whiteboard.js'),
  read('SPRINT.md'),
]);

const whiteboardBundleSource = `${whiteboardSource}\n${whiteboardRuntimeSource}`;

assertOrdered(
  lifecycleTestSource,
  [
    "videochat_call_app_create_organization_order($pdo, $tenantId, $adminUserId, 'whiteboard')",
    "videochat_call_app_create_organization_installation($pdo, $tenantId, $adminUserId, 'whiteboard')",
    '/call-apps/available?query=whiteboard&page=1&page_size=8',
  ],
  'backend journey must order and install before listing call availability',
);

assert.match(
  lifecycleTestSource,
  /installed whiteboard availability should return 200[\s\S]*installed whiteboard must appear in available Call Apps[\s\S]*semantic_dns_mcp/,
  'backend journey must prove installed whiteboard availability through Semantic-DNS/MCP discovery',
);

assert.match(
  lifecycleTestSource,
  /non-owner participant must not attach Call App[\s\S]*owner attach should create session[\s\S]*default-allowed participant launch token should return 201/,
  'backend journey must prove owner-only attach and participant launch admission',
);

assert.match(
  lifecycleTestSource,
  /denied participant launch must not allow CRDT read[\s\S]*owner should re-allow participant app access[\s\S]*re-allowed participant launch token should return 201/,
  'backend journey must prove grant denial, reconnect-safe private-state blocking, and re-allow',
);

assert.match(
  lifecycleTestSource,
  /payload_type' => 'stroke\.add'[\s\S]*payload_type' => 'sticky_note\.add'[\s\S]*second participant append should admit CRDT op[\s\S]*collaborative CRDT op must carry the second participant actor id/,
  'backend journey must admit collaborative whiteboard operations from distinct participants',
);

assert.match(
  lifecycleTestSource,
  /CRDT replay should return both collaborative admitted ops[\s\S]*CRDT replay should preserve collaborative operation order[\s\S]*collaborative snapshot clock/,
  'backend journey must replay and compact the multi-actor whiteboard state',
);

assert.match(
  lifecycleTestSource,
  /remove should return 200[\s\S]*remove must retire active launch tokens for the collaborative journey[\s\S]*include_removed should expose removed history/,
  'backend journey must remove the Call App session and retain explicit history',
);

assert.match(
  catalogStoreSource,
  /\/api\/calls\/\$\{encodeURIComponent\(normalizedCallId\)\}\/call-apps\/available/,
  'frontend catalog must load organization-installed Call Apps from the backend availability endpoint',
);

assert.match(
  adminMarketplaceSource,
  /\/api\/marketplace\/call-apps\/\$\{encodeURIComponent\(appKey\)\}\/orders[\s\S]*\/api\/marketplace\/call-apps\/\$\{encodeURIComponent\(appKey\)\}\/installations/s,
  'admin marketplace must order and install Call Apps for the active organization from the real marketplace endpoints',
);

assert.match(
  adminMarketplaceTableSource,
  /catalogApp\(app\)[\s\S]*install-call-app[\s\S]*Verify organization installation[\s\S]*Install for organization/s,
  'admin marketplace table must expose idempotent catalog-backed organization install actions',
);

assert.doesNotMatch(
  adminMarketplaceTableSource,
  /canInstallCallApp[\s\S]*!isInstalled\(app\)/,
  'organization install action must remain clickable so admins can repair or verify existing Call App installs',
);

assert.match(
  sidebarSource,
  /availableApps[\s\S]*\/api\/calls\/\$\{encodeURIComponent\(normalizedCallId\.value\)\}\/call-app-sessions[\s\S]*default_app_policy[\s\S]*emit\('session-created'/,
  'left sidebar must let the owner select an available Call App, create a session, and emit session-created',
);

assert.match(
  crdtBridgeSource,
  /\/crdt\/ops[\s\S]*method:\s*['"]POST['"][\s\S]*call_app\.crdt\.op\.appended[\s\S]*call_app\.crdt\.op\.append/s,
  'parent bridge must forward iframe CRDT append messages to the backend and acknowledge admission',
);

assert.match(
  whiteboardBundleSource,
  /appendOperation\('stroke\.add'[\s\S]*appendOperation\(editorKind === 'sticky' \? 'sticky_note\.add' : 'text\.add'/,
  'whiteboard runtime must emit real sticky-note and stroke operations for collaboration',
);

assert.doesNotMatch(
  sidebarSource + crdtBridgeSource + whiteboardBundleSource,
  /sessionToken|localStorage|Authorization|primary_session_token_received:\s*true/,
  'marketplace-to-call app journey must not leak primary auth material into sidebar, bridge, or iframe',
);

assert.match(
  sprintSource,
  /Whiteboard can be discovered from the package metadata and Marketplace\/Call\s+App catalog path/,
  'SPRINT.md must keep the Marketplace-to-call Whiteboard journey in active acceptance criteria',
);

console.log('[call-app-marketplace-to-call-journey-contract] PASS');
