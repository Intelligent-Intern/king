import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);
const frontendRoot = path.resolve(__dirname, '../..');

function readUtf8(relativePath) {
  return fs.readFileSync(path.join(frontendRoot, relativePath), 'utf8');
}

function requireContains(source, needle, message) {
  assert.ok(source.includes(needle), message);
}

function requireRegex(source, pattern, message) {
  assert.ok(pattern.test(source), message);
}

try {
  const html = readUtf8('tests/standalone/sputnik-peer-harness.html');
  const source = readUtf8('tests/standalone/sputnik-peer-harness.ts');
  const packageJson = readUtf8('package.json');

  requireContains(html, 'sputnik-peer-harness.ts', 'standalone page must load the TypeScript harness module');
  assert.ok(!html.includes('<script>'), 'Sputnik harness must not use inline JavaScript');
  requireContains(html, 'id="fakePeerCountSelect"', 'harness must expose fake peer count control');
  requireContains(html, '<option value="2" selected>2</option>', 'default harness must start with two fake peers available');
  requireContains(html, '<input id="soundToggle" type="checkbox">', 'Sputnik sound must be optional and off by default');

  requireContains(source, "from '../../src/lib/gossipmesh/gossipController'", 'harness must use the production GossipController');
  requireContains(source, "id: 'sputnik-1'", 'harness must define first fake Sputnik peer');
  requireContains(source, "id: 'sputnik-2'", 'harness must define second fake Sputnik peer');
  requireContains(source, "id: 'sputnik-3'", 'harness must support an additional fake peer for stress');
  requireContains(source, 'navigator.mediaDevices.getUserMedia', 'harness must use the real local camera as Alice');
  requireContains(source, 'new RTCPeerConnection', 'harness must create browser-native local peer connections');
  requireContains(source, 'generatedCanvas.captureStream(30)', 'harness must publish generated video using canvas captureStream');
  requireContains(source, 'createOscillator', 'optional synthetic audio must use browser-local generated sound');
  requireContains(source, 'createMediaStreamDestination', 'optional synthetic audio must publish as a MediaStream');

  requireContains(source, 'function createGossipControllers', 'harness must build a local gossip network');
  requireContains(source, 'new GossipController(ROOM_ID, CALL_ID)', 'each fake peer must use the production gossip controller');
  requireContains(source, "diagnosticsLabel: 'sputnik_browser_fake_peer_mesh'", 'harness gossip diagnostics label must be explicit');
  requireContains(source, "kind: 'rtc_datachannel'", 'harness gossip transport must model RTC data-channel carriage');
  requireContains(source, 'gossipControllers.get(String(targetPeerId))?.handleData', 'gossip transport must deliver through controller handleData');
  requireContains(source, 'applyTopologyHint(peerId, createTopologyHint', 'server-headed topology hints must drive neighbor assignment');
  requireContains(source, "type: 'topology_hint'", 'topology messages must use the production topology hint type');
  requireContains(source, 'publishFrame(peerId', 'fake peers must publish gossip frames');
  requireContains(source, "type: 'sfu/frame'", 'gossip metadata frames must use the production media frame envelope type');
  requireContains(source, "'sputnik_canvas_metadata'", 'Sputnik generated media must be represented in gossip metadata');
  requireContains(source, "'camera_metadata'", 'Alice camera media must be represented in gossip metadata');

  requireContains(source, 'bot.botPeerConnection.ontrack', 'each fake peer must receive Alice camera tracks');
  requireContains(source, 'bot.alicePeerConnection.ontrack', 'Alice must receive fake peer tracks');
  requireContains(source, 'bot.sawCamera = true', 'harness must mark when a fake peer receives Alice camera');
  requireContains(source, 'soundToggle.checked', 'audio creation must stay behind the optional sound toggle');
  requireContains(source, 'track.stop()', 'harness teardown must stop media tracks');
  requireContains(source, 'bot.audioContext.close()', 'harness teardown must close optional audio contexts');
  requireContains(source, 'window.cancelAnimationFrame', 'harness teardown must stop generated video animation');
  requireContains(source, 'controller.dispose()', 'harness teardown must dispose production gossip controllers');

  requireRegex(
    source,
    /const BOT_DEFINITIONS = Object\.freeze\(\[[\s\S]*sputnik-1[\s\S]*sputnik-2[\s\S]*sputnik-3[\s\S]*\]\)/,
    'fake peer inventory must be centralized and visible to reviewers',
  );
  requireRegex(
    packageJson,
    /"test:contract:sputnik-peer-harness":\s*"node tests\/contract\/sputnik-peer-harness-contract\.mjs"/,
    'package scripts must expose the Sputnik peer harness contract',
  );
  requireContains(packageJson, 'sputnik-peer-harness-contract.mjs', 'aggregate contracts must keep the Sputnik harness covered');

  console.log('[sputnik-peer-harness-contract] PASS');
} catch (error) {
  console.error(`[sputnik-peer-harness-contract] FAIL: ${error.message}`);
  process.exit(1);
}
