export const SESSION_CONTRACT_NAME = `king-video-chat-${'e2' + 'ee'}-session`;
export const FRAME_CONTRACT_NAME = 'king-video-chat-protected-media-frame';
export const TRANSPORT_ENVELOPE_CONTRACT_NAME = 'king-video-chat-protected-media-transport-envelope';
export const CONTRACT_VERSION = 'v1.0.0';
export const FRAME_MAGIC = 'KPMF';
export const FRAME_VERSION = 1;
export const CLASSICAL_KEX_SUITE = 'x25519_hkdf_sha256_v1';
export const HYBRID_KEX_SUITE = 'hybrid_x25519_mlkem768_hkdf_sha256_v1';
export const KEX_SUITE = CLASSICAL_KEX_SUITE;
export const MEDIA_SUITE = 'aes_256_gcm_v1';
export const ACTIVE_STATE = `media_${'e2' + 'ee'}_active`;
export const TEXT_ENCODER = new TextEncoder();
export const TEXT_DECODER = new TextDecoder();
export const PROTECTED_ENVELOPE_PREFIX_BYTES = 8;
export const MEDIA_NONCE_BYTES = 24;
export const WRAP_NONCE_BYTES = 12;
export const MAX_PUBLIC_METADATA_BYTES = 4096;
export const MAX_PROTECTED_CIPHERTEXT_BYTES = 16_777_216;
export const KEX_POLICIES = Object.freeze(['classical_required', 'hybrid_preferred', 'hybrid_required']);
export const KEX_SUITES = Object.freeze({
  [CLASSICAL_KEX_SUITE]: Object.freeze({
    id: CLASSICAL_KEX_SUITE,
    family: 'classical',
    production: true,
    components: Object.freeze(['x25519', 'hkdf_sha256']),
  }),
  [HYBRID_KEX_SUITE]: Object.freeze({
    id: HYBRID_KEX_SUITE,
    family: 'hybrid',
    production: false,
    policyGated: true,
    components: Object.freeze(['x25519', 'ml_kem_768', 'hkdf_sha256']),
  }),
});

export const MEDIA_SECURITY_SIGNAL_TYPES = Object.freeze([
  'media-security/hello',
  'media-security/sender-key',
]);

export function asString(value) {
  return String(value ?? '').trim();
}

export function normalizeUserId(value) {
  const normalized = Number(value);
  return Number.isInteger(normalized) && normalized > 0 ? normalized : 0;
}

export function subtleCrypto() {
  return globalThis.crypto?.subtle || null;
}

export function randomBytes(length) {
  const out = new Uint8Array(length);
  if (!globalThis.crypto || typeof globalThis.crypto.getRandomValues !== 'function') {
    throw new Error('unsupported_capability');
  }
  globalThis.crypto.getRandomValues(out);
  return out;
}

export function randomToken(length = 16) {
  try {
    return bytesToBase64Url(randomBytes(length));
  } catch {
    return `${Date.now().toString(36)}${Math.random().toString(36).slice(2)}`;
  }
}

export function bytesToBase64Url(bytes) {
  const input = bytes instanceof Uint8Array ? bytes : new Uint8Array(bytes || []);
  let binary = '';
  for (const value of input) binary += String.fromCharCode(value);
  const base64 = typeof btoa === 'function'
    ? btoa(binary)
    : Buffer.from(input).toString('base64');
  return base64.replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/g, '');
}

export function base64UrlToBytes(value) {
  const normalized = asString(value).replace(/-/g, '+').replace(/_/g, '/');
  const padded = normalized + '='.repeat((4 - (normalized.length % 4)) % 4);
  const binary = typeof atob === 'function'
    ? atob(padded)
    : Buffer.from(padded, 'base64').toString('binary');
  const out = new Uint8Array(binary.length);
  for (let index = 0; index < binary.length; index += 1) {
    out[index] = binary.charCodeAt(index);
  }
  return out;
}

export function bytesFromData(value) {
  if (value instanceof Uint8Array) return value;
  if (value instanceof ArrayBuffer) return new Uint8Array(value);
  if (ArrayBuffer.isView(value)) return new Uint8Array(value.buffer, value.byteOffset, value.byteLength);
  if (Array.isArray(value)) return new Uint8Array(value);
  return new Uint8Array();
}

export function cloneBuffer(bytes) {
  const normalized = bytesFromData(bytes);
  return normalized.buffer.slice(normalized.byteOffset, normalized.byteOffset + normalized.byteLength);
}

export function concatBytes(...chunks) {
  const normalizedChunks = chunks.map(bytesFromData);
  const total = normalizedChunks.reduce((sum, chunk) => sum + chunk.byteLength, 0);
  const out = new Uint8Array(total);
  let offset = 0;
  for (const chunk of normalizedChunks) {
    out.set(chunk, offset);
    offset += chunk.byteLength;
  }
  return out;
}

export function stableJson(value) {
  if (Array.isArray(value)) {
    return `[${value.map((entry) => stableJson(entry)).join(',')}]`;
  }
  if (value && typeof value === 'object') {
    return `{${Object.keys(value).sort().map((key) => `${JSON.stringify(key)}:${stableJson(value[key])}`).join(',')}}`;
  }
  return JSON.stringify(value);
}

export function jsonBytes(value) {
  return TEXT_ENCODER.encode(stableJson(value));
}

export async function sha256Base64Url(value) {
  const subtle = subtleCrypto();
  if (!subtle) throw new Error('unsupported_capability');
  const bytes = typeof value === 'string' ? TEXT_ENCODER.encode(value) : bytesFromData(value);
  return bytesToBase64Url(new Uint8Array(await subtle.digest('SHA-256', bytes)));
}

export function importHkdfKey(sharedBits) {
  const subtle = subtleCrypto();
  if (!subtle) throw new Error('unsupported_capability');
  return subtle.importKey('raw', sharedBits, 'HKDF', false, ['deriveKey']);
}

export function normalizeKexPolicy(value) {
  const policy = asString(value);
  return KEX_POLICIES.includes(policy) ? policy : 'classical_required';
}

export function normalizeKexSuite(value) {
  const suite = asString(value);
  return Object.prototype.hasOwnProperty.call(KEX_SUITES, suite) ? suite : '';
}

export function hasHybridProvider(provider) {
  return !!provider
    && typeof provider.generateKeyPair === 'function'
    && typeof provider.deriveSharedSecret === 'function';
}

export function normalizeSuiteList(values) {
  return Array.from(new Set((Array.isArray(values) ? values : [])
    .map(normalizeKexSuite)
    .filter((suite) => suite !== '')));
}

export function selectKexSuite(localCapability, remoteCapability, kexPolicy) {
  const localSuites = normalizeSuiteList(localCapability?.supported_kex_suites);
  const remoteSuites = normalizeSuiteList(remoteCapability?.supported_kex_suites);
  const bothSupport = (suite) => localSuites.includes(suite) && remoteSuites.includes(suite);
  const policy = normalizeKexPolicy(kexPolicy);
  if (policy !== 'classical_required' && bothSupport(HYBRID_KEX_SUITE)) {
    return HYBRID_KEX_SUITE;
  }
  if (policy === 'hybrid_required') {
    throw new Error('unsupported_capability');
  }
  if (bothSupport(CLASSICAL_KEX_SUITE)) {
    return CLASSICAL_KEX_SUITE;
  }
  throw new Error('unsupported_capability');
}

export function nativeFrameKind(encodedFrame) {
  const frameType = asString(encodedFrame?.type).toLowerCase();
  if (frameType === 'key' || frameType === 'keyframe') return 'keyframe';
  if (frameType === 'audio') return 'audio';
  return 'delta';
}

export function buildAadContext({ callId, roomId, senderUserId, receiverUserId, trackId, timestamp, header }) {
  return {
    call_id: asString(callId),
    room_id: asString(roomId),
    sender_user_id: normalizeUserId(senderUserId),
    receiver_user_id: normalizeUserId(receiverUserId),
    runtime_path: asString(header?.runtime_path),
    codec_id: asString(header?.codec_id),
    track_kind: asString(header?.track_kind),
    track_id: asString(trackId),
    frame_kind: asString(header?.frame_kind),
    epoch: Number(header?.epoch || 0),
    sender_key_id: asString(header?.sender_key_id),
    sequence: Number(header?.sequence || 0),
    timestamp: Number(timestamp || 0),
  };
}

export function buildWrapAad({
  callId,
  roomId,
  senderUserId,
  targetUserId,
  epoch,
  senderKeyId,
  kexSuite,
  transcriptHash,
  participantSetHash,
  rekeyReason,
}) {
  return jsonBytes({
    contract_name: SESSION_CONTRACT_NAME,
    contract_version: CONTRACT_VERSION,
    call_id: asString(callId),
    room_id: asString(roomId),
    sender_user_id: normalizeUserId(senderUserId),
    target_user_id: normalizeUserId(targetUserId),
    epoch: Number(epoch || 0),
    sender_key_id: asString(senderKeyId),
    kex_suite: normalizeKexSuite(kexSuite) || KEX_SUITE,
    media_suite: MEDIA_SUITE,
    kex_transcript_hash: asString(transcriptHash),
    participant_set_hash: asString(participantSetHash),
    rekey_reason: asString(rekeyReason) || 'sender_key',
  });
}

export function validateProtectedHeader(header) {
  if (!header || typeof header !== 'object') throw new Error('malformed_protected_frame');
  if (header.contract_name !== FRAME_CONTRACT_NAME) throw new Error('malformed_protected_frame');
  if (header.contract_version !== CONTRACT_VERSION) throw new Error('malformed_protected_frame');
  if (header.magic !== FRAME_MAGIC || Number(header.version || 0) !== FRAME_VERSION) throw new Error('malformed_protected_frame');
  if (!['webrtc_native', 'wlvc_sfu'].includes(asString(header.runtime_path))) throw new Error('unsupported_capability');
  if (!['webrtc_native', 'wlvc_wasm', 'wlvc_ts', 'wlvc_unknown'].includes(asString(header.codec_id))) throw new Error('unsupported_capability');
  if (!['video', 'audio', 'data'].includes(asString(header.track_kind))) throw new Error('malformed_protected_frame');
  if (!['keyframe', 'delta', 'audio', 'control'].includes(asString(header.frame_kind))) throw new Error('malformed_protected_frame');
  if (!normalizeKexSuite(header.kex_suite) || header.media_suite !== MEDIA_SUITE) throw new Error('unsupported_capability');
  if (Number(header.epoch || 0) < 1) throw new Error('wrong_epoch');
  if (asString(header.sender_key_id) === '') throw new Error('wrong_key_id');
  if (Number(header.sequence || 0) < 1) throw new Error('replay_detected');
  if (base64UrlToBytes(header.nonce).byteLength !== MEDIA_NONCE_BYTES) throw new Error('malformed_protected_frame');
  if (Number(header.aad_length || 0) > MAX_PUBLIC_METADATA_BYTES) throw new Error('malformed_protected_frame');
  if (Number(header.tag_length || 0) !== 16) throw new Error('malformed_protected_frame');
}

export function encodeProtectedFrameEnvelope(header, ciphertext) {
  validateProtectedHeader(header);
  const headerBytes = jsonBytes(header);
  if (headerBytes.byteLength > MAX_PUBLIC_METADATA_BYTES) throw new Error('malformed_protected_frame');
  const body = bytesFromData(ciphertext);
  if (body.byteLength <= 0 || body.byteLength > MAX_PROTECTED_CIPHERTEXT_BYTES) throw new Error('malformed_protected_frame');
  if (Number(header.ciphertext_length || 0) !== body.byteLength) throw new Error('malformed_protected_frame');
  const out = new Uint8Array(PROTECTED_ENVELOPE_PREFIX_BYTES + headerBytes.byteLength + body.byteLength);
  out[0] = 0x4b;
  out[1] = 0x50;
  out[2] = 0x4d;
  out[3] = 0x46;
  const view = new DataView(out.buffer);
  view.setUint32(4, headerBytes.byteLength, false);
  out.set(headerBytes, PROTECTED_ENVELOPE_PREFIX_BYTES);
  out.set(body, PROTECTED_ENVELOPE_PREFIX_BYTES + headerBytes.byteLength);
  return out.buffer;
}

export function decodeProtectedFrameEnvelope(data) {
  const bytes = bytesFromData(data);
  if (bytes.byteLength <= PROTECTED_ENVELOPE_PREFIX_BYTES) throw new Error('malformed_protected_frame');
  if (bytes[0] !== 0x4b || bytes[1] !== 0x50 || bytes[2] !== 0x4d || bytes[3] !== 0x46) {
    throw new Error('malformed_protected_frame');
  }
  const view = new DataView(bytes.buffer, bytes.byteOffset, bytes.byteLength);
  const headerLength = view.getUint32(4, false);
  if (headerLength <= 0 || headerLength > MAX_PUBLIC_METADATA_BYTES) throw new Error('malformed_protected_frame');
  if (bytes.byteLength <= PROTECTED_ENVELOPE_PREFIX_BYTES + headerLength) throw new Error('malformed_protected_frame');
  const headerRaw = bytes.slice(PROTECTED_ENVELOPE_PREFIX_BYTES, PROTECTED_ENVELOPE_PREFIX_BYTES + headerLength);
  const header = JSON.parse(TEXT_DECODER.decode(headerRaw));
  validateProtectedHeader(header);
  const ciphertext = bytes.slice(PROTECTED_ENVELOPE_PREFIX_BYTES + headerLength);
  if (ciphertext.byteLength <= 0 || ciphertext.byteLength > MAX_PROTECTED_CIPHERTEXT_BYTES) throw new Error('malformed_protected_frame');
  if (Number(header.ciphertext_length || 0) !== ciphertext.byteLength) throw new Error('malformed_protected_frame');
  return { header, ciphertext };
}

export function encodeProtectedFrameEnvelopeBase64Url(header, ciphertext) {
  return bytesToBase64Url(encodeProtectedFrameEnvelope(header, ciphertext));
}

export function decodeProtectedFrameEnvelopeBase64Url(value) {
  return decodeProtectedFrameEnvelope(base64UrlToBytes(value));
}
