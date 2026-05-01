import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

function fail(message) {
  throw new Error(`[wlvc-binding-recovery-contract] FAIL: ${message}`);
}

function requireContains(source, needle, label) {
  assert.ok(source.includes(needle), `${label} missing: ${needle}`);
}

function requireMatchCountAtLeast(source, pattern, expectedCount, label) {
  const matches = source.match(pattern) || [];
  assert.ok(matches.length >= expectedCount, `${label} expected at least ${expectedCount} matches for ${pattern}, got ${matches.length}`);
}

function sliceBetween(source, startNeedle, endNeedle, label) {
  const start = source.indexOf(startNeedle);
  assert.notEqual(start, -1, `${label} missing start: ${startNeedle}`);
  const end = source.indexOf(endNeedle, start + startNeedle.length);
  assert.notEqual(end, -1, `${label} missing end: ${endNeedle}`);
  return source.slice(start, end);
}

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);
const codecPath = path.resolve(__dirname, '../../src/lib/wasm/wasm-codec.ts');
const source = fs.readFileSync(codecPath, 'utf8');

try {
  requireContains(source, 'function isBindingMismatchError(error: unknown, className: string): boolean', 'binding mismatch helper');
  requireContains(source, "if (!(error instanceof Error)) return false", 'binding mismatch helper');
  requireContains(source, "message.includes('Expected null or instance of')", 'binding mismatch helper');
  requireContains(source, 'return message.includes(className)', 'binding mismatch helper');

  const encoderRecreate = sliceBetween(
    source,
    'private recreateEncoder(): boolean {',
    '\n  encodeFrame(imageData: ImageData, timestamp: number): FrameData {',
    'encoder recreate block'
  );
  requireContains(encoderRecreate, 'if (!this.moduleRef) return false', 'encoder recreate block');
  requireContains(encoderRecreate, 'this.encoder?.delete()', 'encoder recreate block');
  requireContains(encoderRecreate, 'new this.moduleRef.Encoder(', 'encoder recreate block');
  requireContains(encoderRecreate, 'this.config.width', 'encoder recreate block');
  requireContains(encoderRecreate, 'this.config.height', 'encoder recreate block');
  requireContains(encoderRecreate, 'this.config.quality', 'encoder recreate block');
  requireContains(encoderRecreate, 'this.config.keyFrameInterval', 'encoder recreate block');
  requireContains(encoderRecreate, 'return true', 'encoder recreate block');

  const encoderEncode = sliceBetween(
    source,
    'encodeFrame(imageData: ImageData, timestamp: number): FrameData {',
    '\n  setQuality(quality: number): void {',
    'encoder encode block'
  );
  requireContains(encoderEncode, "if (!isBindingMismatchError(error, 'Encoder') || !this.recreateEncoder() || !this.encoder) {", 'encoder encode block');
  requireContains(encoderEncode, 'throw error', 'encoder encode block');
  requireMatchCountAtLeast(encoderEncode, /encoded\s*=\s*this\.encoder\.encode\(imageData\.data,\s*timestampUs\)/g, 2, 'encoder encode retry');

  const decoderRecreate = sliceBetween(
    source,
    'private recreateDecoder(): boolean {',
    '\n  decodeFrame(frameData: FrameData): DecodedFrame {',
    'decoder recreate block'
  );
  requireContains(decoderRecreate, 'if (!this.moduleRef) return false', 'decoder recreate block');
  requireContains(decoderRecreate, 'this.decoder?.delete()', 'decoder recreate block');
  requireContains(decoderRecreate, 'new this.moduleRef.Decoder(this.config.width, this.config.height, this.config.quality)', 'decoder recreate block');
  requireContains(decoderRecreate, 'return true', 'decoder recreate block');

  const decoderDecode = sliceBetween(
    source,
    'decodeFrame(frameData: FrameData): DecodedFrame {',
    '\n  setQuality(quality: number): void {',
    'decoder decode block'
  );
  requireContains(decoderDecode, "if (!isBindingMismatchError(error, 'Decoder') || !this.recreateDecoder() || !this.decoder) {", 'decoder decode block');
  requireContains(decoderDecode, 'throw error', 'decoder decode block');
  requireMatchCountAtLeast(decoderDecode, /rgba\s*=\s*this\.decoder\.decode\(encoded\)/g, 2, 'decoder decode retry');

  process.stdout.write('[wlvc-binding-recovery-contract] PASS\n');
} catch (error) {
  if (error instanceof Error) {
    fail(error.message);
  }
  fail('unknown failure');
}
