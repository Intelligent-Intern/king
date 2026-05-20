import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { createServer } from 'vite';

function fail(message) {
  throw new Error(`[wlvc-first-keyframe-downscale-contract] FAIL: ${message}`);
}

function requireContains(source, needle, label) {
  assert.ok(source.includes(needle), `${label} missing: ${needle}`);
}

if (typeof globalThis.ImageData === 'undefined') {
  globalThis.ImageData = class ImageData {
    constructor(data, width, height) {
      this.data = data;
      this.width = width;
      this.height = height;
    }
  };
}

function installFakeCanvasDocument() {
  const previousDocument = globalThis.document;
  globalThis.document = {
    createElement(tagName) {
      assert.equal(tagName, 'canvas');
      const canvas = {
        width: 0,
        height: 0,
        getContext() {
          return {
            putImageData(imageData) {
              canvas.imageData = imageData;
            },
            drawImage() {},
            getImageData() {
              return new ImageData(new Uint8ClampedArray(canvas.width * canvas.height * 4), canvas.width, canvas.height);
            },
          };
        },
      };
      return canvas;
    },
  };
  return () => {
    if (typeof previousDocument === 'undefined') {
      delete globalThis.document;
    } else {
      globalThis.document = previousDocument;
    }
  };
}

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);
const frontendRoot = path.resolve(__dirname, '../..');

function read(relativePath) {
  return fs.readFileSync(path.resolve(frontendRoot, relativePath), 'utf8');
}

async function main() {
  const pipelineSource = read('src/domain/realtime/local/publisherPipeline.ts');
  const downscaleSource = read('src/domain/realtime/local/publisherKeyframeDownscale.ts');
  const configSource = read('src/domain/realtime/workspace/config.ts');

  requireContains(downscaleSource, 'FIRST_KEYFRAME_DOWNSCALE_MIN_WIDTH = 320', 'bounded downscale minimum width');
  requireContains(downscaleSource, 'FIRST_KEYFRAME_DOWNSCALE_MIN_HEIGHT = 180', 'bounded downscale minimum height');
  requireContains(downscaleSource, "FIRST_KEYFRAME_DOWNSCALE_EVENT = 'wlvc_first_keyframe_downscale_retry'", 'downscale diagnostic event');
  requireContains(downscaleSource, 'first_keyframe_hard_budget_exceeded', 'hard-budget retry reason');
  requireContains(downscaleSource, 'first_keyframe_soft_payload_pressure', 'soft-payload retry reason');
  requireContains(downscaleSource, 'first_keyframe_encode_budget_pressure', 'encode-budget retry reason');
  requireContains(downscaleSource, 'targetUnit * 16', 'downscale width remains 16:9');
  requireContains(downscaleSource, 'targetUnit * 9', 'downscale height remains 16:9');
  requireContains(downscaleSource, 'downscaleImageDataForInitialKeyframe(activeImageData, downscaleTargetSize)', 'helper downscales the actual first frame');
  requireContains(downscaleSource, 'width: downscaleTargetSize.frameWidth', 'retry encoder matches downscaled width');
  requireContains(downscaleSource, 'height: downscaleTargetSize.frameHeight', 'retry encoder matches downscaled height');
  requireContains(downscaleSource, 'keyFrameInterval,', 'retry encoder preserves normal keyframe cadence');
  requireContains(downscaleSource, 'FIRST_KEYFRAME_DOWNSCALE_EVENT', 'successful retry emits diagnostic');

  requireContains(configSource, "DEFAULT_SFU_VIDEO_QUALITY_PROFILE = 'rescue'", 'default 360p profile');
  requireContains(configSource, 'captureWidth: 640', 'default rescue capture width remains 360p');
  requireContains(configSource, 'captureHeight: 360', 'default rescue capture height remains 360p');
  requireContains(configSource, 'captureFrameRate: LOCAL_CAMERA_CAPTURE_FRAME_RATE', 'rescue profile uses 30fps camera cadence');
  requireContains(configSource, 'keyFrameInterval: SFU_WLVC_KEYFRAME_INTERVAL', 'rescue profile uses 30-frame WLVC cadence');

  requireContains(pipelineSource, 'shouldRetryInitialKeyframeDownscale', 'publisher consults bounded initial-keyframe retry gate');
  requireContains(pipelineSource, 'lastFullFrameSentAtMs <= 0', 'retry is scoped to the initial full frame');
  requireContains(pipelineSource, 'firstKeyframeDownscaleRetryConsumed = true', 'retry is consumed exactly once per continuity');
  requireContains(pipelineSource, 'attemptInitialKeyframeDownscaleRetry', 'publisher delegates bounded retry implementation to helper');
  requireContains(pipelineSource, 'const frameSize = resolvePublisherFrameSize(video, videoProfile, videoTrack)', 'normal publisher readback keeps applying the selected profile');
  requireContains(pipelineSource, 'const restoredEncoder = await ensureFullFrameEncoder(firstKeyframeOriginalFrameSize', 'successful retry restores the selected-profile encoder for the next cadence');
  requireContains(pipelineSource, 'preserveFirstKeyframeDownscaleRetry: true', 'selected-profile restore must preserve the consumed first-frame retry marker');
  requireContains(pipelineSource, 'first_keyframe_downscale_next_profile_keyframe_interval', 'transport metrics expose restored selected-profile cadence');
  requireContains(pipelineSource, 'downscaleRetry.encoder?.destroy?.()', 'single-use retry encoder is destroyed after the budget frame');
  assert.doesNotMatch(
    pipelineSource,
    /firstKeyframeDownscaleFrameSize|currentReadbackVideoProfile/,
    'first-keyframe downscale must not pin later readback to the smaller retry size',
  );
  requireContains(downscaleSource, 'first_keyframe_downscale_retry_count: 1', 'transport metrics expose bounded retry count');
  requireContains(downscaleSource, 'keyframe_interval_after_downscale', 'transport metrics expose post-retry cadence');

  const server = await createServer({
    root: frontendRoot,
    logLevel: 'error',
    server: { middlewareMode: true, hmr: false },
  });

  try {
    const config = await server.ssrLoadModule('/src/domain/realtime/workspace/config.ts');
    const downscale = await server.ssrLoadModule('/src/domain/realtime/local/publisherKeyframeDownscale.ts');
    assert.equal(config.DEFAULT_SFU_VIDEO_QUALITY_PROFILE, 'rescue', 'default publisher profile must stay rescue/360p');
    assert.equal(config.SFU_VIDEO_QUALITY_PROFILES.rescue.frameWidth, 640, 'rescue encoded width must stay 640');
    assert.equal(config.SFU_VIDEO_QUALITY_PROFILES.rescue.frameHeight, 360, 'rescue encoded height must stay 360');
    assert.equal(config.SFU_VIDEO_QUALITY_PROFILES.rescue.captureFrameRate, 30, 'rescue capture must run at 30fps');
    assert.equal(config.SFU_VIDEO_QUALITY_PROFILES.rescue.keyFrameInterval, 30, 'rescue cadence must be keyframe plus 29 deltas');

    assert.deepEqual(
      downscale.resolveFirstKeyframeDownscaleSize({ frameWidth: 640, frameHeight: 360 }),
      { frameWidth: 320, frameHeight: 180 },
      '360p first-keyframe retry must fall back to 320x180',
    );
    assert.deepEqual(
      downscale.resolveFirstKeyframeDownscaleSize({ frameWidth: 1280, frameHeight: 720 }),
      { frameWidth: 640, frameHeight: 360 },
      '720p first-keyframe retry must halve to 640x360',
    );
    assert.equal(
      downscale.resolveFirstKeyframeDownscaleSize({ frameWidth: 320, frameHeight: 180 }),
      null,
      'minimum 320x180 retry must not downscale further',
    );
    assert.deepEqual(
      downscale.shouldRetryInitialKeyframeDownscale({
        isInitialFullFrame: true,
        retryConsumed: false,
        frameType: 'keyframe',
        payloadBytes: 1201,
        maxPayloadBytes: 1200,
        payloadSoftLimitBytes: 1000,
        encodeMs: 1,
        encodeBudgetMs: 100,
      }),
      { retry: true, reason: 'first_keyframe_hard_budget_exceeded' },
      'hard oversized initial keyframe must retry once',
    );
    assert.deepEqual(
      downscale.shouldRetryInitialKeyframeDownscale({
        isInitialFullFrame: true,
        retryConsumed: false,
        frameType: 'keyframe',
        payloadBytes: 1000,
        maxPayloadBytes: 1200,
        payloadSoftLimitBytes: 1000,
        encodeMs: 1,
        encodeBudgetMs: 100,
      }),
      { retry: true, reason: 'first_keyframe_soft_payload_pressure' },
      'soft pressure initial keyframe must retry once',
    );
    assert.equal(
      downscale.shouldRetryInitialKeyframeDownscale({
        isInitialFullFrame: false,
        retryConsumed: false,
        frameType: 'keyframe',
        payloadBytes: 1201,
        maxPayloadBytes: 1200,
      }).retry,
      false,
      'non-initial keyframes must not enter the first-frame retry path',
    );
    assert.equal(
      downscale.shouldRetryInitialKeyframeDownscale({
        isInitialFullFrame: true,
        retryConsumed: true,
        frameType: 'keyframe',
        payloadBytes: 1201,
        maxPayloadBytes: 1200,
      }).retry,
      false,
      'retry consumption must prevent loops',
    );
    const frameSize = downscale.buildFirstKeyframeDownscaleFrameSize(
      { frameWidth: 640, frameHeight: 360, sourceWidth: 1920, sourceHeight: 1080 },
      { frameWidth: 320, frameHeight: 180 },
    );
    assert.equal(frameSize.frameWidth, 320);
    assert.equal(frameSize.frameHeight, 180);
    assert.equal(frameSize.profileFrameWidth, 320);
    assert.equal(frameSize.profileFrameHeight, 180);
    assert.equal(frameSize.sourceWidth, 1920);
    assert.equal(frameSize.sourceHeight, 1080);

    const restoreDocument = installFakeCanvasDocument();
    const encoderConfigs = [];
    const diagnostics = [];
    try {
      const retry = await downscale.attemptInitialKeyframeDownscaleRetry({
        activeImageData: new ImageData(new Uint8ClampedArray(640 * 360 * 4), 640, 360),
        frameSizeForMetrics: { frameWidth: 640, frameHeight: 360 },
        firstKeyframeOriginalFrameSize: { frameWidth: 640, frameHeight: 360 },
        firstKeyframeOriginalPayloadBytes: 1400,
        downscaleRetryDecision: { retry: true, reason: 'first_keyframe_hard_budget_exceeded' },
        videoProfile: { frameQuality: 32, keyFrameInterval: 30 },
        constants: { sfuWlvcFrameQuality: 32 },
        timestamp: 1234,
        trackId: 'camera',
        mediaRuntimePath: 'wlvc_sfu',
        pipelineProfileId: 'rescue',
        maxEncodedPayloadBytes: 1000,
        payloadSoftLimitBytes: 900,
        encodeBudgetMs: 100,
        createHybridEncoder: async (config) => {
          encoderConfigs.push(config);
          return {
            sfuCodecId: 'wlvc_ts',
            encodeFrame(imageData, timestamp) {
              assert.equal(imageData.width, 320);
              assert.equal(imageData.height, 180);
              return { type: 'keyframe', timestamp, data: new ArrayBuffer(512) };
            },
            destroy() {},
          };
        },
        sfuFrameTypeFromWlvcData: (_data, type) => type,
        captureClientDiagnostic: (event) => diagnostics.push(event),
      });
      assert.equal(retry.ok, true, 'bounded retry must encode a downscaled first keyframe when it fits budget');
      assert.deepEqual(encoderConfigs[0], { width: 320, height: 180, quality: 32, keyFrameInterval: 30 });
      assert.equal(retry.frameSize.frameWidth, 320);
      assert.equal(retry.frameSize.frameHeight, 180);
      assert.equal(retry.keyFrameInterval, 30);
      assert.equal(retry.transportMetrics.first_keyframe_downscale_retry_count, 1);
      assert.equal(retry.transportMetrics.keyframe_interval_after_downscale, 30);
      assert.equal(diagnostics.at(-1)?.eventType, downscale.FIRST_KEYFRAME_DOWNSCALE_EVENT);
      retry.encoder.destroy();
    } finally {
      restoreDocument();
    }
  } finally {
    await server.close();
  }

  process.stdout.write('[wlvc-first-keyframe-downscale-contract] PASS\n');
}

main().catch((error) => {
  if (error instanceof Error) {
    fail(error.message);
  }
  fail(String(error));
});
