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
  assert.ok(!imageSegmenterWorker.includes("from '@mediapipe/tasks-vision'"), 'Image segmenter worker must not leave a bare package import for production');
  assert.ok(!imageSegmenterWorker.includes('/node_modules/@mediapipe/tasks-vision'), 'Image segmenter worker must not load node_modules at runtime');
  assert.ok(imageSegmenterWorker.includes('/cdn/vendor/mediapipe/models/selfie_multiclass_256x256.tflite'), 'Image segmenter worker must use the vendored multiclass model path');
  assert.ok(!imageSegmenterWorker.includes('/cdn/vendor/mediapipe/selfie_segmentation/'), 'Image segmenter worker must not use the legacy SelfieSegmentation assets');

  const backgroundStream = readUtf8(path.join(frontendRoot, 'src/domain/realtime/background/stream.ts'));
  assert.ok(backgroundStream.includes("import { createWorkerSegmenterBackend } from './backendWorkerSegmenter';"), 'Background stream must use the worker segmenter backend');
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
