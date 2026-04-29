import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

function fail(message) {
  throw new Error(`[wavelet-codec-header-contract] FAIL: ${message}`);
}

function hasWrite(encBody, method, offset) {
  const re = new RegExp(`view\\.${method}\\s*\\(${offset}\\s*,`);
  return re.test(encBody);
}

function hasRead(decBody, method, offset) {
  const re = new RegExp(`view\\.get${method}\\(${offset}(?:\\s*,|\\))`);
  return re.test(decBody);
}

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);
const codecPath = path.resolve(__dirname, '../../src/lib/wavelet/codec.ts');
const source = fs.readFileSync(codecPath, 'utf8');
const processorPipelinePath = path.resolve(__dirname, '../../src/lib/wavelet/processor-pipeline.ts');
const processorPipelineSource = fs.readFileSync(processorPipelinePath, 'utf8');

try {
  assert.ok(
    source.includes('const MAGIC = 0x574C5643') || source.includes('const MAGIC = 0x574c5643'),
    'codec.ts must define WLVC magic'
  );
  assert.ok(
    source.includes('const HEADER_BYTES_V2 = 33'),
    'codec.ts must define header v2 as 33 bytes'
  );
  assert.ok(
    source.includes('const CURRENT_CODEC_VERSION = 2'),
    'codec.ts must default to codec version 2'
  );

  const encodeFnStart = source.indexOf('encodeFrame');
  assert.notEqual(encodeFnStart, -1, 'codec.ts must have encodeFrame');
  const decodeFnStart = source.indexOf('decodeFrame');
  assert.notEqual(decodeFnStart, -1, 'codec.ts must have decodeFrame');

  const encodeBody = source.slice(encodeFnStart, encodeFnStart + 10000);
  const decodeBody = source.slice(decodeFnStart, decodeFnStart + 10000);

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
  assert.ok(hasRead(decodeBody, 'Uint8', 29), 'decodeFrame must read colorSpace from offset 29');
  assert.ok(hasRead(decodeBody, 'Uint8', 30), 'decodeFrame must read entropyMode from offset 30');

  assert.ok(
    /magic !== MAGIC|getUint32\(0, false\) !== MAGIC/.test(decodeBody),
    'decodeFrame must validate magic number'
  );
  assert.ok(
    /payload\.byteLength < HEADER_BYTES_V1/.test(decodeBody) || /byteLength < HEADER_BYTES_V1/.test(decodeBody),
    'decodeFrame must guard minimum frame length'
  );

  assert.ok(
    processorPipelineSource.includes('finally {\n          frame.close()\n        }'),
    'processor pipeline must close locally-created encoder VideoFrames in a finally block'
  );
  assert.ok(
    processorPipelineSource.includes('finally {\n              decoded.close()\n            }'),
    'processor pipeline must close decoded VideoFrames in a finally block'
  );

  process.stdout.write('[wavelet-codec-header-contract] PASS\n');
} catch (error) {
  if (error instanceof Error) {
    fail(error.message);
  }
  fail('unknown failure');
}
