import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const frontendRoot = path.resolve(__dirname, '../..');

function read(relPath) {
  return fs.readFileSync(path.join(frontendRoot, relPath), 'utf8');
}

function requireContains(source, needle, label) {
  assert.ok(source.includes(needle), `[client-console-warning-diagnostics-contract] missing ${label}`);
}

function requireNotContains(source, needle, label) {
  assert.ok(!source.includes(needle), `[client-console-warning-diagnostics-contract] unexpected ${label}`);
}

const diagnostics = read('src/support/clientDiagnostics.js');
const publisherBackpressureController = read('src/domain/realtime/workspace/callWorkspace/publisherBackpressureController.js');
const frameDecode = read('src/domain/realtime/sfu/frameDecode.js');
const mediaSecurityRuntime = read('src/domain/realtime/workspace/callWorkspace/mediaSecurityRuntime.js');
const nativeAudioBridgeFailureReporter = read('src/domain/realtime/native/audioBridgeFailureReporter.js');
const nativeAudioBridgeRecovery = read('src/domain/realtime/native/audioBridgeRecovery.js');
const runtimeSwitching = read('src/domain/realtime/workspace/callWorkspace/runtimeSwitching.js');
const sfuLifecycle = read('src/domain/realtime/sfu/lifecycle.js');
const socketLifecycle = read('src/domain/realtime/workspace/callWorkspace/socketLifecycle.js');
const videoConnectionStatus = read('src/domain/realtime/sfu/videoConnectionStatus.js');
const runtimeHealth = read('src/domain/realtime/workspace/callWorkspace/runtimeHealth.js');

requireContains(diagnostics, 'export function bindClientConsoleWarningDiagnostics()', 'console warning diagnostics binder');
requireContains(diagnostics, 'bindClientConsoleWarningDiagnostics();', 'workspace diagnostics config binds console warning capture');
requireContains(diagnostics, "eventType: 'client_console_warning'", 'console warnings become backend diagnostics');
requireContains(diagnostics, "code: 'console_warn'", 'console warning diagnostic code');
requireContains(diagnostics, "console_method: 'warn'", 'console warning payload method');
requireContains(diagnostics, 'console_stack: consoleWarningStack()', 'console warning stack payload');
requireContains(diagnostics, 'clientConsoleWarningPassthroughEnabled()', 'debug-only console passthrough guard');
requireContains(diagnostics, 'originalConsoleWarn(...args);', 'passthrough keeps explicit debug path');
requireContains(diagnostics, 'scheduleDiagnosticsFlush(100);', 'immediate diagnostics flush quickly');
requireNotContains(
  publisherBackpressureController,
  'console.warn(',
  'direct SFU publisher warnings; they must use backend client diagnostics instead',
);
for (const [label, source] of Object.entries({
  frameDecode,
  mediaSecurityRuntime,
  nativeAudioBridgeFailureReporter,
  nativeAudioBridgeRecovery,
  runtimeSwitching,
  sfuLifecycle,
  socketLifecycle,
  videoConnectionStatus,
  runtimeHealth,
})) {
  requireNotContains(source, 'console.warn(', `direct ${label} warnings; call runtime warnings must use backend diagnostics`);
  requireNotContains(source, 'console.info(', `direct ${label} info logs; call runtime status must use backend diagnostics`);
  requireNotContains(source, 'console.error(', `direct ${label} errors; call runtime errors must use backend diagnostics`);
}
requireContains(publisherBackpressureController, "eventType: 'sfu_video_backpressure'", 'SFU video backpressure backend diagnostic');
requireContains(publisherBackpressureController, "eventType: 'sfu_source_readback_budget_pressure'", 'source readback pressure backend diagnostic');
requireContains(publisherBackpressureController, "eventType: 'sfu_frame_send_failed'", 'frame send failure backend diagnostic');
requireContains(publisherBackpressureController, "eventType: 'sfu_video_reconnect_after_stall'", 'video stall reconnect backend diagnostic');
requireContains(mediaSecurityRuntime, "eventType: 'media_security_handshake_timeout'", 'media-security handshake warnings stay in backend diagnostics');
requireContains(nativeAudioBridgeFailureReporter, 'eventType: normalizedCode', 'native audio bridge warnings stay in backend diagnostics');
requireContains(runtimeSwitching, "eventType: 'sfu_encode_quality_downgraded'", 'quality downgrade warnings stay in backend diagnostics');
requireContains(sfuLifecycle, "eventType: 'sfu_connect_retry_scheduled'", 'SFU reconnect warnings stay in backend diagnostics');
requireContains(sfuLifecycle, "eventType: 'sfu_connect_exhausted'", 'SFU retry exhaustion stays in backend diagnostics');
requireContains(socketLifecycle, "eventType: 'media_security_handshake_started_after_ws_open'", 'websocket handshake status stays in backend diagnostics');
requireContains(videoConnectionStatus, "eventType: 'sfu_remote_video_stable'", 'SFU stable-video status stays in backend diagnostics');
requireContains(nativeAudioBridgeRecovery, "eventType: 'native_audio_track_recovery_exhausted'", 'native audio recovery exhaustion stays in backend diagnostics');

process.stdout.write('[client-console-warning-diagnostics-contract] PASS\n');
