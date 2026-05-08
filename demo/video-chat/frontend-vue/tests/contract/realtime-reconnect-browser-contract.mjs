import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

function fail(message) {
  throw new Error(`[realtime-reconnect-browser-contract] FAIL: ${message}`);
}

function read(root, relativePath) {
  return fs.readFileSync(path.join(root, relativePath), 'utf8');
}

function section(source, start, end, label) {
  const startIndex = source.indexOf(start);
  assert.notEqual(startIndex, -1, `${label} start missing`);
  const endIndex = source.indexOf(end, startIndex + start.length);
  assert.notEqual(endIndex, -1, `${label} end missing`);
  return source.slice(startIndex, endIndex);
}

function assertIncludes(source, needle, message) {
  assert.ok(source.includes(needle), message);
}

function assertOrder(source, first, second, message) {
  const firstIndex = source.indexOf(first);
  const secondIndex = source.indexOf(second);
  assert.ok(firstIndex >= 0, `${message}: first anchor missing`);
  assert.ok(secondIndex >= 0, `${message}: second anchor missing`);
  assert.ok(firstIndex < secondIndex, message);
}

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);
const root = path.resolve(__dirname, '../..');

try {
  const packageJson = JSON.parse(read(root, 'package.json'));
  assert.equal(
    packageJson.scripts['test:contract:realtime-reconnect-browser'],
    'node tests/contract/realtime-reconnect-browser-contract.mjs',
    'package script must expose the focused browser reconnect contract',
  );

  const socketLifecycle = read(root, 'src/domain/realtime/workspace/callWorkspace/socketLifecycle.ts');
  const workspace = read(root, 'src/domain/realtime/CallWorkspaceView.vue');

  assertIncludes(
    socketLifecycle,
    "'websocket_reconnect_backfill_unavailable'",
    'browser lifecycle must know the retryable reconnect backfill code from the backend contract',
  );
  assert.match(
    socketLifecycle,
    /const transientAuthBackendError = code === 'websocket_auth_temporarily_unavailable'[\s\S]*\|\| closeReason === 'auth_backend_error';/,
    'auth backend websocket errors must remain retryable in the workspace lifecycle',
  );
  assert.match(
    socketLifecycle,
    /const transientReconnectBackfillError = code === 'websocket_reconnect_backfill_unavailable'[\s\S]*RETRYABLE_RECONNECT_BACKFILL_REASONS\.includes\(closeReason\);/,
    'reconnect backfill failures must be classified with retryable auth/backend failures',
  );

  const retryableSystemErrorBlock = section(
    socketLifecycle,
    'if (retryableRealtimeReconnectError) {',
    "      if (code === 'websocket_session_invalidated'",
    'retryable system/error handler',
  );
  assertIncludes(retryableSystemErrorBlock, 'state.manualSocketClose = false;', 'retryable websocket errors must not become manual closes');
  assertIncludes(retryableSystemErrorBlock, "refs.connectionState.value = 'retrying';", 'retryable websocket errors must leave the UI in retrying state');
  assertIncludes(retryableSystemErrorBlock, "eventType: 'realtime_websocket_retryable_error'", 'retryable websocket errors must emit a diagnostic');
  assertIncludes(retryableSystemErrorBlock, 'retryable: true', 'retryable diagnostic must carry retryable=true');
  assertIncludes(retryableSystemErrorBlock, 'requested_room_id: refs.desiredRoomId.value', 'retryable diagnostic must include requested call room scope');
  assertIncludes(retryableSystemErrorBlock, 'active_call_id: refs.activeSocketCallId.value', 'retryable diagnostic must include call scope');
  assertIncludes(retryableSystemErrorBlock, 'closeSocketLocal();', 'retryable websocket errors may recycle the socket');
  assertIncludes(retryableSystemErrorBlock, 'scheduleReconnect();', 'retryable websocket errors must schedule reconnect');
  assert.doesNotMatch(
    retryableSystemErrorBlock,
    /connectionState\.value = 'expired'|connectionState\.value = 'blocked'|manualSocketClose = true|location\.reload|window\.location\.reload|logoutSession|router\.replace/,
    'retryable auth/backfill errors must not trigger logout, reload, blocked, or expired UI paths',
  );

  const closeHandlerBlock = section(
    socketLifecycle,
    "socket.addEventListener('close', (event) => {",
    '      negotiationTimer = setTimeout',
    'websocket close handler',
  );
  assertIncludes(closeHandlerBlock, 'const retryableBackfillClose = RETRYABLE_RECONNECT_BACKFILL_REASONS.includes(closeReason);', 'close handler must classify retryable backfill closes');
  assert.match(
    closeHandlerBlock,
    /if \(retryableBackfillClose\) \{[\s\S]*refs\.connectionState\.value = 'retrying';[\s\S]*captureRetryableReconnectClose\(closeReason, event\);[\s\S]*scheduleReconnect\(\);[\s\S]*return;/,
    'retryable backfill close must diagnose and retry instead of ending the call',
  );
  assert.match(
    closeHandlerBlock,
    /if \(closeReason === 'auth_backend_error' \|\| event\?\.code === 1011\) \{[\s\S]*refs\.connectionState\.value = 'retrying';[\s\S]*captureRetryableReconnectClose\(closeReason \|\| 'socket_internal_error', event\);[\s\S]*scheduleReconnect\(\);/,
    'auth backend/internal closes must remain retryable and diagnostic',
  );

  const originExhaustedBlock = section(
    socketLifecycle,
    'if (originIndex >= orderedSocketOrigins.length) {',
    '      const socketOrigin = orderedSocketOrigins[originIndex] || \'\';',
    'websocket origin exhaustion handler',
  );
  assertIncludes(originExhaustedBlock, "refs.connectionState.value = 'retrying';", 'pre-upgrade websocket failures must leave the UI retrying');
  assertIncludes(originExhaustedBlock, "refs.connectionReason.value = 'socket_unreachable';", 'pre-upgrade websocket failures must keep a retry reason');
  assertIncludes(originExhaustedBlock, "eventType: 'realtime_websocket_retryable_error'", 'pre-upgrade websocket failures must emit retryable diagnostics');
  assertIncludes(originExhaustedBlock, "code: 'websocket_connect_retry_scheduled'", 'pre-upgrade websocket diagnostic must use a stable retry code');
  assertIncludes(originExhaustedBlock, 'retryable: true', 'pre-upgrade websocket diagnostic must carry retryable=true');
  assertIncludes(originExhaustedBlock, 'scheduleReconnect();', 'pre-upgrade websocket failures must retry instead of expiring the session');
  assert.doesNotMatch(
    originExhaustedBlock,
    /connectionState\.value = 'expired'|connectionState\.value = 'blocked'|manualSocketClose = true|location\.reload|window\.location\.reload|logoutSession|router\.replace/,
    'pre-upgrade websocket failures must not trigger logout, reload, blocked, or expired UI paths',
  );

  const openHandlerBlock = section(
    socketLifecycle,
    "socket.addEventListener('open', () => {",
    "      socket.addEventListener('message', handleSocketMessage);",
    'websocket open handler',
  );
  assertOrder(
    openHandlerBlock,
    "refs.connectionState.value = 'online';",
    'requestRoomSnapshot();',
    'successful reconnect must request authoritative room snapshot after the socket is online',
  );
  assertIncludes(openHandlerBlock, 'reconnect: isReconnectOpen', 'open diagnostic must identify reconnect opens');

  const welcomeBlock = section(
    socketLifecycle,
    "if (type === 'system/welcome') {",
    "    if (type === 'room/snapshot') {",
    'system welcome handler',
  );
  assertIncludes(welcomeBlock, 'requestRoomSnapshot();', 'system welcome must request room snapshot backfill');
  assertIncludes(
    workspace,
    "sendSocketFrame({ type: 'room/snapshot/request' })",
    'workspace snapshot request must use the room/snapshot/request websocket command',
  );
  assert.doesNotMatch(
    socketLifecycle,
    /location\.reload|window\.location\.reload|logoutSession|router\.replace/,
    'socket lifecycle must not handle reconnect auth/backfill failures through reload or logout navigation',
  );

  process.stdout.write('[realtime-reconnect-browser-contract] PASS\n');
} catch (error) {
  if (error instanceof Error) {
    fail(error.message);
  }
  fail('unknown failure');
}
