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

  const joinView = read(root, 'src/domain/calls/access/JoinView.vue');
  assert.match(joinView, /attachForegroundReconnectHandlers/, 'call access join view must use foreground reconnect helper');
  assert.match(joinView, /function reconnectAdmissionAfterForeground\(\)/, 'call access join view must define foreground reconnect');
  assert.match(joinView, /admissionReconnectAfterForeground = true;/, 'call access join view must mark reconnect pending');
  assert.match(joinView, /connectAdmissionSocket\(accessId\)/, 'call access join view must reconnect the admission socket');

  const dashboard = read(root, 'src/domain/calls/dashboard/enterCall.ts');
  assert.match(dashboard, /attachForegroundReconnectHandlers/, 'user dashboard must use foreground reconnect helper');
  assert.match(dashboard, /function reconnectEnterAdmissionAfterForeground\(\)/, 'user dashboard must define modal foreground reconnect');
  assert.match(dashboard, /enterAdmissionReconnectAfterForeground = true;/, 'user dashboard must mark reconnect pending');
  assert.match(dashboard, /connectEnterAdmissionSocket\(\)/, 'user dashboard must reconnect the enter-call admission socket');

  const workspace = read(root, 'src/domain/realtime/CallWorkspaceView.vue');
  const workspaceTemplate = read(root, 'src/domain/realtime/CallWorkspaceView.template.html');
  const workspaceLifecycle = read(root, 'src/domain/realtime/workspace/callWorkspace/lifecycle.ts');
  const foregroundRecovery = read(root, 'src/domain/realtime/workspace/callWorkspace/foregroundRecovery.ts');
  assert.match(workspace, /attachForegroundReconnectHandlers/, 'workspace must use foreground reconnect helper');
  assert.match(workspace, /createWorkspaceForegroundRecoveryController/, 'workspace must delegate foreground recovery policy to the focused helper');
  assert.match(workspace, /function reconnectWorkspaceAfterForeground\(\)/, 'workspace must define foreground reconnect');
  assert.match(workspace, /setArmed: \(value\) => \{ workspaceReconnectAfterForeground = value; \}/, 'workspace must mark reconnect pending through the recovery helper');
  assert.match(foregroundRecovery, /if \(shouldAcquireLocalMedia\?\.\(\) === true && hasLiveLocalMedia\?\.\(\) !== true\) \{[\s\S]*void publishLocalTracks\?\.\(\);/, 'workspace foreground recovery must reacquire local media when preview/tracks are gone');
  assert.match(foregroundRecovery, /const socketHealthy = isSocketOpen\?\.\(\) === true[\s\S]*hasRealtimeRoomSync\?\.\(\) === true[\s\S]*getConnectionState/, 'workspace foreground recovery must classify healthy sockets before reconnecting');
  assert.match(foregroundRecovery, /if \(socketHealthy && sfuHealthy\) \{[\s\S]*requestRoomSnapshot\?\.\(\);[\s\S]*action: 'snapshot_only'/, 'healthy foreground recovery must request a snapshot instead of recycling sockets');
  assert.match(foregroundRecovery, /resetReconnectAttempt\?\.\(\);[\s\S]*void connectSocket\?\.\(\);/, 'unhealthy foreground recovery must still reconnect the realtime socket');
  assert.match(foregroundRecovery, /if \(sfuExpected && !sfuHealthy\) \{[\s\S]*recycleSfu\?\.\(\);[\s\S]*initSfu\?\.\(\);/, 'unhealthy SFU foreground recovery must recycle stale SFU state');
  assert.match(workspaceLifecycle, /onBackground: \(context\) => \{\s*markWorkspaceReconnectAfterForeground\(\);[\s\S]*sfuBackgroundTabPolicy\.pauseVideoForBackground\(context\);/, 'workspace background callback remains the only path that arms foreground reconnect');
  assert.match(workspaceLifecycle, /onForeground: \(context\) => \{\s*reconnectWorkspaceAfterForeground\(\);[\s\S]*sfuBackgroundTabPolicy\.resumeVideoAfterForeground\(context\);/, 'workspace foreground callback keeps real hidden/pagehide recovery');
  assert.match(workspaceLifecycle, /await publishLocalTracks\(\);\s*\n\s*if \(shouldConnectSfu\.value && sessionState\.sessionToken && sessionState\.userId\) \{\s*\n\s*initSFU\(\);/m, 'workspace mount must start local media before SFU connect');
  assert.match(workspaceTemplate, /class="call-control-btn"[\s\S]*@click="toggleCamera"/, 'call controls must remain ordinary visible click targets covered by focus churn proof');
  assert.match(workspaceTemplate, /class="workspace-video-fullscreen-overlay"[\s\S]*@click\.stop="closeVideoFullscreen"/, 'fullscreen media overlay clicks must stay local to fullscreen handling');
  assert.match(workspaceTemplate, /id="workspace-fullscreen-video-slot"[\s\S]*class="workspace-fullscreen-video-slot"[\s\S]*@click\.stop/, 'fullscreen media slot clicks must not bubble into reconnect-sensitive workspace handlers');

  const callAppWorkspaceHost = read(root, 'src/domain/realtime/callApps/CallAppWorkspaceHost.vue');
  assert.match(callAppWorkspaceHost, /class="call-app-workspace-fullscreen-toggle"[\s\S]*@click\.stop="toggleWorkspaceFullscreen"/, 'Call App fullscreen toggle clicks must stay local to the Call App host');
  assert.match(callAppWorkspaceHost, /<iframe[\s\S]*class="call-app-workspace-frame"/, 'Call App iframe must stay covered by visible iframe focus churn proof');

  const socketLifecycle = read(root, 'src/domain/realtime/workspace/callWorkspace/socketLifecycle.ts');
  assert.match(
    socketLifecycle,
    /const transientAuthBackendError = code === 'websocket_auth_temporarily_unavailable'[\s\S]*\|\| closeReason === 'auth_backend_error';/,
    'workspace realtime must classify auth_backend_error as a transient reconnect condition',
  );
  assert.match(
    socketLifecycle,
    /if \(closeReason === 'auth_backend_error' \|\| event\?\.code === 1011\) \{[\s\S]*refs\.connectionState\.value = 'retrying';[\s\S]*scheduleReconnect\(\);/,
    'workspace realtime close handler must retry internal auth backend closes instead of blocking the call',
  );
  assert.doesNotMatch(
    socketLifecycle,
    /closeReason === 'auth_backend_error' \|\| closeReason === 'role_not_allowed'/,
    'workspace realtime must not group auth_backend_error with policy blocks',
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
  const recoveryEvents = [];
  let recoveryArmed = false;
  let lastRecoveryAt = 0;
  let socketOpen = true;
  let roomSynced = true;
  let sfuConnected = true;
  let sfuOpen = true;
  const recovery = foregroundRecoveryModule.createWorkspaceForegroundRecoveryController({
    connectSocket: () => recoveryEvents.push('connect'),
    getArmed: () => recoveryArmed,
    getConnectionState: () => 'online',
    getDocument: () => ({ visibilityState: 'visible' }),
    getLastAt: () => lastRecoveryAt,
    getManualSocketClose: () => false,
    getRouteBusy: () => false,
    getSessionToken: () => 'session-token',
    hasLiveLocalMedia: () => true,
    hasRealtimeRoomSync: () => roomSynced,
    initSfu: () => recoveryEvents.push('init-sfu'),
    isSfuClientOpen: () => sfuOpen,
    isSfuConnected: () => sfuConnected,
    isSocketOpen: () => socketOpen,
    minIntervalMs: 0,
    publishLocalTracks: () => recoveryEvents.push('publish-media'),
    recycleSfu: () => recoveryEvents.push('recycle-sfu'),
    requestRoomSnapshot: () => recoveryEvents.push('snapshot'),
    resetReconnectAttempt: () => recoveryEvents.push('reset-reconnect'),
    setArmed: (value) => { recoveryArmed = value; },
    setLastAt: (value) => { lastRecoveryAt = value; },
    shouldAcquireLocalMedia: () => false,
    shouldConnectSfu: () => true,
  });
  recovery.mark();
  assert.equal(recoveryArmed, true, 'foreground recovery controller must arm on real background');
  assert.equal(recovery.recover().action, 'snapshot_only', 'healthy foreground recovery should not reconnect');
  assert.deepEqual(recoveryEvents, ['snapshot'], 'healthy foreground recovery must only request a snapshot');

  recoveryArmed = true;
  recoveryEvents.length = 0;
  socketOpen = false;
  assert.equal(recovery.recover().action, 'socket_reconnect', 'unhealthy socket foreground recovery should reconnect');
  assert.deepEqual(recoveryEvents, ['reset-reconnect', 'connect'], 'unhealthy socket recovery must not recycle healthy SFU');

  recoveryArmed = true;
  recoveryEvents.length = 0;
  socketOpen = true;
  sfuConnected = false;
  assert.equal(recovery.recover().action, 'sfu_recover', 'healthy socket with unhealthy SFU should recover SFU only');
  assert.deepEqual(recoveryEvents, ['snapshot', 'recycle-sfu', 'init-sfu'], 'SFU-only recovery must keep the realtime socket open');

  process.stdout.write('[foreground-reconnect-contract] PASS\n');
} catch (error) {
  if (error instanceof Error) {
    fail(error.message);
  }
  fail('unknown failure');
}
