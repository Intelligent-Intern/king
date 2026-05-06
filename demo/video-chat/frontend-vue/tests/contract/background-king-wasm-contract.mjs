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

function requireContains(source, needle, label) {
  assert.ok(source.includes(needle), `${label} missing: ${needle}`);
}

function requireMissing(source, needle, label) {
  assert.ok(!source.includes(needle), `${label} must not contain: ${needle}`);
}

try {
  const stream = readUtf8('src/domain/realtime/background/stream.ts');
  const backend = readUtf8('src/domain/realtime/background/backendKingWasm.ts');
  const wasmCodec = readUtf8('src/lib/wasm/wasm-codec.ts');
  const exportsCpp = readUtf8('src/lib/wasm/cpp/exports.cpp');
  const segmenterCpp = readUtf8('src/lib/wasm/cpp/background_segmenter.cpp');
  const selector = readUtf8('src/domain/realtime/background/backendSelector.ts');

  requireContains(stream, "import { createKingWasmSegmentationBackend } from './backendKingWasm';", 'production background stream');
  requireContains(stream, 'segmentationBackend = await createKingWasmSegmentationBackend({', 'production backend construction');
  requireMissing(stream, 'createMediaPipeSegmentationBackend', 'production background stream');
  requireMissing(stream, 'createTfjsSegmentationBackend', 'production background stream');

  requireContains(backend, "kind: 'king_wasm'", 'King WASM backend identity');
  requireContains(backend, 'const alpha = refiner.segment(frame.data);', 'King WASM backend native segmentation call');
  requireContains(wasmCodec, 'createKingBackgroundMatteRefiner', 'TypeScript WASM matte factory');
  requireContains(wasmCodec, 'BackgroundMatteRefiner?', 'WASM module background export surface');
  requireContains(exportsCpp, 'class_<BackgroundMatteRefinerJS>("BackgroundMatteRefiner")', 'C++ embind background export');
  requireContains(exportsCpp, '.function("segment", &BackgroundMatteRefinerJS::segment)', 'C++ embind segment export');
  requireContains(segmenterCpp, 'segment_portrait_rgba', 'King-owned native bootstrap segmenter');
  requireContains(selector, "backend: 'king_wasm'", 'backend selector');
  requireMissing(selector, 'center_mask_fallback', 'backend selector');
  requireMissing(selector, 'face_detector', 'backend selector');

  console.log('[background-king-wasm-contract] PASS');
} catch (error) {
  console.error(`[background-king-wasm-contract] FAIL: ${error.message}`);
  process.exit(1);
}
