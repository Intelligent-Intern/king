import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import path from 'node:path';

const root = path.resolve(new URL('../..', import.meta.url).pathname);
const repoRoot = path.resolve(root, '../../..');

async function read(relativePath) {
  return readFile(path.join(repoRoot, relativePath), 'utf8');
}

const [
  migrationSource,
  databaseMigrationsSource,
  domainSource,
  routeSource,
  hostSource,
  bridgeSource,
  presenceRelaySource,
  runtimeConfigSource,
  signalingSource,
  iframeSource,
  iframeRuntimeSource,
  lifecycleTestSource,
  sprintSource,
] = await Promise.all([
  read('demo/video-chat/backend-king-php/support/call_app_session_migrations.php'),
  read('demo/video-chat/backend-king-php/support/database_migrations.php'),
  read('demo/video-chat/backend-king-php/domain/call_apps/call_app_crdt.php'),
  read('demo/video-chat/backend-king-php/http/module_call_apps.php'),
  read('demo/video-chat/frontend-vue/src/domain/realtime/callApps/CallAppWorkspaceHost.vue'),
  read('demo/video-chat/frontend-vue/src/domain/realtime/callApps/useCallAppCrdtBridge.js'),
  read('demo/video-chat/frontend-vue/src/domain/realtime/callApps/callAppPresenceRelay.js'),
  read('demo/video-chat/frontend-vue/src/domain/realtime/workspace/callWorkspace/runtimeConfig.ts'),
  read('demo/video-chat/backend-king-php/domain/realtime/realtime_signaling.php'),
  read('demo/call-app/whiteboard/public/index.html'),
  read('demo/call-app/whiteboard/public/whiteboard.js'),
  read('demo/video-chat/backend-king-php/tests/call-app-session-lifecycle-contract.php'),
  read('SPRINT.md'),
]);

const whiteboardSource = `${iframeSource}\n${iframeRuntimeSource}`;

assert.match(
  migrationSource,
  /CREATE TABLE IF NOT EXISTS call_app_crdt_documents[\s\S]*CREATE TABLE IF NOT EXISTS call_app_crdt_ops/s,
  'CRDT documents and ops must have persistent tables',
);

assert.match(
  databaseMigrationsSource,
  /0052_call_app_crdt_envelope[\s\S]*videochat_call_app_crdt_migration_statements/,
  'CRDT migration must be registered',
);

assert.match(
  domainSource,
  /function videochat_call_app_crdt_envelope[\s\S]*server_admission_stamp[\s\S]*causal_dependencies/s,
  'backend must build the King CRDT envelope with causal and admission fields',
);

assert.match(
  domainSource,
  /function videochat_call_app_crdt_append_op[\s\S]*operation_id[\s\S]*state' => 'duplicate'[\s\S]*ignore_after_first_admission/s,
  'append path must suppress duplicate operation ids after first admission',
);

assert.match(
  domainSource,
  /presence_payload_types[\s\S]*cursor\.move[\s\S]*presence_must_not_be_persisted/s,
  'append path must reject non-persistent presence payloads before CRDT persistence',
);

assert.match(
  domainSource,
  /function videochat_call_app_crdt_bootstrap[\s\S]*snapshot_clock[\s\S]*replay_cursor/s,
  'bootstrap must include snapshot and replay cursor state',
);

assert.match(
  domainSource,
  /function videochat_call_app_crdt_compact_snapshot[\s\S]*king\.call_app\.crdt\.checkpoint\.v1[\s\S]*compacted_through_clock/s,
  'snapshot compaction must be server-owned and checkpointed',
);

assert.match(
  routeSource,
  /\/api\/call-app-sessions\/\(\[A-Za-z0-9\._:-\]\+\)\/crdt\/bootstrap[\s\S]*videochat_call_app_crdt_bootstrap/s,
  'backend must expose CRDT bootstrap route',
);

assert.match(
  routeSource,
  /\/api\/call-app-sessions\/\(\[A-Za-z0-9\._:-\]\+\)\/crdt\/ops[\s\S]*GET[\s\S]*POST[\s\S]*videochat_call_app_crdt_append_op/s,
  'backend must expose CRDT replay and append route',
);

assert.match(
  routeSource,
  /\/api\/call-app-sessions\/\(\[A-Za-z0-9\._:-\]\+\)\/crdt\/snapshots[\s\S]*videochat_call_app_crdt_compact_snapshot/s,
  'backend must expose CRDT snapshot compaction route',
);

assert.match(
  hostSource,
  /createCallAppCrdtBridge[\s\S]*apiRequest:\s*props\.apiRequest/s,
  'Call App host must wire the dedicated CRDT bridge',
);

assert.match(
  bridgeSource,
  /\/crdt\/bootstrap[\s\S]*call_app\.crdt\.bootstrap\.response[\s\S]*call_app\.crdt\.bootstrap\.request/s,
  'frontend bridge must translate iframe bootstrap requests to backend bootstrap',
);

assert.match(
  bridgeSource,
  /method:\s*['"]POST['"][\s\S]*call_app\.crdt\.op\.appended[\s\S]*call_app\.crdt\.op\.append/s,
  'frontend bridge must translate iframe append requests to backend append',
);

assert.match(
  bridgeSource,
  /call_app\.presence\.published[\s\S]*persisted:\s*false[\s\S]*call_app\.presence\.publish/s,
  'frontend bridge must accept non-persistent Call App presence without routing it through CRDT persistence',
);

assert.match(
  bridgeSource,
  /CALL_APP_PRESENCE_SIGNAL_TYPE[\s\S]*sendSocketFrame[\s\S]*target_user_id[\s\S]*call_app\.presence\.update/s,
  'frontend bridge must relay non-persistent Call App presence over targeted call signaling and back into active iframes',
);

assert.match(
  bridgeSource,
  /const senderDisplayName = normalizeCallAppPresenceDisplayName[\s\S]*payload\.display_name = senderDisplayName[\s\S]*payload\.label = senderDisplayName/s,
  'frontend bridge must restore sender display names onto remote cursor presence before posting into the iframe',
);

assert.match(runtimeConfigSource, /['"]call-app\/presence['"]/, 'frontend signaling allowlist must include Call App presence relay');
assert.match(signalingSource, /['"]call-app\/presence['"]/, 'backend signaling allowlist must route Call App presence relay');
assert.match(
  presenceRelaySource,
  /CALL_APP_PRESENCE_PAYLOAD_TYPES[\s\S]*cursor\.move[\s\S]*selection\.update[\s\S]*tool\.preview[\s\S]*plainClone/s,
  'Call App presence relay must sanitize cloneable non-persistent payloads before socket forwarding',
);

assert.doesNotMatch(
  bridgeSource + hostSource,
  /sessionToken|Authorization|localStorage|primary_session_token/,
  'CRDT bridge must not expose primary auth material to the iframe',
);

assert.match(
  whiteboardSource,
  /call_app\.crdt\.bootstrap\.request[\s\S]*call_app\.crdt\.bootstrap\.response/s,
  'whiteboard iframe must use the parent CRDT bridge for bootstrap',
);

assert.match(
  lifecycleTestSource,
  /CRDT bootstrap should return 200[\s\S]*denied participant must not append CRDT ops[\s\S]*presence updates must not be persisted as CRDT ops[\s\S]*duplicate CRDT op must be suppressed[\s\S]*CRDT snapshot must compact through collaborative admitted clock/s,
  'backend lifecycle contract must cover bootstrap, denied append, non-persistent presence, duplicate suppression, replay, and snapshot compaction',
);

assert.match(
  sprintSource,
  /synchronized through King CRDT envelopes/,
  'SPRINT.md must keep the King CRDT envelope contract in the active Whiteboard sprint',
);

console.log('[call-app-crdt-sync-contract] PASS');
