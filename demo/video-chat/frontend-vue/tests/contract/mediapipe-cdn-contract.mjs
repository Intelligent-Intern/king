import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);
const frontendRoot = path.resolve(__dirname, '../..');
const repoVideoChatRoot = path.resolve(frontendRoot, '..');
const tasksVisionVendorDir = path.join(frontendRoot, 'public/cdn/vendor/mediapipe/tasks-vision');
const modelVendorDir = path.join(frontendRoot, 'public/cdn/vendor/mediapipe/models');
const sinetVendorDir = path.join(frontendRoot, 'public/cdn/vendor/sinet');
const wasmDir = path.join(frontendRoot, 'public/wasm');

function readUtf8(file) {
  return fs.readFileSync(file, 'utf8');
}

function assertFile(file) {
  const stat = fs.statSync(file);
  assert.ok(stat.isFile(), `${file} must exist`);
  assert.ok(stat.size > 0, `${file} must not be empty`);
  return stat.size;
}

function assertNoJsdelivrAssetSource(source, label) {
  const externalUrlPattern = /(?:https?:)?\/\/[^\s'"`<>)]+/g;
  for (const match of source.matchAll(externalUrlPattern)) {
    const urlText = match[0].startsWith('//') ? `https:${match[0]}` : match[0];
    let url;
    try {
      url = new URL(urlText);
    } catch {
      continue;
    }
    assert.notEqual(url.hostname, 'cdn.jsdelivr.net', `${label} must not load jsDelivr assets`);
  }
}

try {
  const workerBackend = readUtf8(path.join(frontendRoot, 'src/domain/realtime/background/backendWorkerSegmenter.js'));
  assertNoJsdelivrAssetSource(workerBackend, 'Worker segmenter backend');
  assert.ok(workerBackend.includes('VITE_VIDEOCHAT_CDN_ORIGIN'), 'Worker segmenter backend must support deploy-time CDN origin');
  assert.ok(workerBackend.includes('/cdn/vendor/mediapipe/models/'), 'Worker segmenter backend must use the vendored model directory');
  assert.ok(workerBackend.includes('selfie_multiclass_256x256.tflite'), 'Worker segmenter must use the Tasks ImageSegmenter multiclass model');
  assert.ok(!workerBackend.includes('/cdn/vendor/mediapipe/selfie_segmentation/'), 'Worker segmenter must not use the legacy SelfieSegmentation assets');

  const imageSegmenterWorker = readUtf8(path.join(frontendRoot, 'src/domain/realtime/background/workers/imageSegmenterWorker.js'));
  assertNoJsdelivrAssetSource(imageSegmenterWorker, 'Image segmenter worker');
  assert.ok(imageSegmenterWorker.includes('/cdn/vendor/mediapipe/tasks-vision/vision_bundle.mjs'), 'Image segmenter worker must load the vendored Tasks-Vision module');
  assert.ok(imageSegmenterWorker.includes('installMediaPipeConsoleNoiseFilter()'), 'Image segmenter worker must suppress known MediaPipe/Emscripten console noise');
  assert.ok(imageSegmenterWorker.includes("'debug', 'log', 'info', 'warn'"), 'MediaPipe console noise filter must cover non-error console channels only');
  assert.ok(!imageSegmenterWorker.includes("const method of ['debug', 'log', 'info', 'warn', 'error']"), 'MediaPipe console noise filter must not hide initialization errors');
  assert.ok(!imageSegmenterWorker.includes("from '@mediapipe/tasks-vision'"), 'Image segmenter worker must not leave a bare package import for production');
  assert.ok(!imageSegmenterWorker.includes('/node_modules/@mediapipe/tasks-vision'), 'Image segmenter worker must not load node_modules at runtime');
  assert.ok(imageSegmenterWorker.includes('/cdn/vendor/mediapipe/models/selfie_multiclass_256x256.tflite'), 'Image segmenter worker must use the vendored multiclass model path');
  assert.ok(imageSegmenterWorker.includes('function cacheBustUrl'), 'Image segmenter worker must cache-bust model and wasm probes');
  assert.ok(imageSegmenterWorker.includes("fetch(cacheBustUrl(resolvedModel), { cache: 'no-store' })"), 'Image segmenter worker must bypass cached missing-model responses during initialization');
  assert.ok(!imageSegmenterWorker.includes('/cdn/vendor/mediapipe/selfie_segmentation/'), 'Image segmenter worker must not use the legacy SelfieSegmentation assets');

  const sinetBackend = readUtf8(path.join(frontendRoot, 'src/domain/realtime/background/backendSinetWasm.js'));
  const maskPostprocess = readUtf8(path.join(frontendRoot, 'src/domain/realtime/background/maskPostprocess.js'));
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
  assert.ok(!sinetBackend.includes('probabilityToAlphaByte'), 'SINet fallback must not map logits as blended probabilities');
  assert.ok(!sinetBackend.includes('@mediapipe'), 'SINet backend must not depend on MediaPipe');
  assert.ok(maskPostprocess.includes('function smoothstep(edge0, edge1, value)'), 'Mask postprocess must smooth only the contour transition');
  assert.ok(maskPostprocess.includes('const edgeLow = 0.5 - contourHalfWidth'), 'Mask postprocess must keep hard background outside the contour band');
  assert.ok(!maskPostprocess.includes('(raw - 0.5) * contrast + 0.5'), 'Mask postprocess must not raise background alpha through global contrast shaping');

  const backgroundStream = readUtf8(path.join(frontendRoot, 'src/domain/realtime/background/stream.ts'));
  assert.ok(backgroundStream.includes("import { createSinetWasmSegmentationBackend } from './backendSinetWasm';"), 'Background stream must import the SINet WASM backend');
  assert.ok(backgroundStream.includes("import { acquireWorkerSegmenterBackendLease } from './backendWorkerSegmenter';"), 'Background stream must keep the shared worker segmenter lease as fallback');
  assert.ok(backgroundStream.indexOf('createSinetWasmSegmentationBackend') < backgroundStream.indexOf('acquireWorkerSegmenterBackendLease({'), 'Background stream must initialize SINet before the MediaPipe worker fallback');
  assert.ok(backgroundStream.includes("delegate: 'CPU'"), 'MediaPipe fallback must request the CPU delegate');
  assert.ok(backgroundStream.includes('BACKGROUND_SEGMENTER_INIT_RETRY_MS'), 'Background stream must back off repeated segmenter init failures');
  assert.ok(!backgroundStream.includes('backendMediapipe'), 'Background stream must not import the legacy MediaPipe backend');
  assert.ok(!backgroundStream.includes('backendTfjs'), 'Background stream must not import the legacy TensorFlow backend');

  for (const removedBackend of ['backendMediapipe.js', 'backendTfjs.js', 'backend.js', 'backendSelector.js', 'backendMediapipe.ts', 'backendTfjs.ts', 'backend.ts', 'backendSelector.ts']) {
    assert.ok(!fs.existsSync(path.join(frontendRoot, 'src/domain/realtime/background', removedBackend)), `${removedBackend} must not be restored`);
  }

  const tasksVisionManifest = JSON.parse(readUtf8(path.join(tasksVisionVendorDir, 'manifest.json')));
  assert.equal(tasksVisionManifest.package, '@mediapipe/tasks-vision');
  assert.equal(tasksVisionManifest.version, '0.10.35');
  const tasksVisionFiles = new Set((tasksVisionManifest.files || []).map((entry) => entry.path));
  for (const file of ['vision_bundle.mjs', 'vision_bundle_mjs.js.map']) {
    assert.ok(tasksVisionFiles.has(file), `Tasks-Vision manifest must pin ${file}`);
    assertFile(path.join(tasksVisionVendorDir, file));
  }

  assertFile(path.join(modelVendorDir, 'selfie_multiclass_256x256.tflite'));
  assertFile(path.join(sinetVendorDir, 'metadata-float.json'));
  assertFile(path.join(sinetVendorDir, 'sinet-float.onnx'));
  assertFile(path.join(sinetVendorDir, 'sinet.data'));
  const sinetMetadata = JSON.parse(readUtf8(path.join(sinetVendorDir, 'metadata-float.json')));
  assert.equal(sinetMetadata.model_id, 'sinet');
  assert.equal(sinetMetadata.runtime, 'onnx');
  assert.deepEqual(
    sinetMetadata.model_files?.['sinet.onnx']?.outputs?.mask?.shape,
    [1, 2, 256, 256],
    'SINet metadata must describe the foreground/background mask output',
  );

  const packageJson = JSON.parse(readUtf8(path.join(frontendRoot, 'package.json')));
  assert.ok(packageJson.dependencies?.['onnxruntime-web'], 'frontend package must depend on onnxruntime-web');
  for (const optionalModel of ['deeplab_v3.tflite', 'hair_segmenter.tflite', 'selfie_segmenter.tflite']) {
    assert.ok(!workerBackend.includes(optionalModel), `Worker segmenter must not reference unused optional model ${optionalModel}`);
    assert.ok(!imageSegmenterWorker.includes(optionalModel), `Image segmenter worker must not reference unused optional model ${optionalModel}`);
  }

  for (const file of ['vision_wasm_internal.js', 'vision_wasm_internal.wasm']) {
    assertFile(path.join(wasmDir, file));
  }

  const viteConfig = readUtf8(path.join(frontendRoot, 'vite.config.js'));
  assert.ok(!viteConfig.includes("external: ['@mediapipe/tasks-vision']"), 'Vite worker build must not externalize tasks-vision');

  const preferences = readUtf8(path.join(frontendRoot, 'src/domain/realtime/media/preferences.ts'));
  assert.ok(preferences.includes('/assets/orgas/kingrt/social/invitation-preview.png') || preferences.includes('/assets/images/bookshelf.png'), 'Image background preset must use an existing production asset');

  const edge = readUtf8(path.join(repoVideoChatRoot, 'edge/edge.php'));
  assert.ok(edge.includes('VIDEOCHAT_EDGE_CDN_DOMAIN'), 'King edge must recognize the CDN host');
  assert.ok(edge.includes('Access-Control-Allow-Origin'), 'King edge must emit CORS for CDN assets');
  assert.ok(edge.includes("'wasm' => 'application/wasm'"), 'King edge must serve wasm with the correct MIME type');

  const deploy = readUtf8(path.join(repoVideoChatRoot, 'scripts/deploy.sh'));
  assert.ok(deploy.includes('VIDEOCHAT_DEPLOY_CDN_DOMAIN'), 'deploy must persist the CDN domain');
  assert.ok(deploy.includes('VITE_VIDEOCHAT_CDN_ORIGIN=https://\\${CDN_DOMAIN}'), 'production deploy must build frontend against the CDN origin');
  assert.ok(deploy.includes('-d "\\${CDN_DOMAIN}"'), 'certbot SAN list must include the CDN domain');

  console.log('[mediapipe-cdn-contract] PASS');
} catch (error) {
  console.error(`[mediapipe-cdn-contract] FAIL: ${error.message}`);
  process.exit(1);
}
