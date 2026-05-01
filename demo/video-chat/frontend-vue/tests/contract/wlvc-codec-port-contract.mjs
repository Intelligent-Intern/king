import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

function fail(message) {
  throw new Error(`[wlvc-codec-port-contract] FAIL: ${message}`);
}

function requireContains(source, needle, label) {
  assert.ok(source.includes(needle), `${label} missing: ${needle}`);
}

function requireNotContains(source, needle, label) {
  assert.ok(!source.includes(needle), `${label} must not contain: ${needle}`);
}

function requireMissing(filePath, label) {
  assert.equal(fs.existsSync(filePath), false, `${label} should not exist: ${filePath}`);
}

function requireExists(filePath, label) {
  assert.equal(fs.existsSync(filePath), true, `${label} should exist: ${filePath}`);
}

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);
const repoRoot = path.resolve(__dirname, '../../../../..');
const frontendRoot = path.resolve(__dirname, '../..');

function readFromFrontend(relativePath) {
  return fs.readFileSync(path.resolve(frontendRoot, relativePath), 'utf8');
}

try {
  const waveletCodec = readFromFrontend('src/lib/wavelet/codec.ts');
  requireContains(waveletCodec, 'const vStart  = HEADER_BYTES + yBytes + uBytes', 'bounded V-channel start');
  requireContains(waveletCodec, 'const vEnd    = vStart + vBytes', 'bounded V-channel end');
  requireContains(waveletCodec, 'if (vEnd > payload.byteLength) {', 'payload length guard');
  requireContains(waveletCodec, "throw new Error('[WaveletDecoder] Invalid frame: payload length mismatch')", 'payload length error');
  requireContains(waveletCodec, 'const vRle    = payload.subarray(vStart, vEnd)', 'bounded V-channel slice');
  requireContains(waveletCodec, 'const vQ = rleDecode(vRle)', 'V-channel decode target');

  const codecTest = readFromFrontend('codec-test.html');
  requireContains(codecTest, 'payload.subarray(HEAD + yB + uB, HEAD + yB + uB + vB);', 'codec-test V-channel parity');
  requireNotContains(codecTest, 'const vRle = payload.subarray(HEAD + yB + uB);', 'unbounded codec-test V-channel slice');

  const kalmanFilter = readFromFrontend('src/lib/kalman/filter.ts');
  requireContains(kalmanFilter, 'const SInv = [', 'Kalman innovation inverse');
  requireContains(kalmanFilter, 'const K = this.multiplyMatrix(this.multiplyMatrix(this.P, this.transpose(H)), SInv)', 'Kalman gain uses SInv');
  requireContains(kalmanFilter, 'const dt2 = dt * dt / 2', 'local dt2 process-noise term');
  requireContains(kalmanFilter, 'const dt3 = dt * dt * dt / 2', 'local dt3 process-noise term');
  requireContains(kalmanFilter, 'const dt4 = dt2 * dt2', 'local dt4 process-noise term');
  requireNotContains(kalmanFilter, 'const dt2 = 1', 'stale module dt2 constant');
  requireNotContains(kalmanFilter, 'const dt3 = 1', 'stale module dt3 constant');
  requireNotContains(kalmanFilter, 'const dt4 = 1', 'stale module dt4 constant');

  requireMissing(path.resolve(frontendRoot, 'codec-test.md'), 'active-source codec test markdown');
  requireMissing(path.resolve(frontendRoot, 'src/lib/wavelet/README.md'), 'active-source wavelet README');
  requireMissing(path.resolve(repoRoot, 'demo/video-chat/frontend/src/lib/wasm/wasm-codec.ts'), 'duplicate legacy WASM tree');
  requireMissing(path.resolve(repoRoot, 'demo/video-chat/frontend/src/lib/wavelet/codec.ts'), 'duplicate legacy wavelet tree');
  requireMissing(path.resolve(repoRoot, 'demo/video-chat/frontend/src/lib/kalman/filter.ts'), 'duplicate legacy Kalman tree');
  requireExists(path.resolve(repoRoot, 'documentation/dev/video-chat-codec-test.md'), 'canonical codec test docs');
  requireExists(path.resolve(repoRoot, 'documentation/dev/video-chat-wavelet-codec.md'), 'canonical wavelet docs');

  process.stdout.write('[wlvc-codec-port-contract] PASS\n');
} catch (error) {
  if (error instanceof Error) {
    fail(error.message);
  }
  fail('unknown failure');
}
