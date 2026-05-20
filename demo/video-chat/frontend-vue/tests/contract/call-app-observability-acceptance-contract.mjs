import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import path from 'node:path';
import { pathToFileURL } from 'node:url';

const root = path.resolve(new URL('../..', import.meta.url).pathname);
const repoRoot = path.resolve(root, '../../..');

async function read(relativePath) {
  return readFile(path.join(repoRoot, relativePath), 'utf8');
}

async function readOptional(relativePath) {
  try {
    return await read(relativePath);
  } catch (error) {
    if (error && error.code === 'ENOENT') {
      return '';
    }
    throw error;
  }
}

async function assertCallAppDiagnosticsStayConsoleSilent() {
  const previousConsole = globalThis.console;
  const hadWindow = Object.prototype.hasOwnProperty.call(globalThis, 'window');
  const previousWindow = globalThis.window;
  const hadCustomEvent = Object.prototype.hasOwnProperty.call(globalThis, 'CustomEvent');
  const previousCustomEvent = globalThis.CustomEvent;
  const consoleCalls = [];
  const observedDiagnostics = [];
  const listeners = new Map();

  const silentConsole = Object.create(previousConsole);
  for (const method of ['debug', 'log', 'info', 'warn', 'error']) {
    silentConsole[method] = (...args) => {
      consoleCalls.push({ method, args });
    };
  }

  globalThis.console = silentConsole;
  globalThis.window = {
    addEventListener(type, listener) {
      const eventType = String(type || '');
      const eventListeners = listeners.get(eventType) || [];
      eventListeners.push(listener);
      listeners.set(eventType, eventListeners);
    },
    removeEventListener(type, listener) {
      const eventType = String(type || '');
      listeners.set(
        eventType,
        (listeners.get(eventType) || []).filter((entry) => entry !== listener),
      );
    },
    dispatchEvent(event) {
      for (const listener of listeners.get(String(event?.type || '')) || []) {
        listener.call(globalThis.window, event);
      }
      return true;
    },
  };
  globalThis.CustomEvent = class TestCustomEvent {
    constructor(type, init = {}) {
      this.type = String(type || '');
      this.detail = init.detail;
    }
  };

  try {
    globalThis.window.addEventListener('king:call-app-diagnostic', (event) => {
      observedDiagnostics.push(event.detail);
    });
    const diagnosticsModuleUrl = pathToFileURL(path.join(
      repoRoot,
      'demo/video-chat/frontend-vue/src/domain/realtime/callApps/callAppDiagnostics.js',
    )).href;
    const {
      emitCallAppDiagnostic,
      emitCallAppResponseDiagnostics,
    } = await import(`${diagnosticsModuleUrl}?console-silent-contract=${Date.now()}`);

    assert.equal(emitCallAppDiagnostic('not_a_call_app_event', {}), null);
    const normalDiagnostic = emitCallAppDiagnostic('call_app_iframe_bridge_error', {
      session_id: 'normal-session',
      app_key: 'whiteboard',
      reason: 'post_message_failed',
      authorization: 'Bearer secret',
      frame_payload: 'raw-frame-bytes',
    });
    emitCallAppResponseDiagnostics({
      result: {
        diagnostics: [{
          event_type: 'call_app_grants_changed',
          session_id: 'admin-visible-session',
          app_key: 'whiteboard',
          token: 'secret-token',
        }],
      },
    }, { call_id: 'call-123' });

    assert.equal(consoleCalls.length, 0, 'normal Call App diagnostics must not call console debug/log/info/warn/error');
    assert.equal(normalDiagnostic.authorization, undefined, 'normal diagnostic payload must redact auth fields');
    assert.equal(normalDiagnostic.frame_payload, undefined, 'normal diagnostic payload must redact media frame fields');
    assert.deepEqual(
      observedDiagnostics.map((entry) => entry?.event_type),
      ['call_app_iframe_bridge_error', 'call_app_grants_changed'],
      'admin/internal diagnostic tail must still receive Call App browser events',
    );
    assert.equal(observedDiagnostics[1]?.source, 'backend_response');
    assert.equal(observedDiagnostics[1]?.call_id, 'call-123');
    assert.equal(observedDiagnostics[1]?.token, undefined, 'backend response diagnostics must redact token fields');
  } finally {
    globalThis.console = previousConsole;
    if (hadWindow) {
      globalThis.window = previousWindow;
    } else {
      delete globalThis.window;
    }
    if (hadCustomEvent) {
      globalThis.CustomEvent = previousCustomEvent;
    } else {
      delete globalThis.CustomEvent;
    }
  }
}

const [
  diagnosticsSource,
  tailBridgeSource,
  iframeBridgeSource,
  crdtBridgeSource,
  routeSource,
  lifecycleTestSource,
  acceptanceSource,
  sprintSource,
] = await Promise.all([
  read('demo/video-chat/frontend-vue/src/domain/realtime/callApps/callAppDiagnostics.js'),
  read('demo/video-chat/frontend-vue/src/domain/realtime/callApps/callAppDiagnosticTailBridge.js'),
  read('demo/video-chat/frontend-vue/src/domain/realtime/callApps/useCallAppIframeBridge.js'),
  read('demo/video-chat/frontend-vue/src/domain/realtime/callApps/useCallAppCrdtBridge.js'),
  read('demo/video-chat/backend-king-php/http/module_call_apps.php'),
  read('demo/video-chat/backend-king-php/tests/call-app-session-lifecycle-contract.php'),
  readOptional('WHITEBOARD_CHECK.md'),
  Promise.all([read('SPRINT.md'), read('BACKLOG.md')]).then(([sprint, backlog]) => `${sprint}\n${backlog}`),
]);

const observabilityEvents = [
  'call_app_launch_token_failed',
  'call_app_grants_changed',
  'call_app_crdt_append_latency',
  'call_app_crdt_replay_latency',
  'call_app_crdt_duplicate_suppressed',
  'call_app_crdt_snapshot_compacted',
  'call_app_iframe_bridge_error',
];

for (const eventType of observabilityEvents) {
  assert.match(
    `${diagnosticsSource}\n${routeSource}\n${lifecycleTestSource}\n${acceptanceSource}`,
    new RegExp(eventType),
    `Call App observability must cover ${eventType}`,
  );
}

assert.match(
  diagnosticsSource,
  /king:call-app-diagnostic/,
  'frontend diagnostics must emit a dedicated browser event',
);

assert.doesNotMatch(
  diagnosticsSource,
  /console\.(debug|log|info|warn|error)\(\s*['"]\[CallAppDiagnostics\]/,
  'frontend Call App diagnostics must not spam the production browser console',
);

assert.match(
  tailBridgeSource,
  /window\.addEventListener\(CALL_APP_DIAGNOSTIC_WINDOW_EVENT, handleCallAppDiagnostic\)/,
  'admin/internal diagnostics tail must subscribe to Call App browser events',
);

assert.match(
  tailBridgeSource,
  /sessionForTail\(activeSession\)[\s\S]*postToIframe\(frameWindow, session, messageType, payload\)/,
  'admin/internal diagnostics tail must send Call App diagnostics to the diagnostics iframe',
);

assert.match(
  diagnosticsSource,
  /token\|authorization\|password\|secret/i,
  'frontend diagnostics must redact sensitive fields',
);

assert.match(
  iframeBridgeSource,
  /call_app_launch_token_failed[\s\S]*response_status[\s\S]*call_app_iframe_bridge_error/s,
  'iframe launch bridge must emit launch-token failure and iframe bridge error diagnostics',
);

for (const eventType of ['call_app_crdt_append_latency', 'call_app_crdt_replay_latency', 'call_app_crdt_snapshot_compacted']) {
  assert.match(
    crdtBridgeSource,
    new RegExp(`callAppDiagnosticNow[\\s\\S]*${eventType}`),
    `CRDT bridge must record ${eventType}`,
  );
}

assert.match(
  routeSource,
  /videochat_call_app_module_with_diagnostic[\s\S]*call_app_grants_changed[\s\S]*call_app_crdt_duplicate_suppressed/s,
  'backend Call App routes must attach grant-change and CRDT diagnostics to API responses',
);

assert.match(
  lifecycleTestSource,
  /assert_diagnostic[\s\S]*call_app_grants_changed[\s\S]*call_app_launch_token_failed[\s\S]*call_app_crdt_replay_latency[\s\S]*call_app_crdt_snapshot_compacted/s,
  'backend lifecycle contract must assert the observability events',
);

if (acceptanceSource.trim() !== '') {
  for (const role of ['Owner', 'Moderator', 'Participant', 'Guest', 'Revoked Participant', 'Reconnect', 'Export']) {
    assert.match(acceptanceSource, new RegExp(`### ${role}`), `Whiteboard acceptance form must include ${role} checks`);
  }

  assert.match(
    acceptanceSource,
    /bewusst nicht[\s\S]*als bestanden ausgefuellt[\s\S]*Status: Auszufuellen/s,
    'Whiteboard acceptance form must remain an unfilled form, not a completed acceptance',
  );

  assert.doesNotMatch(
    acceptanceSource,
    /\[x\]|Status:\s*(passed|pass|bestanden|ok)/i,
    'Whiteboard acceptance form must not be pre-filled as passed',
  );
}

assert.match(
  sprintSource,
  /VST-22 Remove normal-session console spam from Call-App diagnostics while[\s\S]*preserving admin diagnostics/,
  'planning sources must track the active Call App diagnostics console-spam cleanup',
);

await assertCallAppDiagnosticsStayConsoleSilent();

console.log('[call-app-observability-acceptance-contract] PASS');
