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

function requireContains(source, needle, label) {
  assert.ok(source.includes(needle), `${label} missing: ${needle}`);
}

function requireMissing(source, needle, label) {
  assert.ok(!source.includes(needle), `${label} must not contain: ${needle}`);
}

try {
  const stream = readUtf8('src/domain/realtime/background/stream.ts');
  const backend = readUtf8('src/domain/realtime/background/backendSinetWasm.ts');
  const postprocess = readUtf8('src/domain/realtime/background/maskPostprocess.ts');
  const selector = readUtf8('src/domain/realtime/background/backendSelector.ts');

  requireContains(stream, "import { createSinetWasmSegmentationBackend } from './backendSinetWasm';", 'production background stream');
  requireContains(stream, 'segmentationBackend = await createSinetWasmSegmentationBackend({', 'production backend construction');
  requireMissing(stream, 'createMediaPipeSegmentationBackend', 'production background stream');
  requireMissing(stream, 'createTfjsSegmentationBackend', 'production background stream');

  requireContains(backend, "import('onnxruntime-web/wasm')", 'SINet WASM backend runtime');
  requireContains(backend, 'function configureOrtWasmRuntime', 'SINet WASM runtime guard');
  requireContains(backend, 'wasm.proxy = false;', 'SINet WASM runtime must not depend on ORT proxy workers');
  requireContains(backend, 'wasm.numThreads = 1;', 'SINet WASM runtime must not depend on SharedArrayBuffer threading');
  requireContains(backend, 'const SINET_MODEL_WIDTH = 256;', 'SINet model width');
  requireContains(backend, 'const SINET_MODEL_HEIGHT = 256;', 'SINet model height');
  requireContains(backend, "const SINET_GRAPH_URL = '/cdn/vendor/sinet/sinet-float.onnx';", 'vendored SINet graph');
  requireContains(backend, "const SINET_EXTERNAL_WEIGHTS_URL = '/cdn/vendor/sinet/sinet.data';", 'vendored SINet weights');
  requireContains(backend, "externalData: [{ path: SINET_EXTERNAL_WEIGHTS_PATH, data: weights }]", 'explicit SINet external data mount');
  requireContains(backend, "executionProviders: ['wasm']", 'SINet backend must use local WASM execution');
  requireContains(backend, 'sinetForegroundAlpha', 'SINet foreground conversion');
  requireContains(backend, 'probabilityLike', 'SINet foreground conversion must avoid softmaxing probability outputs');
  requireContains(backend, 'shapeForegroundAlpha', 'SINet matte shaping controls');
  requireContains(backend, "kind: 'sinet_wasm'", 'SINet WASM backend identity');
  requireContains(postprocess, 'function gaussianAverageAlpha', 'mask local Gaussian averaging');
  assert.ok(
    postprocess.indexOf('const averagedInput = gaussianAverageAlpha(alpha, width, height, averageRadius);')
      < postprocess.indexOf('const contrasted = Math.max(0, Math.min(1, (raw - 0.5) * contrast + 0.5));'),
    'mask postprocess must average raw SINet probabilities before contrast'
  );
  assert.ok(
    postprocess.indexOf('const contrasted = Math.max(0, Math.min(1, (raw - 0.5) * contrast + 0.5));')
      < postprocess.indexOf('const value = clampByte(Math.pow(normalized, gamma) * 255);'),
    'mask postprocess must apply contrast before gamma'
  );
  requireContains(postprocess, 'controls.contrast ?? 0.75', 'mask contrast default');
  requireContains(postprocess, 'Number(controls.averageRadius ?? 6)', 'wide default Gaussian radius');
  requireContains(backend, 'opts.maskContrast ?? 0.75', 'production mask contrast fallback');
  requireContains(backend, 'opts.averageRadius ?? 6', 'production average radius fallback');
  requireMissing(postprocess, 'blackPoint', 'mask postprocess');
  requireMissing(postprocess, 'whitePoint', 'mask postprocess');
  requireMissing(postprocess, 'threshold ? value : 0', 'mask postprocess');
  requireMissing(postprocess, 'keepDominantComponents', 'mask postprocess');
  requireMissing(postprocess, 'fillEnclosedHoles', 'mask postprocess');
  requireContains(postprocess, 'previousAlpha', 'mask temporal averaging');
  requireContains(selector, "backend: 'sinet_wasm'", 'backend selector');
  requireMissing(selector, 'center_mask_fallback', 'backend selector');
  requireMissing(selector, 'face_detector', 'backend selector');

  console.log('[background-king-wasm-contract] PASS production uses SINet WASM segmentation');
} catch (error) {
  console.error(`[background-king-wasm-contract] FAIL: ${error.message}`);
  process.exit(1);
}
