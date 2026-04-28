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
const framePayload = read('../../src/lib/sfu/framePayload.ts');
const outboundFrameQueue = read('../../src/lib/sfu/outboundFrameQueue.ts');
const inboundFrameAssembler = read('../../src/lib/sfu/inboundFrameAssembler.ts');
const backendOrigin = read('../../src/support/backendOrigin.js');
const stackEnv = read('../../../.env');
const deployScript = read('../../../scripts/deploy.sh');

try {
  requireContains(sfuClient, 'resolveBackendSfuOriginCandidates', 'SFU client origin imports');
  requireContains(sfuClient, 'setBackendSfuOrigin', 'SFU client working origin persistence');
  requireContains(sfuClient, "return buildWebSocketUrl(origin, '/sfu', query)", 'SFU websocket URL builder');
  requireContains(sfuClient, 'const candidates = resolveBackendSfuOriginCandidates()', 'SFU origin candidates');
  requireContains(sfuClient, "setBackendSfuOrigin(candidates[index] || '')", 'SFU working origin persistence');
  requireContains(sfuClient, 'this.connectWithCandidates(candidates, index + 1, query, roomId, generation)', 'SFU failover to next origin');

  requireContains(sfuClient, 'room: roomId,', 'legacy room query compatibility');
  requireContains(sfuClient, 'room_id: roomId,', 'snake_case room query binding');
  requireContains(sfuClient, "if (/^[A-Za-z0-9._-]{1,200}$/.test(normalizedCallId)) {", 'call_id validation');
  requireContains(sfuClient, "query.set('call_id', normalizedCallId)", 'call_id query binding');
  requireContains(sfuClient, "this.send({ type: 'sfu/join', room_id: roomId, role: 'publisher' })", 'snake_case join room binding');

  requireContains(sfuClient, "this.send({ type: 'sfu/publish', track_id: t.id, kind: t.kind, label: t.label })", 'snake_case publish command');
  requireContains(sfuClient, 'isOpen(): boolean {', 'SFU client exposes socket open state without leaking private ws access');
  requireContains(sfuClient, 'getBufferedAmount(): number {', 'SFU client exposes websocket send-buffer size for encoder backpressure');
  requireContains(sfuClient, "this.send({ type: 'sfu/subscribe', publisher_id: publisherId })", 'snake_case subscribe command');
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
  requireContains(sfuClient, 'const SFU_FRAME_CHUNK_BACKPRESSURE_BYTES = 512 * 1024', 'chunk send backpressure guard');
  requireContains(sfuClient, 'private async waitForSendBufferDrain()', 'chunk sender waits for websocket drain');
  requireContains(sfuClient, 'private outboundFrameSequenceByTrack = new Map<string, number>()', 'per-track outgoing frame sequence');
  requireContains(sfuClient, 'private nextOutboundFrameSequence(trackId: string): number', 'outgoing frame sequence allocator');
  requireContains(sfuClient, 'this.outboundFrameQueue.enqueue(prepared)', 'bounded outbound frame queue');
  requireContains(outboundFrameQueue, 'const SFU_FRAME_SEND_QUEUE_MAX_FRAMES = 3', 'bounded queue frame cap');
  requireContains(outboundFrameQueue, 'const SFU_FRAME_SEND_QUEUE_MAX_PAYLOAD_CHARS = 2 * 1024 * 1024', 'bounded queue byte cap');
  requireContains(outboundFrameQueue, "this.dropQueuedDeltaFrames('replaced_by_newer_delta', prepared.trackId)", 'queued delta replacement');
  requireContains(outboundFrameQueue, "'sfu_frame_send_queue_keyframe_blocked'", 'keyframe queue reject diagnostic');
  requireContains(sfuClient, 'this.sendBinaryFrame(prepared, metrics)', 'outbound media sends binary envelopes before any compatibility path');
  assert.ok(
    !sfuClient.includes('this.sendChunkedFramePayload(prepared.payload, prepared.chunkField'),
    'outbound media hot path must not send legacy JSON/base64 chunks',
  );
  requireContains(sfuClient, 'new SfuInboundFrameAssembler({ getRoomId: () => this.roomId })', 'inbound chunk assembler wiring');
  requireContains(inboundFrameAssembler, 'const SFU_FRAME_CHUNK_TTL_MS = 5000', 'inbound chunk TTL guard');
  requireContains(inboundFrameAssembler, 'private pendingChunks = new Map<string, PendingInboundFrameChunk>()', 'inbound chunk cache');
  requireContains(inboundFrameAssembler, 'acceptChunk(msg: any): Record<string, unknown> | null {', 'inbound chunk assembler');
  requireContains(inboundFrameAssembler, 'chunkPayloadChars !== chunkValue.length', 'inbound chunk advertised length validation');
  requireContains(inboundFrameAssembler, 'assembled.length !== existing.payloadChars', 'inbound assembled payload length validation');
  requireContains(inboundFrameAssembler, "'duplicate_chunk_mismatch'", 'inbound duplicate chunk fail-fast');
  requireContains(inboundFrameAssembler, "'out_of_order_chunk'", 'inbound out-of-order chunk fail-fast');
  requireContains(sfuClient, "case 'sfu/frame-chunk': {", 'inbound chunk message handler');
  requireContains(sfuClient, 'const reassembledFrame = this.inboundFrameAssembler.acceptChunk(msg)', 'inbound chunk reassembly dispatch');
  requireContains(inboundFrameAssembler, "reject_reason: 'payload_length_mismatch'", 'direct frame advertised length validation');

  requireContains(sfuClient, 'const stringField = (...values: any[]): string => {', 'camel/snake inbound helper');
  requireContains(sfuClient, 'roomId:          stringField(msg.roomId, msg.room_id)', 'room event camel/snake compatibility');
  requireContains(sfuClient, 'publisherId:     stringField(msg.publisherId, msg.publisher_id)', 'publisher event camel/snake compatibility');
  requireContains(sfuClient, 'publisherUserId: stringField(msg.publisherUserId, msg.publisher_user_id)', 'publisher user event camel/snake compatibility');
  requireContains(sfuClient, 'publisherName:   stringField(msg.publisherName, msg.publisher_name)', 'publisher name event camel/snake compatibility');
  requireContains(sfuClient, 'stringField(msg.trackId, msg.track_id)', 'track event camel/snake compatibility');
  requireContains(sfuClient, 'const protectedFrame = stringField(msg.protectedFrame, msg.protected_frame)', 'protected frame inbound compatibility');
  requireContains(sfuClient, 'stringField(msg.protectionMode, msg.protection_mode)', 'protection mode inbound compatibility');
  requireContains(sfuClient, 'stringField(msg.frameType, msg.frame_type)', 'frame type inbound compatibility');
  requireContains(sfuClient, 'frameSequence: Math.max(0, integerField(0, msg.frameSequence, msg.frame_sequence))', 'frame sequence inbound compatibility');

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
