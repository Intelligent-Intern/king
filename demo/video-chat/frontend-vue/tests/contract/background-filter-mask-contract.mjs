import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);
const frontendRoot = path.resolve(__dirname, '../..');

function readUtf8(file) {
  return fs.readFileSync(file, 'utf8');
}

try {
  const source = readUtf8(path.join(frontendRoot, 'src/domain/realtime/background/stream.ts'));
  const controller = readUtf8(path.join(frontendRoot, 'src/domain/realtime/background/controller.ts'));
  const localOrchestration = readUtf8(path.join(frontendRoot, 'src/domain/realtime/local/mediaOrchestration.ts'));
  const joinPreview = readUtf8(path.join(frontendRoot, 'src/domain/calls/access/joinPreview.ts'));
  const dashboardEnter = readUtf8(path.join(frontendRoot, 'src/domain/calls/dashboard/enterCall.ts'));
  const adminEnter = readUtf8(path.join(frontendRoot, 'src/domain/calls/admin/enterCall.ts'));
  const harness = readUtf8(path.join(frontendRoot, 'tests/standalone/king-background-segmentation-harness.ts'));
  assert.ok(source.includes('const DEFAULT_INNER_CONTRACT_PX = 16;'), 'background filter must contract the matte around 16px inward from the detected contour');
  assert.ok(source.includes('const DEFAULT_INNER_FEATHER_PX = 24;'), 'background filter must keep the feathered edge half as wide for a faster blur falloff');
  assert.ok(source.includes("{ progress: 0.0, alpha: 0.05 }"), 'background filter must start the inner feather ramp near 5 percent alpha');
  assert.ok(source.includes("{ progress: 0.2, alpha: 0.15 }"), 'background filter must include the 15 percent inner feather stop');
  assert.ok(source.includes("{ progress: 0.4, alpha: 0.4 }"), 'background filter must include the 40 percent inner feather stop');
  assert.ok(source.includes("{ progress: 0.6, alpha: 0.7 }"), 'background filter must include the 70 percent inner feather stop');
  assert.ok(source.includes("{ progress: 0.8, alpha: 0.9 }"), 'background filter must include the 90 percent inner feather stop');
  assert.ok(source.includes("{ progress: 1.0, alpha: 1.0 }"), 'background filter must end the inner feather ramp at full opacity');
  assert.ok(source.includes('function sampleInnerFeatherRamp(progress) {'), 'background filter must centralize the stepped inner feather ramp');
  assert.ok(source.includes('function buildInnerDistanceFeatherAlpha(base, width, height, threshold = 110) {'), 'background filter must centralize contour shaping in a shared helper');
  assert.ok(source.includes('const inside = sampleInnerFeatherRamp(t);'), 'background filter must use the stepped feather ramp when shaping contour alpha');
  assert.ok(source.includes('const outFastAlpha = buildInnerDistanceFeatherAlpha(base, width, height);'), 'fast matte path must apply the shared contour shaping');
  assert.ok(source.includes('const outAlpha = buildInnerDistanceFeatherAlpha(base, width, height);'), 'full matte path must apply the shared contour shaping');
  assert.ok(source.includes('const BACKGROUND_FILTER_READY_TIMEOUT_MS = 500;'), 'background filter stream must bound blur handoff readiness waits');
  assert.ok(source.includes('const ready = new Promise((resolve) => {'), 'background filter stream must expose a readiness promise');
  assert.ok(source.includes('const readyTimer = setTimeout(markReady, Math.max(BACKGROUND_FILTER_READY_TIMEOUT_MS, detectIntervalMs + 100));'), 'background filter stream must time out readiness if segmentation is slow');
  assert.ok(source.includes('function drawVideoToDownsampleBlurCache('), 'background filter stream must use a filter-independent downsample blur cache');
  assert.ok(source.includes('function drawVideoWithDownsampleBlur('), 'background filter stream must use downsample blur while a fresh matte is still warming up');
  assert.ok(source.includes('function createNoMatteSegmentationBackend('), 'background filter stream must keep a local compositor when SINet is unavailable');
  assert.ok(source.includes('segmentationBackend = createNoMatteSegmentationBackend("sinet_unavailable");'), 'SINet setup failure must not return the raw camera stream');
  assert.ok(source.includes('drawVideoToDownsampleBlurCache(backgroundLayerCanvas, backgroundLayer, video, canvas.width, canvas.height, blurPx);'), 'background blur must refresh through the downsample cache');
  assert.ok(source.includes('drawVideoWithDownsampleBlur(ctx, video, backgroundLayerCanvas, backgroundLayer, canvas.width, canvas.height, blurPx);'), 'background filter stream must keep the frame visibly blurred while a fresh matte is still warming up');
  assert.ok(!source.includes('ctx.filter = `blur(${blurPx}px)`;'), 'background blur must not rely on CanvasRenderingContext2D.filter support');
  assert.ok(!source.includes('backgroundLayer.filter = `blur('), 'background blur cache must not rely on CanvasRenderingContext2D.filter support');
  assert.ok(source.includes('if (!backgroundImage && !backgroundColor) {'), 'solid/image backgrounds must not draw raw or blurred camera frames while a fresh matte is still warming up');
  assert.ok(source.includes('const canRunSegmentation = !overloadDisabled && now >= overloadCooldownUntil;'), 'background overload may pause SINet detection but must keep the active compositor running');
  assert.ok(!source.includes('if (overloadDisabled) {\n      const vwFast = video.videoWidth || canvas.width;'), 'background overload must not install a raw-camera pass-through branch');
  assert.ok(harness.includes("preset === 'weak_blur' ? 'blur(14px)' : 'blur(32px)'"), 'standalone harness must use stronger thin/thick blur previews');
  for (const [label, file] of [
    ['local orchestration', localOrchestration],
    ['join preview', joinPreview],
    ['dashboard enter preview', dashboardEnter],
    ['admin enter preview', adminEnter],
  ]) {
    assert.ok(file.includes('const blurStepPx = [8, 12, 18, 26, 34];'), `${label} must use stronger blur steps`);
    assert.ok(file.includes('Math.round(blurPx * 1.55)'), `${label} must ramp thick blur above the thin blur levels`);
    assert.ok(file.includes('Math.min(64, blurPx)'), `${label} must allow stronger blur cap`);
    assert.ok(file.includes("const isExclusionBackdrop = backdrop === 'exclusion';"), `${label} must detect the solid blue exclusion backdrop`);
    assert.ok(file.includes("backgroundColor: isExclusionBackdrop ? '#061a4a' : ''"), `${label} must pass the deep-blue replacement color`);
    assert.ok(file.includes("mattePreset: isExclusionBackdrop ? 'replace' : (backdrop === 'blur9' ? 'hard_blur' : 'weak_blur')"), `${label} must map exclusion to replace matte and keep blur presets`);
  }
  assert.ok(localOrchestration.includes('refs.localFilteredStreamRef.value = stream;'), 'local media must store the processed background stream');
  assert.ok(localOrchestration.includes('refs.localStreamRef.value = stream;'), 'local media must publish the processed background stream');
  assert.ok(localOrchestration.includes('const videoTrack = stream.getVideoTracks()[0];'), 'initial local publisher must encode the processed stream video track');
  assert.ok(localOrchestration.includes('const videoTrack = nextStream.getVideoTracks()[0] || null;'), 'background reconfigure must encode the replacement processed stream video track');
  assert.ok(localOrchestration.includes("eventType: 'local_background_sinet_unavailable'"), 'local media must emit a diagnostic if SINet cannot initialize in the call path');
  assert.ok(!source.includes('ctx.filter = "none";\n      ctx.drawImage(video, 0, 0, canvas.width, canvas.height);\n      ctx.restore();'), 'background filter stream must not fall back to raw video while blur presets switch');
  assert.ok(source.includes('ready,'), 'background filter stream handle must return the readiness promise');
  assert.ok(controller.includes('const shouldAwaitReadyHandoff = Boolean(previousHandle?.active && handle?.active);'), 'background filter controller must gate blur-to-blur swaps on ready handoff');
  assert.ok(controller.includes('await handle.ready;'), 'background filter controller must wait for the new blur stream before replacing the previous one');
  console.log('[background-filter-mask-contract] PASS');
} catch (error) {
  console.error(`[background-filter-mask-contract] FAIL: ${error.message}`);
  process.exit(1);
}
