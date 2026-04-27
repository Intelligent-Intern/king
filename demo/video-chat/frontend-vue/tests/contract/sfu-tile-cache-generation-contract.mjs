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
    server: { middlewareMode: true },
  });

  try {
    const tileMetadataModule = await server.ssrLoadModule('/src/lib/sfu/tilePatchMetadata.ts');
    const assemblerModule = await server.ssrLoadModule('/src/lib/sfu/inboundFrameAssembler.ts');
    const { normalizeTilePatchMetadata, hasExplicitSfuTileMetadataFields } = tileMetadataModule;
    const { SfuInboundFrameAssembler } = assemblerModule;

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

    const assembler = new SfuInboundFrameAssembler({
      getRoomId: () => 'room-test',
    });

    const rejectedFrame = assembler.acceptChunk({
      type: 'sfu/frame-chunk',
      frame_id: 'frame-invalid',
      publisher_id: 'publisher-1',
      publisher_user_id: '12',
      track_id: 'track-1',
      timestamp: 100,
      frame_type: 'keyframe',
      protocol_version: 2,
      frame_sequence: 2,
      sender_sent_at_ms: 100,
      codec_id: 'wlvc_ts',
      runtime_id: 'wlvc_sfu',
      chunk_count: 1,
      chunk_index: 0,
      payload_chars: 4,
      chunk_payload_chars: 4,
      data_base64_chunk: 'AAAA',
      layout_mode: 'tile_foreground',
      layer_id: 'background',
      cache_epoch: 4,
      tile_columns: 2,
      tile_rows: 2,
      tile_width: 96,
      tile_height: 96,
      tile_indices: [0],
      roi_norm_x: 0,
      roi_norm_y: 0,
      roi_norm_width: 0.5,
      roi_norm_height: 0.5,
    });
    assert.equal(rejectedFrame, null, 'chunk assembler must reject invalid mixed-generation tile metadata');

    const acceptedFrame = assembler.acceptChunk({
      type: 'sfu/frame-chunk',
      frame_id: 'frame-valid',
      publisher_id: 'publisher-1',
      publisher_user_id: '12',
      track_id: 'track-1',
      timestamp: 101,
      frame_type: 'keyframe',
      protocol_version: 2,
      frame_sequence: 3,
      sender_sent_at_ms: 101,
      codec_id: 'wlvc_ts',
      runtime_id: 'wlvc_sfu',
      chunk_count: 1,
      chunk_index: 0,
      payload_chars: 4,
      chunk_payload_chars: 4,
      data_base64_chunk: 'AAAA',
      layout_mode: 'background_snapshot',
      layer_id: 'background',
      cache_epoch: 5,
      tile_columns: 2,
      tile_rows: 2,
      tile_width: 96,
      tile_height: 96,
      tile_indices: [1],
      roi_norm_x: 0.5,
      roi_norm_y: 0.5,
      roi_norm_width: 0.5,
      roi_norm_height: 0.5,
    });
    assert.ok(acceptedFrame, 'chunk assembler must accept valid tile metadata');
    assert.equal(acceptedFrame.layout_mode, 'background_snapshot', 'reassembled frame must preserve the validated layout mode');
    assert.equal(acceptedFrame.layer_id, 'background', 'reassembled frame must preserve the validated layer id');
    assert.equal(acceptedFrame.cache_epoch, 5, 'reassembled frame must preserve the validated cache epoch');

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
