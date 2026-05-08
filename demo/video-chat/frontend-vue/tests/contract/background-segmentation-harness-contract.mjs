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

function requireFile(relativePath, label) {
  const fullPath = path.join(frontendRoot, relativePath);
  assert.ok(fs.existsSync(fullPath), `${label} missing: ${relativePath}`);
  assert.ok(fs.statSync(fullPath).size > 0, `${label} must not be empty`);
}

try {
  const html = readUtf8('tests/standalone/king-background-segmentation-harness.html');
  const harness = readUtf8('tests/standalone/king-background-segmentation-harness.ts');
  const stream = readUtf8('src/domain/realtime/background/stream.ts');
  const workerBackend = readUtf8('src/domain/realtime/background/backendWorkerSegmenter.js');
  const worker = readUtf8('src/domain/realtime/background/workers/imageSegmenterWorker.js');

  requireFile('public/cdn/vendor/mediapipe/tasks-vision/vision_bundle.mjs', 'vendored MediaPipe Tasks bundle');
  requireFile('public/cdn/vendor/mediapipe/models/selfie_multiclass_256x256.tflite', 'vendored MediaPipe segmentation model');
  requireFile('public/wasm/vision_wasm_internal.js', 'vendored MediaPipe wasm loader');

  assert.ok(html.includes('king-background-segmentation-harness.ts'), 'standalone harness must stay available for model comparison');
  assert.ok(harness.includes("from 'onnxruntime-web/wasm'"), 'standalone harness may keep SINet/ONNX experiments isolated from production');
  assert.ok(harness.includes('createKingBackgroundMatteRefiner'), 'standalone harness must keep King WASM comparison available');
  assert.ok(!stream.includes('onnxruntime-web/wasm'), 'production stream must not import the standalone ONNX experiment');
  assert.ok(!stream.includes('/cdn/vendor/sinet/'), 'production stream must not load SINet assets');

  assert.ok(stream.includes('acquireWorkerSegmenterBackendLease'), 'production stream must use the worker segmenter backend');
  assert.ok(workerBackend.includes('selfie_multiclass_256x256.tflite'), 'worker backend must use the vendored selfie multiclass model');
  assert.ok(worker.includes('ImageSegmenter.createFromOptions'), 'worker must initialize MediaPipe Tasks ImageSegmenter');
  assert.ok(worker.includes('outputCategoryMask: true'), 'worker must request category masks');
  assert.ok(worker.includes('outputConfidenceMasks: true'), 'worker must keep confidence masks as a local worker fallback');
  assert.ok(!worker.includes('cdn.jsdelivr.net'), 'worker must not fetch runtime from jsDelivr');
  assert.ok(!worker.includes('unpkg.com'), 'worker must not fetch runtime from unpkg');

  assert.ok(html.includes('Foreground Mask'), 'standalone harness must show a mask pane');
  assert.ok(html.includes('Composited Blur'), 'standalone harness must show a result pane');
  assert.ok(html.includes('value="exclusion"'), 'standalone harness must expose deep-blue exclusion background');
  assert.ok(harness.includes("const EXCLUSION_BACKGROUND = '#061a4a';"), 'standalone harness exclusion must use deep blue background');

  console.log('[background-segmentation-harness-contract] PASS');
} catch (error) {
  console.error(`[background-segmentation-harness-contract] FAIL: ${error.message}`);
  process.exit(1);
}
