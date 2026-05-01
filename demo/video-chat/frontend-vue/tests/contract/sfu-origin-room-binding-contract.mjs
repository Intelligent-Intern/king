import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

function fail(message) {
  throw new Error(`[sfu-origin-room-binding-contract] FAIL: ${message}`);
}

function requireContains(source, needle, label) {
  assert.ok(source.includes(needle), `${label} missing: ${needle}`);
}

function requireMatch(source, pattern, label) {
  assert.ok(pattern.test(source), `${label} missing pattern: ${pattern}`);
}

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);

function read(relativePath) {
  return fs.readFileSync(path.resolve(__dirname, relativePath), 'utf8');
}

const sfuClient = read('../../src/lib/sfu/sfuClient.ts');
const sfuMessageHandler = read('../../src/lib/sfu/sfuMessageHandler.ts');
const outboundFrameBudget = read('../../src/lib/sfu/outboundFrameBudget.ts');
const framePayload = read('../../src/lib/sfu/framePayload.ts');
const outboundFrameQueue = read('../../src/lib/sfu/outboundFrameQueue.ts');
const inboundFrameAssembler = read('../../src/lib/sfu/inboundFrameAssembler.ts');
const backendOrigin = read('../../src/support/backendOrigin.js');
const socketLifecycle = read('../../src/domain/realtime/workspace/callWorkspace/socketLifecycle.js');
const stackEnv = read('../../../.env');
const deployScript = read('../../../scripts/deploy.sh');

try {
  requireContains(sfuClient, 'resolveBackendSfuOriginCandidates', 'SFU client origin imports');
  requireContains(sfuClient, 'setBackendSfuOrigin', 'SFU client working origin persistence');
  requireContains(sfuClient, "return buildWebSocketUrl(origin, '/sfu', query)", 'SFU websocket URL builder');
  requireContains(sfuClient, 'const candidates = resolveBackendSfuOriginCandidates()', 'SFU origin candidates');
  requireContains(sfuClient, "setBackendSfuOrigin(candidates[index] || '')", 'SFU working origin persistence');
  requireContains(sfuClient, 'this.connectWithCandidates(candidates, index + 1, query, roomId, generation)', 'SFU failover to next origin');
  requireContains(sfuClient, 'private connectAttemptInFlight = false', 'SFU client tracks one pending websocket handshake');
  requireContains(sfuClient, 'if (this.connectAttemptInFlight) return', 'SFU client refuses duplicate pending websocket connect attempts');
  requireContains(sfuClient, 'this.connectAttemptInFlight = false\n      this.disconnectNotified = false', 'SFU client clears pending handshake only after socket open');
  requireContains(sfuClient, 'Browsers follow pre-open errors with close; wait for that terminal', 'SFU client waits for close before origin failover');
  requireContains(sfuClient, 'const SFU_WEBSOCKET_NEGOTIATION_TIMEOUT_MS = 5000', 'SFU client bounds pending websocket negotiation to 5s');
  requireContains(sfuClient, "failToNextCandidateAfterSocketClose('negotiation_timeout')", 'SFU client closes timed-out websocket negotiation before failover');
  requireContains(socketLifecycle, 'if (state.connectInFlight && !state.manualSocketClose) return;', 'workspace websocket refuses duplicate pending connect attempts');
  requireContains(socketLifecycle, 'state.connectInFlight = true;', 'workspace websocket opens a single-flight gate during handshake');
  requireContains(socketLifecycle, 'The browser will emit close after a failed handshake', 'workspace websocket waits for close before origin failover');
  requireContains(socketLifecycle, 'const WEBSOCKET_NEGOTIATION_TIMEOUT_MS = 5000;', 'workspace websocket bounds pending negotiation to 5s');
  requireContains(socketLifecycle, "refs.connectionReason.value = 'socket_negotiation_timeout';", 'workspace websocket reports timed-out negotiation');
  requireContains(socketLifecycle, "failOverToNextOrigin('negotiation_timeout')", 'workspace websocket closes timed-out negotiation before failover');

  requireContains(sfuClient, 'room: roomId,', 'legacy room query compatibility');
  requireContains(sfuClient, 'room_id: roomId,', 'snake_case room query binding');
  requireContains(sfuClient, "if (/^[A-Za-z0-9._-]{1,200}$/.test(normalizedCallId)) {", 'call_id validation');
  requireContains(sfuClient, "query.set('call_id', normalizedCallId)", 'call_id query binding');
  requireContains(sfuClient, "this.send({ type: 'sfu/join', room_id: roomId, role: 'publisher' })", 'snake_case join room binding');

  requireContains(sfuClient, "this.send({ type: 'sfu/publish', track_id: t.id, kind: t.kind, label: t.label })", 'snake_case publish command');
  requireContains(sfuClient, 'isOpen(): boolean {', 'SFU client exposes socket open state without leaking private ws access');
  requireContains(sfuClient, 'getBufferedAmount(): number {', 'SFU client exposes websocket send-buffer size for encoder backpressure');
  requireContains(sfuClient, 'const normalizedPublisherId = stringField(publisherId)', 'subscribe command normalizes publisher id');
  requireContains(sfuClient, 'this.trackSubscribedPublisher(normalizedPublisherId)', 'subscribe command tracks publisher frame health');
  requireContains(sfuClient, "this.send({ type: 'sfu/subscribe', publisher_id: normalizedPublisherId })", 'snake_case subscribe command');
  requireContains(sfuClient, "this.send({ type: 'sfu/unpublish', track_id: trackId })", 'snake_case unpublish command');
  requireContains(framePayload, "publisher_id: frame.publisherId", 'snake_case frame publisher');
  requireContains(framePayload, "publisher_user_id: frame.publisherUserId || ''", 'snake_case frame publisher user');
  requireContains(framePayload, "track_id: frame.trackId", 'snake_case frame track');
  requireContains(framePayload, "frame_type: frame.type", 'snake_case frame type');
  requireContains(framePayload, 'export const SFU_FRAME_PROTOCOL_VERSION = 2', 'versioned SFU frame protocol');
  requireContains(framePayload, 'protocol_version: SFU_FRAME_PROTOCOL_VERSION', 'SFU frame protocol version field');
  requireContains(framePayload, 'frame_sequence: frameSequence', 'SFU frame sequence field');
  requireContains(framePayload, 'payload.codec_id = codecId', 'SFU frame codec id field');
  requireContains(framePayload, 'payload.runtime_id = runtimeId', 'SFU frame runtime id field');
  requireContains(framePayload, 'payload.payload_chars = payloadChars', 'SFU frame advertised payload length');
  requireContains(framePayload, 'payload.protection_mode = normalizeProtectionMode(frame.protectionMode,', 'snake_case protection mode');
  requireContains(framePayload, 'export const SFU_FRAME_CHUNK_MAX_CHARS = 8 * 1024', 'chunk size guard');
  requireContains(sfuClient, 'const SFU_FRAME_CHUNK_BACKPRESSURE_BYTES = 2 * 1024 * 1024', 'binary send backpressure guard aligns with encoder high-water mark');
  requireContains(outboundFrameBudget, 'const SFU_FRAME_CHUNK_BACKPRESSURE_LOW_WATER_BYTES = 192 * 1024', 'binary send resumes only at low-water');
  requireContains(sfuClient, 'private async waitForSendBufferDrain(targetBufferedBytes: number', 'chunk sender waits for budgeted websocket drain');
  requireContains(sfuClient, 'private outboundFrameSequenceByTrack = new Map<string, number>()', 'per-track outgoing frame sequence');
  requireContains(sfuClient, 'private nextOutboundFrameSequence(trackId: string): number', 'outgoing frame sequence allocator');
  requireContains(sfuClient, 'this.outboundFrameQueue.enqueue(prepared)', 'bounded outbound frame queue');
  requireContains(outboundFrameQueue, 'const SFU_FRAME_SEND_QUEUE_MAX_FRAMES = 3', 'bounded queue frame cap');
  requireContains(outboundFrameQueue, 'const SFU_FRAME_SEND_QUEUE_MAX_PAYLOAD_CHARS = 12 * 1024 * 1024', 'bounded queue byte cap');
  requireContains(outboundFrameQueue, "this.dropQueuedDeltaFrames('replaced_by_newer_delta', prepared.trackId)", 'queued delta replacement');
  requireContains(outboundFrameQueue, "'sfu_frame_send_queue_keyframe_blocked'", 'keyframe queue reject diagnostic');
  requireContains(sfuClient, 'this.sendBinaryFrame(prepared, metrics)', 'outbound media sends binary envelopes before any compatibility path');
  assert.ok(
    !sfuClient.includes('this.sendChunkedFramePayload(prepared.payload, prepared.chunkField'),
    'outbound media hot path must not send legacy JSON/base64 chunks',
  );
  assert.ok(
    !sfuClient.includes('this.inboundFrameAssembler.acceptChunk(msg)'),
    'inbound media hot path must not accept legacy JSON/base64 chunks',
  );
  requireContains(sfuMessageHandler, "eventType: 'sfu_legacy_frame_chunk_rejected'", 'legacy inbound media chunks are diagnostically rejected');
  requireContains(sfuClient, "ws.binaryType = 'arraybuffer'", 'SFU websocket receives binary media envelopes');
  requireContains(sfuClient, 'decodeSfuBinaryFrameEnvelope(ev.data)', 'inbound media uses binary envelope decode');
  requireContains(framePayload, "export const SFU_BINARY_FRAME_MAGIC = 'KSFB'", 'binary frame magic');
  requireContains(framePayload, 'export const SFU_BINARY_FRAME_LAYOUT_ENVELOPE_VERSION = 2', 'binary frame layout envelope version');
  requireContains(framePayload, 'encodeSfuBinaryFrameEnvelope(prepared: PreparedSfuOutboundFramePayload)', 'binary frame envelope encoder');
  requireContains(framePayload, 'decodeSfuBinaryFrameEnvelope(input: ArrayBuffer)', 'binary frame envelope decoder');
  requireContains(framePayload, 'metadataJsonBytes > 0 ? 46 : 42', 'binary frame v1 header projection must match the timestamp/sequence/payload-length layout');
  requireContains(framePayload, 'SFU_BINARY_FRAME_LAYOUT_ENVELOPE_VERSION ? 46 : 42', 'binary frame v1 header length must match the timestamp/sequence/payload-length layout');
  requireContains(inboundFrameAssembler, "reject_reason: 'payload_length_mismatch'", 'direct frame advertised length validation');

  requireContains(sfuMessageHandler, "import { stringField, type SfuInboundFrameAssembler } from './inboundFrameAssembler'", 'camel/snake inbound helper import');
  requireContains(inboundFrameAssembler, 'export function stringField(...values: any[]): string {', 'shared camel/snake inbound helper');
  requireContains(sfuMessageHandler, 'roomId:          stringField(msg.roomId, msg.room_id)', 'room event camel/snake compatibility');
  requireContains(sfuMessageHandler, 'publisherId:     stringField(msg.publisherId, msg.publisher_id)', 'publisher event camel/snake compatibility');
  requireContains(sfuMessageHandler, 'publisherUserId: stringField(msg.publisherUserId, msg.publisher_user_id)', 'publisher user event camel/snake compatibility');
  requireContains(sfuMessageHandler, 'publisherName:   stringField(msg.publisherName, msg.publisher_name)', 'publisher name event camel/snake compatibility');
  requireContains(sfuMessageHandler, 'stringField(msg.trackId, msg.track_id)', 'track event camel/snake compatibility');
  requireContains(sfuMessageHandler, 'const protectedFrame = stringField(msg.protectedFrame, msg.protected_frame)', 'protected frame inbound compatibility');
  requireContains(sfuMessageHandler, 'stringField(msg.protectionMode, msg.protection_mode)', 'protection mode inbound compatibility');
  requireContains(sfuMessageHandler, 'stringField(msg.frameType, msg.frame_type)', 'frame type inbound compatibility');
  requireContains(sfuMessageHandler, 'frameSequence: Math.max(0, integerField(0, msg.frameSequence, msg.frame_sequence))', 'frame sequence inbound compatibility');

  requireContains(backendOrigin, 'export function resolveBackendSfuOriginCandidates()', 'SFU origin candidate resolver');
  requireContains(backendOrigin, 'const primarySfuOrigin = resolveBackendSfuOrigin();', 'primary SFU origin');
  requireContains(backendOrigin, 'if (hasExplicitBackendSfuConfig()) {', 'explicit SFU config boundary');
  requireContains(backendOrigin, 'const websocketOrigin = resolveBackendWebSocketOrigin();', 'websocket fallback origin');
  requireContains(backendOrigin, 'const backendOrigin = resolveBackendOrigin();', 'backend fallback origin');
  requireMatch(backendOrigin, /pushUniqueCandidate\(candidates,\s*primarySfuOrigin\)[\s\S]*appendLoopbackHostVariant\(candidates,\s*primarySfuOrigin\)/, 'SFU loopback origin fallback');

  requireMatch(stackEnv, /^VITE_VIDEOCHAT_ENABLE_SFU=true$/m, 'default SFU runtime flag');
  requireContains(deployScript, 'VITE_VIDEOCHAT_ENABLE_SFU=true', 'deployment SFU runtime flag template');
  requireContains(deployScript, 'set_env_value VITE_VIDEOCHAT_ENABLE_SFU true', 'deployment SFU runtime env persistence');

  process.stdout.write('[sfu-origin-room-binding-contract] PASS\n');
} catch (error) {
  if (error instanceof Error) {
    fail(error.message);
  }
  fail('unknown failure');
}
