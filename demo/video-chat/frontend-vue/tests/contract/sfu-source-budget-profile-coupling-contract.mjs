import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { createServer } from 'vite';

function fail(message) {
  throw new Error(`[sfu-source-budget-profile-coupling-contract] FAIL: ${message}`);
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
  const workspaceConfig = read('src/domain/realtime/workspace/config.js');
  const mediaOrchestration = read('src/domain/realtime/local/mediaOrchestration.js');
  const publisherPipeline = read('src/domain/realtime/local/publisherPipeline.js');
  const sourceReadback = read('src/domain/realtime/local/publisherSourceReadback.js');
  const publisherFrameTrace = read('src/domain/realtime/local/publisherFrameTrace.js');

  requireContains(workspaceConfig, 'readbackFrameRate', 'profiles define readback FPS');
  requireContains(workspaceConfig, 'readbackIntervalMs', 'profiles define readback interval');
  requireContains(mediaOrchestration, 'frameRate: { ideal: videoProfile.captureFrameRate, max: videoProfile.captureFrameRate }', 'capture constraints hard-cap profile FPS');
  requireContains(mediaOrchestration, 'requested_readback_frame_rate', 'capture diagnostics include readback FPS');
  requireContains(mediaOrchestration, 'requested_keyframe_interval', 'capture diagnostics include keyframe cadence');
  requireContains(mediaOrchestration, 'requested_wire_budget_bytes_per_second', 'capture diagnostics include wire budget');
  requireContains(publisherPipeline, 'resolveProfileReadbackIntervalMs(videoProfile)', 'publisher tick cadence uses profile readback interval');
  requireContains(sourceReadback, 'resolveProfileReadbackIntervalMs(activeProfile)', 'source readback timeouts use active profile interval');
  requireContains(publisherFrameTrace, 'readback_frame_rate', 'transport metrics include readback FPS');
  requireContains(publisherFrameTrace, 'readback_interval_ms', 'transport metrics include readback interval');
  requireContains(publisherFrameTrace, 'keyframe_interval', 'transport metrics include keyframe cadence');

  const server = await createServer({
    root: frontendRoot,
    logLevel: 'error',
    server: { middlewareMode: true, hmr: false },
  });

  try {
    const config = await server.ssrLoadModule('/src/domain/realtime/workspace/config.js');
    const { resolveProfileReadbackIntervalMs } = await server.ssrLoadModule('/src/domain/realtime/local/videoFrameSizing.js');
    const profiles = config.SFU_VIDEO_QUALITY_PROFILES;

    for (const profileId of ['rescue', 'realtime', 'balanced', 'quality']) {
      const profile = profiles[profileId];
      assert.ok(profile.captureWidth > 0 && profile.captureHeight > 0, `${profileId} capture dimensions must be positive`);
      assert.ok(profile.captureFrameRate > 0, `${profileId} capture FPS must be positive`);
      assert.ok(profile.readbackFrameRate > 0, `${profileId} readback FPS must be positive`);
      assert.ok(profile.readbackIntervalMs > 0, `${profileId} readback interval must be positive`);
      assert.ok(profile.keyFrameInterval > 0, `${profileId} keyframe cadence must be positive`);
      assert.ok(profile.maxWireBytesPerSecond > 0, `${profileId} wire budget must be positive`);
      assert.ok(profile.readbackFrameRate <= profile.captureFrameRate, `${profileId} readback FPS must not exceed capture FPS`);
      assert.equal(resolveProfileReadbackIntervalMs(profile), profile.readbackIntervalMs, `${profileId} readback resolver must return coupled profile interval`);
      assert.equal(profile.readbackIntervalMs, profile.encodeIntervalMs, `${profileId} readback and encode tick must advance together`);
      assert.equal(Number((1000 / profile.readbackIntervalMs).toFixed(3)), profile.readbackFrameRate, `${profileId} readback FPS must derive from interval`);
    }

    assert.ok(profiles.rescue.captureFrameRate < profiles.realtime.captureFrameRate, 'capture FPS must increase from rescue to realtime');
    assert.ok(profiles.realtime.captureFrameRate < profiles.balanced.captureFrameRate, 'capture FPS must increase from realtime to balanced');
    assert.ok(profiles.balanced.captureFrameRate < profiles.quality.captureFrameRate, 'capture FPS must increase from balanced to quality');
    assert.ok(profiles.rescue.readbackFrameRate < profiles.realtime.readbackFrameRate, 'readback FPS must increase from rescue to realtime');
    assert.ok(profiles.realtime.readbackFrameRate < profiles.balanced.readbackFrameRate, 'readback FPS must increase from realtime to balanced');
    assert.ok(profiles.balanced.readbackFrameRate < profiles.quality.readbackFrameRate, 'readback FPS must increase from balanced to quality');
    assert.ok(profiles.rescue.keyFrameInterval >= profiles.realtime.keyFrameInterval, 'lower profiles must not request more frequent keyframes');
    assert.ok(profiles.balanced.keyFrameInterval >= profiles.quality.keyFrameInterval, 'quality profile may use tighter keyframes');
    assert.ok(profiles.rescue.maxWireBytesPerSecond < profiles.realtime.maxWireBytesPerSecond, 'wire budget must increase from rescue to realtime');
    assert.ok(profiles.realtime.maxWireBytesPerSecond < profiles.balanced.maxWireBytesPerSecond, 'wire budget must increase from realtime to balanced');
    assert.ok(profiles.balanced.maxWireBytesPerSecond < profiles.quality.maxWireBytesPerSecond, 'wire budget must increase from balanced to quality');

    process.stdout.write('[sfu-source-budget-profile-coupling-contract] PASS\n');
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
