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
  const socketLifecycle = readFrontend('src/domain/realtime/workspace/callWorkspace/socketLifecycle.ts');
  const workspaceLifecycle = readFrontend('src/domain/realtime/workspace/callWorkspace/lifecycle.ts');
  const orchestration = readFrontend('src/domain/realtime/workspace/callWorkspace/orchestration.ts');
  assert.match(
    socketLifecycle,
    /function closeSocket\(options = \{\}\)[\s\S]*const leaveRoom = options\?\.leaveRoom === true;/,
    'workspace closeSocket must distinguish real leave from reconnect',
  );
  assert.match(
    socketLifecycle,
    /if \(leaveRoom && socket\.readyState === WebSocket\.OPEN\) \{[\s\S]*socket\.send\(JSON\.stringify\(\{ type: 'room\/leave' \}\)\);/,
    'workspace must send room/leave before closing a real leave socket',
  );
  assert.match(
    socketLifecycle,
    /previousSocket\.close\(1000, 'reconnect'\);/,
    'workspace reconnect socket replacement must not be treated as room leave',
  );
  assert.match(
    workspaceLifecycle,
    /closeSocket\(\{ leaveRoom: true \}\);/,
    'workspace unmount must explicitly leave the realtime room',
  );
  assert.match(
    orchestration,
    /async function reportExplicitCallLeave\(\)[\s\S]*apiRequest\(`\/api\/calls\/\$\{encodeURIComponent\(callId\)\}\/leave`, \{[\s\S]*method: 'POST'/,
    'workspace explicit hangup must notify the backend leave endpoint',
  );
  assert.match(
    orchestration,
    /void reportExplicitCallLeave\(\);/,
    'workspace hangup must report explicit leave before routing away',
  );

  const moduleCalls = readRepo('demo/video-chat/backend-king-php/http/module_calls.php');
  assert.match(
    moduleCalls,
    /require_once __DIR__ \. '\/module_calls_leave\.php';[\s\S]*videochat_handle_call_leave_routes\(/,
    'backend call module must dispatch the call leave endpoint',
  );
  const moduleCallsLeave = readRepo('demo/video-chat/backend-king-php/http/module_calls_leave.php');
  assert.match(
    moduleCallsLeave,
    /\/api\/calls\/\(\[A-Za-z0-9\._-\]\{1,200\}\)\/leave\$/,
    'backend must expose an authenticated call leave endpoint',
  );
  const callManagementCancel = readRepo('demo/video-chat/backend-king-php/domain/calls/call_management_cancel.php');
  assert.match(
    callManagementCancel,
    /function videochat_leave_call\(PDO \$pdo, string \$callId, int \$authUserId, string \$authRole, \?int \$tenantId = null\): array[\s\S]*\$isOwner[\s\S]*videochat_end_call\(/,
    'backend owner leave must route through the terminal end-call lifecycle',
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
