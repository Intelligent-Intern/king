import assert from 'node:assert/strict';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { createServer } from 'vite';

function fail(message) {
  throw new Error(`[sfu-tile-cache-generation-contract] FAIL: ${message}`);
}

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);
const frontendRoot = path.resolve(__dirname, '../..');

async function main() {
  const server = await createServer({
    root: frontendRoot,
    logLevel: 'error',
    server: { middlewareMode: true, hmr: false },
  });

  try {
    const tileMetadataModule = await server.ssrLoadModule('/src/lib/sfu/tilePatchMetadata.ts');
    const framePayloadModule = await server.ssrLoadModule('/src/lib/sfu/framePayload.ts');
    const { normalizeTilePatchMetadata, hasExplicitSfuTileMetadataFields } = tileMetadataModule;
    const {
      prepareSfuOutboundFramePayload,
      encodeSfuBinaryFrameEnvelope,
      decodeSfuBinaryFrameEnvelope,
    } = framePayloadModule;

    const validForegroundMetadata = normalizeTilePatchMetadata({
      layoutMode: 'tile_foreground',
      layerId: 'foreground',
      cacheEpoch: 7,
      tileColumns: 4,
      tileRows: 3,
      tileWidth: 96,
      tileHeight: 96,
      tileIndices: [1, 2],
      roiNormX: 0.25,
      roiNormY: 0.2,
      roiNormWidth: 0.5,
      roiNormHeight: 0.4,
    });
    assert.ok(validForegroundMetadata, 'valid foreground tile metadata must normalize');
    assert.equal(validForegroundMetadata.layerId, 'foreground', 'foreground patch must preserve foreground layer id');

    assert.equal(
      normalizeTilePatchMetadata({
        layoutMode: 'tile_foreground',
        layerId: 'background',
        cacheEpoch: 7,
        tileColumns: 4,
        tileRows: 3,
        tileWidth: 96,
        tileHeight: 96,
        tileIndices: [1, 2],
        roiNormX: 0.25,
        roiNormY: 0.2,
        roiNormWidth: 0.5,
        roiNormHeight: 0.4,
      }),
      null,
      'mismatched layout/layer combinations must fail closed',
    );

    assert.equal(
      normalizeTilePatchMetadata({
        cacheEpoch: 7,
        tileColumns: 4,
        tileRows: 3,
        tileWidth: 96,
        tileHeight: 96,
        tileIndices: [1, 2],
        roiNormX: 0.25,
        roiNormY: 0.2,
        roiNormWidth: 0.5,
        roiNormHeight: 0.4,
      }),
      null,
      'patch metadata without an explicit layout mode must fail closed',
    );

    assert.equal(
      normalizeTilePatchMetadata({
        layoutMode: 'full_frame',
        layerId: 'full',
        cacheEpoch: 7,
        tileIndices: [1],
        roiNormWidth: 0.5,
      }),
      null,
      'full-frame metadata must reject stray patch tile fields',
    );

    assert.equal(
      hasExplicitSfuTileMetadataFields({
        layoutMode: 'background_snapshot',
        layerId: 'background',
      }),
      true,
      'explicit tile metadata fields must be detectable for inbound validation',
    );

    const rejectedTilePatch = normalizeTilePatchMetadata({
      layoutMode: 'tile_foreground',
      layerId: 'background',
      cacheEpoch: 4,
      tileColumns: 2,
      tileRows: 2,
      tileWidth: 96,
      tileHeight: 96,
      tileIndices: [0],
      roiNormX: 0,
      roiNormY: 0,
      roiNormWidth: 0.5,
      roiNormHeight: 0.5,
    });
    assert.equal(rejectedTilePatch, null, 'binary envelope metadata must reject invalid mixed-generation tile metadata');

    const acceptedPrepared = prepareSfuOutboundFramePayload({
      publisherId: 'publisher-1',
      publisherUserId: '12',
      trackId: 'track-1',
      timestamp: 101,
      dataBase64: 'AAAA',
      type: 'keyframe',
      frameSequence: 3,
      senderSentAtMs: 101,
      codecId: 'wlvc_ts',
      runtimeId: 'wlvc_sfu',
      tilePatch: {
        layoutMode: 'background_snapshot',
        layerId: 'background',
        cacheEpoch: 5,
        tileColumns: 2,
        tileRows: 2,
        tileWidth: 96,
        tileHeight: 96,
        tileIndices: [1],
        roiNormX: 0.5,
        roiNormY: 0.5,
        roiNormWidth: 0.5,
        roiNormHeight: 0.5,
      },
    });
    const acceptedEnvelope = encodeSfuBinaryFrameEnvelope(acceptedPrepared);
    assert.ok(acceptedEnvelope instanceof ArrayBuffer, 'binary envelope must encode valid tile metadata');
    const acceptedFrame = decodeSfuBinaryFrameEnvelope(acceptedEnvelope);
    assert.ok(acceptedFrame, 'binary envelope must decode valid tile metadata');
    assert.equal(acceptedFrame.payload.layout_mode, 'background_snapshot', 'decoded binary frame must preserve the validated layout mode');
    assert.equal(acceptedFrame.payload.layer_id, 'background', 'decoded binary frame must preserve the validated layer id');
    assert.equal(acceptedFrame.payload.cache_epoch, 5, 'decoded binary frame must preserve the validated cache epoch');

    process.stdout.write('[sfu-tile-cache-generation-contract] PASS\n');
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
