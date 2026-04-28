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
  requireContains(publisherPipeline, 'handleWlvcFramePayloadPressure(encodedPayloadBytes', 'publisher drops oversized frames before send');
  requireContains(publisherPipeline, 'encodedPayloadBytes > maxEncodedPayloadBytes', 'publisher compares encoded WLVC payload with cap');
  requireContains(sfuTransport, 'sfu_high_motion_payload_pressure', 'transport high-motion pressure reason');
  requireContains(sfuTransport, '[KingRT] SFU video payload pressure - dropping oversized WLVC frame', 'transport payload pressure log');
  assert.equal(
    runtimeSwitching.includes("immediateMotionPressure && currentProfile === 'quality'"),
    false,
    'runtime must not skip balanced during high-motion pressure',
  );
  requireContains(runtimeSwitching, '!immediateMotionPressure', 'runtime bypasses cooldown for immediate high-motion pressure');

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
