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
requireContains(workspace, 'decoder = await createHybridDecoder({ width: 640, height: 480, quality: 75 });', 'remote hybrid decoder');
requireContains(workspace, 'const nextEncoder = await createHybridEncoder({', 'local hybrid encoder');
requireContains(waveletCodec, 'destroy(): void {', 'software codec destroy method');
requireContains(waveletCodec, 'this.reset()', 'software codec destroy reset delegation');

process.stdout.write('[wlvc-hybrid-fallback-contract] PASS\n');
