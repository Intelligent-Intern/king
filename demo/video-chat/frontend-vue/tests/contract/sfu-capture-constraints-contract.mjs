import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { createServer } from 'vite';

function fail(message) {
  throw new Error(`[sfu-capture-constraints-contract] FAIL: ${message}`);
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

async function main() {
  const mediaOrchestration = read('src/domain/realtime/local/mediaOrchestration.js');
  const mediaStack = read('src/domain/realtime/workspace/callWorkspace/mediaStack.js');
  const publisherPipeline = read('src/domain/realtime/local/publisherPipeline.js');
  const lifecycle = read('src/domain/realtime/workspace/callWorkspace/lifecycle.js');

  requireContains(mediaStack, 'captureClientDiagnostic: callbacks.captureClientDiagnostic', 'media stack passes diagnostics into local media orchestration');
  requireContains(mediaOrchestration, 'videoTrack.getSettings()', 'local media orchestration reads browser-reported track settings');
  requireContains(mediaOrchestration, 'sfu_local_capture_constraints_applied', 'local media orchestration reports applied capture settings');
  requireContains(mediaOrchestration, 'stale_hd_capture_after_downgrade', 'local media orchestration flags stale HD capture after downgrade');
  requireContains(mediaOrchestration, "reportLocalCaptureSettings(rawStream, 'publish')", 'initial publish reports track settings');
  requireContains(mediaOrchestration, "reportLocalCaptureSettings(nextRawStream, 'reconfigure')", 'profile/device reconfigure reports track settings');
  requireContains(lifecycle, 'void reconfigureLocalTracksFromSelectedDevices();', 'quality profile change reconfigures local tracks');
  requireContains(publisherPipeline, "import { resolvePublisherFrameSize } from './videoFrameSizing';", 'publisher uses aspect-preserving source frame sizing');
  requireContains(publisherPipeline, 'frame_width: frameSize.frameWidth', 'publisher telemetry reports actual WLVC frame width');
  requireContains(publisherPipeline, 'frame_height: frameSize.frameHeight', 'publisher telemetry reports actual WLVC frame height');
  requireContains(publisherPipeline, 'profile_frame_width: frameSize.profileFrameWidth', 'publisher telemetry keeps profile frame width');
  requireContains(publisherPipeline, 'source_aspect_ratio: Number(frameSize.sourceAspectRatio.toFixed(6))', 'publisher telemetry reports source aspect ratio');

  const server = await createServer({
    root: frontendRoot,
    logLevel: 'error',
    server: { middlewareMode: true, hmr: false },
  });

  try {
    const { SFU_VIDEO_QUALITY_PROFILES } = await server.ssrLoadModule('/src/domain/realtime/workspace/config.js');
    const { resolveContainFrameSizeFromDimensions } = await server.ssrLoadModule('/src/domain/realtime/local/videoFrameSizing.js');
    const quality = SFU_VIDEO_QUALITY_PROFILES.quality;
    const realtime = SFU_VIDEO_QUALITY_PROFILES.realtime;
    const rescue = SFU_VIDEO_QUALITY_PROFILES.rescue;

    assert.ok(realtime.captureWidth < quality.captureWidth, 'realtime capture width must downscale below quality');
    assert.ok(realtime.captureHeight < quality.captureHeight, 'realtime capture height must downscale below quality');
    assert.ok(realtime.captureFrameRate < quality.captureFrameRate, 'realtime capture fps must downscale below quality');
    assert.ok(rescue.captureWidth <= realtime.captureWidth, 'rescue capture width must not exceed realtime');
    assert.ok(rescue.captureHeight <= realtime.captureHeight, 'rescue capture height must not exceed realtime');
    assert.ok(rescue.captureFrameRate < realtime.captureFrameRate, 'rescue capture fps must downscale below realtime');
    assert.ok(realtime.frameWidth < quality.frameWidth, 'realtime publisher frame width must downscale below quality');
    assert.ok(rescue.frameWidth < realtime.frameWidth, 'rescue publisher frame width must downscale below realtime');

    const portrait = resolveContainFrameSizeFromDimensions(720, 1280, quality.frameWidth, quality.frameHeight);
    assert.equal(portrait.frameWidth, 404, 'portrait sources must preserve aspect ratio inside the quality frame budget');
    assert.equal(portrait.frameHeight, 720, 'portrait sources must use the quality profile height budget');
    assert.equal(portrait.aspectMode, 'source_contain', 'portrait sources must not be stretched into the profile aspect ratio');

    const landscape = resolveContainFrameSizeFromDimensions(1280, 720, quality.frameWidth, quality.frameHeight);
    assert.equal(landscape.frameWidth, 1280, 'landscape sources keep the full quality width');
    assert.equal(landscape.frameHeight, 720, 'landscape sources keep the full quality height');

    process.stdout.write('[sfu-capture-constraints-contract] PASS\n');
  } finally {
    await server.close();
  }
}

main().catch((error) => {
  if (error instanceof Error) {
    fail(error.message);
  }
  fail('unknown failure');
});
