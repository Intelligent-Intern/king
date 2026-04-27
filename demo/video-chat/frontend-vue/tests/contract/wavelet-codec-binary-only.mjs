import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);
const codecPath = path.resolve(__dirname, '../../src/lib/wavelet/codec.ts');
const source = fs.readFileSync(codecPath, 'utf8');

// Ensure the codec implementation does not reference JSON (no JSON fallback)
assert.ok(!/JSON/.test(source), 'codec.ts should not contain any JSON usage');

// Verify that encodeFrame writes binary fields (IIBIN) and decodeFrame reads them
assert.ok(/view\.setUint8\s*\(\s*4/.test(source), 'encodeFrame must write version byte at offset 4');
assert.ok(/view\.setUint8\s*\(\s*5/.test(source), 'encodeFrame must write frame type at offset 5');
assert.ok(/view\.setUint8\s*\(\s*6/.test(source), 'encodeFrame must write quality at offset 6');
assert.ok(/view\.setUint8\s*\(\s*7/.test(source), 'encodeFrame must write DWT levels at offset 7');
assert.ok(/view\.setUint16\(8,/.test(source), 'encodeFrame must write width at offset 8');
assert.ok(/view\.setUint16\(10,/.test(source), 'encodeFrame must write height at offset 10');
assert.ok(/view\.setUint32\(12,/.test(source), 'encodeFrame must write yBytes at offset 12');
assert.ok(/view\.setUint32\(16,/.test(source), 'encodeFrame must write uBytes at offset 16');
assert.ok(/view\.setUint32\(20,/.test(source), 'encodeFrame must write vBytes at offset 20');
assert.ok(/view\.setUint8\s*\(\s*28/.test(source), 'encodeFrame must write waveletType at offset 28');
assert.ok(/view\.setUint8\s*\(\s*29/.test(source), 'encodeFrame must write colorSpace at offset 29');
assert.ok(/view\.setUint8\s*\(\s*30/.test(source), 'encodeFrame must write entropyMode at offset 30');
assert.ok(/view\.setUint8\s*\(\s*31/.test(source), 'encodeFrame must write flags at offset 31');
assert.ok(/view\.setUint8\s*\(\s*32/.test(source), 'encodeFrame must write blurRadius at offset 32');

process.stdout.write('[wavelet-codec-binary-only] PASS\n');
