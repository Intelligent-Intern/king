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
  assert.equal(source.includes(needle), false, `${label} must not contain: ${needle}`);
}

try {
  const stream = readUtf8('src/domain/realtime/background/stream.ts');
  const maskPostprocess = readUtf8('src/domain/realtime/background/maskPostprocess.js');
  const compositorStage = readUtf8('src/domain/realtime/background/pipeline/compositorStage.js');
  const compositorShared = readUtf8('src/domain/realtime/background/pipeline/compositorShared.js');
  const compositorCanvas = readUtf8('src/domain/realtime/background/pipeline/compositorCanvasStage.js');
  const compositorWebgl = readUtf8('src/domain/realtime/background/pipeline/compositorWebglStage.js');
  const controller = readUtf8('src/domain/realtime/background/controller.ts');
  const segmenter = readUtf8('src/domain/realtime/background/pipeline/segmenterStage.js');
  const sinetBackend = readUtf8('src/domain/realtime/background/backendSinetWasm.js');

  requireContains(maskPostprocess, 'const DEFAULT_INNER_CONTRACT_PX = 16;', 'background filter contour contraction');
  requireContains(maskPostprocess, 'const DEFAULT_INNER_FEATHER_PX = 24;', 'background filter contour feather');
  requireContains(maskPostprocess, 'function smoothstep(edge0, edge1, value)', 'background filter contour-only smoothing');
  requireContains(maskPostprocess, 'const edgeLow = 0.5 - contourHalfWidth', 'background filter contour low edge');
  requireContains(maskPostprocess, 'function sampleInnerFeatherRamp(progress) {', 'background filter stepped feather ramp');
  requireContains(maskPostprocess, 'function buildInnerDistanceFeatherAlpha(base, width, height, threshold = 110) {', 'shared contour shaping helper');
  requireContains(maskPostprocess, 'const inside = sampleInnerFeatherRamp(t);', 'stepped feather ramp application');
  requireMissing(maskPostprocess, '(raw - 0.5) * contrast + 0.5', 'mask global contrast alpha lift');

  requireContains(compositorShared, 'buildInnerDistanceFeatherAlpha(sourceAlpha, sourceWidth, sourceHeight)', 'bitmap matte contour shaping');
  requireContains(compositorShared, 'return buildInnerDistanceFeatherMaskValues(mask, width, height);', 'value matte contour shaping');
  requireMissing(compositorShared, 'function blurMask(', 'secondary contour blur');
  requireContains(compositorStage, 'createWebGlBackgroundCompositorStage(options)', 'WebGL compositor preference');
  requireContains(compositorStage, 'createCanvasBackgroundCompositorStage(options)', 'canvas compositor fallback');
  requireContains(compositorCanvas, 'getShowSourceUntilMask?.() === true', 'canvas preview warmup source policy');
  requireContains(compositorCanvas, "ctx.fillStyle = '#061a4a';", 'canvas replacement warmup privacy placeholder');
  requireMissing(compositorCanvas, 'if (hasMatteMask && !maskUpdated) return;', 'canvas stale-mask frame freeze');
  requireContains(compositorWebgl, 'float maskAlpha = uHasMask == 1 ? readMask(vUv) : 0.0;', 'WebGL direct shaped alpha mask');
  requireContains(compositorWebgl, "const warmupPlaceholder = !hasRenderableMask && mode === 'replace' && !showSourceUntilMask;", 'WebGL replacement warmup privacy placeholder');
  requireMissing(compositorWebgl, 'smoothstep(uMaskLow, uMaskHigh', 'WebGL shader global mask blending');
  requireMissing(compositorWebgl, 'if (hasMatteMask && !maskUpdated', 'WebGL stale-mask frame freeze');

  requireContains(stream, "import { createSinetWasmSegmentationBackend } from './backendSinetWasm';", 'background stream SINet WASM backend');
  requireContains(stream, 'const BACKGROUND_FILTER_READY_TIMEOUT_MS = 500;', 'background stream bounded ready handoff');
  requireContains(stream, 'const ready = new Promise((resolve) => {', 'background stream readiness promise');
  requireContains(stream, 'const readyTimer = setTimeout(', 'background stream readiness timeout');
  requireContains(stream, 'segmentationBackend = await createSinetWasmSegmentationBackend({', 'background stream lazy SINet acquisition');
  requireContains(stream, "requested: 'sinet-wasm'", 'background stream SINet diagnostics name');
  requireContains(stream, 'maskContrast: runtimeConfig.maskContrast,', 'background stream mask contrast controls');
  requireContains(stream, 'averageRadius: runtimeConfig.averageRadius,', 'background stream Gaussian averaging controls');
  requireContains(stream, 'temporalRise: runtimeConfig.temporalRise,', 'background stream temporal rise controls');
  requireContains(stream, 'temporalFall: runtimeConfig.temporalFall,', 'background stream temporal fall controls');
  requireContains(stream, 'getShowSourceUntilMask: () => runtimeConfig.showSourceUntilMask,', 'background stream preview warmup policy');
  requireContains(stream, "Object.prototype.hasOwnProperty.call(nextOptions, 'showSourceUntilMask')", 'background stream preserves preview warmup policy on config update');
  requireMissing(stream, 'acquireWorkerSegmenterBackendLease', 'background stream MediaPipe worker lease');
  requireMissing(stream, 'backendWorkerSegmenter', 'background stream MediaPipe worker backend');
  requireMissing(stream, 'backendMediapipe', 'background stream legacy MediaPipe backend import');
  requireMissing(stream, 'backendTfjs', 'background stream legacy TFJS backend import');
  requireContains(stream, 'ready,', 'background filter stream handle readiness promise');
  requireContains(controller, 'await handle.ready;', 'background filter controller ready handoff');
  requireContains(segmenter, 'latestMaskValues = hasValueMask ? segmentation.matteMaskValues : null;', 'segmenter keeps latest value mask');
  requireContains(sinetBackend, "executionProviders: ['wasm']", 'SINet backend local WASM execution');
  requireContains(sinetBackend, 'pendingMaskValues = alphaToFloatMask(alpha);', 'SINet backend value masks');
  requireContains(sinetBackend, 'function binaryForegroundAlpha(value, threshold = 0)', 'SINet no-softmax foreground classification');
  requireMissing(sinetBackend, 'Math.exp(bg - max)', 'SINet softmax');
  requireMissing(sinetBackend, '1 / (1 + Math.exp', 'SINet sigmoid');

  console.log('[background-filter-mask-contract] PASS');
} catch (error) {
  console.error(`[background-filter-mask-contract] FAIL: ${error.message}`);
  process.exit(1);
}
