import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';

function fail(message) {
  throw new Error(`[sfu-dom-canvas-last-resort-contract] FAIL: ${message}`);
}

function requireContains(source, needle, label) {
  assert.ok(source.includes(needle), `${label} missing: ${needle}`);
}

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);
const frontendRoot = path.resolve(__dirname, '../..');

function read(relativePath) {
  return fs.readFileSync(path.resolve(frontendRoot, relativePath), 'utf8');
}

try {
  const policySource = read('src/domain/realtime/local/domCanvasFallbackPolicy.js');
  const sourceReadback = read('src/domain/realtime/local/publisherSourceReadback.js');
  const frameTrace = read('src/domain/realtime/local/publisherFrameTrace.js');
  const packageJson = read('package.json');

  requireContains(policySource, 'DOM_CANVAS_COMPATIBILITY_SOURCE_BACKEND', 'DOM compatibility source label');
  requireContains(policySource, 'dom_canvas_compatibility_fallback', 'DOM fallback compatibility backend id');
  requireContains(policySource, 'DOM_CANVAS_COMPATIBILITY_MAX_FRAME_WIDTH = 854', 'DOM fallback max width cap');
  requireContains(policySource, 'DOM_CANVAS_COMPATIBILITY_MAX_FRAME_HEIGHT = 480', 'DOM fallback max height cap');
  requireContains(policySource, 'DOM_CANVAS_COMPATIBILITY_MAX_FPS = 4', 'DOM fallback FPS cap');
  requireContains(policySource, 'domCanvasCompatibilityReadbackIntervalMs', 'DOM fallback readback interval cap');

  requireContains(sourceReadback, 'resolveDomCanvasCompatibilityFrameSize(video, activeProfile, activeTrack)', 'DOM video fallback uses compatibility frame size');
  requireContains(sourceReadback, 'resolveDomCanvasCompatibilityVideoFrameSize(source, activeProfile, resolvePublisherFramingTarget(video))', 'VideoFrame canvas fallback uses compatibility frame size with active framing target');
  requireContains(sourceReadback, 'domCanvasCompatibilityReadbackIntervalMs(activeProfile)', 'DOM canvas fallback throttles readback FPS');
  requireContains(sourceReadback, "'dom_canvas_compatibility_throttle'", 'DOM canvas fallback traces throttled skipped reads');
  requireContains(sourceReadback, 'DOM_CANVAS_COMPATIBILITY_READBACK_METHOD', 'DOM canvas fallback labels readback method');
  requireContains(sourceReadback, 'dom_canvas_compatibility_draw_budget_exceeded', 'DOM fallback distinguishes compatibility draw pressure');
  requireContains(sourceReadback, 'dom_canvas_compatibility_get_image_data_budget_exceeded', 'DOM fallback distinguishes compatibility readback pressure');
  assert.ok(
    sourceReadback.indexOf('copyVideoFrameToRgbaImageData({') < sourceReadback.indexOf('captureWorkerReadback.readFrame({'),
    'copyTo must remain before worker fallback',
  );
  assert.ok(
    sourceReadback.indexOf('captureWorkerReadback.readFrame({') < sourceReadback.indexOf('const imageData = context.getImageData('),
    'worker fallback must remain before DOM canvas fallback',
  );

  requireContains(frameTrace, 'trace_dom_canvas_compatibility_draw_image_ms', 'trace exposes DOM compatibility draw timing');
  requireContains(frameTrace, 'trace_dom_canvas_compatibility_get_image_data_ms', 'trace exposes DOM compatibility readback timing');
  requireContains(frameTrace, 'trace_dom_canvas_compatibility_throttle_ms', 'trace exposes DOM compatibility throttle timing');
  requireContains(packageJson, 'sfu-dom-canvas-last-resort-contract.mjs', 'SFU suite includes DOM last-resort proof');

  const policyUrl = pathToFileURL(path.resolve(frontendRoot, 'src/domain/realtime/local/domCanvasFallbackPolicy.js')).href;
  const policy = await import(policyUrl);
  const profile = {
    frameWidth: 1280,
    frameHeight: 720,
    captureFrameRate: 27,
    encodeIntervalMs: 92,
  };
  const compatibilityProfile = policy.resolveDomCanvasCompatibilityProfile(profile);
  assert.equal(compatibilityProfile.frameWidth, 854);
  assert.equal(compatibilityProfile.frameHeight, 480);
  assert.equal(compatibilityProfile.captureFrameRate, 4);
  assert.ok(compatibilityProfile.encodeIntervalMs >= 250);
  const portrait = policy.resolveDomCanvasCompatibilityVideoFrameSize({
    displayWidth: 720,
    displayHeight: 1280,
  }, profile);
  assert.equal(portrait.frameHeight, 480);
  assert.ok(portrait.frameWidth < 480, 'portrait DOM fallback must preserve aspect ratio inside capped frame');

  process.stdout.write('[sfu-dom-canvas-last-resort-contract] PASS\n');
} catch (error) {
  if (error instanceof Error) {
    fail(error.message);
  }
  fail('unknown failure');
}
