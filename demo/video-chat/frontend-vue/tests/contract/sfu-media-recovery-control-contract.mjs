import fs from 'node:fs';
import path from 'node:path';
import assert from 'node:assert/strict';
import { fileURLToPath } from 'node:url';

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);
const frontendRoot = path.resolve(__dirname, '../..');
const repoRoot = path.resolve(frontendRoot, '..', '..', '..');

function read(relativePath) {
  return fs.readFileSync(path.join(repoRoot, relativePath), 'utf8');
}

function requireContains(source, needle, message) {
  assert.ok(source.includes(needle), message);
}

function main() {
  const sprint = read('SPRINT.md');
  const sfuClient = read('demo/video-chat/frontend-vue/src/lib/sfu/sfuClient.ts');
  const messageHandler = read('demo/video-chat/frontend-vue/src/lib/sfu/sfuMessageHandler.ts');
  const mediaStack = read('demo/video-chat/frontend-vue/src/domain/realtime/workspace/callWorkspace/mediaStack.ts');
  const runtimeHealth = read('demo/video-chat/frontend-vue/src/domain/realtime/workspace/callWorkspace/runtimeHealth.ts');
  const lifecycle = read('demo/video-chat/frontend-vue/src/domain/realtime/sfu/lifecycle.ts');
  const workspace = read('demo/video-chat/frontend-vue/src/domain/realtime/CallWorkspaceView.vue');
  const sfuStore = read('demo/video-chat/backend-king-php/domain/realtime/realtime_sfu_store.php');
  const gateway = read('demo/video-chat/backend-king-php/domain/realtime/realtime_sfu_gateway.php');
  const recoveryBroker = read('demo/video-chat/backend-king-php/domain/realtime/realtime_sfu_recovery_requests.php');
  const packageJson = read('demo/video-chat/frontend-vue/package.json');

  requireContains(sprint, '4. [x] `[packet-layer-sfu-forwarder]`', 'sprint issue 4 must be checked after recovery control routing lands');

  requireContains(sfuClient, 'requestPublisherMediaRecovery(', 'SFU client exposes publisher media recovery control');
  requireContains(sfuClient, "type: 'sfu/media-recovery-request'", 'publisher recovery request stays on SFU control plane');
  requireContains(messageHandler, "case 'sfu/publisher-recovery-request':", 'publisher consumes routed recovery requests');
  requireContains(messageHandler, "stage: 'sfu_media_recovery_request'", 'publisher recovery message is diagnosable by stage');

  requireContains(mediaStack, 'requestPublisherMediaRecovery', 'receiver feedback prefers SFU control recovery over call signaling');
  requireContains(runtimeHealth, 'requestPublisherMediaRecovery', 'runtime stall health uses SFU control recovery before socket fallback');
  requireContains(lifecycle, 'handleSfuPublisherPressureMessage', 'publisher lifecycle handles recovery pressure centrally');
  requireContains(lifecycle, 'requestWlvcFullFrameKeyframe', 'routed publisher recovery can force a full-frame keyframe');
  requireContains(lifecycle, "requestWlvcFullFrameKeyframe('sfu_socket_connected'", 'SFU reconnect resets publisher media generation with a keyframe before replay resumes');
  requireContains(lifecycle, 'stopLocalEncodingPipeline();', 'SFU disconnect stops stale browser encoders before reconnect');
  requireContains(workspace, 'requestWlvcFullFrameKeyframe: (...args) => requestWlvcFullFrameKeyframe(...args)', 'workspace wires publisher keyframe recovery into SFU lifecycle');
  requireContains(workspace, 'stopLocalEncodingPipeline,', 'workspace wires publisher encoder teardown into SFU lifecycle');

  requireContains(sfuStore, "'sfu/media-recovery-request'", 'backend admits media recovery control frames');
  requireContains(sfuStore, 'videochat_sfu_bootstrap_recovery_requests($pdo)', 'broker recovery table is part of SFU bootstrap');
  requireContains(gateway, "case 'sfu/media-recovery-request':", 'SFU gateway routes media recovery requests');
  requireContains(gateway, "'route' => 'direct_worker'", 'same-worker recovery requests route immediately');
  requireContains(gateway, "'route' => 'sqlite_broker'", 'cross-worker recovery requests route through broker');
  requireContains(gateway, 'videochat_sfu_poll_recovery_requests(', 'publishers poll broker-backed recovery requests');
  requireContains(recoveryBroker, 'CREATE TABLE IF NOT EXISTS sfu_recovery_requests', 'recovery requests have durable cross-worker relay');
  requireContains(recoveryBroker, 'videochat_sfu_recovery_request_ttl_ms(): int', 'recovery broker has TTL bounds');
  requireContains(recoveryBroker, "'sfu/publisher-recovery-request'", 'broker emits publisher recovery request frames');
  requireContains(recoveryBroker, '$primaryLayerPreferenceRequested', 'backend explicitly classifies primary layer preference');
  requireContains(recoveryBroker, '&& !$primaryLayerPreferenceRequested', 'backend ignores legacy client full-keyframe flags for primary layer preference');
  assert.equal(
    recoveryBroker.includes("|| $requestedVideoLayer === 'primary'"),
    false,
    'backend recovery broker must not promote primary layer preference to a full keyframe',
  );

  requireContains(packageJson, 'sfu-media-recovery-control-contract.mjs', 'SFU contract script includes media recovery control contract');
}

main();
process.stdout.write('[sfu-media-recovery-control-contract] PASS\n');
