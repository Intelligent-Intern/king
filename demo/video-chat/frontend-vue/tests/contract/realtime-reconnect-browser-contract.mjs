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
    'package script must expose the focused browser websocket lifecycle contract',
  );

  const socketLifecycle = read(root, 'src/domain/realtime/workspace/callWorkspace/socketLifecycle.ts');
  const mediaPlanBridge = read(root, 'src/domain/realtime/workspace/callWorkspace/mediaCapabilityPlanBridge.ts');
  const workspace = read(root, 'src/domain/realtime/CallWorkspaceView.vue');

  assertIncludes(socketLifecycle, 'const CONNECT_CYCLE_TIMEOUT_MS = 5 * 60 * 1000;', 'connect cycle must keep the five minute timeout');
  assertIncludes(socketLifecycle, 'const CONTROL_LANE_SECOND_CONNECT_DELAY_MS = 5 * 1000;', 'control-lane second connect must wait 5 seconds before retrying');
  assertIncludes(socketLifecycle, 'const CONTROL_LANE_SECOND_CONNECT_MAX_ATTEMPTS = 1;', 'control-lane second connect must be limited to one retry');
  assertIncludes(socketLifecycle, 'const EXPECTED_BROWSER_PAGE_EXIT_SOCKET_CLOSE_GRACE_MS = 15 * 1000;', 'browser page-exit websocket closes must have a bounded classification window');
  assertIncludes(socketLifecycle, "const CALL_WORKSPACE_FORCE_RELOAD_EVENT = 'kingrt:call-workspace-force-reload';", 'forced call reloads must have a stable browser diagnostic signal');
  assertIncludes(socketLifecycle, 'window.addEventListener(CALL_WORKSPACE_FORCE_RELOAD_EVENT, markBrowserPageExitObserved', 'forced call reload intent must mark page exit before websocket close');
  assertIncludes(socketLifecycle, "window.addEventListener('beforeunload', markBrowserPageExitObserved", 'browser reload handling must mark page exit before websocket close');
  assertIncludes(socketLifecycle, "window.addEventListener('pagehide', markBrowserPageExitObserved", 'browser pagehide handling must mark page exit before websocket close');
  assertIncludes(socketLifecycle, "window.addEventListener('pageshow', clearBrowserPageExitObserved", 'browser bfcache return must clear stale page-exit state');
  assertIncludes(socketLifecycle, 'if (event?.persisted === true) return;', 'bfcache pagehide must not mark an expected reload close');
  assertIncludes(socketLifecycle, 'closeCode !== 1006 || closeReason !==', 'expected browser page-exit close classification must be limited to abnormal close without a server reason');
  assert.doesNotMatch(socketLifecycle, /function scheduleReconnect|scheduleReconnect\(/, 'socket lifecycle must not restore unbounded websocket reconnects');
  assertIncludes(socketLifecycle, 'function scheduleControlLaneSecondConnect({', 'socket lifecycle must expose the bounded control-lane second connect path');
  assertIncludes(socketLifecycle, 'function scheduleControlLaneSecondConnectReadinessCheck(reason =', 'socket lifecycle must check participant readiness before the one allowed second connect');
  assertIncludes(socketLifecycle, 'function isAbnormalControlLaneClose(event, closeReason =', 'socket lifecycle must classify abnormal opened-socket closes for the one bounded second connect');
  assertIncludes(socketLifecycle, 'function shouldScheduleCloseSecondConnect({ opened = false, event = null, closeReason =', 'socket lifecycle must centralize the close-to-second-connect admission guard');
  assertIncludes(socketLifecycle, 'state.reconnectTimer = setTimeout(() => {', 'the bounded second connect must be timer driven');
  assertIncludes(socketLifecycle, 'void connectSocket();', 'the bounded second connect timer must invoke the normal connect path');
  assert.doesNotMatch(socketLifecycle, /handleAssetVersionConnectionFailure\s*\(/, 'socket lifecycle must not use a failed-connection API probe to start another websocket');
  assert.doesNotMatch(socketLifecycle, /location\.reload|window\.location\.reload|logoutSession|router\.replace/, 'socket lifecycle must not reload or navigate the page');

  const admissionBlock = section(
    socketLifecycle,
    'function connectCycleAdmission() {',
    '  function scheduleControlLaneSecondConnect',
    'connect cycle admission',
  );
  assertIncludes(admissionBlock, 'state.connectCycleStarted !== true', 'initial connect cycle must be admitted exactly once');
  assertIncludes(admissionBlock, 'state.connectCycleSecondConnectPending === true', 'one pending control-lane second connect must be admitted');
  assertIncludes(admissionBlock, 'state.connectCycleParticipantGrowthPending === true', 'participant growth must be the only later admission path');
  assert.doesNotMatch(admissionBlock, /connectionState|visibility|focus|probe|reconnectAttempt/, 'connect admission must not depend on focus, visibility, probe, or UI retry state');

  const connectBlock = section(
    socketLifecycle,
    'async function connectSocket() {',
    '    const connectWithOriginAt = (originIndex) => {',
    'connectSocket preflight',
  );
  assertOrder(socketLifecycle, 'const admission = connectCycleAdmission();', 'const socket = new WebSocket(socketUrl);', 'one-shot admission must run before any websocket is constructed');
  assertIncludes(connectBlock, 'suppressConnectCycle(admission.reason);', 'rejected connect requests must be logged and suppressed');
  assertIncludes(connectBlock, "state.connectCycleStartedReason = admission.reason;", 'started cycles must record whether they are initial or participant-driven');
  assertIncludes(connectBlock, "const isSecondConnect = admission.reason === 'control_lane_second_connect';", 'second connect admission must keep retry accounting intact');
  assertIncludes(connectBlock, 'state.connectCycleSecondConnectPending = false;', 'pending second connect admission must be consumed by the next cycle');
  assertIncludes(connectBlock, 'state.connectCycleSecondConnectAttempts = 0;', 'initial and participant-growth cycles must reset second-connect budget');
  assertIncludes(connectBlock, "state.connectCycleParticipantGrowthPending = false;", 'participant-growth admission must be consumed by the next cycle');
  assertIncludes(connectBlock, "refs.connectionReason.value = 'probing_session';", 'session probe is part of the single connect cycle');
  assertIncludes(connectBlock, "code: 'websocket_session_probe_failed'", 'session probe failure must end the current cycle visibly');
  assertIncludes(connectBlock, "previousSocket.close(1000, 'one_shot_cycle_replaced');", 'second connect may replace transport locally without a semantic room leave');
  assert.doesNotMatch(connectBlock, /scheduleReconnect|reconnectAttempt\.value \+=|reconnectDelayMs|requestRoomSnapshot\(\);\s*return;/, 'connect preflight must not retry or turn focus/chat calls into snapshot probes');

  const closeSocketBlock = section(
    socketLifecycle,
    'function closeSocket(options = {}) {',
    '  async function probeWorkspaceSession',
    'closeSocket semantic leave boundary',
  );
  assertIncludes(closeSocketBlock, 'const leaveRoom = options?.leaveRoom === true;', 'closeSocket must make semantic room leave explicit');
  assertIncludes(closeSocketBlock, "socket.send(JSON.stringify({ type: 'room/leave' }));", 'room/leave must only be sent by explicit closeSocket leave handling');
  assertIncludes(closeSocketBlock, "leaveRoom ? 'client_leave' : 'client_close'", 'plain transport close must not masquerade as client_leave');

  const closeHandlerBlock = section(
    socketLifecycle,
    "socket.addEventListener('close', (event) => {",
    '      negotiationTimer = setTimeout',
    'websocket close handler',
  );
  assertIncludes(closeHandlerBlock, 'scheduleControlLaneSecondConnect({', 'close handler must schedule the one allowed control-lane second connect before terminal failure');
  assertIncludes(closeHandlerBlock, 'const canScheduleCloseSecondConnect = shouldScheduleCloseSecondConnect({', 'close handler must delegate close retry admission to the bounded one-retry guard');
  assertIncludes(socketLifecycle, 'return closeCode === 1006 || closeCode === 1011 || normalizedReason ===', 'opened sockets may only use the bounded second connect for abnormal control-lane closes');
  assertIncludes(closeHandlerBlock, 'if (canScheduleCloseSecondConnect && scheduleControlLaneSecondConnect({', 'socket-close second connect must be guarded by open-state and roster readiness');
  assertIncludes(closeHandlerBlock, "closeReason === 'control_lane_second_connect' && state.connectCycleSecondConnectPending === true", 'the close caused by the bounded second-connect handoff must not become a terminal failure');
  assertIncludes(closeHandlerBlock, 'failConnectCycleOnce({', 'close handler must terminate the cycle after the second-connect budget is spent');
  assertIncludes(closeHandlerBlock, 'websocket_closed_one_shot', 'close diagnostics must use a one-shot close code');
  assertIncludes(closeHandlerBlock, 'isExpectedBrowserPageExitSocketClose(event)', 'close handler must classify expected browser reload closes before one-shot failure logging');
  assertIncludes(closeHandlerBlock, 'observeExpectedBrowserPageExitSocketClose(event, closeReason, opened);', 'expected browser reload close must be diagnosed separately');
  assertOrder(closeHandlerBlock, 'isExpectedBrowserPageExitSocketClose(event)', 'failConnectCycleOnce({', 'expected browser reload close must be checked before one-shot failure logging');
  assertIncludes(socketLifecycle, "eventType: 'realtime_websocket_expected_browser_page_exit_close'", 'expected browser reload close must have a distinct diagnostic event');
  assertIncludes(socketLifecycle, 'expected_browser_page_exit_close: true', 'expected browser reload close diagnostic must be machine-classifiable');
  assert.doesNotMatch(closeHandlerBlock, /scheduleReconnect|connectSocket\(|connectWithOriginAt|handleAssetVersionConnectionFailure/, 'close handler must not restore unbounded reconnect, fail over, or run an API probe');
  assertIncludes(socketLifecycle, 'stopLocalEncodingPipeline();', 'terminal websocket failure must stop the publisher loop instead of continuing to emit unsendable frames');
  assertIncludes(socketLifecycle, 'local_encoding_stopped_after_socket_failure: true', 'terminal websocket diagnostics must prove local encoding was stopped');
  assertIncludes(socketLifecycle, 'local_encoding_stopped_before_second_connect: true', 'second-connect diagnostics must prove local encoding was paused while the socket is down');
  assertIncludes(workspace, 'stopLocalEncodingPipeline,', 'workspace must wire publisher teardown into socket lifecycle');

  const errorHandlerBlock = section(
    socketLifecycle,
    "socket.addEventListener('error', () => {",
    "      socket.addEventListener('close', (event) => {",
    'websocket error handler',
  );
  assertIncludes(errorHandlerBlock, "reason: 'socket_error'", 'error handler must expose socket_error');
  assertIncludes(errorHandlerBlock, 'failConnectCycleOnce({', 'error handler must log a terminal one-shot failure');
  assert.doesNotMatch(errorHandlerBlock, /scheduleReconnect|connectSocket\(/, 'error handler must not reconnect');

  const timeoutBlock = section(
    socketLifecycle,
    'negotiationTimer = setTimeout',
    '    };',
    'websocket negotiation timeout',
  );
  assertIncludes(timeoutBlock, 'CONNECT_CYCLE_TIMEOUT_MS', 'negotiation timeout must use the one-shot cycle timeout');
  assertIncludes(timeoutBlock, "reason: 'socket_negotiation_timeout'", 'timeout must expose a stable failure reason');
  assert.doesNotMatch(timeoutBlock, /connectSocket\(|connectWithOriginAt|scheduleReconnect/, 'timeout must not start another websocket');

  const roomSnapshotBlock = section(
    socketLifecycle,
    "if (type === 'room/snapshot') {",
    "    if (type === 'client.capabilities.v1/ack') {",
    'room snapshot handler',
  );
  assertOrder(
    roomSnapshotBlock,
    "observeExpectedParticipantRoster('room_snapshot');",
    "applyLocalMediaStateForLastPlan('room_snapshot', payload)",
    'participant growth and readiness must be observed before local media publication is requested',
  );
  assertIncludes(socketLifecycle, 'function allExpectedCallParticipantsConnected()', 'socket lifecycle must own the expected-participant readiness predicate');
  assertIncludes(socketLifecycle, 'function canStartRealtimeMediaSending()', 'media sending must use a stricter readiness predicate than roster presence alone');
  assertIncludes(socketLifecycle, "String(refs.connectionState?.value || '').trim().toLowerCase() !== 'online'", 'media sending must be blocked while the realtime socket is retrying or offline');
  assertIncludes(socketLifecycle, 'canStartRealtimeMediaSending,', 'media plan bridge must receive the strict socket-online media gate');
  assertIncludes(socketLifecycle, 'function controlLaneHasParticipantsForConnect()', 'control lane must be the authority for allowing the bounded second connect');
  assertIncludes(socketLifecycle, "scheduleControlLaneSecondConnectReadinessCheck('room_snapshot');", 'room snapshots must arm the 5 second readiness check');
  assertIncludes(socketLifecycle, "reason: 'control_lane_participants_not_connected_after_5s'", 'readiness expiry must explain why the second connect was attempted');
  assertIncludes(socketLifecycle, "eventType: 'websocket_control_lane_open_socket_kept_after_unready_roster'", 'readiness expiry must keep an already-open websocket instead of replacing call presence');
  assert.doesNotMatch(socketLifecycle, /activeSocket\.close\(1000,\s*'control_lane_second_connect'\)/, 'control-lane readiness retry must not close an open websocket and kick the participant from call presence');
  assert.doesNotMatch(
    roomSnapshotBlock,
    /closeSocket\(\{[\s\S]*leaveRoom:\s*true|room\/leave/,
    'room snapshots and readiness checks must not turn an unready roster into a semantic leave',
  );
  assertIncludes(socketLifecycle, 'second_connect_max_attempts: CONTROL_LANE_SECOND_CONNECT_MAX_ATTEMPTS', 'diagnostics must expose the one-retry limit');
  assertIncludes(socketLifecycle, 'refs.hasRealtimeRoomSync?.value !== true', 'media sending must wait for authoritative room sync');
  assertIncludes(socketLifecycle, 'expectedIds.every((userId) => connectedIds.has(userId))', 'media sending must wait for every expected participant to be connected');
  assertIncludes(socketLifecycle, 'websocket_one_shot_participant_join_observed', 'new participants must be logged as the only extra cycle unlock');

  assertIncludes(mediaPlanBridge, 'refs.canStartRealtimeMediaSending(sourcePayload) !== true', 'media plan gate must block local video/gossip until lifecycle says participants are ready');
  assertIncludes(mediaPlanBridge, 'all_expected_call_participants_connected', 'blocked media diagnostics must expose participant readiness');
  assertIncludes(workspace, 'connectedParticipantUsers,', 'workspace must pass connected participants into socket lifecycle');
  assertIncludes(workspace, 'participantUsers,', 'workspace must pass expected participants into socket lifecycle');

  process.stdout.write('[realtime-reconnect-browser-contract] PASS\n');
} catch (error) {
  if (error instanceof Error) {
    fail(error.message);
  }
  fail('unknown failure');
}
