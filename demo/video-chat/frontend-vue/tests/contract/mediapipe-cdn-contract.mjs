import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);
const frontendRoot = path.resolve(__dirname, '../..');

function readUtf8(relativePath) {
  return fs.readFileSync(path.join(frontendRoot, relativePath), 'utf8');
}

function requireMissingPath(relativePath, label) {
  assert.ok(!fs.existsSync(path.join(frontendRoot, relativePath)), `${label} must be removed`);
}

try {
  requireMissingPath('src/domain/realtime/background/backendMediapipe.ts', 'MediaPipe background backend');
  requireMissingPath('src/domain/realtime/background/backendTfjs.ts', 'TFJS background backend');
  requireMissingPath('src/domain/realtime/background/backendWorkerSegmenter.js', 'MediaPipe worker segmenter backend');
  requireMissingPath('src/domain/realtime/background/workers/imageSegmenterWorker.js', 'MediaPipe ImageSegmenter worker');

  const stream = readUtf8('src/domain/realtime/background/stream.ts');
  const selector = readUtf8('src/domain/realtime/background/backendSelector.ts');
  const packageJson = readUtf8('package.json');

  assert.ok(stream.includes('createSinetWasmSegmentationBackend'), 'background stream must use SINet WASM');
  assert.ok(selector.includes("backend: 'sinet_wasm'"), 'backend selector must report SINet WASM');
  assert.ok(!stream.includes('MediaPipe'), 'production background stream must not reference MediaPipe');
  assert.ok(!stream.includes('TensorFlow'), 'production background stream must not reference TensorFlow');
  assert.ok(!stream.includes('tfjs'), 'production background stream must not reference TFJS');
  assert.ok(!packageJson.includes('@mediapipe/tasks-vision'), 'frontend package must not depend on MediaPipe Tasks');
  assert.ok(packageJson.includes('mediapipe-cdn-contract.mjs'), 'legacy CDN contract name must remain executable in CI');

  console.log('[mediapipe-cdn-contract] PASS legacy MediaPipe/TFJS background fallback removed');
} catch (error) {
  console.error(`[mediapipe-cdn-contract] FAIL: ${error.message}`);
  process.exit(1);
}
