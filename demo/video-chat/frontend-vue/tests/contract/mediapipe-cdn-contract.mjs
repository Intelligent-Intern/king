import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);
const frontendRoot = path.resolve(__dirname, '../..');
const repoVideoChatRoot = path.resolve(frontendRoot, '..');
const mediaPipeVendorDir = path.join(frontendRoot, 'public/cdn/vendor/mediapipe');

function readUtf8(relativePath) {
  return fs.readFileSync(path.join(frontendRoot, relativePath), 'utf8');
}

function requireMissingPath(relativePath, label) {
  assert.ok(!fs.existsSync(path.join(frontendRoot, relativePath)), `${label} must be removed`);
}

function assertFile(filePath) {
  assert.ok(fs.existsSync(filePath), `${filePath} must exist`);
  assert.ok(fs.statSync(filePath).size > 0, `${filePath} must not be empty`);
}

function assertNoExternalAssetSource(source, label) {
  assert.ok(!source.includes('cdn.jsdelivr.net'), `${label} must not load jsDelivr assets`);
  assert.ok(!source.includes('unpkg.com'), `${label} must not load unpkg assets`);
}

try {
  requireMissingPath('src/domain/realtime/background/backendMediapipe.ts', 'legacy MediaPipe main-thread backend');
  requireMissingPath('src/domain/realtime/background/backendTfjs.ts', 'TFJS background backend');
  requireMissingPath('src/domain/realtime/background/backendSinetWasm.js', 'production SINet backend');
  requireMissingPath('src/domain/realtime/background/backendSelector.ts', 'production SINet selector');

  const workerBackend = readUtf8('src/domain/realtime/background/backendWorkerSegmenter.js');
  const worker = readUtf8('src/domain/realtime/background/workers/imageSegmenterWorker.js');
  const stream = readUtf8('src/domain/realtime/background/stream.ts');
  const featureFlags = readUtf8('src/domain/realtime/background/pipeline/featureFlags.js');
  const packageJson = readUtf8('package.json');

  assertNoExternalAssetSource(workerBackend, 'worker segmenter backend');
  assertNoExternalAssetSource(worker, 'ImageSegmenter worker');
  assert.ok(workerBackend.includes("const MODEL_PATH = `${VIDEOCHAT_CDN_ORIGIN}${MEDIAPIPE_MODEL_BASE_PATH}selfie_multiclass_256x256.tflite`;"), 'worker backend must load the vendored model through the configured CDN origin');
  assert.ok(workerBackend.includes("const WASM_PATH = `${VIDEOCHAT_CDN_ORIGIN}${MEDIAPIPE_WASM_BASE_PATH}`;"), 'worker backend must load wasm through the configured CDN origin');
  assert.ok(worker.includes("const DEFAULT_MODEL_PATH = '/cdn/vendor/mediapipe/models/selfie_multiclass_256x256.tflite';"), 'worker default model path must be local CDN');
  assert.ok(worker.includes("const DEFAULT_WASM_PATH = '/wasm';"), 'worker default wasm path must be local');
  assert.ok(worker.includes('loadModuleFactory(resolvedWasm);'), 'worker must load the vendored wasm factory explicitly');
  assert.ok(worker.includes('modelAssetBuffer: new Uint8Array(modelBuffer)'), 'worker must pass a local model buffer to MediaPipe');
  assert.ok(stream.includes('backendWorkerSegmenter'), 'production stream must use Pierre worker segmenter');
  assert.ok(!stream.includes('backendSinetWasm'), 'production stream must not use SINet as fallback');
  assert.ok(!featureFlags.includes('VITE_VIDEOCHAT_WORKER_SEGMENTER'), 'worker segmenter must be the production path, not a dead toggle');
  assert.ok(packageJson.includes('mediapipe-cdn-contract.mjs'), 'CDN contract must remain executable in CI');

  assertFile(path.join(mediaPipeVendorDir, 'tasks-vision/vision_bundle.mjs'));
  assertFile(path.join(mediaPipeVendorDir, 'models/selfie_multiclass_256x256.tflite'));
  assertFile(path.join(frontendRoot, 'public/wasm/vision_wasm_internal.js'));

  const edge = fs.readFileSync(path.join(repoVideoChatRoot, 'edge/edge.php'), 'utf8');
  assert.ok(edge.includes('VIDEOCHAT_EDGE_CDN_DOMAIN'), 'King edge must recognize the CDN host');
  assert.ok(edge.includes('Access-Control-Allow-Origin'), 'King edge must emit CORS for CDN assets');
  assert.ok(edge.includes("'wasm' => 'application/wasm'"), 'King edge must serve wasm with the correct MIME type');

  console.log('[mediapipe-cdn-contract] PASS worker MediaPipe assets are local/CDN-hosted');
} catch (error) {
  console.error(`[mediapipe-cdn-contract] FAIL: ${error.message}`);
  process.exit(1);
}
