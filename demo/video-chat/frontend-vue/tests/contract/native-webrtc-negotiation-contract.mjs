import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

function fail(message) {
  throw new Error(`[native-webrtc-negotiation-contract] FAIL: ${message}`);
}

function functionBody(source, name) {
  const marker = `function ${name}`;
  const start = source.indexOf(marker);
  assert.notEqual(start, -1, `missing ${name}`);
  const open = source.indexOf('{', start);
  assert.notEqual(open, -1, `missing ${name} body`);

  let depth = 0;
  for (let index = open; index < source.length; index += 1) {
    const char = source[index];
    if (char === '{') depth += 1;
    if (char === '}') {
      depth -= 1;
      if (depth === 0) {
        return source.slice(open + 1, index);
      }
    }
  }
  fail(`unterminated ${name}`);
}

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);
const workspacePath = path.resolve(__dirname, '../../src/domain/realtime/CallWorkspaceView.vue');
const source = fs.readFileSync(workspacePath, 'utf8');

try {
  const ensureLocalMediaBody = functionBody(source, 'ensureLocalMediaForNativeNegotiation');
  assert.match(ensureLocalMediaBody, /publishLocalTracks\(\)/, 'native negotiation must acquire local media when missing');

  const offerBody = functionBody(source, 'sendNativeOffer');
  assert.match(
    offerBody,
    /await ensureLocalMediaForNativeNegotiation\(\);[\s\S]*await syncNativePeerLocalTracks\(peer\);[\s\S]*createOffer\(\)/,
    'native offers must include current local tracks before createOffer'
  );

  const answerBody = functionBody(source, 'handleNativeOfferSignal');
  assert.match(
    answerBody,
    /await ensureLocalMediaForNativeNegotiation\(\);[\s\S]*setRemoteDescription[\s\S]*await syncNativePeerLocalTracks\(peer\);[\s\S]*createAnswer\(\)/,
    'native answers must bind current local tracks to the offered transceiver before createAnswer'
  );

  assert.match(
    source,
    /if \(peer\.initiator && !peer\.negotiating\) \{\s*void sendNativeOffer\(peer\);/m,
    'native initiator must renegotiate after local track changes'
  );

  process.stdout.write('[native-webrtc-negotiation-contract] PASS\n');
} catch (error) {
  if (error instanceof Error) {
    fail(error.message);
  }
  fail('unknown failure');
}
