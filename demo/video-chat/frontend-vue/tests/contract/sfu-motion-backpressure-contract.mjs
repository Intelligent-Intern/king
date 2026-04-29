import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { createServer } from 'vite';

function fail(message) {
  throw new Error(`[sfu-motion-backpressure-contract] FAIL: ${message}`);
}

function requireContains(source, needle, label) {
  assert.ok(source.includes(needle), `${label} missing: ${needle}`);
}

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);
const frontendRoot = path.resolve(__dirname, '../..');

if (typeof globalThis.ImageData === 'undefined') {
  globalThis.ImageData = class ImageData {
    constructor(data, width, height) {
      this.data = data;
      this.width = width;
      this.height = height;
    }
  };
}

function read(relativePath) {
  return fs.readFileSync(path.resolve(frontendRoot, relativePath), 'utf8');
}

function makeImage(width, height, painter) {
  const data = new Uint8ClampedArray(width * height * 4);
  for (let y = 0; y < height; y += 1) {
    for (let x = 0; x < width; x += 1) {
      const offset = ((y * width) + x) * 4;
      const [r, g, b] = painter(x, y);
      data[offset] = r;
      data[offset + 1] = g;
      data[offset + 2] = b;
      data[offset + 3] = 255;
    }
  }
  return new ImageData(data, width, height);
}

function encodeHighMotionDelta(WaveletVideoEncoder, profile) {
  const encoder = new WaveletVideoEncoder({
    quality: profile.frameQuality,
    levels: 3,
    keyFrameInterval: profile.keyFrameInterval,
  });
  const width = profile.frameWidth;
  const height = profile.frameHeight;
  const baseFrame = makeImage(width, height, (x, y) => [30 + ((x + y) % 20), 80, 140]);
  const highMotionFrame = makeImage(width, height, (x, y) => [
    ((x * 17) + (y * 31)) % 256,
    ((x * 29) + (y * 13)) % 256,
    ((x * 7) + (y * 11)) % 256,
  ]);
  encoder.encodeFrame(baseFrame, 1);
  return encoder.encodeFrame(highMotionFrame, 2);
}

async function main() {
  const runtimeConfig = read('src/domain/realtime/workspace/callWorkspace/runtimeConfig.js');
  const publisherPipeline = read('src/domain/realtime/local/publisherPipeline.js');
  const sfuTransport = read('src/domain/realtime/workspace/callWorkspace/sfuTransport.js');
  const runtimeSwitching = read('src/domain/realtime/workspace/callWorkspace/runtimeSwitching.js');

  requireContains(runtimeConfig, 'SFU_WLVC_MAX_DELTA_FRAME_BYTES', 'motion payload delta cap');
  requireContains(runtimeConfig, 'SFU_WLVC_MAX_KEYFRAME_FRAME_BYTES', 'motion payload keyframe cap');
  requireContains(runtimeConfig, 'export const SFU_AUTO_QUALITY_DOWNGRADE_BACKPRESSURE_WINDOW_MS = 1000;', 'one second send-pressure downgrade window');
  requireContains(runtimeConfig, 'export const SFU_AUTO_QUALITY_DOWNGRADE_SKIP_THRESHOLD = 2;', 'two skipped frames trigger quality downgrade');
  requireContains(runtimeConfig, 'export const SFU_AUTO_QUALITY_DOWNGRADE_SEND_FAILURE_THRESHOLD = 2;', 'two send failures trigger quality downgrade');
  requireContains(publisherPipeline, 'handleWlvcFramePayloadPressure(encodedPayloadBytes', 'publisher drops oversized frames before send');
  requireContains(publisherPipeline, 'encodedPayloadBytes > maxEncodedPayloadBytes', 'publisher compares encoded WLVC payload with cap');
  requireContains(sfuTransport, 'sfu_high_motion_payload_pressure', 'transport high-motion pressure reason');
  requireContains(sfuTransport, 'sfu_send_backpressure_critical', 'transport uses critical send backpressure as an immediate quality-pressure reason');
  requireContains(sfuTransport, 'adaptive_quality_downgrade_enabled: true', 'transport diagnostics expose adaptive quality downgrade');
  requireContains(sfuTransport, '[KingRT] SFU video payload pressure - dropping oversized WLVC frame', 'transport payload pressure log');
  assert.equal(
    sfuTransport.includes('hd_baseline_no_auto_downgrade'),
    false,
    'transport diagnostics must not claim HD is pinned while adaptive downgrade is active',
  );
  assert.equal(
    runtimeSwitching.includes("immediateMotionPressure && currentProfile === 'quality'"),
    false,
    'runtime must not skip balanced during high-motion pressure',
  );
  requireContains(runtimeSwitching, 'immediateQualityPressureReasons', 'runtime has explicit immediate quality-pressure reasons');
  requireContains(runtimeSwitching, "'sfu_send_backpressure_critical'", 'critical send backpressure bypasses downgrade cooldown');
  requireContains(runtimeSwitching, "'sfu_remote_quality_pressure'", 'receiver freeze signals bypass downgrade cooldown on the sender');

  const server = await createServer({
    root: frontendRoot,
    logLevel: 'error',
    server: { middlewareMode: true, hmr: false },
  });

  try {
    const { WaveletVideoEncoder } = await server.ssrLoadModule('/src/lib/wavelet/codec.ts');
    const workspaceConfig = await server.ssrLoadModule('/src/domain/realtime/workspace/config.js');
    const motionConfig = await server.ssrLoadModule('/src/domain/realtime/workspace/callWorkspace/runtimeConfig.js');
    const profiles = workspaceConfig.SFU_VIDEO_QUALITY_PROFILES;
    const maxDeltaBytes = motionConfig.SFU_WLVC_MAX_DELTA_FRAME_BYTES;

    assert.deepEqual(
      {
        rescue: {
          captureFrameRate: profiles.rescue.captureFrameRate,
          encodeIntervalMs: profiles.rescue.encodeIntervalMs,
          frameQuality: profiles.rescue.frameQuality,
        },
        realtime: {
          captureFrameRate: profiles.realtime.captureFrameRate,
          encodeIntervalMs: profiles.realtime.encodeIntervalMs,
          frameQuality: profiles.realtime.frameQuality,
        },
        balanced: {
          captureFrameRate: profiles.balanced.captureFrameRate,
          encodeIntervalMs: profiles.balanced.encodeIntervalMs,
          frameQuality: profiles.balanced.frameQuality,
        },
        quality: {
          captureFrameRate: profiles.quality.captureFrameRate,
          encodeIntervalMs: profiles.quality.encodeIntervalMs,
          frameQuality: profiles.quality.frameQuality,
        },
      },
      {
        rescue: { captureFrameRate: 7, encodeIntervalMs: 244, frameQuality: 20 },
        realtime: { captureFrameRate: 11, encodeIntervalMs: 167, frameQuality: 29 },
        balanced: { captureFrameRate: 14, encodeIntervalMs: 111, frameQuality: 33 },
        quality: { captureFrameRate: 27, encodeIntervalMs: 92, frameQuality: 43 },
      },
      'SFU profiles must trade about 10% less framerate for higher per-frame quality',
    );

    const qualityDelta = encodeHighMotionDelta(WaveletVideoEncoder, profiles.quality);
    const balancedDelta = encodeHighMotionDelta(WaveletVideoEncoder, profiles.balanced);
    const realtimeDelta = encodeHighMotionDelta(WaveletVideoEncoder, profiles.realtime);

    assert.equal(qualityDelta.type, 'delta', 'quality high-motion second frame must be a delta');
    assert.equal(balancedDelta.type, 'delta', 'balanced high-motion second frame must be a delta');
    assert.equal(realtimeDelta.type, 'delta', 'realtime high-motion second frame must be a delta');
    assert.ok(
      qualityDelta.data.byteLength > maxDeltaBytes,
      `quality high-motion delta must trip the payload cap; bytes=${qualityDelta.data.byteLength}, cap=${maxDeltaBytes}`,
    );
    assert.ok(
      balancedDelta.data.byteLength <= maxDeltaBytes,
      `balanced high-motion delta must fit the payload cap; bytes=${balancedDelta.data.byteLength}, cap=${maxDeltaBytes}`,
    );
    assert.ok(
      realtimeDelta.data.byteLength <= maxDeltaBytes,
      `realtime high-motion delta must fit the payload cap; bytes=${realtimeDelta.data.byteLength}, cap=${maxDeltaBytes}`,
    );
    assert.ok(
      profiles.realtime.frameWidth < profiles.balanced.frameWidth,
      'realtime profile must be a real downscale below balanced after pressure',
    );
    assert.ok(
      profiles.rescue.encodeIntervalMs > profiles.realtime.encodeIntervalMs,
      'rescue profile must slow encoding below realtime to drain sender pressure',
    );

    process.stdout.write('[sfu-motion-backpressure-contract] PASS\n');
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
