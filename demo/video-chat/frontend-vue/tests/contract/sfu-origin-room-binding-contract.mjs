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
  requireContains(sfuClient, "publisher_id: frame.publisherId", 'snake_case frame publisher');
  requireContains(sfuClient, "publisher_user_id: frame.publisherUserId || ''", 'snake_case frame publisher user');
  requireContains(sfuClient, "track_id: frame.trackId", 'snake_case frame track');
  requireContains(sfuClient, "frame_type: frame.type", 'snake_case frame type');
  requireContains(sfuClient, 'payload.protected_frame = frame.protectedFrame', 'snake_case protected frame');
  requireContains(sfuClient, 'payload.protection_mode = frame.protectionMode ||', 'snake_case protection mode');
  requireContains(sfuClient, 'const SFU_FRAME_CHUNK_MAX_CHARS = 8 * 1024', 'chunk size guard');
  requireContains(sfuClient, "type: 'sfu/frame-chunk'", 'chunked frame command');
  requireContains(sfuClient, "frame_id: frameId", 'chunked frame id');
  requireContains(sfuClient, "chunk_index: chunkIndex", 'chunk index');
  requireContains(sfuClient, "chunk_count: totalChunks", 'chunk count');
  requireContains(sfuClient, 'this.sendChunkedFramePayload(payload,', 'chunked frame sender');
  requireContains(sfuClient, 'const SFU_FRAME_CHUNK_TTL_MS = 5000', 'inbound chunk TTL guard');
  requireContains(sfuClient, 'private pendingInboundFrameChunks = new Map<string, PendingInboundFrameChunk>()', 'inbound chunk cache');
  requireContains(sfuClient, 'private acceptInboundFrameChunk(msg: any): any | null {', 'inbound chunk assembler');
  requireContains(sfuClient, "case 'sfu/frame-chunk': {", 'inbound chunk message handler');
  requireContains(sfuClient, 'const reassembledFrame = this.acceptInboundFrameChunk(msg)', 'inbound chunk reassembly dispatch');

  requireContains(sfuClient, 'const stringField = (...values: any[]): string => {', 'camel/snake inbound helper');
  requireContains(sfuClient, 'roomId:          stringField(msg.roomId, msg.room_id)', 'room event camel/snake compatibility');
  requireContains(sfuClient, 'publisherId:     stringField(msg.publisherId, msg.publisher_id)', 'publisher event camel/snake compatibility');
  requireContains(sfuClient, 'publisherUserId: stringField(msg.publisherUserId, msg.publisher_user_id)', 'publisher user event camel/snake compatibility');
  requireContains(sfuClient, 'publisherName:   stringField(msg.publisherName, msg.publisher_name)', 'publisher name event camel/snake compatibility');
  requireContains(sfuClient, 'stringField(msg.trackId, msg.track_id)', 'track event camel/snake compatibility');
  requireContains(sfuClient, 'const protectedFrame = stringField(msg.protectedFrame, msg.protected_frame)', 'protected frame inbound compatibility');
  requireContains(sfuClient, 'stringField(msg.protectionMode, msg.protection_mode)', 'protection mode inbound compatibility');
  requireContains(sfuClient, 'stringField(msg.frameType, msg.frame_type)', 'frame type inbound compatibility');

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
