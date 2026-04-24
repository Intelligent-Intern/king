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
  assert.ok(source.includes('const DEFAULT_INNER_CONTRACT_PX = 20;'), 'background filter must contract the matte around 20px inward from the detected contour');
  assert.ok(source.includes('const DEFAULT_INNER_FEATHER_PX = 48;'), 'background filter must keep a longer feathered edge for slower blur falloff');
  assert.ok(source.includes('const DEFAULT_INNER_FEATHER_CURVE = 1.18;'), 'background filter must use a flatter feather curve');
  assert.ok(source.includes('function buildInnerDistanceFeatherAlpha(base, width, height, threshold = 110) {'), 'background filter must centralize contour shaping in a shared helper');
  assert.ok(source.includes('const outFastAlpha = buildInnerDistanceFeatherAlpha(base, width, height);'), 'fast matte path must apply the shared contour shaping');
  assert.ok(source.includes('const outAlpha = buildInnerDistanceFeatherAlpha(base, width, height);'), 'full matte path must apply the shared contour shaping');
  console.log('[background-filter-mask-contract] PASS');
} catch (error) {
  console.error(`[background-filter-mask-contract] FAIL: ${error.message}`);
  process.exit(1);
}
