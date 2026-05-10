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
  sessionsDomainSource,
  sessionLifecycleSource,
  crdtDomainSource,
  routeSource,
  workspaceApiSource,
  crdtBridgeSource,
  iframeBridgeSource,
  presenceRelaySource,
  iframeSource,
  iframeRuntimeSource,
  lifecycleTestSource,
  sprintSource,
] = await Promise.all([
  read('demo/video-chat/backend-king-php/domain/call_apps/call_app_launch_tokens.php'),
  read('demo/video-chat/backend-king-php/domain/call_apps/call_app_sessions.php'),
  read('demo/video-chat/backend-king-php/domain/call_apps/call_app_session_lifecycle.php'),
  read('demo/video-chat/backend-king-php/domain/call_apps/call_app_crdt.php'),
  read('demo/video-chat/backend-king-php/http/module_call_apps.php'),
  read('demo/video-chat/frontend-vue/src/domain/realtime/workspace/api.ts'),
  read('demo/video-chat/frontend-vue/src/domain/realtime/callApps/useCallAppCrdtBridge.js'),
  read('demo/video-chat/frontend-vue/src/domain/realtime/callApps/useCallAppIframeBridge.js'),
  read('demo/video-chat/frontend-vue/src/domain/realtime/callApps/callAppPresenceRelay.js'),
  read('demo/call-app/whiteboard/public/index.html'),
  read('demo/call-app/whiteboard/public/whiteboard.js'),
  read('demo/video-chat/backend-king-php/tests/call-app-session-lifecycle-contract.php'),
  Promise.all([read('SPRINT.md'), read('BACKLOG.md')]).then(([sprint, backlog]) => `${sprint}\n${backlog}`),
]);

const whiteboardSource = `${iframeSource}\n${iframeRuntimeSource}`;

assert.match(
  launchDomainSource,
  /function videochat_call_app_launch_subject_grant_state[\s\S]*subject_type = :subject_type[\s\S]*function videochat_call_app_launch_guest_grant_state/s,
  'launch grant resolution must support user and guest subjects through one reconnect-safe lookup',
);

assert.match(
  sessionLifecycleSource,
  /function videochat_call_app_session_installation_available[\s\S]*installations\.status = 'enabled'[\s\S]*entitlements\.status = 'active'[\s\S]*entitlements\.expires_at[\s\S]*catalog\.health_status = 'healthy'/s,
  'Call App session access must re-check active installation, entitlement expiry, and catalog health',
);

assert.match(
  launchDomainSource,
  /function videochat_call_app_mint_launch_token[\s\S]*videochat_call_app_session_installation_available[\s\S]*app_not_available[\s\S]*function videochat_call_app_validate_launch_token[\s\S]*videochat_call_app_session_installation_available[\s\S]*app_not_available/s,
  'launch mint and validation must re-check active organization installation and entitlement state after revocation',
);

assert.match(
  crdtDomainSource,
  /function videochat_call_app_crdt_session_for_actor[\s\S]*videochat_call_app_session_installation_available[\s\S]*app_not_available/s,
  'CRDT bootstrap/replay/append must fail closed when a cached session loses its organization Call App entitlement',
);

assert.match(
  launchDomainSource,
  /\$base = \['call_apps\.launch'\][\s\S]*if \(\$grantState !== 'allowed'\)[\s\S]*return array_values/s,
  'denied participants must receive only status launch capability, not CRDT read',
);

assert.match(
  sessionsDomainSource,
  /function videochat_call_app_retire_launch_tokens_for_grant[\s\S]*UPDATE call_app_launch_tokens[\s\S]*issued_to_user_id/s,
  'denying a user grant must retire that user subject active launch tokens',
);

assert.match(
  sessionsDomainSource,
  /retired_launch_tokens[\s\S]*reconnect_policy[\s\S]*payload_json/s,
  'grant audit payloads must include revocation and reconnect metadata',
);

assert.match(
  sessionsDomainSource,
  /function videochat_call_app_fetch_audit_events[\s\S]*payload_json[\s\S]*'payload' =>/s,
  'grant audit listing must return decoded payload details',
);

assert.match(
  crdtDomainSource,
  /function videochat_call_app_crdt_requires_allowed_grant[\s\S]*participant_grant_denied/s,
  'CRDT domain must have one explicit allowed-grant gate',
);

assert.match(
  crdtDomainSource,
  /function videochat_call_app_crdt_bootstrap[\s\S]*videochat_call_app_crdt_requires_allowed_grant[\s\S]*function videochat_call_app_crdt_list_ops[\s\S]*videochat_call_app_crdt_requires_allowed_grant/s,
  'CRDT bootstrap and replay must reject revoked participants before returning private state',
);

assert.match(
  crdtDomainSource,
  /function videochat_call_app_crdt_permission_for_payload_type[\s\S]*\.delete[\s\S]*function videochat_call_app_crdt_append_op[\s\S]*videochat_call_app_crdt_normalize_append[\s\S]*videochat_call_app_crdt_permission_for_payload_type/s,
  'CRDT append must normalize payloads before gating write payloads by write and .delete payloads by delete',
);

assert.match(
  routeSource,
  /call_app_crdt_bootstrap_failed[\s\S]*participant_grant_denied[\s\S]*\? 403/s,
  'CRDT bootstrap route must map participant grant denial to HTTP 403',
);

assert.match(
  workspaceApiSource,
  /responseDetails[\s\S]*responseReason/,
  'workspace api errors must preserve backend error details and denial reason for iframe bridges',
);

assert.match(
  crdtBridgeSource,
  /participant_grant_denied[\s\S]*denied[\s\S]*call_app\.crdt\.error[\s\S]*grant_state/s,
  'CRDT iframe bridge must forward participant grant denial to the sandbox runtime',
);

assert.match(
  iframeBridgeSource,
  /permission_actions[\s\S]*permissions[\s\S]*read[\s\S]*write[\s\S]*delete/s,
  'iframe launch bridge must forward canonical permission actions and map to sandbox runtime permissions',
);

assert.match(
  presenceRelaySource,
  /callAppPresenceUserAuthorizedForSession\(session = \{\}, userId = 0, requiredAction = 'read'\)[\s\S]*actions\.includes[\s\S]*requiredAction/s,
  'presence relay authorization must evaluate read/write permission actions, not only binary grant state',
);

assert.match(
  crdtBridgeSource,
  /handlePresencePublish[\s\S]*callAppPresenceUserAuthorizedForSession\(session,[\s\S]*'write'\)[\s\S]*handleRemotePresence[\s\S]*callAppPresenceUserAuthorizedForSession\(session,[\s\S]*'read'\)/s,
  'iframe CRDT bridge must require write for presence publish and read for incoming presence delivery',
);

assert.match(
  whiteboardSource,
  /let capabilities = new Set\(\)[\s\S]*function canRead\(\)[\s\S]*capabilities\.has\('call_apps\.crdt\.read'\)/,
  'whiteboard iframe must derive read access from launch capabilities',
);

assert.match(
  whiteboardSource,
  /let permissionActions = new Set\(\)[\s\S]*function canDelete\(\)[\s\S]*permissionActions\.has\('delete'\)[\s\S]*appendOperation\(payloadType[\s\S]*canAppendPayload\(payloadType\)/s,
  'whiteboard iframe must gate delete operations by delete permission action before sending append requests',
);

assert.match(
  whiteboardSource,
  /function requestBootstrap\(afterClock = 0\)[\s\S]*if \(!canRead\(\)\) return/s,
  'whiteboard iframe must not request private CRDT bootstrap without read capability',
);

assert.match(
  whiteboardSource,
  /if \(canRead\(\)\)[\s\S]*requestBootstrap\(0\)[\s\S]*setInterval\(requestOps, 1500\)[\s\S]*Access not granted for this whiteboard/s,
  'whiteboard launch path must avoid CRDT polling when access is revoked',
);

assert.match(
  whiteboardSource,
  /function applyAccessState[\s\S]*grantState = nextGrantState[\s\S]*clearInterval\(pollTimer\)[\s\S]*call_app\.crdt\.error[\s\S]*participant_grant_denied/s,
  'whiteboard runtime must consume runtime grant denial and disable polling/editing after revocation',
);

assert.match(
  lifecycleTestSource,
  /denying a participant must revoke their active launch token[\s\S]*revoked participant launch token must fail reconnect validation/s,
  'backend lifecycle contract must prove active token revocation on denied grants',
);

assert.match(
  lifecycleTestSource,
  /guest grant should inherit default allow[\s\S]*guest grant state must apply across reconnect lookups/s,
  'backend lifecycle contract must cover guest grant reconnect semantics',
);

assert.match(
  lifecycleTestSource,
  /denied participant launch must not allow CRDT read[\s\S]*denied participant must not bootstrap private CRDT state[\s\S]*denied participant must not replay private CRDT state/s,
  'backend lifecycle contract must prove revoked participants receive no private CRDT state',
);

assert.match(
  sprintSource,
  /revoked participants cannot submit CRDT ops/,
  'SPRINT.md must keep revocation hardening in the active Whiteboard acceptance criteria',
);

console.log('[call-app-permission-revocation-contract] PASS');
