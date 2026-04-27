import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import fsPromises from 'node:fs/promises';

function fail(message) {
  throw new Error(`[wlvc-wasm-config-surface-contract] FAIL: ${message}`);
}

function requireContains(source, needle, label) {
  assert.ok(source.includes(needle), `${label} missing: ${needle}`);
}

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);
const wasmCodecPath = path.resolve(__dirname, '../../src/lib/wasm/wasm-codec.ts');
const exportsPath = path.resolve(__dirname, '../../src/lib/wasm/cpp/exports.cpp');
const codecCppPath = path.resolve(__dirname, '../../src/lib/wasm/cpp/codec.cpp');
const wlvcJsUrl = new URL('../../src/lib/wasm/wlvc.js', import.meta.url);

async function main() {
  const wasmCodecSource = fs.readFileSync(wasmCodecPath, 'utf8');
  const exportsSource = fs.readFileSync(exportsPath, 'utf8');
  const codecCppSource = fs.readFileSync(codecCppPath, 'utf8');

  requireContains(wasmCodecSource, 'dwtLevels?: number', 'wasm codec config surface');
  requireContains(wasmCodecSource, 'motionEstimation?: boolean', 'wasm codec config surface');
  requireContains(wasmCodecSource, "waveletType: config.waveletType ?? 'haar'", 'wasm encoder defaults');
  requireContains(wasmCodecSource, "colorSpace: config.colorSpace ?? 'yuv'", 'wasm encoder defaults');
  requireContains(wasmCodecSource, "entropyCoding: config.entropyCoding ?? 'rle'", 'wasm encoder defaults');
  requireContains(wasmCodecSource, 'config.motionEstimation', 'wasm advanced encoder args');
  requireContains(wasmCodecSource, 'config.dwtLevels', 'wasm advanced constructor args');

  requireContains(exportsSource, '.constructor<int, int, int, int, int, int, int, int, bool>()', 'encoder embind constructor');
  requireContains(exportsSource, '.constructor<int, int, int, int, int, int, int>()', 'decoder embind constructor');
  requireContains(codecCppSource, 'w8(2);                                    // version', 'native header v2');
  requireContains(codecCppSource, 'w8(static_cast<uint8_t>(cfg_.wavelet_type));', 'native wavelet header field');
  requireContains(codecCppSource, 'w8(static_cast<uint8_t>(cfg_.color_space));', 'native colorspace header field');
  requireContains(codecCppSource, 'w8(static_cast<uint8_t>(cfg_.entropy_coding));', 'native entropy header field');
  requireContains(codecCppSource, 'if (cfg_.motion_estimation) flags |= 0x01;', 'native motion flag usage');

  const originalFetch = globalThis.fetch;
  globalThis.fetch = async (input, init) => {
    const url = typeof input === 'string' ? input : input instanceof URL ? input.href : String(input?.url ?? input);
    if (url.startsWith('file://')) {
      const bytes = await fsPromises.readFile(fileURLToPath(url));
      const contentType = url.endsWith('.wasm') ? 'application/wasm' : 'application/javascript';
      return new Response(bytes, { status: 200, headers: { 'Content-Type': contentType } });
    }
    return originalFetch(input, init);
  };

  try {
    const createModule = (await import(wlvcJsUrl.href)).default;
    const mod = await createModule();

    const encoder = new mod.Encoder(8, 8, 60, 30, 4, 2, 1, 2, false);
    const decoder = new mod.Decoder(8, 8, 60, 4, 2, 1, 2);

    const rgba = new Uint8Array(8 * 8 * 4);
    for (let i = 0; i < rgba.length; i += 4) {
      rgba[i] = (i / 4) % 255;
      rgba[i + 1] = 80;
      rgba[i + 2] = 160;
      rgba[i + 3] = 255;
    }

    const encoded = encoder.encode(rgba, 123000);
    assert.ok(encoded instanceof Uint8Array, 'advanced encoder should return Uint8Array');
    assert.ok(encoded.length > 33, 'advanced encoder should produce non-empty payload past header');
    assert.deepEqual(Array.from(encoded.slice(0, 4)), [87, 76, 86, 67], 'encoded payload should start with WLVC magic');
    assert.equal(encoded[4], 2, 'encoded payload should use WLVC v2 header');
    assert.equal(encoded[28], 2, 'encoded payload should carry waveletType=cdf97');
    assert.equal(encoded[29], 1, 'encoded payload should carry colorSpace=rgb');
    assert.equal(encoded[30], 2, 'encoded payload should carry entropyCoding=none');
    assert.equal(encoded[31] & 0x01, 0, 'encoded payload should clear motionEstimation flag when disabled');

    const decoded = decoder.decode(encoded);
    assert.ok(decoded instanceof Uint8Array, 'advanced decoder should return Uint8Array');
    assert.equal(decoded.length, rgba.length, 'advanced decoder should return full RGBA payload');

    const audio = new mod.AudioProcessor(48000, -48.0, -16.0);
    const samples = new Float32Array([0.0, 0.0, 0.25, -0.25, 0.5, -0.5, 0.0, 0.0]);
    audio.process(samples);
    assert.equal(samples.length, 8, 'audio processor should operate in-place on sample buffers');

    encoder.delete();
    decoder.delete();
    audio.delete();
  } finally {
    globalThis.fetch = originalFetch;
  }

  process.stdout.write('[wlvc-wasm-config-surface-contract] PASS\n');
}

main().catch((error) => {
  if (error instanceof Error) {
    fail(error.message);
  }
  fail(String(error));
});
