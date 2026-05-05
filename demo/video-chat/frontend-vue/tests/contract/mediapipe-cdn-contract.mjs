import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);
const frontendRoot = path.resolve(__dirname, '../..');
const repoVideoChatRoot = path.resolve(frontendRoot, '..');
const vendorDir = path.join(frontendRoot, 'public/cdn/vendor/mediapipe/selfie_segmentation');
const tasksVisionVendorDir = path.join(frontendRoot, 'public/cdn/vendor/mediapipe/tasks-vision');
const tensorflowVendorDir = path.join(frontendRoot, 'public/cdn/vendor/tensorflow');

function readUtf8(file) {
  return fs.readFileSync(file, 'utf8');
}

function assertFile(file) {
  const stat = fs.statSync(file);
  assert.ok(stat.isFile(), `${file} must exist`);
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
  const backend = readUtf8(path.join(frontendRoot, 'src/domain/realtime/background/backendMediapipe.js'));
  assertNoJsdelivrAssetSource(backend, 'MediaPipe backend');
  assert.ok(backend.includes('VITE_VIDEOCHAT_CDN_ORIGIN'), 'MediaPipe backend must support deploy-time CDN origin');
  assert.ok(backend.includes('/cdn/vendor/mediapipe/selfie_segmentation/'), 'MediaPipe backend must use the vendored CDN path');

  const workerBackend = readUtf8(path.join(frontendRoot, 'src/domain/realtime/background/backendWorkerSegmenter.js'));
  assert.ok(workerBackend.includes('VITE_VIDEOCHAT_CDN_ORIGIN'), 'Worker segmenter backend must support deploy-time CDN origin');
  assert.ok(workerBackend.includes('/cdn/vendor/mediapipe/selfie_segmentation/'), 'Worker segmenter backend must use a vendored model path');
  assert.ok(!workerBackend.includes('/cdn/vendor/mediapipe/models/selfie_multiclass_256x256.tflite'), 'Worker segmenter must not point at an unvendored model');

  const imageSegmenterWorker = readUtf8(path.join(frontendRoot, 'src/domain/realtime/background/workers/imageSegmenterWorker.js'));
  assert.ok(imageSegmenterWorker.includes('/cdn/vendor/mediapipe/tasks-vision/vision_bundle.mjs'), 'Image segmenter worker must load the vendored tasks-vision module');
  assert.ok(!imageSegmenterWorker.includes("from '@mediapipe/tasks-vision'"), 'Image segmenter worker must not leave a bare package import for production');
  assert.ok(!imageSegmenterWorker.includes('/node_modules/@mediapipe/tasks-vision'), 'Image segmenter worker must not load node_modules at runtime');
  assert.ok(imageSegmenterWorker.includes('/cdn/vendor/mediapipe/selfie_segmentation/selfie_segmentation.tflite'), 'Image segmenter worker must use a vendored model path');

  const backgroundStream = readUtf8(path.join(frontendRoot, 'src/domain/realtime/background/stream.js'));
  assert.ok(backgroundStream.includes("import { createMediaPipeSegmentationBackend } from './backendMediapipe';"), 'Background stream must keep the production MediaPipe fallback');
  assert.ok(backgroundStream.includes('Falling back to MediaPipe selfie segmentation backend'), 'Background stream must fall back when the worker backend fails');

  const viteConfig = readUtf8(path.join(frontendRoot, 'vite.config.js'));
  assert.ok(!viteConfig.includes("external: ['@mediapipe/tasks-vision']"), 'Vite worker build must not externalize tasks-vision');

  const tfjsBackend = readUtf8(path.join(frontendRoot, 'src/domain/realtime/background/backendTfjs.js'));
  assertNoJsdelivrAssetSource(tfjsBackend, 'TensorFlow fallback backend');
  assert.ok(tfjsBackend.includes('VITE_VIDEOCHAT_CDN_ORIGIN'), 'TensorFlow fallback backend must support deploy-time CDN origin');
  assert.ok(tfjsBackend.includes('/cdn/vendor/tensorflow/'), 'TensorFlow fallback backend must use the vendored CDN path');

  const manifest = JSON.parse(readUtf8(path.join(vendorDir, 'manifest.json')));
  assert.equal(manifest.package, '@mediapipe/selfie_segmentation');
  assert.equal(manifest.version, '0.1.1675465747');

  const requiredFiles = [
    'selfie_segmentation.js',
    'selfie_segmentation.binarypb',
    'selfie_segmentation.tflite',
    'selfie_segmentation_landscape.tflite',
    'selfie_segmentation_solution_simd_wasm_bin.data',
    'selfie_segmentation_solution_simd_wasm_bin.js',
    'selfie_segmentation_solution_simd_wasm_bin.wasm',
    'selfie_segmentation_solution_wasm_bin.js',
    'selfie_segmentation_solution_wasm_bin.wasm',
  ];
  const manifestPaths = new Set((manifest.files || []).map((entry) => entry.path));
  for (const file of requiredFiles) {
    assert.ok(manifestPaths.has(file), `manifest must pin ${file}`);
    const size = assertFile(path.join(vendorDir, file));
    if (!file.endsWith('.data')) {
      assert.ok(size > 0, `${file} must not be empty`);
    }
  }

  const tasksVisionManifest = JSON.parse(readUtf8(path.join(tasksVisionVendorDir, 'manifest.json')));
  assert.equal(tasksVisionManifest.package, '@mediapipe/tasks-vision');
  assert.equal(tasksVisionManifest.version, '0.10.35');
  const tasksVisionFiles = new Set((tasksVisionManifest.files || []).map((entry) => entry.path));
  for (const file of ['vision_bundle.mjs', 'vision_bundle_mjs.js.map']) {
    assert.ok(tasksVisionFiles.has(file), `Tasks-Vision manifest must pin ${file}`);
    assert.ok(assertFile(path.join(tasksVisionVendorDir, file)) > 0, `${file} must not be empty`);
  }

  const preferences = readUtf8(path.join(frontendRoot, 'src/domain/realtime/media/preferences.js'));
  assert.ok(preferences.includes('/assets/orgas/kingrt/social/invitation-preview.png'), 'Image background preset must use an existing production asset');
  assert.ok(!preferences.includes("setCallBackgroundReplacementImageUrl('/assets/images/bookshelf.png')"), 'Image background preset must not store the removed bookshelf asset');
  assert.ok(assertFile(path.join(frontendRoot, 'public/assets/orgas/kingrt/social/invitation-preview.png')) > 0, 'Replacement background image must exist');

  const tensorflowManifest = JSON.parse(readUtf8(path.join(tensorflowVendorDir, 'manifest.json')));
  assert.equal(tensorflowManifest.vendor, 'tensorflow');
  const tensorflowFiles = new Map((tensorflowManifest.files || []).map((entry) => [entry.path, entry]));
  const requiredTensorflowFiles = [
    ['tfjs-core/tf-core.min.js', '@tensorflow/tfjs-core', '4.22.0'],
    ['tfjs-converter/tf-converter.min.js', '@tensorflow/tfjs-converter', '4.22.0'],
    ['tfjs-backend-webgl/tf-backend-webgl.min.js', '@tensorflow/tfjs-backend-webgl', '4.22.0'],
    ['body-segmentation/body-segmentation.min.js', '@tensorflow-models/body-segmentation', '1.0.2'],
  ];
  for (const [file, packageName, version] of requiredTensorflowFiles) {
    const entry = tensorflowFiles.get(file);
    assert.ok(entry, `TensorFlow manifest must pin ${file}`);
    assert.equal(entry.package, packageName);
    assert.equal(entry.version, version);
    assert.ok(assertFile(path.join(tensorflowVendorDir, file)) > 0, `${file} must not be empty`);
  }

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
