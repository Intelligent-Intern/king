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
  const captureProfileConstraints = read('src/domain/realtime/local/sfuCaptureProfileConstraints.js');
  const mediaStack = read('src/domain/realtime/workspace/callWorkspace/mediaStack.js');
  const publisherPipeline = read('src/domain/realtime/local/publisherPipeline.js');
  const publisherFrameTrace = read('src/domain/realtime/local/publisherFrameTrace.js');
  const audioCaptureConstraints = read('src/domain/realtime/media/audioCaptureConstraints.js');
  const accessJoinView = read('src/domain/calls/access/JoinView.vue');
  const dashboardEnterCall = read('src/domain/calls/dashboard/enterCall.js');
  const adminEnterCall = read('src/domain/calls/admin/enterCall.js');
  const workspaceShell = read('src/layouts/WorkspaceShell.vue');
  const mediaPreferences = read('src/domain/realtime/media/preferences.js');
  const publisherTelemetry = `${publisherPipeline}\n${publisherFrameTrace}`;
  const lifecycle = read('src/domain/realtime/workspace/callWorkspace/lifecycle.js');

  requireContains(mediaStack, 'captureClientDiagnostic: callbacks.captureClientDiagnostic', 'media stack passes diagnostics into local media orchestration');
  requireContains(captureProfileConstraints, 'videoTrack.getSettings()', 'capture profile constraints read browser-reported track settings');
  requireContains(captureProfileConstraints, 'buildSfuVideoProfileTrackConstraints', 'capture profile constraints build hard track constraints');
  requireContains(captureProfileConstraints, 'videoTrack.applyConstraints(constraints)', 'capture profile constraints enforce the active profile on the real camera track');
  requireContains(captureProfileConstraints, 'sfu_capture_track_constraints_enforced', 'capture profile constraints report successful track enforcement');
  requireContains(captureProfileConstraints, 'sfu_capture_track_constraints_failed', 'capture profile constraints report failed track enforcement');
  requireContains(mediaOrchestration, 'frameRate: { ideal: videoProfile.captureFrameRate, max: videoProfile.captureFrameRate }', 'local media constraints cap capture FPS to automatic profile');
  requireContains(mediaOrchestration, "await enforceSfuVideoCaptureProfile(stream, 'strict')", 'strict local media acquisition enforces capture constraints after browser selection');
  requireContains(mediaOrchestration, "await enforceSfuVideoCaptureProfile(stream, 'loose_retry')", 'loose local media retry enforces capture constraints after browser selection');
  requireContains(mediaOrchestration, "await enforceSfuVideoCaptureProfile(stream, 'boolean_fallback')", 'boolean getUserMedia fallback still enforces capture constraints after browser selection');
  requireContains(captureProfileConstraints, 'sfu_local_capture_constraints_applied', 'capture profile constraints report applied capture settings');
  requireContains(mediaOrchestration, 'buildOptionalCallAudioCaptureConstraints(wantsAudio, microphoneDeviceId)', 'local media constraints request browser echo cancellation for selected microphones');
  requireContains(mediaOrchestration, 'buildOptionalCallAudioCaptureConstraints(wantsAudio)', 'loose local media retry keeps echo cancellation enabled');
  requireContains(audioCaptureConstraints, 'audio_echo_cancellation', 'capture diagnostics report applied echo-cancellation settings');
  requireContains(audioCaptureConstraints, 'echoCancellation: true', 'call audio capture requests echo cancellation');
  requireContains(audioCaptureConstraints, 'noiseSuppression: true', 'call audio capture requests noise suppression');
  requireContains(audioCaptureConstraints, 'autoGainControl: true', 'call audio capture requests automatic gain control');
  requireContains(audioCaptureConstraints, 'channelCount: { ideal: 1 }', 'call audio capture requests mono voice input');
  for (const [label, source] of Object.entries({
    accessJoinView,
    dashboardEnterCall,
    adminEnterCall,
    workspaceShell,
    mediaPreferences,
  })) {
    requireContains(source, 'buildOptionalCallAudioCaptureConstraints', `${label} uses shared call audio capture constraints`);
    assert.equal(source.includes('echoCancellation: false'), false, `${label} must not disable browser echo cancellation`);
  }
  requireContains(captureProfileConstraints, 'stale_hd_capture_after_downgrade', 'local capture diagnostics flag stale HD capture after downgrade');
  requireContains(mediaOrchestration, "reportLocalCaptureSettings(rawStream, 'publish')", 'initial publish reports track settings');
  requireContains(mediaOrchestration, "reportLocalCaptureSettings(nextRawStream, 'reconfigure')", 'profile/device reconfigure reports track settings');
  requireContains(lifecycle, 'void reconfigureLocalTracksFromSelectedDevices();', 'automatic quality profile change reconfigures local tracks');
  requireContains(publisherPipeline, "resolvePublisherFrameSize } from './videoFrameSizing';", 'publisher uses aspect-preserving source frame sizing');
  requireContains(publisherTelemetry, 'frame_width: frameSize.frameWidth', 'publisher telemetry reports actual WLVC frame width');
  requireContains(publisherTelemetry, 'frame_height: frameSize.frameHeight', 'publisher telemetry reports actual WLVC frame height');
  requireContains(publisherTelemetry, 'profile_frame_width: frameSize.profileFrameWidth', 'publisher telemetry keeps profile frame width');
  requireContains(publisherTelemetry, 'source_aspect_ratio: Number(frameSize.sourceAspectRatio.toFixed(6))', 'publisher telemetry reports source aspect ratio');

  const server = await createServer({
    root: frontendRoot,
    logLevel: 'error',
    server: { middlewareMode: true, hmr: false },
  });

  try {
    const { SFU_VIDEO_QUALITY_PROFILES } = await server.ssrLoadModule('/src/domain/realtime/workspace/config.js');
    const { resolveContainFrameSizeFromDimensions } = await server.ssrLoadModule('/src/domain/realtime/local/videoFrameSizing.js');
    const { buildCallAudioCaptureConstraints } = await server.ssrLoadModule('/src/domain/realtime/media/audioCaptureConstraints.js');
    const {
      applySfuVideoProfileConstraintsToStream,
      buildSfuVideoProfileTrackConstraints,
    } = await server.ssrLoadModule('/src/domain/realtime/local/sfuCaptureProfileConstraints.js');
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

    const defaultAudio = buildCallAudioCaptureConstraints();
    assert.equal(defaultAudio.echoCancellation, true, 'default call audio must request echo cancellation');
    assert.equal(defaultAudio.noiseSuppression, true, 'default call audio must request noise suppression');
    assert.equal(defaultAudio.autoGainControl, true, 'default call audio must request automatic gain control');
    assert.equal(defaultAudio.channelCount.ideal, 1, 'default call audio must request mono input');

    const selectedAudio = buildCallAudioCaptureConstraints('mic-1');
    assert.equal(selectedAudio.deviceId.exact, 'mic-1', 'selected microphone id must be preserved with audio processing constraints');
    assert.equal(selectedAudio.echoCancellation, true, 'selected call audio must keep echo cancellation');

    const fakeTrack = {
      applied: null,
      getCapabilities() {
        return {
          width: { min: 320, max: 1920 },
          height: { min: 180, max: 1080 },
          frameRate: { min: 5, max: 60 },
        };
      },
      getSettings() {
        return { width: 1920, height: 1080, frameRate: 60 };
      },
      async applyConstraints(constraints) {
        this.applied = constraints;
      },
    };
    const constraints = buildSfuVideoProfileTrackConstraints(realtime, fakeTrack);
    assert.equal(constraints.width.max, realtime.captureWidth, 'track width max must follow the active SFU profile');
    assert.equal(constraints.height.max, realtime.captureHeight, 'track height max must follow the active SFU profile');
    assert.equal(constraints.frameRate.max, realtime.captureFrameRate, 'track FPS max must follow the active SFU profile');
    const constrained = await applySfuVideoProfileConstraintsToStream({
      stream: {
        getVideoTracks: () => [fakeTrack],
        getAudioTracks: () => [],
      },
      videoProfile: realtime,
      reason: 'contract',
      captureDiagnostic: () => {},
      captureClientDiagnosticError: () => {
        throw new Error('unexpected capture constraint failure');
      },
      mediaRuntimePath: 'wlvc_wasm',
    });
    assert.equal(constrained.ok, true, 'track constraint enforcement must succeed on capable tracks');
    assert.equal(fakeTrack.applied.width.max, realtime.captureWidth, 'enforced track width must cap the source before readback');
    assert.equal(fakeTrack.applied.frameRate.max, realtime.captureFrameRate, 'enforced track FPS must cap the source before readback');

    const minClamped = buildSfuVideoProfileTrackConstraints(
      { captureWidth: 160, captureHeight: 90, captureFrameRate: 2 },
      fakeTrack,
    );
    assert.equal(minClamped.width.max, 320, 'track constraints must respect browser-reported camera minimum width');
    assert.equal(minClamped.height.max, 180, 'track constraints must respect browser-reported camera minimum height');
    assert.equal(minClamped.frameRate.max, 5, 'track constraints must respect browser-reported camera minimum FPS');

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
