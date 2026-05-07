import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

function fail(message) {
  throw new Error(`[background-aspect-preservation-contract] FAIL: ${message}`);
}

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);
const frontendRoot = path.resolve(__dirname, '../..');

function read(relativePath) {
  return fs.readFileSync(path.resolve(frontendRoot, relativePath), 'utf8');
}

try {
  const packageJson = read('package.json');
  const backgroundStream = read('src/domain/realtime/background/stream.ts');
  const compositorStage = read('src/domain/realtime/background/pipeline/compositorStage.js');

  assert.ok(packageJson.includes('background-aspect-preservation-contract.mjs'), 'package script must expose background aspect contract');
  assert.ok(backgroundStream.includes('function resolveVideoSourceDimensions(video, settings = {})'), 'background stream must prefer real video dimensions over track settings');
  assert.ok(backgroundStream.includes('const videoWidth = Math.max(0, Math.round(toNumber(video?.videoWidth, 0)));'), 'background stream must read portrait videoWidth after metadata');
  assert.ok(backgroundStream.includes('function syncCanvasToSourceFrame(nextSourceWidth, nextSourceHeight)'), 'background stream must resize its output canvas when mobile orientation metadata changes');
  assert.ok(backgroundStream.includes('const segmentationWidth = Math.max(1, Math.round(canvas.width));'), 'background segmentation must use the aspect-preserving canvas size');
  assert.ok(compositorStage.includes('function drawContainImage(ctx, image, width, height)'), 'background compositor must have aspect-preserving contain drawing');
  assert.ok(compositorStage.includes('function drawCoverImage(ctx, image, width, height)'), 'background compositor must have aspect-preserving cover drawing');
  assert.ok(!compositorStage.includes('ctx.drawImage(video, 0, 0, canvas.width, canvas.height);'), 'background compositor must not stretch mobile portrait video directly into landscape canvas');

  process.stdout.write('[background-aspect-preservation-contract] PASS\n');
} catch (error) {
  if (error instanceof Error) {
    fail(error.message);
  }
  fail('unknown failure');
}
