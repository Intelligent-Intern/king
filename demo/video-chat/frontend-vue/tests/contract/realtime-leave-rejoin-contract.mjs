import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

function fail(message) {
  throw new Error(`[realtime-leave-rejoin-contract] FAIL: ${message}`);
}

function contractDir() {
  const __filename = fileURLToPath(import.meta.url);
  return path.dirname(__filename);
}

function readFrontend(relativePath) {
  return fs.readFileSync(path.resolve(contractDir(), '../..', relativePath), 'utf8');
}

function readRepo(relativePath) {
  return fs.readFileSync(path.resolve(contractDir(), '../../../../..', relativePath), 'utf8');
}

try {
  const workspace = readFrontend('src/domain/realtime/CallWorkspaceView.vue');
  assert.match(
    workspace,
    /function closeSocket\(options = \{\}\)[\s\S]*const leaveRoom = options\?\.leaveRoom === true;/,
    'workspace closeSocket must distinguish real leave from reconnect',
  );
  assert.match(
    workspace,
    /if \(leaveRoom && socket\.readyState === WebSocket\.OPEN\) \{[\s\S]*socket\.send\(JSON\.stringify\(\{ type: 'room\/leave' \}\)\);/,
    'workspace must send room/leave before closing a real leave socket',
  );
  assert.match(
    workspace,
    /previousSocket\.close\(1000, 'reconnect'\);/,
    'workspace reconnect socket replacement must not be treated as room leave',
  );
  assert.match(
    workspace,
    /closeSocket\(\{ leaveRoom: true \}\);/,
    'workspace unmount must explicitly leave the realtime room',
  );

  const edge = readRepo('demo/video-chat/edge/edge.php');
  assert.match(
    edge,
    /\['head' => null, 'bytes_read' => strlen\(\$head\), 'reason' => 'client_closed'\]/,
    'edge must track zero-byte client aborts separately from malformed requests',
  );
  assert.match(
    edge,
    /if \(\(int\) \(\$requestHead\['bytes_read'\] \?\? 0\) <= 0\) \{[\s\S]*@fclose\(\$client\);[\s\S]*return;/,
    'edge must silently close empty aborted handshakes instead of returning visible 400',
  );

  process.stdout.write('[realtime-leave-rejoin-contract] PASS\n');
} catch (error) {
  if (error instanceof Error) {
    fail(error.message);
  }
  fail('unknown failure');
}
