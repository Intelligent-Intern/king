import assert from 'node:assert/strict';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { createServer } from 'vite';

function fail(message) {
  throw new Error(`[sfu-selective-tile-value-contract] FAIL: ${message}`);
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

function makeImage(width, height, painter) {
  const data = new Uint8ClampedArray(width * height * 4);
  for (let y = 0; y < height; y += 1) {
    for (let x = 0; x < width; x += 1) {
      const offset = ((y * width) + x) * 4;
      const [r, g, b, a] = painter(x, y);
      data[offset] = r;
      data[offset + 1] = g;
      data[offset + 2] = b;
      data[offset + 3] = a;
    }
  }
  return new ImageData(data, width, height);
}

function paintRect(imageData, x0, y0, width, height, rgba) {
  const { width: imageWidth, height: imageHeight, data } = imageData;
  for (let y = y0; y < Math.min(imageHeight, y0 + height); y += 1) {
    for (let x = x0; x < Math.min(imageWidth, x0 + width); x += 1) {
      const offset = ((y * imageWidth) + x) * 4;
      data[offset] = rgba[0];
      data[offset + 1] = rgba[1];
      data[offset + 2] = rgba[2];
      data[offset + 3] = rgba[3];
    }
  }
}

async function main() {
  const server = await createServer({
    root: frontendRoot,
    logLevel: 'error',
    server: { middlewareMode: true },
  });

  try {
    const { WaveletVideoEncoder } = await server.ssrLoadModule('/src/lib/wavelet/codec.ts');
    const { planSelectiveTilePatch, planBackgroundSnapshotPatch } = await server.ssrLoadModule('/src/lib/sfu/selectiveTileTransport.ts');
    const { prepareSfuOutboundFramePayload } = await server.ssrLoadModule('/src/lib/sfu/framePayload.ts');

    const frameWidth = 640;
    const frameHeight = 360;
    const previousFrame = makeImage(frameWidth, frameHeight, (x, y) => {
      const base = 30 + ((x + y) % 20);
      return [base, 80, 140, 255];
    });
    const matteMask = makeImage(frameWidth, frameHeight, (x, y) => {
      const isForeground = x >= 220 && x < 420 && y >= 80 && y < 320;
      return isForeground ? [255, 255, 255, 255] : [0, 0, 0, 0];
    });

    const sparseForegroundFrame = new ImageData(new Uint8ClampedArray(previousFrame.data), frameWidth, frameHeight);
    paintRect(sparseForegroundFrame, 260, 130, 70, 90, [240, 60, 80, 255]);

    const sparseBackgroundFrame = new ImageData(new Uint8ClampedArray(previousFrame.data), frameWidth, frameHeight);
    paintRect(sparseBackgroundFrame, 20, 20, 90, 70, [20, 220, 70, 255]);

    const broadSceneChangeFrame = makeImage(frameWidth, frameHeight, (x, y) => {
      const value = ((x * 3) + (y * 2)) % 255;
      return [value, (value + 50) % 255, (value + 100) % 255, 255];
    });

    const foregroundPlan = planSelectiveTilePatch(sparseForegroundFrame, previousFrame, {
      tileWidth: 96,
      tileHeight: 96,
      maxChangedTileRatio: 0.35,
      maxPatchAreaRatio: 0.55,
      sampleStride: 8,
      diffThreshold: 26,
      cacheEpoch: 4,
      matteMaskImageData: matteMask,
    });

    const backgroundPlan = planBackgroundSnapshotPatch(sparseBackgroundFrame, previousFrame, {
      tileWidth: 128,
      tileHeight: 72,
      minChangedTileRatio: 0.02,
      maxChangedTileRatio: 0.45,
      maxPatchAreaRatio: 0.55,
      sampleStride: 8,
      diffThreshold: 26,
      cacheEpoch: 4,
      matteMaskImageData: matteMask,
    });

    const broadScenePlan = planSelectiveTilePatch(broadSceneChangeFrame, previousFrame, {
      tileWidth: 96,
      tileHeight: 96,
      maxChangedTileRatio: 0.35,
      maxPatchAreaRatio: 0.55,
      sampleStride: 8,
      diffThreshold: 26,
      cacheEpoch: 4,
      matteMaskImageData: matteMask,
    });

    assert.ok(foregroundPlan, 'foreground patch plan must exist for a sparse foreground change');
    assert.ok(backgroundPlan, 'background snapshot plan must exist for a sparse background change');
    assert.equal(broadScenePlan, null, 'broad scene changes must not take the selective tile path');

    function measureFullFrame(currentFrame) {
      const encoder = new WaveletVideoEncoder({
        quality: 75,
        levels: 3,
        keyFrameInterval: 9999,
      });
      encoder.encodeFrame(previousFrame, 1);
      const encoded = encoder.encodeFrame(currentFrame, 2);
      return prepareSfuOutboundFramePayload({
        publisherId: '1',
        publisherUserId: '1',
        trackId: 'track-full',
        timestamp: encoded.timestamp,
        data: encoded.data,
        type: encoded.type,
        codecId: 'wlvc_ts',
        runtimeId: 'wlvc_sfu',
        layoutMode: 'full_frame',
        layerId: 'full',
        cacheEpoch: 4,
      });
    }

    function measurePatch(plan, trackId) {
      const encoder = new WaveletVideoEncoder({
        quality: 75,
        levels: 3,
        keyFrameInterval: 9999,
      });
      const encoded = encoder.encodeFrame(plan.patchImageData, 2);
      return prepareSfuOutboundFramePayload({
        publisherId: '1',
        publisherUserId: '1',
        trackId,
        timestamp: encoded.timestamp,
        data: encoded.data,
        type: 'keyframe',
        codecId: 'wlvc_ts',
        runtimeId: 'wlvc_sfu',
        ...plan.tilePatch,
        transportMetrics: {
          selection_tile_count: plan.changedTileCount,
          selection_total_tile_count: plan.totalTileCount,
          selection_tile_ratio: Number(plan.selectedTileRatio.toFixed(6)),
          selection_mask_guided: plan.matteGuided,
        },
      });
    }

    const foregroundFull = measureFullFrame(sparseForegroundFrame);
    const foregroundPatch = measurePatch(foregroundPlan, 'track-foreground');
    const backgroundFull = measureFullFrame(sparseBackgroundFrame);
    const backgroundPatch = measurePatch(backgroundPlan, 'track-background');

    assert.equal(foregroundPatch.metrics.layout_mode, 'tile_foreground', 'foreground patch must keep explicit tile layout mode');
    assert.equal(backgroundPatch.metrics.layout_mode, 'background_snapshot', 'background patch must keep explicit background layout mode');
    assert.ok(
      foregroundPatch.projectedBinaryEnvelopeBytes < (foregroundFull.projectedBinaryEnvelopeBytes * 0.75),
      `foreground patch must save at least 25% wire bytes; full=${foregroundFull.projectedBinaryEnvelopeBytes}, patch=${foregroundPatch.projectedBinaryEnvelopeBytes}`,
    );
    assert.ok(
      backgroundPatch.projectedBinaryEnvelopeBytes < (backgroundFull.projectedBinaryEnvelopeBytes * 0.5),
      `background snapshot patch must save at least 50% wire bytes; full=${backgroundFull.projectedBinaryEnvelopeBytes}, patch=${backgroundPatch.projectedBinaryEnvelopeBytes}`,
    );
    assert.ok(
      foregroundPatch.metrics.roi_area_ratio < 0.5 && backgroundPatch.metrics.roi_area_ratio < 0.25,
      `patch ROI must stay meaningfully smaller than the frame; fg=${foregroundPatch.metrics.roi_area_ratio}, bg=${backgroundPatch.metrics.roi_area_ratio}`,
    );

    process.stdout.write('[sfu-selective-tile-value-contract] PASS\n');
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
