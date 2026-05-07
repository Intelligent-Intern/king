import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import path from 'node:path';

const root = path.resolve(new URL('../..', import.meta.url).pathname);
const repoRoot = path.resolve(root, '../../..');

async function read(relativePath) {
  return readFile(path.join(repoRoot, relativePath), 'utf8');
}

const [
  launchDomainSource,
  routeSource,
  routerSource,
  hostSource,
  bridgeSource,
  templateSource,
  iframeSource,
  iframeRuntimeSource,
  lifecycleTestSource,
  sprintSource,
] = await Promise.all([
  read('demo/video-chat/backend-king-php/domain/call_apps/call_app_launch_tokens.php'),
  read('demo/video-chat/backend-king-php/http/module_call_apps.php'),
  read('demo/video-chat/backend-king-php/http/router.php'),
  read('demo/video-chat/frontend-vue/src/domain/realtime/callApps/CallAppWorkspaceHost.vue'),
  read('demo/video-chat/frontend-vue/src/domain/realtime/callApps/useCallAppIframeBridge.js'),
  read('demo/video-chat/frontend-vue/src/domain/realtime/CallWorkspaceView.template.html'),
  read('demo/call-app/whiteboard/public/index.html'),
  read('demo/call-app/whiteboard/public/whiteboard.js'),
  read('demo/video-chat/backend-king-php/tests/call-app-session-lifecycle-contract.php'),
  read('SPRINT.md'),
]);

const whiteboardSource = `${iframeSource}\n${iframeRuntimeSource}`;

assert.match(
  launchDomainSource,
  /function videochat_call_app_mint_launch_token[\s\S]*call_app_launch_tokens[\s\S]*videochat_call_app_launch_token_hash/s,
  'backend must mint persistent hashed short-lived Call App launch tokens',
);

assert.match(
  launchDomainSource,
  /function videochat_call_app_validate_launch_token[\s\S]*token_hash[\s\S]*revoked_at[\s\S]*expires_at/s,
  'backend must validate launch tokens by hash, revocation, and expiry',
);

assert.match(
  routeSource,
  /\/api\/call-app-sessions\/\(\[A-Za-z0-9\._:-\]\+\)\/launch-token\/validate[\s\S]*videochat_call_app_validate_launch_token/s,
  'backend must expose launch token validation route',
);

assert.match(
  routeSource,
  /\/api\/call-app-sessions\/\(\[A-Za-z0-9\._:-\]\+\)\/launch-token[\s\S]*videochat_call_app_mint_launch_token/s,
  'backend must expose launch token mint route',
);

assert.match(
  routerSource,
  /launch-token\/validate[\s\S]*return true/s,
  'launch-token validation must be a public token-scoped endpoint so the iframe does not need a primary session',
);

assert.match(
  hostSource,
  /createCallAppIframeBridge[\s\S]*:api-request="apiRequest"|apiRequest:[\s\S]*required:\s*true/s,
  'Call App host must use the dedicated iframe bridge and receive apiRequest from the parent',
);

assert.match(
  templateSource,
  /<CallAppWorkspaceHost[\s\S]*:api-request="apiRequest"/,
  'workspace template must pass apiRequest into the dedicated Call App host',
);

assert.match(
  hostSource,
  /sandbox="allow-scripts allow-forms allow-pointer-lock allow-downloads"[\s\S]*csp="default-src 'self'/,
  'iframe host must enforce sandbox and CSP policy',
);

assert.doesNotMatch(
  hostSource,
  /allow-same-origin/,
  'Call App iframe must keep an opaque sandbox origin',
);

assert.match(
  bridgeSource,
  /CALL_APP_IFRAME_BRIDGE_PROTOCOL\s*=\s*['"]king\.call_app\.iframe\.v1['"]/,
  'parent bridge must pin the iframe protocol version',
);

assert.match(
  bridgeSource,
  /event\.source !== frameWindow[\s\S]*event\.origin !== CALL_APP_IFRAME_OPAQUE_ORIGIN/s,
  'parent bridge must validate iframe source and opaque sandbox origin',
);

assert.match(
  bridgeSource,
  /\/api\/call-app-sessions\/\$\{encodeURIComponent\(currentSessionId\)\}\/launch-token/,
  'parent bridge must mint launch tokens through the backend endpoint',
);

assert.match(
  bridgeSource,
  /safePostMessagePayload[\s\S]*type:\s*['"]call_app\.launch['"][\s\S]*launch_token[\s\S]*frameWindow\.postMessage\(safePostMessagePayload/s,
  'parent bridge must send the launch token only through the iframe bridge message',
);

assert.doesNotMatch(
  bridgeSource + hostSource,
  /sessionToken|Authorization|localStorage|primary_session_token/,
  'parent launch code must not expose primary auth material to the iframe',
);

assert.match(
  whiteboardSource,
  /message\.type === 'call_app\.launch'[\s\S]*emit\('call_app\.ready'[\s\S]*app_session_id/s,
  'whiteboard iframe must acknowledge launch with the app session id',
);

assert.match(
  lifecycleTestSource,
  /launch-token[\s\S]*denied participant should receive only a status launch token[\s\S]*must not allow CRDT read[\s\S]*must not allow CRDT append[\s\S]*launch payload must not expose the primary session token[\s\S]*must not expose raw user ids[\s\S]*launch token validation should return 200/s,
  'backend lifecycle contract must cover launch authorization, token validation, and primary-session non-exposure',
);

assert.match(
  sprintSource,
  /iframe never receives[\s\S]*primary session token/,
  'SPRINT.md must keep the no-primary-token iframe contract in the active Whiteboard sprint',
);

console.log('[call-app-iframe-launch-contract] PASS');
