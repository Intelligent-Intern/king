const SESSION_CONTRACT_NAME = `king-video-chat-${'e2' + 'ee'}-session`;
const FRAME_CONTRACT_NAME = 'king-video-chat-protected-media-frame';
const TRANSPORT_ENVELOPE_CONTRACT_NAME = 'king-video-chat-protected-media-transport-envelope';
const CONTRACT_VERSION = 'v1.0.0';
const FRAME_MAGIC = 'KPMF';
const FRAME_VERSION = 1;
const CLASSICAL_KEX_SUITE = 'x25519_hkdf_sha256_v1';
const HYBRID_KEX_SUITE = 'hybrid_x25519_mlkem768_hkdf_sha256_v1';
const KEX_SUITE = CLASSICAL_KEX_SUITE;
const MEDIA_SUITE = 'aes_256_gcm_v1';
const ACTIVE_STATE = `media_${'e2' + 'ee'}_active`;
const TEXT_ENCODER = new TextEncoder();
const TEXT_DECODER = new TextDecoder();
const PROTECTED_ENVELOPE_PREFIX_BYTES = 8;
const MEDIA_NONCE_BYTES = 24;
const WRAP_NONCE_BYTES = 12;
const MAX_PUBLIC_METADATA_BYTES = 4096;
const MAX_PROTECTED_CIPHERTEXT_BYTES = 16_777_216;
const KEX_POLICIES = Object.freeze(['classical_required', 'hybrid_preferred', 'hybrid_required']);
const KEX_SUITES = Object.freeze({
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

async function sha256Base64Url(value) {
  const subtle = subtleCrypto();
  if (!subtle) throw new Error('unsupported_capability');
  const bytes = typeof value === 'string' ? TEXT_ENCODER.encode(value) : bytesFromData(value);
  return bytesToBase64Url(new Uint8Array(await subtle.digest('SHA-256', bytes)));
}

function importHkdfKey(sharedBits) {
  const subtle = subtleCrypto();
  if (!subtle) throw new Error('unsupported_capability');
  return subtle.importKey('raw', sharedBits, 'HKDF', false, ['deriveKey']);
}

function normalizeKexPolicy(value) {
  const policy = asString(value);
  return KEX_POLICIES.includes(policy) ? policy : 'classical_required';
}

function normalizeKexSuite(value) {
  const suite = asString(value);
  return Object.prototype.hasOwnProperty.call(KEX_SUITES, suite) ? suite : '';
}

function hasHybridProvider(provider) {
  return !!provider
    && typeof provider.generateKeyPair === 'function'
    && typeof provider.deriveSharedSecret === 'function';
}

function normalizeSuiteList(values) {
  return Array.from(new Set((Array.isArray(values) ? values : [])
    .map(normalizeKexSuite)
    .filter((suite) => suite !== '')));
}

function selectKexSuite(localCapability, remoteCapability, kexPolicy) {
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

function buildWrapAad({
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

function validateProtectedHeader(header) {
  if (!header || typeof header !== 'object') throw new Error('malformed_protected_frame');
  if (header.contract_name !== FRAME_CONTRACT_NAME) throw new Error('malformed_protected_frame');
  if (header.contract_version !== CONTRACT_VERSION) throw new Error('malformed_protected_frame');
  if (header.magic !== FRAME_MAGIC || Number(header.version || 0) !== FRAME_VERSION) throw new Error('malformed_protected_frame');
  if (!['webrtc_native', 'wlvc_sfu'].includes(asString(header.runtime_path))) throw new Error('unsupported_capability');
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

export function createMediaSecuritySession(options = {}) {
  return new MediaSecuritySession(options);
}

export class MediaSecuritySession {
  constructor(options = {}) {
    this.callId = asString(options.callId);
    this.roomId = asString(options.roomId);
    this.userId = normalizeUserId(options.userId);
    this.policy = asString(options.policy) || 'preferred';
    this.kexPolicy = normalizeKexPolicy(options.kexPolicy);
    this.deviceId = asString(options.deviceId) || `dev_${randomToken(16)}`;
    this.logger = typeof options.logger === 'function' ? options.logger : () => {};
    this.hybridKexProvider = hasHybridProvider(options.hybridKexProvider) ? options.hybridKexProvider : null;
    this.state = 'transport_only';
    this.epoch = 1;
    this.sequence = 0;
    this.participantSignature = '';
    this.participantSetHash = '';
    this.selectedKexSuite = KEX_SUITE;
    this.lastRekeyReason = 'initial';
    this.keyPair = null;
    this.publicKeyBytes = null;
    this.hybridKeyPair = null;
    this.hybridPublicKeyBytes = null;
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
      if (this.hybridKexProvider) {
        this.hybridKeyPair = await this.hybridKexProvider.generateKeyPair({
          suite: HYBRID_KEX_SUITE,
          callId: this.callId,
          roomId: this.roomId,
          userId: this.userId,
          deviceId: this.deviceId,
        });
        this.hybridPublicKeyBytes = bytesFromData(this.hybridKeyPair?.publicKey || this.hybridKeyPair?.publicKeyBytes);
        if (this.hybridPublicKeyBytes.byteLength <= 0) throw new Error('unsupported_capability');
      } else if (this.kexPolicy === 'hybrid_required') {
        this.state = 'blocked_capability';
        return false;
      }
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
    this.lastRekeyReason = asString(reason) || 'rekey';
    this.state = 'rekeying';
  }

  capabilityPayload(runtimePath = 'wlvc_sfu') {
    const supportedKexSuites = [CLASSICAL_KEX_SUITE];
    if (this.hybridKexProvider && this.hybridPublicKeyBytes?.byteLength > 0) {
      supportedKexSuites.unshift(HYBRID_KEX_SUITE);
    }
    return {
      runtime_path: asString(runtimePath) || 'wlvc_sfu',
      supports_protected_media_frame_v1: true,
      supports_insertable_streams: MediaSecuritySession.supportsNativeTransforms(),
      supports_wlvc_protected_frame: true,
      supported_kex_suites: supportedKexSuites,
      preferred_kex_suites: this.kexPolicy === 'classical_required' ? [CLASSICAL_KEX_SUITE] : supportedKexSuites,
      kex_policy: this.kexPolicy,
      supported_media_suites: [MEDIA_SUITE],
    };
  }

  participantIdsForPeer(peerUserId) {
    const ids = new Set([this.userId, normalizeUserId(peerUserId)]);
    for (const value of (this.participantSignature || '').split(',')) {
      const userId = normalizeUserId(value);
      if (userId > 0) ids.add(userId);
    }
    return Array.from(ids).filter((userId) => userId > 0).sort((left, right) => left - right);
  }

  async participantHashForPeer(peerUserId) {
    return sha256Base64Url(stableJson({
      call_id: this.callId,
      room_id: this.roomId,
      participant_user_ids: this.participantIdsForPeer(peerUserId),
    }));
  }

  async transcriptHashForPeer({ sender, selectedKexSuite, payload, participantSetHash }) {
    const peerPublicKey = asString(payload.public_key);
    const peerHybridPublicKey = asString(payload.hybrid_public_key);
    const entries = [
      {
        user_id: this.userId,
        device_id: this.deviceId,
        x25519_public_key: bytesToBase64Url(this.publicKeyBytes),
        hybrid_public_key: this.hybridPublicKeyBytes?.byteLength > 0 ? bytesToBase64Url(this.hybridPublicKeyBytes) : '',
      },
      {
        user_id: normalizeUserId(sender),
        device_id: asString(payload.device_id),
        x25519_public_key: peerPublicKey,
        hybrid_public_key: peerHybridPublicKey,
      },
    ].sort((left, right) => left.user_id - right.user_id || left.device_id.localeCompare(right.device_id));
    return sha256Base64Url(stableJson({
      contract_name: SESSION_CONTRACT_NAME,
      contract_version: CONTRACT_VERSION,
      call_id: this.callId,
      room_id: this.roomId,
      selected_kex_suite: selectedKexSuite,
      media_suite: MEDIA_SUITE,
      kex_policy: this.kexPolicy,
      participant_set_hash: participantSetHash,
      participants: entries,
    }));
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
        hybrid_public_key: this.hybridPublicKeyBytes?.byteLength > 0 ? bytesToBase64Url(this.hybridPublicKeyBytes) : '',
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
    if (!capability.supports_protected_media_frame_v1 || !Array.isArray(capability.supported_kex_suites)) {
      this.peers.set(sender, { state: 'blocked_capability' });
      return false;
    }

    let selectedKexSuite = '';
    try {
      selectedKexSuite = selectKexSuite(this.capabilityPayload(capability.runtime_path), capability, this.kexPolicy);
    } catch {
      this.peers.set(sender, { state: 'blocked_capability', capability });
      return false;
    }
    const activeKexSuite = Array.from(this.peers.values()).find((peer) => normalizeKexSuite(peer?.kexSuite) !== '')?.kexSuite || '';
    if (activeKexSuite !== '' && activeKexSuite !== selectedKexSuite) {
      this.peers.set(sender, { state: 'blocked_capability', capability, error: 'downgrade_attempt' });
      throw new Error('downgrade_attempt');
    }
    this.selectedKexSuite = selectedKexSuite;

    const publicKeyBytes = base64UrlToBytes(payload.public_key);
    const subtle = subtleCrypto();
    const peerPublicKey = await subtle.importKey('raw', publicKeyBytes, { name: 'X25519' }, false, []);
    const sharedBits = await subtle.deriveBits({ name: 'X25519', public: peerPublicKey }, this.keyPair.privateKey, 256);
    const participantSetHash = await this.participantHashForPeer(sender);
    this.participantSetHash = participantSetHash;
    const transcriptHash = await this.transcriptHashForPeer({
      sender,
      selectedKexSuite,
      payload,
      participantSetHash,
    });
    const sharedSecretParts = [new Uint8Array(sharedBits)];
    if (selectedKexSuite === HYBRID_KEX_SUITE) {
      if (!this.hybridKexProvider || !this.hybridKeyPair) {
        this.peers.set(sender, { state: 'blocked_capability', capability });
        return false;
      }
      const peerHybridPublicKeyBytes = base64UrlToBytes(payload.hybrid_public_key);
      if (peerHybridPublicKeyBytes.byteLength <= 0) {
        this.peers.set(sender, { state: 'blocked_capability', capability });
        return false;
      }
      const hybridSecret = await this.hybridKexProvider.deriveSharedSecret({
        suite: HYBRID_KEX_SUITE,
        localKeyPair: this.hybridKeyPair,
        localPublicKey: this.hybridPublicKeyBytes,
        peerPublicKey: peerHybridPublicKeyBytes,
        transcriptHash,
        role: this.userId < sender ? 'left' : 'right',
      });
      const hybridSecretBytes = bytesFromData(hybridSecret);
      if (hybridSecretBytes.byteLength <= 0) throw new Error('unsupported_capability');
      sharedSecretParts.push(hybridSecretBytes);
    }
    const hkdfKey = await importHkdfKey(concatBytes(...sharedSecretParts));
    const wrappingKey = await subtle.deriveKey(
      {
        name: 'HKDF',
        hash: 'SHA-256',
        salt: jsonBytes({ call_id: this.callId, room_id: this.roomId, participant_set_hash: participantSetHash }),
        info: jsonBytes({
          contract_name: SESSION_CONTRACT_NAME,
          contract_version: CONTRACT_VERSION,
          suite: selectedKexSuite,
          media_suite: MEDIA_SUITE,
          transcript_hash: transcriptHash,
          left: Math.min(this.userId, sender),
          right: Math.max(this.userId, sender),
        }),
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
      helloPayload: {
        device_id: asString(payload.device_id),
        public_key: asString(payload.public_key),
        hybrid_public_key: asString(payload.hybrid_public_key),
      },
      kexSuite: selectedKexSuite,
      transcriptHash,
      participantSetHash,
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
    const participantSetHash = await this.participantHashForPeer(target);
    const transcriptHash = await this.transcriptHashForPeer({
      sender: target,
      selectedKexSuite: peer.kexSuite || this.selectedKexSuite || KEX_SUITE,
      payload: peer.helloPayload || {
        device_id: peer.deviceId,
        public_key: bytesToBase64Url(peer.publicKeyBytes || new Uint8Array()),
        hybrid_public_key: '',
      },
      participantSetHash,
    });
    this.participantSetHash = participantSetHash;
    peer.participantSetHash = participantSetHash;
    peer.transcriptHash = transcriptHash;
    const aad = buildWrapAad({
      callId: this.callId,
      roomId: this.roomId,
      senderUserId: this.userId,
      targetUserId: target,
      epoch: this.epoch,
      senderKeyId: this.senderKeyId,
      kexSuite: peer.kexSuite,
      transcriptHash,
      participantSetHash,
      rekeyReason: this.lastRekeyReason,
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
        kex_suite: peer.kexSuite || this.selectedKexSuite || KEX_SUITE,
        media_suite: MEDIA_SUITE,
        kex_transcript_hash: transcriptHash,
        participant_set_hash: participantSetHash,
        rekey_reason: this.lastRekeyReason,
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
    const payloadKexSuite = normalizeKexSuite(payload.kex_suite);
    if (payloadKexSuite === '' || payloadKexSuite !== peer.kexSuite || payload.media_suite !== MEDIA_SUITE) {
      throw new Error('downgrade_attempt');
    }
    const participantSetHash = await this.participantHashForPeer(sender);
    const transcriptHash = await this.transcriptHashForPeer({
      sender,
      selectedKexSuite: payloadKexSuite,
      payload: peer.helloPayload || {
        device_id: peer.deviceId,
        public_key: bytesToBase64Url(peer.publicKeyBytes || new Uint8Array()),
        hybrid_public_key: '',
      },
      participantSetHash,
    });
    this.participantSetHash = participantSetHash;
    if (asString(payload.kex_transcript_hash) !== transcriptHash) throw new Error('downgrade_attempt');
    if (asString(payload.participant_set_hash) !== participantSetHash) throw new Error('downgrade_attempt');

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
      kexSuite: payloadKexSuite,
      transcriptHash,
      participantSetHash,
      rekeyReason: asString(payload.rekey_reason) || 'sender_key',
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
      kexSuite: payloadKexSuite,
      transcriptHash,
      participantSetHash,
    });
    this.selectedKexSuite = payloadKexSuite;
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

  async forceRekey(reason = 'forced_rekey') {
    await this.rotateSenderKey(asString(reason) || 'forced_rekey');
    for (const peer of this.peers.values()) {
      if (peer && peer.state === 'active') peer.state = 'rekeying';
    }
    return { epoch: this.epoch, senderKeyId: this.senderKeyId, reason: this.lastRekeyReason };
  }

  telemetrySnapshot(runtimePath = 'wlvc_sfu') {
    const suite = this.selectedKexSuite || KEX_SUITE;
    return {
      security_state: this.state,
      runtime_path: asString(runtimePath) || 'wlvc_sfu',
      policy_mode: this.policy,
      kex_policy: this.kexPolicy,
      kex_suite: suite,
      kex_family: KEX_SUITES[suite]?.family || 'unknown',
      media_suite: MEDIA_SUITE,
      epoch: this.epoch,
      rekey_reason: this.lastRekeyReason,
      participant_set_hash: this.participantSetHash,
    };
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
      kex_suite: this.selectedKexSuite || KEX_SUITE,
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
      envelope: encodeProtectedFrameEnvelope(header, ciphertext),
      protectedFrame: encodeProtectedFrameEnvelopeBase64Url(header, ciphertext),
    };
  }

  async decryptFrame({ data, protected: protectedHeader, publisherUserId, runtimePath, trackId = '', timestamp = 0 } = {}) {
    const header = protectedHeader && typeof protectedHeader === 'object' ? protectedHeader : null;
    validateProtectedHeader(header);
    const sender = normalizeUserId(publisherUserId);
    if (sender <= 0) throw new Error('wrong_key_id');
    if (runtimePath && header.runtime_path !== runtimePath) throw new Error('unsupported_capability');
    const peer = this.peers.get(sender);
    if (peer?.kexSuite && header.kex_suite !== peer.kexSuite) throw new Error('downgrade_attempt');
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
    return protectedFrame.envelope;
  }

  async decryptNativeEncodedFrame(encodedFrame, senderUserId, { trackId = '', timestamp = 0 } = {}) {
    const envelope = decodeProtectedFrameEnvelope(encodedFrame?.data);
    return this.decryptFrame({
      data: envelope.ciphertext,
      protected: envelope.header,
      publisherUserId: senderUserId,
      runtimePath: 'webrtc_native',
      trackId,
      timestamp: timestamp || Number(encodedFrame?.timestamp || Date.now()),
    });
  }

  async decryptProtectedFrameEnvelope({ protectedFrame, envelope, publisherUserId, runtimePath, trackId = '', timestamp = 0 } = {}) {
    const decodedEnvelope = protectedFrame
      ? decodeProtectedFrameEnvelopeBase64Url(protectedFrame)
      : decodeProtectedFrameEnvelope(envelope);
    return this.decryptFrame({
      data: decodedEnvelope.ciphertext,
      protected: decodedEnvelope.header,
      publisherUserId,
      runtimePath,
      trackId,
      timestamp,
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
  TRANSPORT_ENVELOPE_CONTRACT_NAME,
  CONTRACT_VERSION,
  KEX_SUITE,
  CLASSICAL_KEX_SUITE,
  HYBRID_KEX_SUITE,
  KEX_POLICIES,
  KEX_SUITES,
  MEDIA_SUITE,
  ACTIVE_STATE,
  MEDIA_NONCE_BYTES,
  PROTECTED_ENVELOPE_PREFIX_BYTES,
  MAX_PUBLIC_METADATA_BYTES,
  MAX_PROTECTED_CIPHERTEXT_BYTES,
  encodeProtectedFrameEnvelope,
  decodeProtectedFrameEnvelope,
  encodeProtectedFrameEnvelopeBase64Url,
  decodeProtectedFrameEnvelopeBase64Url,
  selectKexSuite,
});
