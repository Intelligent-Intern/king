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
const waveletCodec = read('src/lib/wavelet/codec.ts');

requireContains(workspace, "import { createHybridEncoder, createHybridDecoder } from '../../lib/wasm/wasm-codec';", 'hybrid codec import');
requireContains(workspace, 'SFU_WLVC_FRAME_WIDTH', 'shared WLVC frame width constant');
requireContains(workspace, 'SFU_WLVC_FRAME_HEIGHT', 'shared WLVC frame height constant');
requireContains(workspace, 'SFU_WLVC_FRAME_QUALITY', 'shared WLVC frame quality constant');
requireContains(workspace, 'decoder = await createHybridDecoder({', 'remote hybrid decoder');
requireContains(workspace, 'const nextEncoder = await createHybridEncoder({', 'local hybrid encoder');
requireContains(workspace, 'SFU_TRACK_ANNOUNCE_INTERVAL_MS', 'periodic SFU track re-announce');
requireContains(waveletCodec, 'destroy(): void {', 'software codec destroy method');
requireContains(waveletCodec, 'this.reset()', 'software codec destroy reset delegation');

process.stdout.write('[wlvc-hybrid-fallback-contract] PASS\n');
