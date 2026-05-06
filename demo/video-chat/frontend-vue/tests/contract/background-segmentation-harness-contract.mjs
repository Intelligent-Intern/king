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
  assert.ok(fs.existsSync(path.join(frontendRoot, relativePath)), `${label} missing: ${relativePath}`);
}

try {
  const html = readUtf8('tests/standalone/king-background-segmentation-harness.html');
  const source = readUtf8('tests/standalone/king-background-segmentation-harness.ts');

  requireFile('public/cdn/vendor/sinet/sinet-float.onnx', 'vendored SINet ONNX graph');
  requireFile('public/cdn/vendor/sinet/sinet.data', 'vendored SINet ONNX external weights');
  requireFile('public/cdn/vendor/sinet/metadata-float.json', 'vendored SINet metadata');

  assert.ok(html.includes('king-background-segmentation-harness.ts'), 'standalone harness must load the TypeScript module');
  assert.ok(source.includes("from '../../src/lib/wasm/wasm-codec'"), 'harness must keep King WASM infra available');
  assert.ok(source.includes("from '../../src/domain/realtime/background/maskPostprocess'"), 'harness must use the same matte postprocess as production');
  assert.ok(source.includes("from 'onnxruntime-web'"), 'harness must load ONNX Runtime for fast segmentation candidates');
  assert.ok(source.includes('createKingBackgroundMatteRefiner'), 'harness must instantiate the King background segmenter');
  assert.ok(html.includes('value="sinet"'), 'harness must expose SINet as the fast segmentation candidate');
  assert.ok(source.includes("fetchBinaryAsset('/cdn/vendor/sinet/sinet-float.onnx')"), 'harness must load the vendored SINet ONNX graph');
  assert.ok(source.includes("fetchBinaryAsset('/cdn/vendor/sinet/sinet.data')"), 'harness must load the vendored SINet external weights');
  assert.ok(source.includes("externalData: [{ path: 'sinet.data', data: weights }]"), 'harness must mount SINet external weights explicitly');
  assert.ok(source.includes('InferenceSession.create(model'), 'harness must create SINet sessions from fetched model bytes');
  assert.ok(source.includes('sinetForegroundAlpha'), 'harness must convert SINet output to foreground alpha');
  assert.ok(source.includes('probabilityLike'), 'harness must avoid softmaxing probability-like SINet outputs');
  assert.ok(source.includes('shapeForegroundAlpha'), 'harness must apply shared Gaussian, contrast, gamma, and temporal mask shaping');
  assert.ok(!html.includes('value="modnet"'), 'harness must not expose MODNet fallback');
  assert.ok(!source.includes('Xenova/modnet'), 'harness must not run MODNet fallback');
  assert.ok(html.includes('id="deviceSelect"'), 'harness must expose device selection');
  assert.ok(html.includes('id="dtypeSelect"'), 'harness must expose dtype selection');
  assert.ok(html.includes('id="alphaGammaInput"'), 'harness must expose alpha gamma shaping');
  assert.ok(html.includes('id="maskContrastInput"'), 'harness must expose mask contrast shaping');
  assert.ok(!html.includes('maskBlackPointInput'), 'harness must not expose black point controls');
  assert.ok(!html.includes('maskWhitePointInput'), 'harness must not expose white point controls');
  assert.ok(html.includes('id="averageRadiusInput"'), 'harness must expose Gaussian radius shaping');
  assert.ok(html.includes('id="temporalRiseInput"'), 'harness must expose temporal rise shaping');
  assert.ok(html.includes('id="temporalFallInput"'), 'harness must expose temporal fall shaping');
  assert.ok(html.includes('id="intervalInput"'), 'harness must expose inference interval control');
  assert.ok(source.includes('function shapeAlpha'), 'harness must shape alpha after model inference');
  assert.ok(source.includes('model.segment(image.data)'), 'harness must keep the King WASM bootstrap comparison path');
  assert.ok(html.includes('Foreground Mask'), 'harness must show a mask pane');
  assert.ok(html.includes('Composited Blur'), 'harness must show a result pane');
  assert.ok(source.includes('const personLayer = new OffscreenCanvas(compositeCanvas.width, compositeCanvas.height);'), 'harness must composite foreground on a separate layer');
  assert.ok(source.includes("personLayerCtx.globalCompositeOperation = 'destination-in';"), 'harness must mask only the foreground layer');
  assert.ok(source.includes('function compositeAlpha'), 'harness must suppress weak mask background before compositing');
  assert.ok(source.includes('Math.pow(normalized, 3.2)'), 'harness exclusion must strongly suppress weak background alpha');
  assert.ok(source.includes('maskImage.data[p + 3] = compositeAlpha(alpha[i] ?? 0, preset);'), 'harness must use compositor alpha instead of raw mask alpha');
  assert.ok(source.includes("compositeCtx.globalCompositeOperation = 'source-over';"), 'harness must keep the blurred background under the masked foreground');
  assert.ok(!html.includes('contrastInput'), 'harness must not expose source-image preprocessing controls');
  assert.ok(!html.includes('alphaThresholdInput'), 'harness must not expose hard mask thresholding');
  assert.ok(!source.includes('preprocessSampleFrame'), 'harness must not preprocess frames before model inference');
  assert.ok(html.includes('value="weak_blur"'), 'harness must expose thin blur');
  assert.ok(html.includes('value="hard_blur"'), 'harness must expose thick blur');
  assert.ok(html.includes('value="exclusion"'), 'harness must expose deep-blue exclusion background');
  assert.ok(!html.includes('Replace matte'), 'harness must not label exclusion as replace matte');
  assert.ok(source.includes("const EXCLUSION_BACKGROUND = '#061a4a';"), 'harness exclusion must use deep blue background');
  assert.ok(source.includes("preset === 'exclusion' ? 'replace' : preset"), 'harness must map exclusion to the existing King matte preset');
  assert.ok(source.includes("compositeCtx.globalCompositeOperation = 'copy';"), 'harness exclusion must replace the whole background canvas');
  assert.ok(source.includes('compositeCtx.fillStyle = EXCLUSION_BACKGROUND;'), 'harness exclusion must fill with deep blue before drawing foreground');
  assert.ok(!source.includes('const excludedBackground = new OffscreenCanvas(compositeCanvas.width, compositeCanvas.height);'), 'harness exclusion must not overlay an inverse blue mask over source video');
  assert.ok(!source.includes('MediaPipe'), 'harness must not use MediaPipe');
  assert.ok(!source.includes('tfjs'), 'harness must not use TFJS');
  assert.ok(!source.includes('@huggingface/transformers'), 'harness must not use Transformers.js');

  console.log('[background-segmentation-harness-contract] PASS');
} catch (error) {
  console.error(`[background-segmentation-harness-contract] FAIL: ${error.message}`);
  process.exit(1);
}
