import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

function fail(message) {
  throw new Error(`[foreground-reconnect-contract] FAIL: ${message}`);
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

function assertNoReconnectOrMediaRecycle(source, label) {
  assert.doesNotMatch(
    source,
    /\b(connectSocket|closeSocket|closeSocketLocal|scheduleReconnect|initSFU|initSfu|recycleSfu|restartSfuAfterVideoStall|publishLocalTracks|teardownLocalPublisher)\b/,
    `${label} must not reconnect websocket/media sessions`,
  );
}

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);
const root = path.resolve(__dirname, '../..');
const repoRoot = path.resolve(root, '../../..');

try {
  const helper = read(root, 'src/support/foregroundReconnect.ts');
  assert.match(helper, /window\.addEventListener\('blur', handleBackground\)/, 'foreground helper must observe blur');
  assert.match(helper, /window\.addEventListener\('focus', handleForeground\)/, 'foreground helper must track focus');
  assert.match(helper, /window\.addEventListener\('pageshow', handleForeground\)/, 'foreground helper must track pageshow');
  assert.match(helper, /window\.addEventListener\('online', handleForeground\)/, 'foreground helper must track online');
  assert.match(helper, /document\.addEventListener\('visibilitychange', handleVisibilityChange\)/, 'foreground helper must track visibility changes');
  assert.match(helper, /function attachForegroundReconnectHandlers/, 'foreground helper must expose the reconnect attachment entrypoint');
  assert.match(helper, /foregroundRecoveryArmed = false/, 'foreground helper must track an explicit recovery-armed state');
  assert.match(helper, /const shouldArmForegroundRecovery = \(event = null\) => \{[\s\S]*reason === 'pagehide'[\s\S]*reason === 'document_hidden'/, 'foreground helper must arm recovery only for true background/pagehide states');
  assert.match(helper, /if \(!shouldArmForegroundRecovery\(event\)\) \{\s*return;\s*\}/, 'visible blur must not arm foreground recovery');
  assert.match(helper, /if \(!shouldRunForegroundRecovery\(event\)\) \{\s*return;\s*\}/, 'visible focus must not run foreground recovery when no true background was observed');
  assert.match(helper, /if \(reason === 'online'\) return true;/, 'online events must still trigger recovery even without focus churn');
  assert.doesNotMatch(helper, /addEventListener\('(?:click|mousedown|pointerdown)'/, 'ordinary element interactions must not be foreground reconnect triggers');

  const joinView = read(root, 'src/domain/calls/access/JoinView.vue');
  assert.match(joinView, /attachForegroundReconnectHandlers/, 'call access join view must use foreground reconnect helper');
  assert.match(joinView, /function refreshAdmissionAfterForeground\(\)/, 'call access join view must define foreground admission refresh');
  assert.match(joinView, /admissionForegroundSnapshotPending = true;/, 'call access join view must mark snapshot refresh pending');
  const joinForegroundBlock = section(
    joinView,
    'function refreshAdmissionAfterForeground() {',
    '\nasync function enterAdmittedCall(accessId) {',
    'call access foreground admission refresh',
  );
  assert.match(joinForegroundBlock, /type: 'lobby\/queue\/request'/, 'call access foreground refresh must request a lobby snapshot');
  assert.doesNotMatch(joinForegroundBlock, /connectAdmissionSocket|scheduleAdmissionReconnect|retireAdmissionSocket|clearAdmissionReconnectTimer/, 'call access foreground refresh must not reconnect the admission socket');

  const dashboard = read(root, 'src/domain/calls/dashboard/enterCall.ts');
  assert.match(dashboard, /attachForegroundReconnectHandlers/, 'user dashboard must use foreground reconnect helper');
  assert.match(dashboard, /function refreshEnterAdmissionAfterForeground\(\)/, 'user dashboard must define modal foreground admission refresh');
  assert.match(dashboard, /enterAdmissionForegroundSnapshotPending = true;/, 'user dashboard must mark snapshot refresh pending');
  const dashboardForegroundBlock = section(
    dashboard,
    'function refreshEnterAdmissionAfterForeground() {',
    '\n\n  async function enterAdmittedCall() {',
    'dashboard foreground admission refresh',
  );
  assert.match(dashboardForegroundBlock, /type: 'lobby\/queue\/request'/, 'dashboard foreground refresh must request a lobby snapshot');
  assert.doesNotMatch(dashboardForegroundBlock, /connectEnterAdmissionSocket|scheduleEnterAdmissionReconnect|retireEnterAdmissionSocket|clearEnterAdmissionReconnectTimer/, 'dashboard foreground refresh must not reconnect the admission socket');

  const workspace = read(root, 'src/domain/realtime/CallWorkspaceView.vue');
  const workspaceTemplate = read(root, 'src/domain/realtime/CallWorkspaceView.template.html');
  const workspaceLifecycle = read(root, 'src/domain/realtime/workspace/callWorkspace/lifecycle.ts');
  const foregroundRecovery = read(root, 'src/domain/realtime/workspace/callWorkspace/foregroundRecovery.ts');
  const participantUi = read(root, 'src/domain/realtime/workspace/callWorkspace/participantUi.ts');
  assert.match(workspace, /attachForegroundReconnectHandlers/, 'workspace must use foreground reconnect helper');
  assert.match(workspace, /createWorkspaceForegroundRecoveryController/, 'workspace must delegate foreground recovery policy to the focused helper');
  assert.match(workspace, /function syncWorkspaceLifecycleForeground\(context\)/, 'workspace must define lifecycle foreground state sync');
  assert.match(workspace, /setArmed: \(value\) => \{ workspaceForegroundRecoveryArmed = value; \}/, 'workspace must mark lifecycle state through the recovery helper');
  assert.match(foregroundRecovery, /export function shouldArmWorkspaceForegroundRecovery\(context = null, documentRef = null\)/, 'workspace foreground recovery must expose a call-workspace visibility guard');
  assert.match(foregroundRecovery, /reason === 'pagehide'[\s\S]*reason === 'document_hidden'/, 'workspace foreground recovery guard must preserve true pagehide/document-hidden recovery');
  assert.doesNotMatch(foregroundRecovery, /\b(connectSocket|resetReconnectAttempt|initSfu|recycleSfu|publishLocalTracks)\b/, 'workspace lifecycle recovery must not connect websocket/media sessions');
  assert.match(foregroundRecovery, /if \(socketOpen\) \{[\s\S]*requestRoomSnapshot\?\.\(\);[\s\S]*\}/, 'foreground lifecycle may request snapshot state over an already-open socket');
  assert.match(foregroundRecovery, /action = socketHealthy && roomSyncHealthy[\s\S]*'snapshot_only'[\s\S]*'snapshot_backfill'[\s\S]*'connect_suppressed'/, 'foreground lifecycle must suppress closed-socket connect');
  assert.match(foregroundRecovery, /eventType[\s\S]*call_workspace_lifecycle_foreground_state_sync/, 'foreground lifecycle must emit diagnostics for state sync');
  const workspaceForegroundHandlersBlock = section(
    workspaceLifecycle,
    'setDetachForegroundReconnect(attachForegroundReconnectHandlers({',
    '    }));',
    'workspace foreground lifecycle handlers',
  );
  assert.match(workspaceForegroundHandlersBlock, /onBackground: \(context\) => \{\s*if \(shouldArmWorkspaceForegroundRecovery\(context, typeof document !== 'undefined' \? document : null\)\) \{\s*markWorkspaceLifecycleBackground\(context\);[\s\S]*\}/, 'workspace background callback must only mark lifecycle state');
  assert.match(workspaceForegroundHandlersBlock, /onForeground: \(context\) => \{\s*syncWorkspaceLifecycleForeground\(context\);[\s\S]*\}/, 'workspace foreground callback must only sync state and diagnostics');
  assert.doesNotMatch(workspaceForegroundHandlersBlock, /sfuBackgroundTabPolicy|pauseVideoForBackground|resumeVideoAfterForeground|connectSocket\(\)|initSFU\(\)|publishLocalTracks\(\)/, 'workspace focus/visibility handlers must not connect, reload, or publish media');
  assert.match(workspaceLifecycle, /await publishLocalTracks\(\);\s*\n\s*if \(shouldStartSfuFromLifecycle\('workspace_mount'\)\) \{\s*\n\s*initSFU\(\);/m, 'workspace mount must start local media before any policy-allowed SFU connect');
  assert.match(workspaceTemplate, /class="call-control-btn"[\s\S]*@click="toggleCamera"/, 'call controls must remain ordinary visible click targets covered by focus churn proof');
  assert.match(workspaceTemplate, /class="workspace-video-fullscreen-overlay"[\s\S]*@click\.stop="closeVideoFullscreen"/, 'fullscreen media overlay clicks must stay local to fullscreen handling');
  assert.match(workspaceTemplate, /id="workspace-fullscreen-video-slot"[\s\S]*class="workspace-fullscreen-video-slot"[\s\S]*@click\.stop/, 'fullscreen media slot clicks must not bubble into reconnect-sensitive workspace handlers');
  assert.match(workspaceTemplate, /@click="setActiveTab\('users'\)"[\s\S]*@click="setActiveTab\('chat'\)"/, 'workspace tab switches must remain ordinary button clicks');

  const setActiveTabBlock = section(participantUi, 'function setActiveTab(tab) {', '\nfunction hideRightSidebar()', 'setActiveTab handler');
  assert.match(setActiveTabBlock, /activeTab\.value = nextTab;/, 'tab switches must stay local to tab state');
  assert.match(setActiveTabBlock, /if \(isSocketOnline\.value && nextTab === 'users'\) \{\s*requestRoomSnapshot\(\);/, 'users tab may backfill state with a snapshot without reconnecting');
  assertNoReconnectOrMediaRecycle(setActiveTabBlock, 'tab switch handler');

  const callAppWorkspaceHost = read(root, 'src/domain/realtime/callApps/CallAppWorkspaceHost.vue');
  assert.match(callAppWorkspaceHost, /class="call-app-workspace-fullscreen-toggle"[\s\S]*@click\.stop="toggleWorkspaceFullscreen"/, 'Call App fullscreen toggle clicks must stay local to the Call App host');
  assert.match(callAppWorkspaceHost, /class="call-app-workspace-participants-toggle"[\s\S]*@click\.stop="toggleFullscreenParticipants"/, 'Call App participant strip toggle clicks must stay local to the Call App host');
  assert.match(callAppWorkspaceHost, /<iframe[\s\S]*class="call-app-workspace-frame"/, 'Call App iframe must stay covered by visible iframe focus churn proof');
  const callAppFullscreenToggleBlock = section(callAppWorkspaceHost, 'function toggleWorkspaceFullscreen() {', '\n\nfunction toggleFullscreenParticipants()', 'Call App fullscreen toggle handler');
  const callAppParticipantsToggleBlock = section(callAppWorkspaceHost, 'function toggleFullscreenParticipants() {', '\n</script>', 'Call App participants toggle handler');
  assertNoReconnectOrMediaRecycle(callAppFullscreenToggleBlock, 'Call App fullscreen toggle');
  assertNoReconnectOrMediaRecycle(callAppParticipantsToggleBlock, 'Call App participants toggle');

  const socketLifecycle = read(root, 'src/domain/realtime/workspace/callWorkspace/socketLifecycle.ts');
  assert.match(
    socketLifecycle,
    /const transientAuthBackendError = code === 'websocket_auth_temporarily_unavailable'[\s\S]*\|\| closeReason === 'auth_backend_error';/,
    'workspace realtime must classify auth_backend_error as a transient connect failure condition',
  );
  assert.match(
    socketLifecycle,
    /function failConnectCycleOnce\([\s\S]*eventType: 'realtime_websocket_one_shot_failed'[\s\S]*next_connect_cycle_requires_new_participant: true/,
    'workspace realtime connect failures must end the one-shot cycle until a new participant joins',
  );
  assert.doesNotMatch(
    socketLifecycle,
    /function scheduleReconnect|scheduleReconnect\(/,
    'workspace realtime must not schedule automatic websocket reconnects from focus or close handlers',
  );

  const realtimeWebsocketReconnect = fs.readFileSync(path.join(repoRoot, 'demo/video-chat/backend-king-php/http/module_realtime_websocket_reconnect.php'), 'utf8');
  const router = fs.readFileSync(path.join(repoRoot, 'demo/video-chat/backend-king-php/http/router.php'), 'utf8');
  const authSession = fs.readFileSync(path.join(repoRoot, 'demo/video-chat/backend-king-php/http/module_auth_session.php'), 'utf8');
  assert.match(realtimeWebsocketReconnect, /websocket_auth_temporarily_unavailable/, 'backend websocket liveness must not label transient auth backend errors as invalid sessions');
  assert.match(realtimeWebsocketReconnect, /Session validation is temporarily unavailable for realtime commands\./, 'backend websocket liveness must send a retryable auth backend message');
  assert.match(router, /authentication backend error transport=%s exception=%s message=%s/, 'backend router must log swallowed auth backend exceptions');
  assert.match(authSession, /session probe failed exception=%s message=%s/, 'session probe must log swallowed auth backend exceptions');

  const originalWindow = globalThis.window;
  const originalDocument = globalThis.document;
  const listeners = new Map();
  const documentRef = { visibilityState: 'visible' };
  const windowRef = {
    addEventListener(type, handler) {
      listeners.set(`window:${type}`, handler);
    },
    removeEventListener(type, handler) {
      if (listeners.get(`window:${type}`) === handler) {
        listeners.delete(`window:${type}`);
      }
    },
  };
  const documentHarness = {
    get visibilityState() {
      return documentRef.visibilityState;
    },
    set visibilityState(value) {
      documentRef.visibilityState = value;
    },
    addEventListener(type, handler) {
      listeners.set(`document:${type}`, handler);
    },
    removeEventListener(type, handler) {
      if (listeners.get(`document:${type}`) === handler) {
        listeners.delete(`document:${type}`);
      }
    },
  };

  try {
    globalThis.window = windowRef;
    globalThis.document = documentHarness;
    const helperModule = await import(`data:text/javascript;base64,${Buffer.from(helper).toString('base64')}`);
    const events = [];
    const detach = helperModule.attachForegroundReconnectHandlers({
      onBackground: (context) => events.push(['background', context.reason, context.hidden]),
      onForeground: (context) => events.push(['foreground', context.reason, context.hidden]),
    });

    listeners.get('window:blur')?.({ type: 'blur' });
    listeners.get('window:focus')?.({ type: 'focus' });
    assert.deepEqual(events, [], 'visible blur/focus must not arm or run reconnect callbacks');

    const visibleFocusTargets = [
      { tagName: 'IFRAME', className: 'call-app-workspace-frame' },
      { tagName: 'BUTTON', className: 'call-control-btn' },
      { tagName: 'BUTTON', className: 'call-app-workspace-fullscreen-toggle' },
      { tagName: 'SECTION', className: 'workspace-video-fullscreen-overlay' },
      { tagName: 'DIV', className: 'workspace-fullscreen-video-slot' },
    ];
    for (const target of visibleFocusTargets) {
      listeners.get('window:blur')?.({ type: 'blur', target });
      listeners.get('window:focus')?.({ type: 'focus', target });
    }
    assert.deepEqual(events, [], 'visible iframe, call controls, Call App fullscreen, and fullscreen media slot focus churn must not arm or run reconnect callbacks');

    documentHarness.visibilityState = 'hidden';
    listeners.get('document:visibilitychange')?.();
    assert.deepEqual(events, [['background', 'document_hidden', true]], 'hidden visibility change must arm background recovery');

    documentHarness.visibilityState = 'visible';
    listeners.get('window:focus')?.({ type: 'focus' });
    assert.deepEqual(
      events,
      [
        ['background', 'document_hidden', true],
        ['foreground', 'focus', false],
      ],
      'focus after true hidden state must run foreground recovery',
    );

    listeners.get('window:focus')?.({ type: 'focus' });
    assert.equal(events.length, 2, 'repeated visible focus must not run foreground recovery again');

    listeners.get('window:online')?.({ type: 'online' });
    assert.equal(events.at(-1)?.[0], 'foreground', 'online event must still run foreground recovery');
    assert.equal(events.at(-1)?.[1], 'online', 'online foreground recovery must preserve its reason');

    events.length = 0;
    documentHarness.visibilityState = 'visible';
    listeners.get('window:pagehide')?.({ type: 'pagehide' });
    assert.deepEqual(events, [['background', 'pagehide', false]], 'pagehide must arm true lifecycle recovery even before visibility flips');
    listeners.get('window:pageshow')?.({ type: 'pageshow' });
    assert.deepEqual(
      events,
      [
        ['background', 'pagehide', false],
        ['foreground', 'pageshow', false],
      ],
      'pageshow after pagehide must run foreground recovery',
    );

    detach();
  } finally {
    globalThis.window = originalWindow;
    globalThis.document = originalDocument;
  }

  const foregroundRecoveryModule = await import(`data:text/javascript;base64,${Buffer.from(foregroundRecovery).toString('base64')}`);
  const visibleDocument = { visibilityState: 'visible' };
  assert.equal(
    foregroundRecoveryModule.shouldArmWorkspaceForegroundRecovery({ reason: 'blur', hidden: false, visibility_state: 'visible' }, visibleDocument),
    false,
    'workspace guard must not arm recovery for visible blur',
  );
  assert.equal(
    foregroundRecoveryModule.shouldArmWorkspaceForegroundRecovery({ reason: 'click', hidden: false, visibility_state: 'visible' }, visibleDocument),
    false,
    'workspace guard must not arm recovery for ordinary clicks',
  );
  assert.equal(
    foregroundRecoveryModule.shouldArmWorkspaceForegroundRecovery({ reason: 'document_hidden', hidden: true, visibility_state: 'hidden' }, visibleDocument),
    true,
    'workspace guard must arm recovery for hidden documents',
  );
  assert.equal(
    foregroundRecoveryModule.shouldArmWorkspaceForegroundRecovery({ reason: 'pagehide', hidden: false, visibility_state: 'visible' }, visibleDocument),
    true,
    'workspace guard must arm recovery for pagehide lifecycle transitions',
  );

  const recoveryEvents = [];
  let recoveryArmed = false;
  let lastRecoveryAt = 0;
  let socketOpen = true;
  let roomSynced = true;
  let sfuConnected = true;
  let sfuOpen = true;
  const recovery = foregroundRecoveryModule.createWorkspaceForegroundRecoveryController({
    getArmed: () => recoveryArmed,
    getConnectionState: () => 'online',
    getDocument: () => ({ visibilityState: 'visible' }),
    getLastAt: () => lastRecoveryAt,
    getManualSocketClose: () => false,
    getRouteBusy: () => false,
    getSessionToken: () => 'session-token',
    hasRealtimeRoomSync: () => roomSynced,
    isSocketOpen: () => socketOpen,
    minIntervalMs: 0,
    requestRoomSnapshot: () => recoveryEvents.push('snapshot'),
    setArmed: (value) => { recoveryArmed = value; },
    setLastAt: (value) => { lastRecoveryAt = value; },
  });

  const visibleInteractionContexts = [
    ['Call App iframe click', { reason: 'click', hidden: false, visibility_state: 'visible' }],
    ['Call App iframe focus loss', { reason: 'blur', hidden: false, visibility_state: 'visible' }],
    ['workspace tab switch', { reason: 'tab_switch', hidden: false, visibility_state: 'visible' }],
    ['normal control click', { reason: 'button_click', hidden: false, visibility_state: 'visible' }],
  ];
  for (const [label, context] of visibleInteractionContexts) {
    recoveryArmed = false;
    recoveryEvents.length = 0;
    socketOpen = true;
    roomSynced = true;
    sfuConnected = true;
    sfuOpen = true;
    if (foregroundRecoveryModule.shouldArmWorkspaceForegroundRecovery(context, visibleDocument)) {
      recovery.mark();
    }
    assert.deepEqual(recovery.recover(), { recovered: false, reason: 'not_ready' }, `${label} must not arm workspace recovery`);
    assert.deepEqual(recoveryEvents, [], `${label} must not reconnect websocket/media sessions`);
  }

  recovery.mark();
  assert.equal(recoveryArmed, true, 'foreground recovery controller must arm on real background');
  assert.equal(recovery.recover().action, 'snapshot_only', 'healthy foreground recovery should not reconnect');
  assert.deepEqual(recoveryEvents, ['snapshot'], 'healthy foreground recovery must only request a snapshot');

  recoveryArmed = true;
  recoveryEvents.length = 0;
  roomSynced = false;
  assert.equal(recovery.recover().action, 'snapshot_backfill', 'open socket without room sync should request snapshot backfill');
  assert.deepEqual(recoveryEvents, ['snapshot'], 'open unsynced socket recovery must not reconnect websocket/media sessions');

  recoveryArmed = true;
  recoveryEvents.length = 0;
  socketOpen = false;
  roomSynced = false;
  assert.equal(recovery.recover().action, 'connect_suppressed', 'closed socket foreground recovery must suppress reconnect');
  assert.deepEqual(recoveryEvents, [], 'closed socket recovery must not reconnect websocket/media sessions');

  recoveryArmed = true;
  recoveryEvents.length = 0;
  socketOpen = true;
  roomSynced = true;
  sfuConnected = false;
  assert.equal(recovery.recover().action, 'snapshot_only', 'healthy socket with unhealthy SFU must stay state-only on foreground');
  assert.deepEqual(recoveryEvents, ['snapshot'], 'foreground lifecycle must not recycle SFU');

  process.stdout.write('[foreground-reconnect-contract] PASS\n');
} catch (error) {
  if (error instanceof Error) {
    fail(error.message);
  }
  fail('unknown failure');
}
