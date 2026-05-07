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
  const controller = readUtf8('src/domain/realtime/background/controller.ts');
  const compositor = readUtf8('src/domain/realtime/background/pipeline/compositorStage.js');
  const segmenter = readUtf8('src/domain/realtime/background/pipeline/segmenterStage.js');
  const sinetBackend = readUtf8('src/domain/realtime/background/backendSinetWasm.ts');
  const localOrchestration = readUtf8('src/domain/realtime/local/mediaOrchestration.ts');
  const publisherPipeline = readUtf8('src/domain/realtime/local/publisherPipeline.ts');
  const joinPreview = readUtf8('src/domain/calls/access/joinPreview.ts');
  const dashboardEnter = readUtf8('src/domain/calls/dashboard/enterCall.ts');
  const adminEnter = readUtf8('src/domain/calls/admin/enterCall.ts');

  requireContains(stream, "import { createSinetWasmSegmentationBackend } from './backendSinetWasm';", 'background stream SINet WASM backend');
  requireContains(stream, "import { createBackgroundPipelineController } from './pipeline/controller';", 'background stream pipeline controller');
  requireContains(stream, "import { createBackgroundCompositorStage } from './pipeline/compositorStage';", 'background stream compositor stage');
  requireContains(stream, "import { createBackgroundSegmenterStage } from './pipeline/segmenterStage';", 'background stream segmenter stage');
  requireContains(stream, 'const BACKGROUND_FILTER_READY_TIMEOUT_MS = 500;', 'background stream bounded ready handoff');
  requireContains(stream, 'const ready = new Promise((resolve) => {', 'background stream readiness promise');
  requireContains(stream, 'const readyTimer = setTimeout(', 'background stream readiness timeout');
  requireContains(stream, 'segmentationBackend = await createSinetWasmSegmentationBackend({', 'background stream lazy SINet acquisition');
  requireContains(stream, "requested: 'sinet-wasm'", 'background stream diagnostics name SINet failures');
  requireContains(stream, 'maskContrast: runtimeConfig.maskContrast,', 'background stream passes mask contrast controls');
  requireContains(stream, 'averageRadius: runtimeConfig.averageRadius,', 'background stream passes Gaussian averaging controls');
  requireContains(stream, 'temporalRise: runtimeConfig.temporalRise,', 'background stream passes temporal rise controls');
  requireContains(stream, 'temporalFall: runtimeConfig.temporalFall,', 'background stream passes temporal fall controls');
  requireMissing(stream, 'acquireWorkerSegmenterBackendLease', 'background stream MediaPipe worker lease');
  requireMissing(stream, 'backendWorkerSegmenter', 'background stream MediaPipe worker backend');
  requireMissing(stream, 'backendMediapipe', 'background stream legacy MediaPipe backend import');
  requireMissing(stream, 'backendTfjs', 'background stream legacy TFJS backend import');
  requireMissing(stream, "return sourceStream, active: false, reason: 'sinet_unavailable'", 'background stream raw SINet failure fallback');

  requireContains(compositor, 'function processMaskForAlpha(mask, width, height) {', 'compositor mask postprocess boundary');
  requireContains(compositor, 'processed[i] = Math.max(0, Math.min(1, Number(mask[i]) || 0));', 'compositor preserves shaped SINet alpha values');
  requireMissing(compositor, 'const threshold = 0.5', 'compositor hard threshold');
  requireMissing(compositor, 'return blurMask(processed', 'compositor secondary blur after SINet shaping');
  requireMissing(compositor, 'if (hasMatteMask && !maskUpdated) return;', 'compositor stale-mask frame freeze');
  requireContains(compositor, "ctx.globalCompositeOperation = 'destination-in';", 'compositor foreground is cut by alpha mask');
  requireContains(compositor, "ctx.globalCompositeOperation = 'destination-over';", 'compositor draws replacement background behind cut foreground');
  requireContains(compositor, "ctx.fillStyle = resolveCanvasColor(backgroundColor, '#000010');", 'compositor solid background replacement');
  requireContains(compositor, 'ctx.fillRect(0, 0, canvas.width, canvas.height);', 'compositor solid background fills the full background');
  requireContains(compositor, 'ctx.filter = `blur(${Math.max(blurPx, 6)}px)`;', 'compositor keeps a visibly blurred fallback while mask warms');
  requireContains(compositor, 'return maskLayer?.getImageData?.(0, 0, maskCanvas.width, maskCanvas.height) || null;', 'compositor exposes matte snapshot');

  requireContains(segmenter, 'latestMaskValues = hasValueMask ? segmentation.matteMaskValues : null;', 'segmenter keeps latest value mask');
  requireContains(segmenter, 'latestMaskWidth = Math.max(1, Math.round(Number(segmentation?.matteMaskWidth) || width));', 'segmenter tracks mask width');
  requireContains(sinetBackend, "executionProviders: ['wasm']", 'SINet backend uses local WASM execution');
  requireContains(sinetBackend, 'matteMaskValues = alphaToMaskValues(alpha);', 'SINet backend returns pipeline value masks');
  requireContains(sinetBackend, 'matteMaskWidth: SINET_MODEL_WIDTH', 'SINet backend reports mask width');
  requireContains(sinetBackend, 'matteMaskHeight: SINET_MODEL_HEIGHT', 'SINet backend reports mask height');

  for (const [label, source] of [
    ['local orchestration', localOrchestration],
    ['join preview', joinPreview],
    ['dashboard enter preview', dashboardEnter],
    ['admin enter preview', adminEnter],
  ]) {
    requireContains(source, 'const blurStepPx = [8, 12, 18, 26, 34];', `${label} stronger blur steps`);
    requireContains(source, 'Math.round(blurPx * 1.55)', `${label} stronger thick blur`);
    requireContains(source, 'Math.min(64, blurPx)', `${label} stronger blur cap`);
    requireContains(source, "const isExclusionBackdrop = backdrop === 'exclusion';", `${label} exclusion selector`);
    requireContains(source, "const backgroundColor = isExclusionBackdrop", `${label} background color consolidation`);
    requireContains(source, "? '#061a4a'", `${label} deep-blue exclusion background`);
    requireContains(source, "mattePreset: isExclusionBackdrop ? 'replace' : (backdrop === 'blur9' ? 'hard_blur' : 'weak_blur')", `${label} exclusion matte mapping`);
  }

  requireContains(localOrchestration, 'refs.localFilteredStreamRef.value = stream;', 'local media stores processed background stream');
  requireContains(localOrchestration, 'refs.localStreamRef.value = stream;', 'local media publishes processed background stream');
  requireContains(localOrchestration, 'const videoTrack = stream.getVideoTracks()[0];', 'initial publisher encodes processed track');
  requireContains(localOrchestration, 'const videoTrack = nextStream.getVideoTracks()[0] || null;', 'reconfigured publisher encodes processed track');
  requireContains(publisherPipeline, 'videoTrack === activeRawVideoTrack', 'publisher detects accidental raw-track starts');
  requireContains(publisherPipeline, 'videoTrack = activeOutputVideoTrack;', 'publisher forces active processed track');
  requireContains(publisherPipeline, "eventType: 'local_background_stream_publisher_active'", 'publisher diagnostics prove processed stream is active');
  requireContains(controller, 'previousHandle.sourceStream === sourceStream', 'controller updates same stream in place');
  requireContains(controller, 'this.attachHandlePipelineListener(this.currentHandle);', 'controller forwards pipeline debug changes');
  requireContains(controller, 'getCurrentMatteMaskSnapshot()', 'controller exposes current matte snapshot');

  console.log('[background-filter-mask-contract] PASS');
} catch (error) {
  console.error(`[background-filter-mask-contract] FAIL: ${error.message}`);
  process.exit(1);
}
