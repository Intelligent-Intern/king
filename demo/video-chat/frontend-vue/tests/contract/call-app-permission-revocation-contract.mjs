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
  crdtDomainSource,
  routeSource,
  iframeSource,
  lifecycleTestSource,
  sprintSource,
] = await Promise.all([
  read('demo/video-chat/backend-king-php/domain/call_apps/call_app_launch_tokens.php'),
  read('demo/video-chat/backend-king-php/domain/call_apps/call_app_sessions.php'),
  read('demo/video-chat/backend-king-php/domain/call_apps/call_app_crdt.php'),
  read('demo/video-chat/backend-king-php/http/module_call_apps.php'),
  read('demo/call-app/whiteboard/public/index.html'),
  read('demo/video-chat/backend-king-php/tests/call-app-session-lifecycle-contract.php'),
  read('SPRINT.md'),
]);

assert.match(
  launchDomainSource,
  /function videochat_call_app_launch_subject_grant_state[\s\S]*subject_type = :subject_type[\s\S]*function videochat_call_app_launch_guest_grant_state/s,
  'launch grant resolution must support user and guest subjects through one reconnect-safe lookup',
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
  routeSource,
  /call_app_crdt_bootstrap_failed[\s\S]*participant_grant_denied[\s\S]*\? 403/s,
  'CRDT bootstrap route must map participant grant denial to HTTP 403',
);

assert.match(
  iframeSource,
  /let capabilities = new Set\(\)[\s\S]*function canRead\(\)[\s\S]*capabilities\.has\('call_apps\.crdt\.read'\)/,
  'whiteboard iframe must derive read access from launch capabilities',
);

assert.match(
  iframeSource,
  /function requestBootstrap\(afterClock = 0\)[\s\S]*if \(!canRead\(\)\) return/s,
  'whiteboard iframe must not request private CRDT bootstrap without read capability',
);

assert.match(
  iframeSource,
  /if \(canRead\(\)\)[\s\S]*requestBootstrap\(0\)[\s\S]*setInterval\(requestOps, 1500\)[\s\S]*Access not granted for this whiteboard/s,
  'whiteboard launch path must avoid CRDT polling when access is revoked',
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
  /- \[x\] CAP-14 App permissions and revocation hardening/,
  'SPRINT.md must mark CAP-14 complete',
);

console.log('[call-app-permission-revocation-contract] PASS');
