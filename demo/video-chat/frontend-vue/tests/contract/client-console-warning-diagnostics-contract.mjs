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
requireContains(publisherBackpressureController, "eventType: 'sfu_video_backpressure'", 'SFU video backpressure backend diagnostic');
requireContains(publisherBackpressureController, "eventType: 'sfu_source_readback_budget_pressure'", 'source readback pressure backend diagnostic');
requireContains(publisherBackpressureController, "eventType: 'sfu_frame_send_failed'", 'frame send failure backend diagnostic');
requireContains(publisherBackpressureController, "eventType: 'sfu_video_reconnect_after_stall'", 'video stall reconnect backend diagnostic');

process.stdout.write('[client-console-warning-diagnostics-contract] PASS\n');
