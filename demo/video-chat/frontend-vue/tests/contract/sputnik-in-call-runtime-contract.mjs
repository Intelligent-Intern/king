import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);
const frontendRoot = path.resolve(__dirname, '../..');
const repoRoot = path.resolve(frontendRoot, '../../..');

function readUtf8(absoluteOrRelativePath) {
  const target = path.isAbsolute(absoluteOrRelativePath)
    ? absoluteOrRelativePath
    : path.join(frontendRoot, absoluteOrRelativePath);
  return fs.readFileSync(target, 'utf8');
}

function requireContains(source, needle, message) {
  assert.ok(source.includes(needle), message);
}

function requireRegex(source, pattern, message) {
  assert.ok(pattern.test(source), message);
}

try {
  const runtime = readUtf8('src/domain/realtime/testing/sputnikPeerRuntime.ts');
  const callWorkspace = readUtf8('src/domain/realtime/CallWorkspaceView.vue');
  const template = readUtf8('src/domain/realtime/CallWorkspaceView.template.html');
  const config = readUtf8('src/domain/realtime/workspace/config.ts');
  const packageJson = readUtf8('package.json');
  const backendSputnik = readUtf8(path.join(repoRoot, 'demo/video-chat/backend-king-php/domain/realtime/realtime_sputnik_dev.php'));
  const backendWebsocket = readUtf8(path.join(repoRoot, 'demo/video-chat/backend-king-php/http/module_realtime_websocket.php'));

  requireContains(config, 'SPUTNIK_PEERS_ENABLED', 'Sputnik peers must be guarded behind an explicit frontend flag');
  requireContains(callWorkspace, "import { createSputnikPeerRuntime } from './testing/sputnikPeerRuntime';", 'call workspace must consume only the isolated testing runtime');
  requireContains(callWorkspace, 'const sputnikPeerRuntime = createSputnikPeerRuntime({', 'call workspace must instantiate the isolated runtime');
  requireContains(callWorkspace, 'stopSputnikPeers(\'local_hangup\')', 'hangup must tear down spawned Sputnik peers');
  requireContains(callWorkspace, 'onBeforeUnmount(() => {\n  stopSputnikPeers(\'workspace_unmount\');', 'workspace teardown must stop Sputnik peers');
  requireContains(template, 'v-if="sputnikPeersEnabled"', 'Sputnik controls must stay behind the feature flag');
  requireContains(template, 'class="sputnik-peer-control"', 'call UI must expose an isolated Sputnik control');
  requireContains(template, 'Spawn Alice + Sputnik peers', 'control must clearly spawn Alice plus Sputnik peers');
  requireContains(template, '<option :value="1">A+1</option>', 'control must support one Sputnik peer');
  requireContains(template, '<option :value="2">A+2</option>', 'control must support two Sputnik peers');
  requireContains(template, '<option :value="3">A+3</option>', 'control must support three Sputnik peers');

  requireContains(runtime, "from '../../../lib/gossipmesh/gossipController'", 'runtime must use the production GossipController');
  requireContains(runtime, "from '../../../lib/gossipmesh/rtcDataChannelTransport'", 'runtime must use the production RTC data-channel transport');
  requireContains(runtime, "from '../local/publisherPipeline'", 'runtime must feed synthetic media through the production publisher pipeline');
  requireContains(runtime, "from '../workspace/callWorkspace/gossipNeighborLifecycle'", 'runtime must use dedicated gossip neighbor lifecycle code');
  requireContains(runtime, 'canvas.captureStream(30)', 'runtime must create synthetic video as a real MediaStream');
  requireContains(runtime, 'createOscillator', 'synthetic sound must remain optional and generated locally');
  requireContains(runtime, 'new WebSocket(socketUrl)', 'each fake peer must join through the realtime websocket control plane');
  requireContains(runtime, "sendSocketFrame(peer, { type: 'room/join'", 'each fake peer must join the same room control plane');
  requireContains(runtime, "sendSocketFrame(peer, { type: 'room/snapshot/request' })", 'each fake peer must request room snapshots');
  requireContains(runtime, 'applyGossipTopologyFromRoomStatePayload', 'server room topology must drive Sputnik neighbor assignment');
  requireContains(runtime, 'ensureNeighborLifecycle(peer).handleGossipNeighborSignal', 'Sputnik must handle real signaling events');
  requireContains(runtime, 'publishEncodedFrameToGossip', 'runtime must publish encoded frames to gossip');
  requireContains(runtime, 'controller.publishFrame(peer.definition.peerId, message)', 'Sputnik media must publish through GossipController.publishFrame');
  requireContains(runtime, "type: 'sfu/frame'", 'Sputnik gossip frames must use the production media frame envelope');
  assert.equal(runtime.includes('document.querySelector'), false, 'runtime must not create fake local participant tiles');

  requireContains(backendSputnik, 'VIDEOCHAT_ENABLE_SPUTNIK_PEERS', 'backend identity override must be explicitly gated');
  requireContains(backendSputnik, 'videochat_realtime_sputnik_controller_allowed', 'backend identity override must require a privileged controller');
  requireContains(backendSputnik, 'dev_sputnik_peer_id', 'backend must admit named Sputnik logical peers');
  requireContains(backendSputnik, 'SPUTNIK_DEV_USER_ID_BASE', 'backend must map fake peers to reserved numeric user ids');
  requireContains(backendWebsocket, 'videochat_realtime_apply_sputnik_dev_identity', 'websocket admission must apply dev-only Sputnik identity before presence registration');

  requireRegex(
    packageJson,
    /"test:contract:sputnik-in-call-runtime":\s*"node tests\/contract\/sputnik-in-call-runtime-contract\.mjs"/,
    'package scripts must expose the in-call Sputnik runtime contract',
  );
  requireContains(packageJson, 'sputnik-in-call-runtime-contract.mjs', 'aggregate gossip contracts must include in-call Sputnik coverage');

  console.log('[sputnik-in-call-runtime-contract] PASS');
} catch (error) {
  console.error(`[sputnik-in-call-runtime-contract] FAIL: ${error.message}`);
  process.exit(1);
}
