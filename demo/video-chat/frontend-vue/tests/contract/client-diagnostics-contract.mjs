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

function requireNotContains(source, needle, label) {
  assert.ok(!source.includes(needle), `[client-diagnostics-contract] unexpected ${label}`);
}

const workspace = read('src/domain/realtime/CallWorkspaceView.vue');
const workspaceClientDiagnostics = read('src/domain/realtime/workspace/callWorkspace/clientDiagnostics.ts');
const runtimeHealth = read('src/domain/realtime/workspace/callWorkspace/runtimeHealth.ts');
const socketLifecycle = read('src/domain/realtime/workspace/callWorkspace/socketLifecycle.ts');
const participantUi = read('src/domain/realtime/workspace/callWorkspace/participantUi.ts');
const sfuTransport = read('src/domain/realtime/workspace/callWorkspace/sfuTransport.ts');
const publisherBackpressureController = read('src/domain/realtime/workspace/callWorkspace/publisherBackpressureController.ts');
const sfuPublisherControl = `${sfuTransport}\n${publisherBackpressureController}`;
const frameDecode = read('src/domain/realtime/sfu/frameDecode.ts');
const sfuClient = read('src/lib/sfu/sfuClient.ts');
const sfuMessageHandler = read('src/lib/sfu/sfuMessageHandler.ts');
const sendFailureDetails = read('src/lib/sfu/sendFailureDetails.ts');
const outboundFrameQueue = read('src/lib/sfu/outboundFrameQueue.ts');
const inboundFrameAssembler = read('src/lib/sfu/inboundFrameAssembler.ts');
const diagnostics = read('src/support/clientDiagnostics.ts');

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
requireContains(diagnostics, 'function redactClientDiagnosticWindowPayload(value, depth = 0)', 'diagnostics redacts window-event payloads');
requireContains(diagnostics, '/token|authorization|password|secret|credential|cookie|session/i.test(normalizedKey)', 'diagnostics redacts sensitive payload keys');
requireContains(diagnostics, "payload: redactClientDiagnosticWindowPayload(entry.payload)", 'diagnostics dispatches only redacted window-event payloads');
requireContains(workspace, 'configureCallWorkspaceClientDiagnosticsContext({', 'workspace delegates diagnostics context');
requireContains(workspaceClientDiagnostics, 'configureClientDiagnostics(() => ({', 'workspace diagnostics context');
requireContains(workspaceClientDiagnostics, 'native_bridge_state: callWorkspaceNativeBridgeDiagnosticsSnapshot()', 'native bridge diagnostics context');
requireContains(workspaceClientDiagnostics, 'last_sfu_transport_sample: callWorkspaceLastSfuTransportSample()', 'last sfu transport sample context');
requireContains(sfuClient, 'getLastFrameTransportSample()', 'sfu client exposes last transport sample');
requireContains(runtimeHealth, "eventType: 'sfu_remote_video_stalled'", 'remote stall diagnostics hook');
requireContains(socketLifecycle, "eventType: 'realtime_signaling_publish_failed'", 'signaling diagnostics hook');
requireContains(socketLifecycle, 'recoverExpectedSignalingPublishFailure({', 'expected signaling failures enter recovery path');
requireContains(socketLifecycle, 'removeParticipantLocallyAfterHangup(normalizedTargetUserId)', 'target_not_in_room prunes unreachable peer locally');
requireContains(socketLifecycle, 'const failedStaleTargetPruningSignal = STALE_TARGET_PRUNING_SIGNAL_TYPES.includes(failedCommandType);', 'stale-target pruning stays on explicit signaling families');
requireContains(socketLifecycle, "&& normalizedError === 'target_not_in_room'", 'target_not_in_room still evicts stale participants for active signaling families');
requireContains(socketLifecycle, '&& failedStaleTargetPruningSignal;', 'target_not_in_room pruning is limited to stale-target-safe signaling families');
requireNotContains(socketLifecycle, 'failedMediaSecuritySignal', 'G360 gossip-primary socket lifecycle requiring MediaSecurity recovery classification');
requireNotContains(socketLifecycle, 'requestWlvcFullFrameKeyframe(\'media_security_target_not_in_room_pruned\'', 'G360 gossip-primary socket lifecycle forcing MediaSecurity keyframes');
requireNotContains(socketLifecycle, 'shouldForceMediaSecurityRekey', 'G360 gossip-primary socket lifecycle forcing MediaSecurity rekeys');
requireNotContains(socketLifecycle, 'sendMediaSecuritySync(shouldForceMediaSecurityRekey)', 'G360 gossip-primary socket lifecycle retrying MediaSecurity sync from signaling failures');
requireContains(socketLifecycle, "eventType: 'realtime_signaling_stale_target_pruned'", 'expected stale-target pruning has a dedicated diagnostics hook');
requireContains(socketLifecycle, "const rawSignalType = String(payload?.signal_type || '').trim().toLowerCase();", 'call ack preserves raw signaling type for suppression');
requireContains(socketLifecycle, "scheduleNativeOfferRetryForUserId(payload?.target_user_id, 'brokered_offer_unanswered')", 'brokered unanswered offer ack still schedules native offer retry');
assert.ok(
  !/if \(type === 'call\/ack'\)[\s\S]*setNotice\(`Sent/.test(socketLifecycle),
  '[client-diagnostics-contract] call ack transport receipts must not render green workspace banners',
);
requireContains(participantUi, 'MEDIA_SECURITY_SIGNAL_TYPES.includes(`call/${normalized}`)', 'normalized media-security ack notices are suppressed');
requireContains(socketLifecycle, "if (code === 'signaling_publish_failed' && !expectedStaleTargetPublishFailure)", 'broker failure diagnostics stay separate from expected stale-target pruning');
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
requireContains(sendFailureDetails, 'chunkCount: Math.max(1, Number(prepared.chunkCount || 1))', 'sfu frame diagnostics include chunk count');
requireContains(sfuClient, 'send_wait_ms: drain.waitedMs', 'sfu frame diagnostics include send wait time');
requireContains(sfuClient, 'payload_chars', 'sfu frame diagnostics include base64/protected payload size');
requireContains(sfuClient, 'frame_sequence', 'sfu frame diagnostics include frame sequence');

process.stdout.write('[client-diagnostics-contract] PASS\n');
