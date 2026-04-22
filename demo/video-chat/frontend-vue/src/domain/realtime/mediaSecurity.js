const SESSION_CONTRACT_NAME = `king-video-chat-${'e2' + 'ee'}-session`;
const FRAME_CONTRACT_NAME = 'king-video-chat-protected-media-frame';
const CONTRACT_VERSION = 'v1.0.0';
const FRAME_MAGIC = 'KPMF';
const FRAME_VERSION = 1;
const KEX_SUITE = 'x25519_hkdf_sha256_v1';
const MEDIA_SUITE = 'aes_256_gcm_v1';
const ACTIVE_STATE = `media_${'e2' + 'ee'}_active`;
const TEXT_ENCODER = new TextEncoder();
const TEXT_DECODER = new TextDecoder();
const NATIVE_ENVELOPE_HEADER_BYTES = 8;
const MEDIA_NONCE_BYTES = 24;
const WRAP_NONCE_BYTES = 12;
const MAX_PUBLIC_METADATA_BYTES = 4096;

export const MEDIA_SECURITY_SIGNAL_TYPES = Object.freeze([
  'media-security/hello',
  'media-security/sender-key',
]);

function asString(value) {
  return String(value ?? '').trim();
}

function normalizeUserId(value) {
  const normalized = Number(value);
  return Number.isInteger(normalized) && normalized > 0 ? normalized : 0;
}

function subtleCrypto() {
  return globalThis.crypto?.subtle || null;
}

function randomBytes(length) {
  const out = new Uint8Array(length);
  if (!globalThis.crypto || typeof globalThis.crypto.getRandomValues !== 'function') {
    throw new Error('unsupported_capability');
  }
  globalThis.crypto.getRandomValues(out);
  return out;
}

function randomToken(length = 16) {
  try {
    return bytesToBase64Url(randomBytes(length));
  } catch {
    return `${Date.now().toString(36)}${Math.random().toString(36).slice(2)}`;
  }
}

function bytesToBase64Url(bytes) {
  const input = bytes instanceof Uint8Array ? bytes : new Uint8Array(bytes || []);
  let binary = '';
  for (const value of input) binary += String.fromCharCode(value);
  const base64 = typeof btoa === 'function'
    ? btoa(binary)
    : Buffer.from(input).toString('base64');
  return base64.replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/g, '');
}

function base64UrlToBytes(value) {
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

function bytesFromData(value) {
  if (value instanceof Uint8Array) return value;
  if (value instanceof ArrayBuffer) return new Uint8Array(value);
  if (ArrayBuffer.isView(value)) return new Uint8Array(value.buffer, value.byteOffset, value.byteLength);
  if (Array.isArray(value)) return new Uint8Array(value);
  return new Uint8Array();
}

function cloneBuffer(bytes) {
  const normalized = bytesFromData(bytes);
  return normalized.buffer.slice(normalized.byteOffset, normalized.byteOffset + normalized.byteLength);
}

function concatBytes(...chunks) {
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

function stableJson(value) {
  if (Array.isArray(value)) {
    return `[${value.map((entry) => stableJson(entry)).join(',')}]`;
  }
  if (value && typeof value === 'object') {
    return `{${Object.keys(value).sort().map((key) => `${JSON.stringify(key)}:${stableJson(value[key])}`).join(',')}}`;
  }
  return JSON.stringify(value);
}

function jsonBytes(value) {
  return TEXT_ENCODER.encode(stableJson(value));
}

function importHkdfKey(sharedBits) {
  const subtle = subtleCrypto();
  if (!subtle) throw new Error('unsupported_capability');
  return subtle.importKey('raw', sharedBits, 'HKDF', false, ['deriveKey']);
}

function nativeFrameKind(encodedFrame) {
  const frameType = asString(encodedFrame?.type).toLowerCase();
  if (frameType === 'key' || frameType === 'keyframe') return 'keyframe';
  if (frameType === 'audio') return 'audio';
  return 'delta';
}

function buildAadContext({ callId, roomId, senderUserId, receiverUserId, trackId, timestamp, header }) {
  return {
    call_id: asString(callId),
    room_id: asString(roomId),
    sender_user_id: normalizeUserId(senderUserId),
    receiver_user_id: normalizeUserId(receiverUserId),
    runtime_path: asString(header?.runtime_path),
    track_kind: asString(header?.track_kind),
    track_id: asString(trackId),
    frame_kind: asString(header?.frame_kind),
    epoch: Number(header?.epoch || 0),
    sender_key_id: asString(header?.sender_key_id),
    sequence: Number(header?.sequence || 0),
    timestamp: Number(timestamp || 0),
  };
}

function buildWrapAad({ callId, roomId, senderUserId, targetUserId, epoch, senderKeyId }) {
  return jsonBytes({
    contract_name: SESSION_CONTRACT_NAME,
    contract_version: CONTRACT_VERSION,
    call_id: asString(callId),
    room_id: asString(roomId),
    sender_user_id: normalizeUserId(senderUserId),
    target_user_id: normalizeUserId(targetUserId),
    epoch: Number(epoch || 0),
    sender_key_id: asString(senderKeyId),
    kex_suite: KEX_SUITE,
    media_suite: MEDIA_SUITE,
  });
}

function validateProtectedHeader(header) {
  if (!header || typeof header !== 'object') throw new Error('malformed_protected_frame');
  if (header.contract_name !== FRAME_CONTRACT_NAME) throw new Error('malformed_protected_frame');
  if (header.contract_version !== CONTRACT_VERSION) throw new Error('malformed_protected_frame');
  if (header.magic !== FRAME_MAGIC || Number(header.version || 0) !== FRAME_VERSION) throw new Error('malformed_protected_frame');
  if (!['webrtc_native', 'wlvc_sfu'].includes(asString(header.runtime_path))) throw new Error('unsupported_capability');
  if (!['video', 'audio', 'data'].includes(asString(header.track_kind))) throw new Error('malformed_protected_frame');
  if (!['keyframe', 'delta', 'audio', 'control'].includes(asString(header.frame_kind))) throw new Error('malformed_protected_frame');
  if (header.kex_suite !== KEX_SUITE || header.media_suite !== MEDIA_SUITE) throw new Error('unsupported_capability');
  if (Number(header.epoch || 0) < 1) throw new Error('wrong_epoch');
  if (asString(header.sender_key_id) === '') throw new Error('wrong_key_id');
  if (Number(header.sequence || 0) < 1) throw new Error('replay_detected');
  if (base64UrlToBytes(header.nonce).byteLength !== MEDIA_NONCE_BYTES) throw new Error('malformed_protected_frame');
  if (Number(header.aad_length || 0) > MAX_PUBLIC_METADATA_BYTES) throw new Error('malformed_protected_frame');
  if (Number(header.tag_length || 0) !== 16) throw new Error('malformed_protected_frame');
}

function encodeNativeEnvelope(header, ciphertext) {
  const headerBytes = jsonBytes(header);
  if (headerBytes.byteLength > MAX_PUBLIC_METADATA_BYTES) throw new Error('malformed_protected_frame');
  const body = bytesFromData(ciphertext);
  const out = new Uint8Array(NATIVE_ENVELOPE_HEADER_BYTES + headerBytes.byteLength + body.byteLength);
  out[0] = 0x4b;
  out[1] = 0x50;
  out[2] = 0x4d;
  out[3] = 0x46;
  const view = new DataView(out.buffer);
  view.setUint32(4, headerBytes.byteLength, false);
  out.set(headerBytes, NATIVE_ENVELOPE_HEADER_BYTES);
  out.set(body, NATIVE_ENVELOPE_HEADER_BYTES + headerBytes.byteLength);
  return out.buffer;
}

function decodeNativeEnvelope(data) {
  const bytes = bytesFromData(data);
  if (bytes.byteLength <= NATIVE_ENVELOPE_HEADER_BYTES) throw new Error('malformed_protected_frame');
  if (bytes[0] !== 0x4b || bytes[1] !== 0x50 || bytes[2] !== 0x4d || bytes[3] !== 0x46) {
    throw new Error('malformed_protected_frame');
  }
  const view = new DataView(bytes.buffer, bytes.byteOffset, bytes.byteLength);
  const headerLength = view.getUint32(4, false);
  if (headerLength <= 0 || headerLength > MAX_PUBLIC_METADATA_BYTES) throw new Error('malformed_protected_frame');
  if (bytes.byteLength <= NATIVE_ENVELOPE_HEADER_BYTES + headerLength) throw new Error('malformed_protected_frame');
  const headerRaw = bytes.slice(NATIVE_ENVELOPE_HEADER_BYTES, NATIVE_ENVELOPE_HEADER_BYTES + headerLength);
  const header = JSON.parse(TEXT_DECODER.decode(headerRaw));
  const ciphertext = bytes.slice(NATIVE_ENVELOPE_HEADER_BYTES + headerLength);
  return { header, ciphertext };
}

export function createMediaSecuritySession(options = {}) {
  return new MediaSecuritySession(options);
}

export class MediaSecuritySession {
  constructor(options = {}) {
    this.callId = asString(options.callId);
    this.roomId = asString(options.roomId);
    this.userId = normalizeUserId(options.userId);
    this.policy = asString(options.policy) || 'preferred';
    this.deviceId = asString(options.deviceId) || `dev_${randomToken(16)}`;
    this.logger = typeof options.logger === 'function' ? options.logger : () => {};
    this.state = 'transport_only';
    this.epoch = 1;
    this.sequence = 0;
    this.participantSignature = '';
    this.keyPair = null;
    this.publicKeyBytes = null;
    this.senderKey = null;
    this.senderKeyId = '';
    this.peers = new Map();
    this.pendingSenderKeys = new Map();
    this.replayBySenderEpoch = new Map();
    this.nativeSenders = new WeakSet();
    this.nativeReceivers = new WeakSet();
    this.readyPromise = null;
  }

  updateContext(options = {}) {
    if (Object.prototype.hasOwnProperty.call(options, 'callId')) this.callId = asString(options.callId);
    if (Object.prototype.hasOwnProperty.call(options, 'roomId')) this.roomId = asString(options.roomId);
    if (Object.prototype.hasOwnProperty.call(options, 'userId')) this.userId = normalizeUserId(options.userId);
  }

  async ensureReady() {
    if (this.readyPromise) return this.readyPromise;
    this.readyPromise = this.initialize();
    return this.readyPromise;
  }

  async initialize() {
    const subtle = subtleCrypto();
    if (!subtle || !globalThis.crypto?.getRandomValues) {
      this.state = 'blocked_capability';
      return false;
    }

    try {
      this.keyPair = await subtle.generateKey({ name: 'X25519' }, true, ['deriveBits']);
      this.publicKeyBytes = new Uint8Array(await subtle.exportKey('raw', this.keyPair.publicKey));
      await this.rotateSenderKey('initial');
      this.state = 'protected_not_ready';
      return true;
    } catch (error) {
      this.state = 'blocked_capability';
      this.logger('[MediaSecurity] X25519/WebCrypto unavailable', error);
      return false;
    }
  }

  async rotateSenderKey(reason = 'rekey') {
    const subtle = subtleCrypto();
    if (!subtle) throw new Error('unsupported_capability');
    if (reason !== 'initial') this.epoch += 1;
    this.sequence = 0;
    this.senderKey = await subtle.generateKey({ name: 'AES-GCM', length: 256 }, true, ['encrypt', 'decrypt']);
    this.senderKeyId = bytesToBase64Url(randomBytes(16));
    this.state = 'rekeying';
  }

  capabilityPayload(runtimePath = 'wlvc_sfu') {
    return {
      runtime_path: asString(runtimePath) || 'wlvc_sfu',
      supports_protected_media_frame_v1: true,
      supports_insertable_streams: MediaSecuritySession.supportsNativeTransforms(),
      supports_wlvc_protected_frame: true,
      supported_kex_suites: [KEX_SUITE],
      supported_media_suites: [MEDIA_SUITE],
    };
  }

  async buildHelloSignal(targetUserId, runtimePath = 'wlvc_sfu') {
    if (!(await this.ensureReady())) return null;
    const target = normalizeUserId(targetUserId);
    if (target <= 0 || target === this.userId) return null;
    return {
      type: 'media-security/hello',
      target_user_id: target,
      payload: {
        kind: 'media_security_hello',
        contract_name: SESSION_CONTRACT_NAME,
        contract_version: CONTRACT_VERSION,
        device_id: this.deviceId,
        epoch: this.epoch,
        sender_key_id: this.senderKeyId,
        public_key: bytesToBase64Url(this.publicKeyBytes),
        capability: this.capabilityPayload(runtimePath),
      },
    };
  }

  async handleHelloSignal(senderUserId, payload = {}) {
    if (!(await this.ensureReady())) return false;
    const sender = normalizeUserId(senderUserId);
    if (sender <= 0 || sender === this.userId) return false;
    if (payload.contract_name !== SESSION_CONTRACT_NAME || payload.contract_version !== CONTRACT_VERSION) return false;
    const capability = payload.capability && typeof payload.capability === 'object' ? payload.capability : {};
    if (!capability.supports_protected_media_frame_v1 || !Array.isArray(capability.supported_kex_suites) || !capability.supported_kex_suites.includes(KEX_SUITE)) {
      this.peers.set(sender, { state: 'blocked_capability' });
      return false;
    }

    const publicKeyBytes = base64UrlToBytes(payload.public_key);
    const subtle = subtleCrypto();
    const peerPublicKey = await subtle.importKey('raw', publicKeyBytes, { name: 'X25519' }, false, []);
    const sharedBits = await subtle.deriveBits({ name: 'X25519', public: peerPublicKey }, this.keyPair.privateKey, 256);
    const hkdfKey = await importHkdfKey(sharedBits);
    const wrappingKey = await subtle.deriveKey(
      {
        name: 'HKDF',
        hash: 'SHA-256',
        salt: jsonBytes({ call_id: this.callId, room_id: this.roomId }),
        info: jsonBytes({ suite: KEX_SUITE, left: Math.min(this.userId, sender), right: Math.max(this.userId, sender) }),
      },
      hkdfKey,
      { name: 'AES-GCM', length: 256 },
      false,
      ['encrypt', 'decrypt'],
    );

    const existing = this.peers.get(sender) || {};
    this.peers.set(sender, {
      ...existing,
      state: 'capability_ready',
      publicKeyBytes,
      wrappingKey,
      deviceId: asString(payload.device_id),
      capability,
    });

    const pending = this.pendingSenderKeys.get(sender);
    if (pending) {
      this.pendingSenderKeys.delete(sender);
      await this.handleSenderKeySignal(sender, pending);
    }
    return true;
  }

  async buildSenderKeySignal(targetUserId) {
    if (!(await this.ensureReady())) return null;
    const target = normalizeUserId(targetUserId);
    const peer = this.peers.get(target);
    if (!peer?.wrappingKey) return null;
    const subtle = subtleCrypto();
    const mediaKeyBytes = new Uint8Array(await subtle.exportKey('raw', this.senderKey));
    const nonce = randomBytes(WRAP_NONCE_BYTES);
    const aad = buildWrapAad({
      callId: this.callId,
      roomId: this.roomId,
      senderUserId: this.userId,
      targetUserId: target,
      epoch: this.epoch,
      senderKeyId: this.senderKeyId,
    });
    const encryptedKey = await subtle.encrypt({ name: 'AES-GCM', iv: nonce, additionalData: aad, tagLength: 128 }, peer.wrappingKey, mediaKeyBytes);
    return {
      type: 'media-security/sender-key',
      target_user_id: target,
      payload: {
        kind: 'media_security_sender_key',
        contract_name: SESSION_CONTRACT_NAME,
        contract_version: CONTRACT_VERSION,
        device_id: this.deviceId,
        epoch: this.epoch,
        sender_key_id: this.senderKeyId,
        kex_suite: KEX_SUITE,
        media_suite: MEDIA_SUITE,
        nonce: bytesToBase64Url(nonce),
        encrypted_key: bytesToBase64Url(new Uint8Array(encryptedKey)),
      },
    };
  }

  async handleSenderKeySignal(senderUserId, payload = {}) {
    if (!(await this.ensureReady())) return false;
    const sender = normalizeUserId(senderUserId);
    if (sender <= 0 || sender === this.userId) return false;
    const peer = this.peers.get(sender);
    if (!peer?.wrappingKey) {
      this.pendingSenderKeys.set(sender, payload);
      return false;
    }
    if (payload.contract_name !== SESSION_CONTRACT_NAME || payload.contract_version !== CONTRACT_VERSION) return false;
    if (payload.kex_suite !== KEX_SUITE || payload.media_suite !== MEDIA_SUITE) return false;

    const epoch = Number(payload.epoch || 0);
    const senderKeyId = asString(payload.sender_key_id);
    if (epoch < 1 || senderKeyId === '') throw new Error('wrong_key_id');

    const subtle = subtleCrypto();
    const nonce = base64UrlToBytes(payload.nonce);
    const encryptedKey = base64UrlToBytes(payload.encrypted_key);
    const aad = buildWrapAad({
      callId: this.callId,
      roomId: this.roomId,
      senderUserId: sender,
      targetUserId: this.userId,
      epoch,
      senderKeyId,
    });
    const mediaKeyBytes = await subtle.decrypt({ name: 'AES-GCM', iv: nonce, additionalData: aad, tagLength: 128 }, peer.wrappingKey, encryptedKey);
    const receiverKey = await subtle.importKey('raw', mediaKeyBytes, { name: 'AES-GCM' }, false, ['decrypt']);
    const receiverKeys = peer.receiverKeys instanceof Map ? peer.receiverKeys : new Map();
    receiverKeys.set(`${epoch}:${senderKeyId}`, receiverKey);
    this.peers.set(sender, {
      ...peer,
      state: 'active',
      receiverKeys,
      highestEpoch: Math.max(Number(peer.highestEpoch || 0), epoch),
    });
    this.state = ACTIVE_STATE;
    return true;
  }

  markParticipantSet(userIds) {
    const normalized = Array.from(new Set((Array.isArray(userIds) ? userIds : [])
      .map(normalizeUserId)
      .filter((userId) => userId > 0 && userId !== this.userId)))
      .sort((left, right) => left - right);
    const nextSignature = normalized.join(',');
    const previous = new Set(this.participantSignature === '' ? [] : this.participantSignature.split(',').map(Number));
    const next = new Set(normalized);
    for (const userId of previous) {
      if (!next.has(userId)) this.markPeerRemoved(userId);
    }
    const changed = this.participantSignature !== '' && this.participantSignature !== nextSignature;
    this.participantSignature = nextSignature;
    return { changed, userIds: normalized };
  }

  markPeerRemoved(userId) {
    const normalized = normalizeUserId(userId);
    if (normalized <= 0) return;
    this.peers.set(normalized, { state: 'removed' });
    for (const key of Array.from(this.replayBySenderEpoch.keys())) {
      if (key.startsWith(`${normalized}:`)) this.replayBySenderEpoch.delete(key);
    }
    this.pendingSenderKeys.delete(normalized);
  }

  async protectFrame({ data, runtimePath, trackKind = 'video', frameKind = 'delta', trackId = '', timestamp = 0 } = {}) {
    if (!(await this.ensureReady()) || !this.senderKey) throw new Error('unsupported_capability');
    const subtle = subtleCrypto();
    const plaintext = bytesFromData(data);
    const nonce = randomBytes(MEDIA_NONCE_BYTES);
    const sequence = this.sequence + 1;
    this.sequence = sequence;
    const header = {
      contract_name: FRAME_CONTRACT_NAME,
      contract_version: CONTRACT_VERSION,
      magic: FRAME_MAGIC,
      version: FRAME_VERSION,
      runtime_path: asString(runtimePath),
      track_kind: asString(trackKind) || 'video',
      frame_kind: asString(frameKind) || 'delta',
      kex_suite: KEX_SUITE,
      media_suite: MEDIA_SUITE,
      epoch: this.epoch,
      sender_key_id: this.senderKeyId,
      sequence,
      nonce: bytesToBase64Url(nonce),
      aad_length: 0,
      ciphertext_length: 0,
      tag_length: 16,
    };
    const aad = jsonBytes(buildAadContext({
      callId: this.callId,
      roomId: this.roomId,
      senderUserId: this.userId,
      receiverUserId: 0,
      trackId,
      timestamp,
      header,
    }));
    header.aad_length = aad.byteLength;
    const ciphertext = await subtle.encrypt({ name: 'AES-GCM', iv: nonce, additionalData: aad, tagLength: 128 }, this.senderKey, plaintext);
    header.ciphertext_length = ciphertext.byteLength;
    validateProtectedHeader(header);
    return {
      data: cloneBuffer(ciphertext),
      protected: header,
    };
  }

  async decryptFrame({ data, protected: protectedHeader, publisherUserId, runtimePath, trackId = '', timestamp = 0 } = {}) {
    const header = protectedHeader && typeof protectedHeader === 'object' ? protectedHeader : null;
    validateProtectedHeader(header);
    const sender = normalizeUserId(publisherUserId);
    if (sender <= 0) throw new Error('wrong_key_id');
    if (runtimePath && header.runtime_path !== runtimePath) throw new Error('unsupported_capability');
    const peer = this.peers.get(sender);
    const receiverKey = peer?.receiverKeys instanceof Map
      ? peer.receiverKeys.get(`${Number(header.epoch)}:${asString(header.sender_key_id)}`)
      : null;
    if (!receiverKey) {
      const highestEpoch = Number(peer?.highestEpoch || 0);
      if (highestEpoch > Number(header.epoch || 0)) throw new Error('wrong_epoch');
      throw new Error('wrong_key_id');
    }

    const replayKey = `${sender}:${Number(header.epoch)}:${asString(header.sender_key_id)}`;
    const lastSequence = Number(this.replayBySenderEpoch.get(replayKey) || 0);
    const sequence = Number(header.sequence || 0);
    if (sequence <= lastSequence) throw new Error('replay_detected');

    const aad = jsonBytes(buildAadContext({
      callId: this.callId,
      roomId: this.roomId,
      senderUserId: sender,
      receiverUserId: 0,
      trackId,
      timestamp,
      header,
    }));
    if (Number(header.aad_length || 0) !== aad.byteLength) throw new Error('malformed_protected_frame');

    const plaintext = await subtleCrypto().decrypt(
      { name: 'AES-GCM', iv: base64UrlToBytes(header.nonce), additionalData: aad, tagLength: 128 },
      receiverKey,
      bytesFromData(data),
    );
    this.replayBySenderEpoch.set(replayKey, sequence);
    return cloneBuffer(plaintext);
  }

  async protectNativeEncodedFrame(encodedFrame, { trackKind = 'video', trackId = '', timestamp = 0 } = {}) {
    const protectedFrame = await this.protectFrame({
      data: encodedFrame?.data,
      runtimePath: 'webrtc_native',
      trackKind,
      frameKind: nativeFrameKind(encodedFrame),
      trackId,
      timestamp: timestamp || Number(encodedFrame?.timestamp || Date.now()),
    });
    return encodeNativeEnvelope(protectedFrame.protected, protectedFrame.data);
  }

  async decryptNativeEncodedFrame(encodedFrame, senderUserId, { trackId = '', timestamp = 0 } = {}) {
    const envelope = decodeNativeEnvelope(encodedFrame?.data);
    return this.decryptFrame({
      data: envelope.ciphertext,
      protected: envelope.header,
      publisherUserId: senderUserId,
      runtimePath: 'webrtc_native',
      trackId,
      timestamp: timestamp || Number(encodedFrame?.timestamp || Date.now()),
    });
  }

  attachNativeSenderTransform(sender, { trackKind = 'video', trackId = '' } = {}) {
    if (!sender || typeof sender.createEncodedStreams !== 'function') return false;
    if (this.nativeSenders.has(sender)) return true;
    const streams = sender.createEncodedStreams();
    if (!streams?.readable || !streams?.writable || typeof TransformStream !== 'function') return false;
    this.nativeSenders.add(sender);
    streams.readable
      .pipeThrough(new TransformStream({
        transform: async (encodedFrame, controller) => {
          try {
            encodedFrame.data = await this.protectNativeEncodedFrame(encodedFrame, { trackKind, trackId });
            controller.enqueue(encodedFrame);
          } catch (error) {
            this.logger('[MediaSecurity] native sender frame dropped', error);
          }
        },
      }))
      .pipeTo(streams.writable)
      .catch((error) => this.logger('[MediaSecurity] native sender transform stopped', error));
    return true;
  }

  attachNativeReceiverTransform(receiver, senderUserId, { trackId = '' } = {}) {
    if (!receiver || typeof receiver.createEncodedStreams !== 'function') return false;
    if (this.nativeReceivers.has(receiver)) return true;
    const streams = receiver.createEncodedStreams();
    if (!streams?.readable || !streams?.writable || typeof TransformStream !== 'function') return false;
    this.nativeReceivers.add(receiver);
    streams.readable
      .pipeThrough(new TransformStream({
        transform: async (encodedFrame, controller) => {
          try {
            encodedFrame.data = await this.decryptNativeEncodedFrame(encodedFrame, senderUserId, { trackId });
            controller.enqueue(encodedFrame);
          } catch (error) {
            this.logger('[MediaSecurity] native receiver frame dropped', error);
          }
        },
      }))
      .pipeTo(streams.writable)
      .catch((error) => this.logger('[MediaSecurity] native receiver transform stopped', error));
    return true;
  }

  static supportsNativeTransforms() {
    return typeof RTCRtpSender !== 'undefined'
      && typeof RTCRtpSender.prototype?.createEncodedStreams === 'function'
      && typeof RTCRtpReceiver !== 'undefined'
      && typeof RTCRtpReceiver.prototype?.createEncodedStreams === 'function'
      && typeof TransformStream === 'function';
  }
}

export const mediaSecurityInternalsForTests = Object.freeze({
  SESSION_CONTRACT_NAME,
  FRAME_CONTRACT_NAME,
  CONTRACT_VERSION,
  KEX_SUITE,
  MEDIA_SUITE,
  ACTIVE_STATE,
  MEDIA_NONCE_BYTES,
  encodeNativeEnvelope,
  decodeNativeEnvelope,
});
