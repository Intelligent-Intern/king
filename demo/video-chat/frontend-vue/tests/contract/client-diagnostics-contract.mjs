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
const runtimeHealth = read('src/domain/realtime/workspace/callWorkspace/runtimeHealth.js');
const socketLifecycle = read('src/domain/realtime/workspace/callWorkspace/socketLifecycle.js');
const sfuTransport = read('src/domain/realtime/workspace/callWorkspace/sfuTransport.js');
const frameDecode = read('src/domain/realtime/sfu/frameDecode.js');
const sfuClient = read('src/lib/sfu/sfuClient.ts');
const outboundFrameQueue = read('src/lib/sfu/outboundFrameQueue.ts');
const inboundFrameAssembler = read('src/lib/sfu/inboundFrameAssembler.ts');
const diagnostics = read('src/support/clientDiagnostics.js');

requireContains(diagnostics, "fetchBackend('/api/user/client-diagnostics'", 'backend diagnostics endpoint');
requireContains(diagnostics, 'const DIAGNOSTICS_MAX_BATCH = 12;', 'diagnostics batch limit');
requireContains(workspace, 'configureClientDiagnostics(() => ({', 'workspace diagnostics context');
requireContains(runtimeHealth, "eventType: 'sfu_remote_video_stalled'", 'remote stall diagnostics hook');
requireContains(socketLifecycle, "eventType: 'realtime_signaling_publish_failed'", 'signaling diagnostics hook');
requireContains(sfuClient, "eventType: 'sfu_socket_connect_failed'", 'sfu socket connect diagnostics hook');
requireContains(sfuClient, "case 'sfu/error':", 'sfu command error diagnostics hook');
requireContains(sfuClient, "'sfu_frame_send_pressure'", 'sfu frame send pressure diagnostics hook');
requireContains(sfuClient, "'sfu_frame_send_aborted'", 'sfu frame send abort diagnostics hook');
requireContains(sfuTransport, "eventType: 'sfu_frame_send_failed'", 'workspace failed frame send diagnostics hook');
requireContains(sfuClient, "'sfu_frame_send_queue_pressure'", 'sfu frame send queue pressure diagnostics hook');
requireContains(outboundFrameQueue, "'sfu_frame_send_queue_dropped'", 'sfu frame send queue drop diagnostics hook');
requireContains(outboundFrameQueue, "'sfu_frame_send_queue_keyframe_blocked'", 'sfu keyframe queue blocking diagnostics hook');
requireContains(inboundFrameAssembler, "'sfu_frame_chunk_timeout'", 'sfu inbound chunk timeout diagnostics hook');
requireContains(inboundFrameAssembler, "'sfu_frame_chunk_rejected'", 'sfu inbound chunk rejection diagnostics hook');
requireContains(inboundFrameAssembler, "'sfu_frame_rejected'", 'sfu inbound frame rejection diagnostics hook');
requireContains(frameDecode, "eventType: 'sfu_remote_frame_dropped'", 'remote frame continuity drop diagnostics hook');
requireContains(sfuClient, 'chunkCount: Math.max(1, Number(prepared.chunkCount || 1))', 'sfu frame diagnostics include chunk count');
requireContains(sfuClient, 'send_wait_ms: drain.waitedMs', 'sfu frame diagnostics include send wait time');
requireContains(sfuClient, 'payload_chars', 'sfu frame diagnostics include base64/protected payload size');
requireContains(sfuClient, 'frame_sequence', 'sfu frame diagnostics include frame sequence');

process.stdout.write('[client-diagnostics-contract] PASS\n');
