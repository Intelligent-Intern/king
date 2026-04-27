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

  const tileMetadata = read('src/lib/sfu/tilePatchMetadata.ts');
  requireContains(tileMetadata, "export type SfuLayoutMode = 'full_frame' | 'tile_foreground' | 'background_snapshot'", 'tile metadata layout modes');
  requireContains(tileMetadata, 'serializeTilePatchMetadata', 'tile metadata serializer');
  requireContains(tileMetadata, 'tile_indices', 'tile metadata must preserve tile indices');
  requireContains(tileMetadata, 'roi_norm_width', 'tile metadata must preserve ROI width');

  const sfuClient = read('src/lib/sfu/sfuClient.ts');
  requireContains(sfuClient, 'layout_mode: payload.layout_mode', 'legacy chunk fallback preserves layout mode');
  requireContains(sfuClient, 'tile_indices: payload.tile_indices', 'legacy chunk fallback preserves tile indices');
  requireContains(sfuClient, 'layoutMode: normalizeLayoutMode', 'decoded frame exposes layout mode');

  const assembler = read('src/lib/sfu/inboundFrameAssembler.ts');
  requireContains(assembler, 'layout_mode: input.layoutMode', 'chunk assembler preserves layout mode');
  requireContains(assembler, 'tile_indices: input.tileIndices', 'chunk assembler preserves tile indices');

  process.stdout.write('[sfu-binary-tile-wire-contract] PASS\n');
} catch (error) {
  if (error instanceof Error) {
    fail(error.message);
  }
  fail('unknown failure');
}
