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
const publisherBackpressureController = read('src/domain/realtime/workspace/callWorkspace/publisherBackpressureController.js');
const sfuPublisherControl = `${sfuTransport}\n${publisherBackpressureController}`;
const frameDecode = read('src/domain/realtime/sfu/frameDecode.js');
const sfuClient = read('src/lib/sfu/sfuClient.ts');
const sfuMessageHandler = read('src/lib/sfu/sfuMessageHandler.ts');
const outboundFrameQueue = read('src/lib/sfu/outboundFrameQueue.ts');
const inboundFrameAssembler = read('src/lib/sfu/inboundFrameAssembler.ts');
const diagnostics = read('src/support/clientDiagnostics.js');

requireContains(diagnostics, "fetchBackend('/api/user/client-diagnostics'", 'backend diagnostics endpoint');
requireContains(diagnostics, 'const DIAGNOSTICS_MAX_BATCH = 12;', 'diagnostics batch limit');
requireContains(diagnostics, 'const DIAGNOSTICS_FLUSH_INTERVAL_MS = 30000;', 'diagnostics batches errors before sending');
requireContains(diagnostics, 'const DIAGNOSTICS_MAX_REPEAT_COUNT = 9999;', 'diagnostics duplicate counter cap');
requireContains(diagnostics, 'diagnosticsSentFingerprints = new Set()', 'diagnostics remembers sent fingerprints per page session');
requireContains(diagnostics, "if (normalizedLevel !== 'warning' && normalizedLevel !== 'error')", 'diagnostics ignores non-error health chatter');
requireContains(diagnostics, 'queued.repeat_count = Math.min(DIAGNOSTICS_MAX_REPEAT_COUNT', 'diagnostics coalesces duplicate pending errors');
requireContains(diagnostics, 'diagnosticsSentFingerprints.has(fingerprint)', 'diagnostics suppresses already sent duplicate errors');
requireContains(diagnostics, 'diagnosticsSentFingerprints.add(diagnosticsFingerprint(entry))', 'diagnostics marks successfully sent errors');
requireContains(diagnostics, "reportGlobalClientRuntimeError('call_workspace_runtime_error'", 'global runtime error diagnostics hook');
requireContains(diagnostics, "reportGlobalClientRuntimeError('call_workspace_unhandled_rejection'", 'global promise rejection diagnostics hook');
requireContains(diagnostics, 'source_file: normalizeString(event?.filename', 'minified bundle source position capture');
requireContains(workspace, 'configureClientDiagnostics(() => ({', 'workspace diagnostics context');
requireContains(workspace, 'native_bridge_state: callWorkspaceNativeBridgeDiagnosticsSnapshot()', 'native bridge diagnostics context');
requireContains(workspace, 'last_sfu_transport_sample: callWorkspaceLastSfuTransportSample()', 'last sfu transport sample context');
requireContains(sfuClient, 'getLastFrameTransportSample()', 'sfu client exposes last transport sample');
requireContains(runtimeHealth, "eventType: 'sfu_remote_video_stalled'", 'remote stall diagnostics hook');
requireContains(socketLifecycle, "eventType: 'realtime_signaling_publish_failed'", 'signaling diagnostics hook');
requireContains(socketLifecycle, 'recoverExpectedSignalingPublishFailure({', 'expected signaling failures enter recovery path');
requireContains(socketLifecycle, 'removeParticipantLocallyAfterHangup(normalizedTargetUserId);', 'target_not_in_room prunes unreachable peer locally');
requireContains(socketLifecycle, 'void sendMediaSecuritySync(true);', 'media-security target_not_in_room forces reconnect rekey');
requireContains(sfuClient, "eventType: 'sfu_socket_connect_failed'", 'sfu socket connect diagnostics hook');
requireContains(sfuMessageHandler, "case 'sfu/error':", 'sfu command error diagnostics hook');
requireContains(sfuMessageHandler, "eventType: 'sfu_legacy_frame_chunk_rejected'", 'legacy inbound media chunk rejection diagnostics hook');
requireContains(sfuClient, "'sfu_frame_send_pressure'", 'sfu frame send pressure diagnostics hook');
requireContains(sfuClient, "'sfu_frame_send_aborted'", 'sfu frame send abort diagnostics hook');
requireContains(sfuPublisherControl, "eventType: 'sfu_frame_send_failed'", 'workspace failed frame send diagnostics hook');
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
