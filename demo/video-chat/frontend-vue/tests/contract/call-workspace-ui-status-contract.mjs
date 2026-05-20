import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

function fail(message) {
  throw new Error(`[call-workspace-ui-status-contract] FAIL: ${message}`);
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
    packageJson.scripts['test:contract:call-workspace-ui-status'],
    'node tests/contract/call-workspace-ui-status-contract.mjs',
    'package script must expose the focused workspace UI status contract',
  );

  const workspace = read(root, 'src/domain/realtime/CallWorkspaceView.vue');
  const template = read(root, 'src/domain/realtime/CallWorkspaceView.template.html');
  const participantUi = read(root, 'src/domain/realtime/workspace/callWorkspace/participantUi.ts');
  const socketLifecycle = read(root, 'src/domain/realtime/workspace/callWorkspace/socketLifecycle.ts');
  const callAppCrdtBridge = read(root, 'src/domain/realtime/callApps/useCallAppCrdtBridge.js');
  const callAppDiagnostics = read(root, 'src/domain/realtime/callApps/callAppDiagnostics.js');
  const callAppDiagnosticTailBridge = read(root, 'src/domain/realtime/callApps/callAppDiagnosticTailBridge.js');
  const callAppPresenceRelay = read(root, 'src/domain/realtime/callApps/callAppPresenceRelay.js');

  const callAckBlock = section(
    socketLifecycle,
    "    if (type === 'call/ack') {",
    "    if (type === 'call/gossip-topology') {",
    'call ack handler',
  );
  assertIncludes(callAckBlock, 'scheduleNativeOfferRetryForUserId', 'offer acks with no peers still trigger native offer retry');
  assert.doesNotMatch(callAckBlock, /setNotice\s*\(/, 'transport call acks must not write green workspace banners');
  assert.doesNotMatch(socketLifecycle, /setNotice\s*\(\s*`Sent\s+[^`]*peer\(s\)\.?`/, 'socket lifecycle must not format Sent-to-peer ack notices');

  const chatAckBlock = section(
    socketLifecycle,
    "    if (type === 'chat/ack') {",
    "    if (type === 'system/error') {",
    'chat ack handler',
  );
  assert.doesNotMatch(chatAckBlock, /setNotice\s*\(/, 'chat acks must not write workspace banners');

  const gossipTelemetryAckBlock = section(
    socketLifecycle,
    "    if (type === 'gossip/telemetry/ack') {",
    "    if (type === 'system/welcome') {",
    'gossip telemetry ack handler',
  );
  assertIncludes(gossipTelemetryAckBlock, 'applyGossipTelemetryAck(payload);', 'gossip telemetry ack must still update transport telemetry state');
  assert.doesNotMatch(gossipTelemetryAckBlock, /setNotice|workspaceNotice|workspaceError|console\./, 'gossip telemetry acks must not write banners or console output');

  const signalingEventBlock = section(
    socketLifecycle,
    '  function handleSignalingEvent(payload) {',
    '  function handleSocketMessage(event) {',
    'signaling event handler',
  );
  assertIncludes(signalingEventBlock, "if (type === CALL_APP_PRESENCE_SIGNAL_TYPE) {\n      handleCallAppPresenceSignal(payloadBody || {}, sender);\n      return;\n    }", 'call-app presence must be consumed as an internal relay event');
  assertIncludes(signalingEventBlock, 'if (isInternalStatusSignalType(type)) {\n      return;\n    }', 'unconsumed internal signaling events must be dropped before fallback notices');
  assertOrder(
    signalingEventBlock,
    'if (isInternalStatusSignalType(type)) {\n      return;\n    }',
    'setNotice(`Received ${type.replace(\'call/\', \'\')} from ${senderName}.`);',
    'internal signaling types must be filtered before generic green receive banners',
  );
  assert.match(
    socketLifecycle,
    /const INTERNAL_STATUS_SIGNAL_TYPES = Object\.freeze\(\[[\s\S]*CALL_APP_PRESENCE_SIGNAL_TYPE[\s\S]*'call-app\/grants-updated'[\s\S]*'call\/gossip-recovery'[\s\S]*'gossip\/recovery\/request'/,
    'socket lifecycle must classify call-app and gossip internals as non-UI status signals',
  );

  assert.match(
    participantUi,
    /function isTransportAckNotice\(message\)[\s\S]*\^Sent\\s\+\.\+\\s\+to\\s\+\\d\+\\s\+peer\\\(s\\\)/,
    'participant UI must keep a defensive transport ack notice filter',
  );
  assert.match(
    participantUi,
    /function isTransportAckNotice\(message\)[\s\S]*call-app\\\/presence/,
    'exact call-app presence transport ack text must be filtered defensively',
  );
  assert.match(
    participantUi,
    /function isReconnectRetryNotice\(message\)[\s\S]*reconnect[\s\S]*retry[\s\S]*socket_unreachable/,
    'participant UI must keep reconnect/retry copy out of workspace notices',
  );
  assert.match(
    participantUi,
    /function isRealtimeConnectionNotice\(message\)[\s\S]*realtime\\s\+\(\?:websocket\|socket\)[\s\S]*control\[-_ \]lane[\s\S]*websocket_one_shot/i,
    'participant UI must keep realtime connection failure copy out of visible workspace notices',
  );

  const setNoticeBlock = section(
    participantUi,
    "function setNotice(message, kind = 'ok') {",
    'function clearTransientActivityPublishErrorNotice()',
    'setNotice',
  );
  assertIncludes(setNoticeBlock, 'const normalizedMessage = String(message || \'\').trim();', 'notices must normalize message text before display');
  assertOrder(
    setNoticeBlock,
    'isTransportAckNotice(normalizedMessage)',
    'workspaceNotice.value = normalizedMessage;',
    'noisy status text must be filtered before workspaceNotice is written',
  );
  assertOrder(
    setNoticeBlock,
    'isRealtimeConnectionNotice(normalizedMessage)',
    'workspaceNotice.value = normalizedMessage;',
    'realtime transport failure text must be filtered before workspaceNotice is written',
  );

  const connectionBannerBlock = section(
    participantUi,
    'const workspaceConnectionBanner = computed(() => {',
    'const visibleWorkspaceNotice = computed(() => {',
    'workspace connection banner',
  );
  assertIncludes(connectionBannerBlock, 'void connectionState.value;', 'connection banner must still track socket state changes without rendering transport failures');
  assertIncludes(connectionBannerBlock, 'void connectionReason.value;', 'connection banner must still track socket reason changes without rendering transport failures');
  assertIncludes(connectionBannerBlock, 'return null;', 'connection banner must suppress realtime transport failures in the user-facing call UI');
  assert.doesNotMatch(connectionBannerBlock, /Connecting to realtime|Call session expired|Realtime connection/i, 'connection banner must not show transport failure copy');
  assert.doesNotMatch(connectionBannerBlock, /\b(reconnect|retry|countdown)\b/i, 'connection banner copy must not expose reconnect/retry/countdown wording');
  assertIncludes(participantUi, 'workspaceConnectionBanner,', 'participant UI helpers must expose the connection banner');

  const visibleNoticeBlock = section(
    participantUi,
    'const visibleWorkspaceNotice = computed(() => {',
    'function setNotice(message, kind = \'ok\') {',
    'visible workspace notice filter',
  );
  assertIncludes(visibleNoticeBlock, 'isRealtimeConnectionNotice(normalizedMessage)', 'rendered workspace notices must keep realtime transport failures out of visible text');
  assertIncludes(visibleNoticeBlock, "return '';", 'filtered workspace notices must render as empty text');
  assertIncludes(participantUi, 'visibleWorkspaceNotice,', 'participant UI helpers must expose the filtered notice');

  const failConnectCycleBlock = section(
    socketLifecycle,
    '  function failConnectCycleOnce({',
    '  function connectCycleAdmission()',
    'one-shot websocket failure path',
  );
  assertIncludes(failConnectCycleBlock, "eventType: 'realtime_websocket_one_shot_failed'", 'one-shot websocket failures must still be logged through diagnostics');
  assert.doesNotMatch(failConnectCycleBlock, /setNotice\(/, 'one-shot websocket failures must not render user-facing error banners');

  assertIncludes(workspace, 'connectionReason,\n  connectionState,', 'workspace must pass connection refs into participant UI helpers');
  assertIncludes(workspace, 'workspaceConnectionBanner,', 'workspace must expose the computed connection banner to the template');
  assertIncludes(template, 'v-if="workspaceConnectionBanner"', 'template must render the single connection status banner');
  assertIncludes(template, ':class="workspaceConnectionBanner.kind"', 'connection banner must carry warning/error severity');
  assertIncludes(template, 'role="status"', 'connection banner must be announced as status');
  assertIncludes(template, 'aria-live="polite"', 'connection banner must use polite live-region semantics');
  assertIncludes(workspace, 'visibleWorkspaceNotice,', 'workspace must expose the filtered workspace notice to the template');
  assertIncludes(template, 'v-if="visibleWorkspaceNotice"', 'template must render only the filtered workspace notice');
  assert.doesNotMatch(template, /v-if="workspaceNotice"|\{\{\s*workspaceNotice\s*\}\}/, 'template must not render raw workspaceNotice directly');
  assertIncludes(template, '<OwnerAbsenceCountdownBanner :owner-absence="ownerAbsenceState" />', 'owner absence countdown remains mounted for the five-minute cycle');

  const callAppPresenceSendBlock = section(
    callAppCrdtBridge,
    '  function sendPresenceToPeers(session, payloadType, payload) {',
    '  function handlePresencePublish(frameWindow, session, message) {',
    'call app presence send path',
  );
  assertIncludes(callAppPresenceSendBlock, 'type: CALL_APP_PRESENCE_SIGNAL_TYPE,', 'call app presence must still send through the websocket signal type');
  assert.doesNotMatch(callAppPresenceSendBlock, /setNotice|workspaceNotice|workspaceError|Sent\s+/, 'call app presence publishing must not write workspace status banners');

  for (const [label, source] of [
    ['call app CRDT bridge', callAppCrdtBridge],
    ['call app diagnostics emitter', callAppDiagnostics],
    ['call app diagnostic tail bridge', callAppDiagnosticTailBridge],
    ['call app presence relay', callAppPresenceRelay],
  ]) {
    assert.doesNotMatch(source, /console\.(log|info|warn|error|debug)\s*\(/, `${label} must not produce console spam`);
  }
  assertIncludes(callAppDiagnostics, "window.dispatchEvent(new CustomEvent('king:call-app-diagnostic'", 'call-app diagnostics must be delivered as internal window events');
  assert.doesNotMatch(callAppDiagnostics, /setNotice|workspaceNotice|workspaceError/, 'call-app diagnostics must not touch workspace banners');
  assertIncludes(callAppDiagnosticTailBridge, "window.addEventListener(CALL_APP_DIAGNOSTIC_WINDOW_EVENT, handleCallAppDiagnostic);", 'diagnostic tail must subscribe to internal diagnostic events');
  assertIncludes(callAppDiagnosticTailBridge, 'postToIframe(frameWindow, session, messageType, payload);', 'diagnostic tail must forward diagnostics to the diagnostics app iframe');
  assert.doesNotMatch(callAppDiagnosticTailBridge, /setNotice|workspaceNotice|workspaceError/, 'diagnostic tail events must not touch workspace banners');
  assertIncludes(callAppPresenceRelay, 'window.dispatchEvent(new CustomEvent(CALL_APP_PRESENCE_WINDOW_EVENT,', 'call-app presence must be delivered as an internal window event');
  assert.doesNotMatch(callAppPresenceRelay, /setNotice|workspaceNotice|workspaceError|Sent\s+/, 'call-app presence relay must not write status banners');

  process.stdout.write('[call-workspace-ui-status-contract] PASS\n');
} catch (error) {
  if (error instanceof Error) {
    fail(error.message);
  }
  fail('unknown failure');
}
