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
  assert.ok(source.includes(needle), `[client-diagnostics-contract] missing ${label}`);
}

const workspace = read('src/domain/realtime/CallWorkspaceView.vue');
const sfuClient = read('src/lib/sfu/sfuClient.ts');
const diagnostics = read('src/support/clientDiagnostics.js');

requireContains(diagnostics, "fetchBackend('/api/user/client-diagnostics'", 'backend diagnostics endpoint');
requireContains(diagnostics, 'const DIAGNOSTICS_MAX_BATCH = 12;', 'diagnostics batch limit');
requireContains(workspace, 'configureClientDiagnostics(() => ({', 'workspace diagnostics context');
requireContains(workspace, "eventType: 'sfu_remote_video_stalled'", 'remote stall diagnostics hook');
requireContains(workspace, "eventType: 'realtime_signaling_publish_failed'", 'signaling diagnostics hook');
requireContains(sfuClient, "eventType: 'sfu_socket_connect_failed'", 'sfu socket connect diagnostics hook');
requireContains(sfuClient, "case 'sfu/error':", 'sfu command error diagnostics hook');
requireContains(sfuClient, "'sfu_frame_send_pressure'", 'sfu frame send pressure diagnostics hook');
requireContains(sfuClient, "'sfu_frame_send_aborted'", 'sfu frame send abort diagnostics hook');
requireContains(sfuClient, 'chunk_count: totalChunks', 'sfu frame diagnostics include chunk count');
requireContains(sfuClient, 'send_wait_ms: totalWaitMs', 'sfu frame diagnostics include send wait time');
requireContains(sfuClient, 'payload_chars', 'sfu frame diagnostics include base64/protected payload size');

process.stdout.write('[client-diagnostics-contract] PASS\n');
