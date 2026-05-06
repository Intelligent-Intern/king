import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

function fail(message) {
  throw new Error(`[sfu-binary-tile-wire-contract] FAIL: ${message}`);
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
  const framePayload = read('src/lib/sfu/framePayload.ts');
  requireContains(framePayload, 'SFU_BINARY_FRAME_LAYOUT_ENVELOPE_VERSION = 2', 'binary tile envelope version');
  requireContains(framePayload, 'metadataJsonBytes', 'binary tile envelope metadata bytes');
  requireContains(framePayload, 'flattenTilePatchMetadata(tilePatch)', 'binary envelope must merge tile layout metadata into the payload');
  requireContains(framePayload, 'parseTilePatchMetadataJson', 'binary envelope must decode tile metadata');
  requireContains(framePayload, 'codec_id: codecId', 'binary envelope metadata must preserve codec id');
  requireContains(framePayload, 'runtime_id: runtimeId', 'binary envelope metadata must preserve runtime id');
  requireContains(framePayload, 'serializeSfuEnvelopeMetadata', 'binary envelope must serialize generic frame metadata');
  requireContains(framePayload, 'parseSfuEnvelopeMetadata', 'binary envelope must decode generic frame metadata');
  requireContains(framePayload, 'const dataBase64 = null', 'binary envelope decode avoids transport-only base64 reconstruction');
  requireContains(framePayload, 'const payloadChars = protectedFrame ? protectedFrame.length : base64UrlEncodedLength(payloadByteLength)', 'binary envelope decode advertises projected payload chars without base64 conversion');
  requireContains(framePayload, 'payload_bytes: payloadByteLength', 'binary envelope decode keeps raw byte length separately');
  requireContains(framePayload, 'binary_envelope_version: metadataJsonBytes > 0', 'binary envelope metrics use the numeric metadata byte count');

  const tileMetadata = read('src/lib/sfu/tilePatchMetadata.ts');
  requireContains(tileMetadata, "export type SfuLayoutMode = 'full_frame' | 'tile_foreground' | 'background_snapshot'", 'tile metadata layout modes');
  requireContains(tileMetadata, 'serializeTilePatchMetadata', 'tile metadata serializer');
  requireContains(tileMetadata, 'tile_indices', 'tile metadata must preserve tile indices');
  requireContains(tileMetadata, 'roi_norm_width', 'tile metadata must preserve ROI width');

  const sfuClient = read('src/lib/sfu/sfuClient.ts');
  const sfuMessageHandler = read('src/lib/sfu/sfuMessageHandler.ts');
  const publisherPipeline = read('src/domain/realtime/local/publisherPipeline.ts');
  requireContains(publisherPipeline, "runtimeId: 'wlvc_sfu'", 'publisher sends explicit runtime id');
  requireContains(publisherPipeline, 'codecId: currentSfuCodecId', 'publisher sends explicit codec id');
  requireContains(sfuMessageHandler, 'normalizeTilePatchMetadata(tileMetadataInput)', 'inbound frame path uses centralized tile metadata normalization');
  requireContains(sfuMessageHandler, 'invalid_tile_metadata', 'inbound frame path rejects invalid tile metadata');
  assert.ok(!sfuClient.includes('legacy_chunked_json'), 'outbound SFU sender must not preserve a legacy JSON media transport path');
  assert.ok(!sfuClient.includes('private async sendChunkedFramePayload'), 'outbound SFU sender must not expose a JSON/base64 media chunk sender');
  requireContains(sfuMessageHandler, 'codecId: stringField(msg.codecId, msg.codec_id)', 'decoded frame exposes codec id');
  requireContains(sfuMessageHandler, 'runtimeId: stringField(msg.runtimeId, msg.runtime_id)', 'decoded frame exposes runtime id');
  requireContains(sfuMessageHandler, "layoutMode: tileMetadata?.layoutMode || 'full_frame'", 'decoded frame exposes validated layout mode');

  const assembler = read('src/lib/sfu/inboundFrameAssembler.ts');
  requireContains(assembler, 'normalizeTilePatchMetadata(tileMetadataInput)', 'chunk assembler uses centralized tile metadata normalization');
  requireContains(assembler, 'invalid_tile_metadata', 'chunk assembler rejects invalid tile metadata');
  requireContains(assembler, 'codec_id: input.codecId', 'chunk assembler preserves codec id');
  requireContains(assembler, 'runtime_id: input.runtimeId', 'chunk assembler preserves runtime id');
  requireContains(assembler, 'layout_mode: input.layoutMode', 'chunk assembler preserves layout mode');
  requireContains(assembler, 'tile_indices: input.tileIndices', 'chunk assembler preserves tile indices');

  process.stdout.write('[sfu-binary-tile-wire-contract] PASS\n');
} catch (error) {
  if (error instanceof Error) {
    fail(error.message);
  }
  fail('unknown failure');
}
