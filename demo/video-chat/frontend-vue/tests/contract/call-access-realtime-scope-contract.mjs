import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

import {
  getSeedAccessLink,
  getSeedCall,
  sessionStorageKey,
  storedSessionForSeedUser,
} from '../../tests/e2e/helpers/callAccessSeedMatrix.js';

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);
const frontendRoot = path.resolve(__dirname, '../..');
const videoChatRoot = path.resolve(frontendRoot, '..');
const repoRoot = path.resolve(videoChatRoot, '../..');

function readText(relativePath) {
  return fs.readFileSync(path.join(repoRoot, relativePath), 'utf8');
}

function buildScopedSocketQuery({ roomId, callId, sessionToken }) {
  const query = new URLSearchParams();
  query.set('room', String(roomId || '').trim() || 'lobby');
  if (String(callId || '').trim() !== '') {
    query.set('call_id', String(callId).trim());
  }
  if (String(sessionToken || '').trim() !== '') {
    query.set('session', String(sessionToken).trim());
  }
  return query;
}

function parseStoredSession(storage) {
  const raw = storage.getItem(sessionStorageKey);
  return raw ? JSON.parse(raw) : null;
}

function storageWithSession(session) {
  const rows = new Map([[sessionStorageKey, JSON.stringify(session)]]);
  return {
    getItem: (key) => rows.get(key) ?? null,
    setItem: (key, value) => rows.set(key, String(value)),
    removeItem: (key) => rows.delete(key),
  };
}

const workspaceApi = readText('demo/video-chat/frontend-vue/src/domain/realtime/workspace/api.ts');
const socketLifecycle = readText('demo/video-chat/frontend-vue/src/domain/realtime/workspace/callWorkspace/socketLifecycle.ts');
const roomState = readText('demo/video-chat/frontend-vue/src/domain/realtime/workspace/callWorkspace/roomState.ts');
const callAccessSeedHelper = readText('demo/video-chat/frontend-vue/tests/e2e/helpers/callAccessSeedMatrix.js');
const realtimeReconnectBrowserContract = readText('demo/video-chat/frontend-vue/tests/contract/realtime-reconnect-browser-contract.mjs');
const backendReconnectContract = readText('demo/video-chat/backend-king-php/tests/realtime-reconnect-backfill-contract.php');
const backendCallContext = readText('demo/video-chat/backend-king-php/domain/realtime/realtime_call_context.php');
const backendWebsocket = readText('demo/video-chat/backend-king-php/http/module_realtime_websocket.php');

const alphaCall = getSeedCall('alpha_active');
const betaCall = getSeedCall('beta_active');
const removedMemberLink = getSeedAccessLink('removed_member_personal');
const ownerSession = storedSessionForSeedUser('alpha_call_owner', 'alpha_active');
const switchedSession = storedSessionForSeedUser('alpha_normal_user', 'alpha_active');

assert.equal(alphaCall.room_id, 'iam-alpha-room', 'alpha seed call must expose an IAM-scoped realtime room');
assert.equal(removedMemberLink.call_key, 'alpha_active', 'call-access link must bind to the alpha call for admission websocket scope');
assert.notEqual(ownerSession.sessionToken, switchedSession.sessionToken, 'session-change proof must use distinct account tokens');

const ownerQuery = buildScopedSocketQuery({
  roomId: alphaCall.room_id,
  callId: alphaCall.id,
  sessionToken: ownerSession.sessionToken,
});
assert.equal(ownerQuery.get('room'), alphaCall.room_id, 'socket query must carry the requested call room');
assert.equal(ownerQuery.get('call_id'), alphaCall.id, 'socket query must carry the requested call id');
assert.equal(ownerQuery.get('session'), ownerSession.sessionToken, 'socket query must carry the current account token');

const switchedStorage = storageWithSession(ownerSession);
assert.equal(parseStoredSession(switchedStorage).sessionToken, ownerSession.sessionToken, 'precondition: first session is stored');
switchedStorage.setItem(sessionStorageKey, JSON.stringify(switchedSession));
const switchedQuery = buildScopedSocketQuery({
  roomId: alphaCall.room_id,
  callId: alphaCall.id,
  sessionToken: parseStoredSession(switchedStorage).sessionToken,
});
assert.equal(switchedQuery.get('session'), switchedSession.sessionToken, 'reconnect after login switch must use the replacement session token');
assert.notEqual(switchedQuery.get('session'), ownerSession.sessionToken, 'reconnect after login switch must not reuse the previous account token');
assert.equal(switchedQuery.get('room'), alphaCall.room_id, 'session change must not silently switch the requested room');
assert.equal(switchedQuery.get('call_id'), alphaCall.id, 'session change must keep the explicit call backfill key');

const crossRoomQuery = buildScopedSocketQuery({
  roomId: betaCall.room_id,
  callId: betaCall.id,
  sessionToken: ownerSession.sessionToken,
});
assert.equal(crossRoomQuery.get('room'), betaCall.room_id, 'cross-room reconnect attempts must remain explicit room requests');
assert.equal(crossRoomQuery.get('call_id'), betaCall.id, 'cross-room reconnect attempts must remain explicit call requests for backend binding checks');

assert.match(
  workspaceApi,
  /query\.set\('room', normalizeRoomId\(roomId\)\)[\s\S]*if \(normalizedCallId !== ''\) \{[\s\S]*query\.set\('call_id', normalizedCallId\);[\s\S]*const token = String\(sessionState\.sessionToken \|\| ''\)\.trim\(\);[\s\S]*query\.set\('session', token\);/s,
  'socket URL builder must bind room, call_id, and the current session token at connection time',
);
assert.match(
  workspaceApi,
  /export function requestHeaders[\s\S]*const token = String\(sessionState\.sessionToken \|\| ''\)\.trim\(\);[\s\S]*headers\.authorization = `Bearer \$\{token\}`;/,
  'reconnect session probes and API requests must use the current session token',
);
assert.match(
  socketLifecycle,
  /const token = String\(refs\.sessionState\.sessionToken \|\| ''\)\.trim\(\);[\s\S]*if \(token === ''\) \{[\s\S]*refs\.connectionReason\.value = 'missing_session';[\s\S]*refs\.connectionState\.value = 'expired';/,
  'workspace reconnect must fail closed when an IAM session disappears',
);
assert.match(
  socketLifecycle,
  /const sessionProbe = await probeWorkspaceSession\(\);[\s\S]*if \(!sessionProbe\.ok\) \{[\s\S]*if \(sessionProbe\.state === 'retrying'\)[\s\S]*refs\.connectionState\.value = sessionProbe\.state;[\s\S]*return;/,
  'workspace reconnect must revalidate the current IAM session before opening the websocket',
);
assert.match(
  socketLifecycle,
  /previousSocket\.close\(1000, 'reconnect'\);/,
  'socket replacement during reconnect must be explicit and must not look like a user leave',
);
assert.match(
  socketLifecycle,
  /const socketUrl = refs\.socketUrlForRoom\(refs\.desiredRoomId\.value,\s*socketOrigin,\s*refs\.activeSocketCallId\.value\);/,
  'websocket reconnect must use the desired room and active call id for authoritative backfill',
);
assert.match(
  socketLifecycle,
  /socket\.addEventListener\('open'[\s\S]*refs\.connectionState\.value = 'online';[\s\S]*requestRoomSnapshot\(\);/s,
  'successful websocket reconnect must request an authoritative room snapshot backfill',
);
assert.match(
  socketLifecycle,
  /if \(type === 'system\/welcome'\) \{[\s\S]*const welcomeRoom = normalizeRoomId\(payload\.active_room_id \|\| refs\.desiredRoomId\.value\);[\s\S]*refs\.serverRoomId\.value = welcomeRoom;[\s\S]*applyViewerContext\(payload\?\.call_context \|\| null\);[\s\S]*requestRoomSnapshot\(\);/s,
  'system welcome must adopt the server room, apply call context, and backfill room state',
);
assert.match(
  socketLifecycle,
  /const transientReconnectBackfillError = code === 'websocket_reconnect_backfill_unavailable'[\s\S]*RETRYABLE_RECONNECT_BACKFILL_REASONS\.includes\(closeReason\);[\s\S]*scheduleReconnect\(\);/,
  'retryable reconnect/backfill errors must schedule reconnect instead of logging out',
);
assert.doesNotMatch(
  socketLifecycle,
  /location\.reload|window\.location\.reload|logoutSession|router\.replace/,
  'realtime session-change recovery must not reload or logout the browser from socket lifecycle code',
);
assert.match(
  roomState,
  /function applyViewerContext\(viewerPayload\)[\s\S]*const nextCallId = String\(viewerPayload\?\.call_id[\s\S]*activeCallId\.value = nextCallId[\s\S]*viewerCanModerateCall\.value = Boolean/s,
  'room snapshot/welcome viewer context must update active call scope and moderation rights',
);
assert.match(
  callAccessSeedHelper,
  /this\.emit\(\{[\s\S]*type: 'system\/welcome'[\s\S]*active_room_id: roomId[\s\S]*pending_room_id: roomId[\s\S]*call_id: callId/s,
  'IAM call-access fake realtime must emit welcome frames scoped to the seed room and call',
);
assert.match(
  callAccessSeedHelper,
  /if \(payload\.type === 'lobby\/queue\/join'\) \{[\s\S]*type: 'lobby\/snapshot'[\s\S]*room_id: roomId[\s\S]*call_id: callId/s,
  'IAM call-access fake realtime must backfill lobby snapshots under the same room and call scope',
);
assert.match(
  realtimeReconnectBrowserContract,
  /requested_room_id: refs\.desiredRoomId\.value[\s\S]*active_call_id: refs\.activeSocketCallId\.value/,
  'existing browser reconnect contract must require diagnostics to carry room and call scope',
);
assert.match(
  backendCallContext,
  /\$roomMismatch = \$requestedRoomInput !== '' && \$requestedRoomInput !== \$boundRoomId;[\s\S]*\$callMismatch = \$normalizedRequestedCallId !== '' && \$normalizedRequestedCallId !== \$boundCallId;[\s\S]*\$userMismatch = \$userId > 0 && \$boundUserId > 0 && \$userId !== \$boundUserId;[\s\S]*'access_session_binding' => 'mismatch'/,
  'backend room resolution must reject call-access session room/call/user binding mismatches',
);
assert.match(
  backendCallContext,
  /return videochat_realtime_room_resolution_backfill_unavailable\('access_session_binding_unavailable'\) \+ \['access_session_binding' => 'unavailable'\];/,
  'backend access-session binding lookup failures must be retryable reconnect backfill failures',
);
assert.match(
  backendWebsocket,
  /videochat_realtime_resolve_connection_rooms\([\s\S]*\$requestedRoomId[\s\S]*\$requestedCallId[\s\S]*\);[\s\S]*videochat_realtime_websocket_backfill_retry_response/,
  'websocket route must resolve requested room/call scope before upgrade and return retryable backfill failures',
);
assert.match(
  backendWebsocket,
  /'type' => 'system\/welcome'[\s\S]*'active_room_id' => \(string\) \(\$presenceConnection\['room_id'\][\s\S]*'call_context' => \[[\s\S]*'requested_call_id'[\s\S]*'call_id'[\s\S]*'admission' => \[[\s\S]*'pending_room_id'/,
  'backend welcome frame must expose active room, call context, and admission room scope',
);
assert.match(
  backendReconnectContract,
  /requested call reconnect must not fall back to lobby when backfill lookup fails[\s\S]*failed reconnect backfill must not bind a room[\s\S]*unavailable reconnect backfill must return retryable status before upgrade/s,
  'backend reconnect contract must prove failed requested-call backfill does not bind the wrong room',
);
assert.match(
  backendReconnectContract,
  /available reconnect must return to requested room[\s\S]*reconnected connection must keep active call scope[\s\S]*room snapshot viewer must keep call scope/s,
  'backend reconnect contract must prove successful reconnect/backfill restores requested room and call scope',
);

process.stdout.write('[call-access-realtime-scope-contract] PASS\n');
