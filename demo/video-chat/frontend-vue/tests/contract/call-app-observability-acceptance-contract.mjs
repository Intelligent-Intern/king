import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import path from 'node:path';

const root = path.resolve(new URL('../..', import.meta.url).pathname);
const repoRoot = path.resolve(root, '../../..');

async function read(relativePath) {
  return readFile(path.join(repoRoot, relativePath), 'utf8');
}

const [
  diagnosticsSource,
  iframeBridgeSource,
  crdtBridgeSource,
  routeSource,
  lifecycleTestSource,
  acceptanceSource,
  sprintSource,
] = await Promise.all([
  read('demo/video-chat/frontend-vue/src/domain/realtime/callApps/callAppDiagnostics.js'),
  read('demo/video-chat/frontend-vue/src/domain/realtime/callApps/useCallAppIframeBridge.js'),
  read('demo/video-chat/frontend-vue/src/domain/realtime/callApps/useCallAppCrdtBridge.js'),
  read('demo/video-chat/backend-king-php/http/module_call_apps.php'),
  read('demo/video-chat/backend-king-php/tests/call-app-session-lifecycle-contract.php'),
  read('WHITEBOARD_CHECK.md'),
  read('SPRINT.md'),
]);

const observabilityEvents = [
  'call_app_launch_token_failed',
  'call_app_grants_changed',
  'call_app_crdt_append_latency',
  'call_app_crdt_replay_latency',
  'call_app_crdt_duplicate_suppressed',
  'call_app_crdt_snapshot_compacted',
  'call_app_iframe_bridge_error',
];

for (const eventType of observabilityEvents) {
  assert.match(
    `${diagnosticsSource}\n${routeSource}\n${lifecycleTestSource}\n${acceptanceSource}`,
    new RegExp(eventType),
    `Call App observability must cover ${eventType}`,
  );
}

assert.match(
  diagnosticsSource,
  /king:call-app-diagnostic/,
  'frontend diagnostics must emit a dedicated browser event',
);

assert.match(
  diagnosticsSource,
  /token\|authorization\|password\|secret/i,
  'frontend diagnostics must redact sensitive fields',
);

assert.match(
  iframeBridgeSource,
  /call_app_launch_token_failed[\s\S]*response_status[\s\S]*call_app_iframe_bridge_error/s,
  'iframe launch bridge must emit launch-token failure and iframe bridge error diagnostics',
);

for (const eventType of ['call_app_crdt_append_latency', 'call_app_crdt_replay_latency', 'call_app_crdt_snapshot_compacted']) {
  assert.match(
    crdtBridgeSource,
    new RegExp(`callAppDiagnosticNow[\\s\\S]*${eventType}`),
    `CRDT bridge must record ${eventType}`,
  );
}

assert.match(
  routeSource,
  /videochat_call_app_module_with_diagnostic[\s\S]*call_app_grants_changed[\s\S]*call_app_crdt_duplicate_suppressed/s,
  'backend Call App routes must attach grant-change and CRDT diagnostics to API responses',
);

assert.match(
  lifecycleTestSource,
  /assert_diagnostic[\s\S]*call_app_grants_changed[\s\S]*call_app_launch_token_failed[\s\S]*call_app_crdt_replay_latency[\s\S]*call_app_crdt_snapshot_compacted/s,
  'backend lifecycle contract must assert the observability events',
);

for (const role of ['Owner', 'Moderator', 'Participant', 'Guest', 'Revoked Participant', 'Reconnect', 'Export']) {
  assert.match(acceptanceSource, new RegExp(`### ${role}`), `Whiteboard acceptance form must include ${role} checks`);
}

assert.match(
  acceptanceSource,
  /bewusst nicht[\s\S]*als bestanden ausgefuellt[\s\S]*Status: Auszufuellen/s,
  'Whiteboard acceptance form must remain an unfilled form, not a completed acceptance',
);

assert.doesNotMatch(
  acceptanceSource,
  /\[x\]|Status:\s*(passed|pass|bestanden|ok)/i,
  'Whiteboard acceptance form must not be pre-filled as passed',
);

assert.match(
  sprintSource,
  /WCA-08 Observability and acceptance form/,
  'SPRINT.md must keep WCA-08 tracked while this contract proves the checkbox',
);

console.log('[call-app-observability-acceptance-contract] PASS');
