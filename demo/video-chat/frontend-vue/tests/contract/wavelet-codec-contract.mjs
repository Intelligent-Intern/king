import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

function fail(message) {
  throw new Error(`[wavelet-codec-contract] FAIL: ${message}`);
}

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);

const codecPath = path.resolve(__dirname, '../../src/lib/wavelet/codec.ts');
const source = fs.readFileSync(codecPath, 'utf8');

function hasWrite(encBody, method, offset) {
  const re = new RegExp(`view\\.${method}\\s*\\(?${offset},`);
  return re.test(encBody);
}

function hasRead(decBody, method, offset) {
  const re = new RegExp('view\\.get' + method + '\\(' + offset + '\\)');
  return re.test(decBody);
}

try {
  const MAGIC_VAL = '0x574C5643';
  assert.ok(
    source.indexOf(MAGIC_VAL) !== -1 || source.indexOf('0x574c5643') !== -1,
    'codec.ts must define MAGIC constant (0x574c5643 = WLVC)'
  );

  assert.ok(
    source.indexOf('HEADER_BYTES') !== -1,
    'codec.ts must define HEADER_BYTES'
  );

  const headerMatch = source.match(/HEADER_BYTES\s*[=:]\s*(\d+)/);
  assert.ok(headerMatch, 'codec.ts must define HEADER_BYTES numeric value');
  const headerBytes = parseInt(headerMatch[1], 10);
  assert.equal(headerBytes, 33, 'HEADER_BYTES must be 33 for version 2');

  const encodeFnStart = source.indexOf('encodeFrame');
  assert.notEqual(encodeFnStart, -1, 'codec.ts must have encodeFrame');

  const decodeFnStart = source.indexOf('decodeFrame');
  assert.notEqual(decodeFnStart, -1, 'codec.ts must have decodeFrame');

  const encodeBody = source.slice(encodeFnStart, encodeFnStart + 8000);
  const decodeBody = source.slice(decodeFnStart, decodeFnStart + 8000);

  assert.ok(hasWrite(encodeBody, 'setUint8', 4), 'encodeFrame must write version byte at offset 4');
  assert.ok(hasWrite(encodeBody, 'setUint8', 5), 'encodeFrame must write frame type at offset 5');
  assert.ok(hasWrite(encodeBody, 'setUint8', 6), 'encodeFrame must write quality at offset 6');
  assert.ok(hasWrite(encodeBody, 'setUint8', 7), 'encodeFrame must write DWT levels at offset 7');
  assert.ok(hasWrite(encodeBody, 'setUint16', 8), 'encodeFrame must write width at offset 8');
  assert.ok(hasWrite(encodeBody, 'setUint16', 10), 'encodeFrame must write height at offset 10');
  assert.ok(hasWrite(encodeBody, 'setUint32', 12), 'encodeFrame must write yBytes at offset 12');
  assert.ok(hasWrite(encodeBody, 'setUint32', 16), 'encodeFrame must write uBytes at offset 16');
  assert.ok(hasWrite(encodeBody, 'setUint32', 20), 'encodeFrame must write vBytes at offset 20');
  assert.ok(hasWrite(encodeBody, 'setUint16', 24), 'encodeFrame must write uvW at offset 24');
  assert.ok(hasWrite(encodeBody, 'setUint16', 26), 'encodeFrame must write uvH at offset 26');
  assert.ok(hasWrite(encodeBody, 'setUint8', 28), 'encodeFrame must write waveletType at offset 28');
  assert.ok(hasWrite(encodeBody, 'setUint8', 29), 'encodeFrame must write colorSpace at offset 29');
  assert.ok(hasWrite(encodeBody, 'setUint8', 30), 'encodeFrame must write entropyMode at offset 30');
  assert.ok(hasWrite(encodeBody, 'setUint8', 31), 'encodeFrame must write flags at offset 31');
  assert.ok(hasWrite(encodeBody, 'setUint8', 32), 'encodeFrame must write blurRadius at offset 32');

  assert.ok(hasRead(decodeBody, 'Uint8', 4), 'decodeFrame must read version from offset 4');
  assert.ok(hasRead(decodeBody, 'Uint8', 28), 'decodeFrame must read waveletType from offset 28');
  assert.ok(hasRead(decodeBody, 'Uint8', 29), 'decodeFrame must read colorSpace from offset 29');
  assert.ok(hasRead(decodeBody, 'Uint8', 30), 'decodeFrame must read entropyMode from offset 30');
  assert.ok(hasRead(decodeBody, 'Uint8', 31), 'decodeFrame must read flags from offset 31');
  assert.ok(hasRead(decodeBody, 'Uint8', 32), 'decodeFrame must read blurRadius from offset 32');

  assert.ok(
    /magic !== MAGIC|getUint32\(0, false\) !== MAGIC/.test(decodeBody),
    'decodeFrame must validate magic number'
  );

  assert.ok(
    /byteLength </.test(decodeBody),
    'decodeFrame must check minimum byte length'
  );

  process.stdout.write('[wavelet-codec-contract] PASS\n');
} catch (error) {
  if (error instanceof Error) {
    fail(error.message);
  }
  fail('unknown failure');
}