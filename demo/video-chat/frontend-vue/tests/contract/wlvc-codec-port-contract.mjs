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

  const nativeCodec = readFromFrontend('src/lib/wasm/cpp/codec.cpp');
  requireContains(nativeCodec, 'int checked_plane_value_count(int width, int height, const char* label)', 'native codec checked plane count helper');
  requireContains(nativeCodec, 'static_cast<size_t>(width) * static_cast<size_t>(height)', 'native codec widened dimension product');
  requireContains(nativeCodec, 'const uint64_t payload_end = static_cast<uint64_t>(kHeaderBytes)', 'native codec widened payload bound');
  requireNotContains(nativeCodec, 'resize(w * h)', 'native codec unsafe luma resize');
  requireNotContains(nativeCodec, 'resize(uvW * uvH)', 'native codec unsafe chroma resize');
  requireNotContains(nativeCodec, 'static_cast<size_t>(w * h', 'native codec unsafe luma byte cast');
  requireNotContains(nativeCodec, 'static_cast<size_t>(uvW * uvH', 'native codec unsafe chroma byte cast');
  requireNotContains(nativeCodec, 'const int i = (row * w + col) * 4', 'native codec unsafe RGBA luma index product');
  requireNotContains(nativeCodec, 'const int i = (row * 2 * w + col * 2) * 4', 'native codec unsafe RGBA chroma index product');
  requireNotContains(nativeCodec, 'const int yi  = row * w + col', 'native codec unsafe decoded luma index product');
  requireNotContains(nativeCodec, 'const int pi = i * 4', 'native codec unsafe decoded RGBA index product');

  const nativeEntropy = readFromFrontend('src/lib/wasm/cpp/entropy.h');
  requireContains(nativeEntropy, 'const size_t count = n_values > 0 ? static_cast<size_t>(n_values) : 0;', 'native RLE max widened count');
  requireNotContains(nativeEntropy, 'RLE_HEADER_BYTES + n_values * RLE_PAIR_BYTES', 'native RLE max unsafe int product');

  const nativeEntropyImpl = readFromFrontend('src/lib/wasm/cpp/entropy.cpp');
  requireContains(nativeEntropyImpl, 'static_cast<size_t>(pair_count) * static_cast<size_t>(RLE_PAIR_BYTES)', 'native RLE encode widened pair count');
  requireNotContains(nativeEntropyImpl, 'RLE_HEADER_BYTES + pair_count * RLE_PAIR_BYTES', 'native RLE encode unsafe pair-count product');

  const nativeExports = readFromFrontend('src/lib/wasm/cpp/exports.cpp');
  requireContains(nativeExports, 'static size_t checked_rgba_output_size(int w, int h)', 'native embind checked RGBA output helper');
  requireNotContains(nativeExports, 'rgba_out_(w * h * 4)', 'native embind unsafe RGBA allocation');

  const nativeMotion = readFromFrontend('src/lib/wasm/cpp/motion.cpp');
  requireContains(nativeMotion, 'const size_t frame_bytes = static_cast<size_t>(w) * static_cast<size_t>(h);', 'native motion widened frame copy count');
  requireNotContains(nativeMotion, 'static_cast<size_t>(w * h)', 'native motion unsafe frame copy cast');

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
