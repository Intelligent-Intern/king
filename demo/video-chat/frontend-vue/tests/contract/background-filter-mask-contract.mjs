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
  assert.ok(source.includes('ctx.filter = `blur(${blurPx}px)`;'), 'background filter stream must keep the frame blurred while a fresh matte is still warming up');
  assert.ok(!source.includes('ctx.filter = "none";\n      ctx.drawImage(video, 0, 0, canvas.width, canvas.height);\n      ctx.restore();'), 'background filter stream must not fall back to raw video while blur presets switch');
  assert.ok(source.includes('ready,'), 'background filter stream handle must return the readiness promise');
  assert.ok(controller.includes('const shouldAwaitReadyHandoff = Boolean(previousHandle?.active && handle?.active);'), 'background filter controller must gate blur-to-blur swaps on ready handoff');
  assert.ok(controller.includes('await handle.ready;'), 'background filter controller must wait for the new blur stream before replacing the previous one');
  console.log('[background-filter-mask-contract] PASS');
} catch (error) {
  console.error(`[background-filter-mask-contract] FAIL: ${error.message}`);
  process.exit(1);
}
