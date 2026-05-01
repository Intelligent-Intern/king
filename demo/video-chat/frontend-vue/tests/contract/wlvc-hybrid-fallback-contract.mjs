import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const frontendRoot = path.resolve(__dirname, '../..');

function read(relPath) {
  return fs.readFileSync(path.join(frontendRoot, relPath), 'utf8');
}

function requireContains(source, needle, label) {
  assert.ok(source.includes(needle), `[wlvc-hybrid-fallback-contract] missing ${label}`);
}

const workspace = read('src/domain/realtime/CallWorkspaceView.vue');
const publisherPipeline = read('src/domain/realtime/local/publisherPipeline.js');
const mediaStack = read('src/domain/realtime/workspace/callWorkspace/mediaStack.js');
const frameDecode = read('src/domain/realtime/sfu/frameDecode.js');
const waveletCodec = read('src/lib/wavelet/codec.ts');

requireContains(publisherPipeline, "import { createHybridEncoder } from '../../../lib/wasm/wasm-codec';", 'hybrid encoder import');
requireContains(mediaStack, "import { createHybridDecoder } from '../../../../lib/wasm/wasm-codec';", 'hybrid decoder import');
requireContains(frameDecode, "import { createHybridDecoder } from '../../../lib/wasm/wasm-codec';", 'hybrid patch decoder import');
requireContains(workspace, 'SFU_WLVC_FRAME_WIDTH', 'shared WLVC frame width constant');
requireContains(workspace, 'SFU_WLVC_FRAME_HEIGHT', 'shared WLVC frame height constant');
requireContains(workspace, 'SFU_WLVC_FRAME_QUALITY', 'shared WLVC frame quality constant');
requireContains(mediaStack, 'createHybridDecoder,', 'remote hybrid decoder callback');
requireContains(frameDecode, 'nextDecoder = await createHybridDecoder({', 'remote hybrid patch decoder');
requireContains(publisherPipeline, 'const nextEncoder = await createHybridEncoder({', 'local hybrid encoder');
requireContains(workspace, 'SFU_TRACK_ANNOUNCE_INTERVAL_MS', 'periodic SFU track re-announce');
requireContains(waveletCodec, 'destroy(): void {', 'software codec destroy method');
requireContains(waveletCodec, 'this.reset()', 'software codec destroy reset delegation');

process.stdout.write('[wlvc-hybrid-fallback-contract] PASS\n');
