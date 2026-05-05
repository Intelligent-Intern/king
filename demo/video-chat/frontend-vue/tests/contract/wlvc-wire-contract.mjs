import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

import {
  WLVC_HEADER_BYTES,
  wlvcDecodeFrame,
  wlvcEncodeFrame,
  wlvcFrameToHex,
  wlvcHexToBytes,
} from '../../src/support/wlvcFrame.ts';

function fail(message) {
  throw new Error(`[wlvc-wire-contract] FAIL: ${message}`);
}

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);
const contractPath = path.resolve(__dirname, '../../../contracts/v1/wlvc-frame.contract.json');

const raw = fs.readFileSync(contractPath, 'utf8');
const catalog = JSON.parse(raw);

try {
  assert.equal(catalog.contract_name, 'king-video-chat-wlvc-frame');
  assert.equal(catalog.contract_version, '1.0.0');
  assert.equal(catalog.header?.length_bytes, WLVC_HEADER_BYTES);

  const vector = catalog.sample_vectors?.[0];
  assert.ok(vector, 'missing sample vector');

  const frame = vector.frame;
  const expectedHex = String(vector.expected_frame_hex || '').toLowerCase();
  assert.ok(expectedHex, 'missing expected_frame_hex');

  const encoded = wlvcEncodeFrame({
    version: Number(frame.version),
    frame_type: Number(frame.frame_type),
    quality: Number(frame.quality),
    dwt_levels: Number(frame.dwt_levels),
    width: Number(frame.width),
    height: Number(frame.height),
    uv_width: Number(frame.uv_width),
    uv_height: Number(frame.uv_height),
    y_hex: String(frame.y_hex || ''),
    u_hex: String(frame.u_hex || ''),
    v_hex: String(frame.v_hex || ''),
  });
  assert.equal(encoded.ok, true, `sample encode failed: ${encoded.error_code ?? 'unknown'}`);
  assert.equal(wlvcFrameToHex(encoded.bytes), expectedHex, 'encoded sample hex mismatch');

  const decoded = wlvcDecodeFrame(wlvcHexToBytes(expectedHex));
  assert.equal(decoded.ok, true, `sample decode failed: ${decoded.error_code ?? 'unknown'}`);
  assert.equal(decoded.frame.version, 1);
  assert.equal(decoded.frame.frame_type, 0);
  assert.equal(decoded.frame.quality, 73);
  assert.equal(decoded.frame.dwt_levels, 4);
  assert.equal(decoded.frame.width, 640);
  assert.equal(decoded.frame.height, 360);
  assert.equal(decoded.frame.uv_width, 320);
  assert.equal(decoded.frame.uv_height, 180);
  assert.equal(wlvcFrameToHex(decoded.frame.y_data), '0a0b0c0d');
  assert.equal(wlvcFrameToHex(decoded.frame.u_data), '1112');
  assert.equal(wlvcFrameToHex(decoded.frame.v_data), 'aabbcc');

  const badMagic = new Uint8Array(encoded.bytes);
  badMagic[0] = 0x58;
  const badMagicDecoded = wlvcDecodeFrame(badMagic);
  assert.equal(badMagicDecoded.ok, false);
  assert.equal(badMagicDecoded.error_code, 'magic_mismatch');

  const shortHeaderDecoded = wlvcDecodeFrame(encoded.bytes.slice(0, WLVC_HEADER_BYTES - 1));
  assert.equal(shortHeaderDecoded.ok, false);
  assert.equal(shortHeaderDecoded.error_code, 'frame_too_short');

  const shortPayloadDecoded = wlvcDecodeFrame(encoded.bytes.slice(0, encoded.bytes.length - 1));
  assert.equal(shortPayloadDecoded.ok, false);
  assert.equal(shortPayloadDecoded.error_code, 'payload_length_mismatch');

  const badFrameType = new Uint8Array(encoded.bytes);
  badFrameType[5] = 0x07;
  const badFrameTypeDecoded = wlvcDecodeFrame(badFrameType);
  assert.equal(badFrameTypeDecoded.ok, false);
  assert.equal(badFrameTypeDecoded.error_code, 'frame_type_invalid');

  process.stdout.write('[wlvc-wire-contract] PASS\n');
} catch (error) {
  if (error instanceof Error) {
    fail(error.message);
  }
  fail('unknown failure');
}
