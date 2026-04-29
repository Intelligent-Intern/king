import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

function fail(message) {
  throw new Error(`[sfu-binary-envelope-copy-audit-contract] FAIL: ${message}`);
}

function requireContains(source, needle, label) {
  assert.ok(source.includes(needle), `${label} missing: ${needle}`);
}

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);
const frontendRoot = path.resolve(__dirname, '../..');

function read(relativePath) {
  return fs.readFileSync(path.resolve(frontendRoot, relativePath), 'utf8');
}

try {
  const framePayload = read('src/lib/sfu/framePayload.ts');
  const sfuClient = read('src/lib/sfu/sfuClient.ts');

  requireContains(framePayload, 'let payloadBytes = frame.data instanceof ArrayBuffer ? frame.data : new ArrayBuffer(0)', 'outbound binary media reuses the encoded ArrayBuffer');
  requireContains(framePayload, 'const dataBase64 = null', 'transport-only binary decode does not rebuild base64');
  requireContains(framePayload, 'base64UrlEncodedLength(payloadByteLength)', 'transport-only payload char metrics are calculated without allocating base64');
  requireContains(sfuClient, 'msg.data instanceof ArrayBuffer', 'inbound SFU frames consume binary payloads directly');
  requireContains(sfuClient, 'data_base64: decoded.dataBase64 || undefined', 'legacy base64 field remains absent for binary transport-only frames');
  assert.equal(
    framePayload.includes("const dataBase64 = protectionMode === 'transport_only' ? arrayBufferToBase64Url(payloadBytes) : null"),
    false,
    'transport-only binary frames must not be converted to base64 on receive',
  );
  assert.equal(
    sfuClient.includes(`dataBase64 !== ''
              ? base64UrlToArrayBuffer(dataBase64)
              : (Array.isArray(msg.data)`),
    false,
    'client must not force binary envelope payloads through base64 before ArrayBuffer delivery',
  );

  process.stdout.write('[sfu-binary-envelope-copy-audit-contract] PASS\n');
} catch (error) {
  if (error instanceof Error) {
    fail(error.message);
  }
  fail('unknown failure');
}
