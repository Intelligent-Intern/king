import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { pathToFileURL, fileURLToPath } from 'node:url';

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);
const frontendRoot = path.resolve(__dirname, '../..');
const repoRoot = path.resolve(frontendRoot, '..', '..', '..');

function readFrontend(relativePath) {
  return fs.readFileSync(path.resolve(frontendRoot, relativePath), 'utf8');
}

function readRepo(relativePath) {
  return fs.readFileSync(path.resolve(repoRoot, relativePath), 'utf8');
}

function requireContains(source, needle, message) {
  assert.ok(source.includes(needle), message);
}

async function main() {
  const sprint = readRepo('SPRINT.md');
  const packageJson = readFrontend('package.json');
  const jitter = readFrontend('src/domain/realtime/sfu/remoteJitterBuffer.ts');
  const frameDecode = readFrontend('src/domain/realtime/sfu/frameDecode.ts');
  const remotePeers = readFrontend('src/domain/realtime/sfu/remotePeers.ts');

  requireContains(sprint, '5. [x] `[native-render-and-jitter-buffer]`', 'sprint issue 5 must be checked');
  requireContains(packageJson, 'sfu-receiver-jitter-buffer-contract.mjs', 'SFU contract script includes receiver jitter buffer');
  requireContains(jitter, 'REMOTE_SFU_JITTER_BUFFER_HOLD_MS = 90', 'receiver jitter buffer has bounded hold window');
  requireContains(jitter, 'REMOTE_SFU_JITTER_BUFFER_MAX_FRAMES = 8', 'receiver jitter buffer has bounded frame count');
  requireContains(jitter, 'REMOTE_SFU_JITTER_BUFFER_MAX_GAP = 3', 'receiver jitter buffer only covers small reorder gaps');
  requireContains(jitter, 'popExpiredRemoteJitterFrame', 'jitter buffer releases expired future frames');
  requireContains(frameDecode, 'maybeBufferRemoteFrameForJitter', 'decode path checks jitter buffer before continuity drop');
  requireContains(frameDecode, 'sfu_receiver_jitter_buffer_hold', 'receiver reports jitter holds');
  requireContains(frameDecode, 'sfu_receiver_jitter_buffer_drain', 'receiver reports in-order jitter drains');
  requireContains(frameDecode, 'sfu_receiver_jitter_buffer_release', 'receiver reports hold-window releases');
  requireContains(frameDecode, "decodeSfuFrameForPeer(publisherId, peer, nextFrame, { fromJitterBuffer: true })", 'jitter drain re-enters decode without rebufferring');
  requireContains(remotePeers, 'remoteJitterBufferByTrack: {}', 'remote peer continuity state owns jitter buffers');

  const jitterUrl = pathToFileURL(path.resolve(frontendRoot, 'src/domain/realtime/sfu/remoteJitterBuffer.ts')).href;
  const module = await import(jitterUrl);
  const peer = {
    lastSfuFrameSequenceByTrack: { camera: 10 },
    remoteJitterBufferByTrack: {},
  };
  const future = { trackId: 'camera', frameSequence: 12, type: 'delta' };
  const decision = module.shouldBufferRemoteFrameForJitter(peer, future, 1_000);
  assert.equal(decision.buffer, true, 'one-frame reorder gap should enter jitter buffer');
  assert.equal(decision.missingFrameCount, 1, 'missing frame count should be preserved');
  assert.equal(module.bufferRemoteFrameForJitter(peer, future, decision, 1_000), true, 'future frame should buffer');
  assert.equal(module.remoteJitterBufferSize(peer, 'camera'), 1, 'buffer size should reflect held frame');
  peer.lastSfuFrameSequenceByTrack.camera = 11;
  assert.equal(module.popNextRemoteJitterFrame(peer, 'camera'), future, 'buffer should drain once missing sequence arrives');
  assert.equal(module.remoteJitterBufferSize(peer, 'camera'), 0, 'buffer drains in order');

  const largeGap = module.shouldBufferRemoteFrameForJitter(peer, { trackId: 'camera', frameSequence: 20, type: 'delta' }, 1_000);
  assert.equal(largeGap.buffer, false, 'large gaps must not hide real loss behind jitter buffering');
  assert.equal(largeGap.reason, 'gap_too_large', 'large-gap reason should be explicit');

  process.stdout.write('[sfu-receiver-jitter-buffer-contract] PASS\n');
}

main();
