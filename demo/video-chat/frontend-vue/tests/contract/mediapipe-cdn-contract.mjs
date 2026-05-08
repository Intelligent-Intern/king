import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);
const frontendRoot = path.resolve(__dirname, '../..');
const repoVideoChatRoot = path.resolve(frontendRoot, '..');
const sinetVendorDir = path.join(frontendRoot, 'public/cdn/vendor/sinet');

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

function assertNoJsdelivrAssetSource(source, label) {
  assert.ok(!source.includes('cdn.jsdelivr.net'), `${label} must not load jsDelivr assets`);
  assert.ok(!source.includes('unpkg.com'), `${label} must not load unpkg assets`);
}

try {
  requireMissingPath('src/domain/realtime/background/backendMediapipe.ts', 'MediaPipe background backend');
  requireMissingPath('src/domain/realtime/background/backendTfjs.ts', 'TFJS background backend');
  requireMissingPath('src/domain/realtime/background/backendWorkerSegmenter.js', 'MediaPipe worker segmenter backend');
  requireMissingPath('src/domain/realtime/background/workers/imageSegmenterWorker.js', 'MediaPipe ImageSegmenter worker');

  const sinetBackend = readUtf8('src/domain/realtime/background/backendSinetWasm.js');
  const maskPostprocess = readUtf8('src/domain/realtime/background/maskPostprocess.js');
  const stream = readUtf8('src/domain/realtime/background/stream.ts');
  const selector = readUtf8('src/domain/realtime/background/backendSelector.ts');
  const featureFlags = readUtf8('src/domain/realtime/background/pipeline/featureFlags.js');
  const packageJson = readUtf8('package.json');

  assertNoJsdelivrAssetSource(sinetBackend, 'SINet WASM backend');
  assert.ok(sinetBackend.includes("import('onnxruntime-web/wasm')"), 'SINet backend must import the ONNX Runtime WASM build');
  assert.ok(sinetBackend.includes("executionProviders: ['wasm']"), 'SINet backend must force the WASM execution provider');
  assert.ok(sinetBackend.includes('wasm.proxy = false'), 'SINet backend must avoid ORT worker proxy mode for deterministic init');
  assert.ok(sinetBackend.includes('wasm.numThreads = 1'), 'SINet backend must use single-threaded WASM by default');
  assert.ok(sinetBackend.includes('/cdn/vendor/sinet/'), 'SINet backend must load vendored SINet assets');
  assert.ok(sinetBackend.includes('externalData: [{ path: SINET_EXTERNAL_WEIGHTS_PATH, data: weights }]'), 'SINet backend must mount external ONNX weights');
  assert.ok(sinetBackend.includes("kind: 'sinet_wasm'"), 'SINet backend must expose its backend kind');
  assert.ok(sinetBackend.includes('matteMaskValues'), 'SINet backend must return value masks for the shared compositor');
  assert.ok(sinetBackend.includes('function binaryForegroundAlpha(value, threshold = 0)'), 'SINet fallback must classify raw mask output without sigmoid');
  assert.ok(sinetBackend.includes('alpha[i] = binaryForegroundAlpha(fg, bg);'), 'SINet fallback must use foreground-vs-background argmax for two-channel outputs');
  assert.ok(sinetBackend.includes('const threshold = probabilityLike ? 0.5 : 0;'), 'SINet fallback must threshold single-channel masks without sigmoid');
  assert.ok(!sinetBackend.includes('Math.exp(bg - max)'), 'SINet fallback must not softmax background and foreground logits');
  assert.ok(!sinetBackend.includes('fgExp / Math.max'), 'SINet fallback must not couple foreground alpha to the background logit');
  assert.ok(!sinetBackend.includes('foregroundLogitToProbability'), 'SINet fallback must not run raw logits through sigmoid');
  assert.ok(!sinetBackend.includes('1 / (1 + Math.exp'), 'SINet fallback must not use sigmoid alpha mapping');
  assert.ok(!sinetBackend.includes('@mediapipe'), 'SINet backend must not depend on MediaPipe');

  assert.ok(maskPostprocess.includes('function smoothstep(edge0, edge1, value)'), 'Mask postprocess must smooth only the contour transition');
  assert.ok(maskPostprocess.includes('const edgeLow = 0.5 - contourHalfWidth'), 'Mask postprocess must keep hard background outside the contour band');
  assert.ok(!maskPostprocess.includes('(raw - 0.5) * contrast + 0.5'), 'Mask postprocess must not raise background alpha through global contrast shaping');

  assert.ok(stream.includes("import { createSinetWasmSegmentationBackend } from './backendSinetWasm';"), 'Background stream must import the SINet WASM backend');
  assert.ok(stream.includes('BACKGROUND_SEGMENTER_INIT_RETRY_MS'), 'Background stream must back off repeated segmenter init failures');
  assert.ok(!stream.includes('backendWorkerSegmenter'), 'production background stream must not use the MediaPipe worker fallback');
  assert.ok(!stream.includes('MediaPipe'), 'production background stream must not reference MediaPipe');
  assert.ok(!stream.includes('TensorFlow'), 'production background stream must not reference TensorFlow');
  assert.ok(!stream.includes('tfjs'), 'production background stream must not reference TFJS');
  assert.ok(selector.includes("backend: 'sinet_wasm'"), 'backend selector must report SINet WASM');
  assert.ok(!featureFlags.includes('VITE_VIDEOCHAT_WORKER_SEGMENTER'), 'production background feature flags must not expose legacy worker segmenter toggles');
  assert.ok(!featureFlags.includes('WORKER_SEGMENTER'), 'production background feature flags must not retain dead worker segmenter exports');
  assert.ok(!packageJson.includes('@mediapipe/tasks-vision'), 'frontend package must not depend on MediaPipe Tasks');
  assert.ok(packageJson.includes('mediapipe-cdn-contract.mjs'), 'legacy CDN contract name must remain executable in CI');

  assertFile(path.join(sinetVendorDir, 'metadata-float.json'));
  assertFile(path.join(sinetVendorDir, 'sinet-float.onnx'));
  assertFile(path.join(sinetVendorDir, 'sinet.data'));
  const sinetMetadata = JSON.parse(fs.readFileSync(path.join(sinetVendorDir, 'metadata-float.json'), 'utf8'));
  assert.equal(sinetMetadata.model_id, 'sinet');
  assert.equal(sinetMetadata.runtime, 'onnx');

  const edge = fs.readFileSync(path.join(repoVideoChatRoot, 'edge/edge.php'), 'utf8');
  assert.ok(edge.includes('VIDEOCHAT_EDGE_CDN_DOMAIN'), 'King edge must recognize the CDN host');
  assert.ok(edge.includes('Access-Control-Allow-Origin'), 'King edge must emit CORS for CDN assets');
  assert.ok(edge.includes("'wasm' => 'application/wasm'"), 'King edge must serve wasm with the correct MIME type');

  console.log('[mediapipe-cdn-contract] PASS legacy MediaPipe/TFJS background fallback removed');
} catch (error) {
  console.error(`[mediapipe-cdn-contract] FAIL: ${error.message}`);
  process.exit(1);
}
