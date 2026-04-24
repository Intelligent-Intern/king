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
  const source = readUtf8(path.join(frontendRoot, 'src/domain/realtime/backgroundFilterStream.js'));
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
  console.log('[background-filter-mask-contract] PASS');
} catch (error) {
  console.error(`[background-filter-mask-contract] FAIL: ${error.message}`);
  process.exit(1);
}
