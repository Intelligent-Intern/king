import {
  ACTIVE_STATE,
  CLASSICAL_KEX_SUITE,
  CONTRACT_VERSION,
  FRAME_MAGIC,
  FRAME_CONTRACT_NAME,
  FRAME_VERSION,
  HYBRID_KEX_SUITE,
  KEX_POLICIES,
  KEX_SUITE,
  KEX_SUITES,
  MAX_PROTECTED_CIPHERTEXT_BYTES,
  MAX_PUBLIC_METADATA_BYTES,
  MEDIA_NONCE_BYTES,
  MEDIA_SUITE,
  PROTECTED_ENVELOPE_PREFIX_BYTES,
  SESSION_CONTRACT_NAME,
  TRANSPORT_ENVELOPE_CONTRACT_NAME,
  WRAP_NONCE_BYTES,
  asString,
  base64UrlToBytes,
  buildAadContext,
  buildWrapAad,
  bytesFromData,
  bytesToBase64Url,
  cloneBuffer,
  concatBytes,
  decodeProtectedFrameEnvelope,
  decodeProtectedFrameEnvelopeBase64Url,
  encodeProtectedFrameEnvelope,
  encodeProtectedFrameEnvelopeBase64Url,
  hasHybridProvider,
  importHkdfKey,
  jsonBytes,
  nativeFrameKind,
  normalizeKexPolicy,
  normalizeKexSuite,
  normalizeUserId,
  randomBytes,
  randomToken,
  selectKexSuite,
  sha256Base64Url,
  stableJson,
  subtleCrypto,
  validateProtectedHeader,
} from './securityCore.js';

export {
  MEDIA_SECURITY_SIGNAL_TYPES,
  decodeProtectedFrameEnvelope,
  decodeProtectedFrameEnvelopeBase64Url,
  encodeProtectedFrameEnvelope,
  encodeProtectedFrameEnvelopeBase64Url,
} from './securityCore.js';

export function createMediaSecuritySession(options = {}) {
  return new MediaSecuritySession(options);
}

function nativeEncodedFrameAadTrackId(trackKind = 'data') {
  const normalizedKind = asString(trackKind).toLowerCase();
  if (normalizedKind === 'audio') return 'native_audio';
  if (normalizedKind === 'video') return 'native_video';
  return 'native_data';
}

function normalizeProtectedCodecId(value, runtimePath = '') {
  const normalized = asString(value).toLowerCase();
  if (normalized === 'wlvc_wasm' || normalized === 'wlvc_ts' || normalized === 'webcodecs_vp8' || normalized === 'wlvc_unknown') return normalized;
  if (asString(runtimePath).toLowerCase() === 'webrtc_native') return 'webrtc_native';
  return 'wlvc_unknown';
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
    this.nativeFrameErrorHandler = typeof options.onNativeFrameError === 'function'
      ? options.onNativeFrameError
      : null;
    this.nativeSenderFrameErrorHandler = typeof options.onNativeSenderFrameError === 'function'
      ? options.onNativeSenderFrameError
      : null;
    this.nativeReceiverFrameErrorHandler = typeof options.onNativeReceiverFrameError === 'function'
      ? options.onNativeReceiverFrameError
      : null;
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
    // M11: Purge replay-detection counters for the previous epoch so frames from
    // the new epoch are not rejected as replay_detected after a key rotation.
    const ownPrefix = `${this.userId}:`;
    for (const key of Array.from(this.replayBySenderEpoch.keys())) {
      if (key.startsWith(ownPrefix)) this.replayBySenderEpoch.delete(key);
    }
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

  pendingSenderKeyMapKey(senderUserId, deviceId = '') {
    const sender = normalizeUserId(senderUserId);
    const device = asString(deviceId);
    return `${sender}:${device}`;
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
    const deviceId = asString(payload.device_id);
    const deviceContext = {
      state: 'capability_ready',
      publicKeyBytes,
      wrappingKey,
      deviceId,
      capability,
      helloPayload: {
        device_id: deviceId,
        public_key: asString(payload.public_key),
        hybrid_public_key: asString(payload.hybrid_public_key),
      },
      kexSuite: selectedKexSuite,
      transcriptHash,
      participantSetHash,
    };
    const devices = existing.devices instanceof Map ? new Map(existing.devices) : new Map();
    if (deviceId !== '') {
      devices.set(deviceId, deviceContext);
    }
    this.peers.set(sender, {
      ...existing,
      ...deviceContext,
      devices,
    });

    const pendingKeys = [
      this.pendingSenderKeyMapKey(sender, deviceId),
      this.pendingSenderKeyMapKey(sender, ''),
    ];
    for (const pendingKey of pendingKeys) {
      const pending = this.pendingSenderKeys.get(pendingKey);
      if (!pending) continue;
      this.pendingSenderKeys.delete(pendingKey);
      try {
        await this.handleSenderKeySignal(sender, pending);
      } catch (error) {
        if (asString(error?.message || error).trim().toLowerCase() === 'participant_set_mismatch') {
          continue;
        }
        throw error;
      }
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
    if (
      asString(peer.participantSetHash) !== participantSetHash
      || asString(peer.transcriptHash) !== transcriptHash
    ) {
      throw new Error('participant_set_mismatch');
    }
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
    const senderDeviceId = asString(payload.device_id);
    const devices = peer?.devices instanceof Map ? peer.devices : new Map();
    const deviceContext = senderDeviceId !== '' ? devices.get(senderDeviceId) : null;
    const keyContext = deviceContext || (senderDeviceId === '' ? peer : null);
    if (!keyContext?.wrappingKey) {
      this.pendingSenderKeys.set(this.pendingSenderKeyMapKey(sender, senderDeviceId), payload);
      return false;
    }
    if (payload.contract_name !== SESSION_CONTRACT_NAME || payload.contract_version !== CONTRACT_VERSION) return false;
    const payloadKexSuite = normalizeKexSuite(payload.kex_suite);
    if (payloadKexSuite === '' || payloadKexSuite !== keyContext.kexSuite || payload.media_suite !== MEDIA_SUITE) {
      throw new Error('downgrade_attempt');
    }
    const participantSetHash = await this.participantHashForPeer(sender);
    const transcriptHash = await this.transcriptHashForPeer({
      sender,
      selectedKexSuite: payloadKexSuite,
      payload: keyContext.helloPayload || {
        device_id: keyContext.deviceId,
        public_key: bytesToBase64Url(keyContext.publicKeyBytes || new Uint8Array()),
        hybrid_public_key: '',
      },
      participantSetHash,
    });
    this.participantSetHash = participantSetHash;
    if (asString(payload.participant_set_hash) !== participantSetHash) throw new Error('participant_set_mismatch');
    if (asString(payload.kex_transcript_hash) !== transcriptHash) throw new Error('downgrade_attempt');
    if (
      asString(keyContext.participantSetHash) !== participantSetHash
      || asString(keyContext.transcriptHash) !== transcriptHash
    ) {
      this.pendingSenderKeys.set(this.pendingSenderKeyMapKey(sender, senderDeviceId), payload);
      return false;
    }

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
    const mediaKeyBytes = await subtle.decrypt({ name: 'AES-GCM', iv: nonce, additionalData: aad, tagLength: 128 }, keyContext.wrappingKey, encryptedKey);
    const receiverKey = await subtle.importKey('raw', mediaKeyBytes, { name: 'AES-GCM' }, false, ['decrypt']);
    const receiverKeys = peer?.receiverKeys instanceof Map ? new Map(peer.receiverKeys) : new Map();
    receiverKeys.set(`${epoch}:${senderKeyId}`, receiverKey);
    const nextDevices = peer?.devices instanceof Map ? new Map(peer.devices) : new Map();
    if (senderDeviceId !== '') {
      nextDevices.set(senderDeviceId, {
        ...keyContext,
        state: 'active',
        kexSuite: payloadKexSuite,
        transcriptHash,
        participantSetHash,
      });
    }
    this.peers.set(sender, {
      ...peer,
      ...keyContext,
      state: 'active',
      receiverKeys,
      highestEpoch: Math.max(Number(peer.highestEpoch || 0), epoch),
      kexSuite: payloadKexSuite,
      transcriptHash,
      participantSetHash,
      devices: nextDevices,
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
    const changed = this.participantSignature !== nextSignature;
    this.participantSignature = nextSignature;
    return { changed, userIds: normalized };
  }

  canProtectForTargets(userIds) {
    const normalized = Array.from(new Set((Array.isArray(userIds) ? userIds : [])
      .map(normalizeUserId)
      .filter((userId) => userId > 0 && userId !== this.userId)));
    if (normalized.length <= 0) return false;

    for (const userId of normalized) {
      const peer = this.peers.get(userId);
      const state = asString(peer?.state);
      if (!peer?.wrappingKey) return false;
      if (state !== 'active') return false;
      if (state === 'blocked_capability' || state === 'removed') return false;
    }

    return true;
  }

  canProtectNativeForTargets(userIds) {
    if (!MediaSecuritySession.supportsNativeTransforms()) return false;
    if (this.state !== ACTIVE_STATE) return false;
    const normalized = Array.from(new Set((Array.isArray(userIds) ? userIds : [])
      .map(normalizeUserId)
      .filter((userId) => userId > 0 && userId !== this.userId)));
    if (normalized.length <= 0) return false;
    if (!this.canProtectForTargets(normalized)) return false;

    for (const userId of normalized) {
      const peer = this.peers.get(userId);
      const capability = peer?.capability && typeof peer.capability === 'object' ? peer.capability : null;
      if (!capability || capability.supports_insertable_streams !== true) {
        return false;
      }
    }

    return true;
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
    for (const key of Array.from(this.pendingSenderKeys.keys())) {
      if (String(key).startsWith(`${normalized}:`)) this.pendingSenderKeys.delete(key);
    }
  }

  async protectFrame({ data, runtimePath, codecId, trackKind = 'video', frameKind = 'delta', trackId = '', timestamp = 0 } = {}) {
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
      codec_id: normalizeProtectedCodecId(codecId, runtimePath),
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

  async decryptFrame({ data, protected: protectedHeader, publisherUserId, runtimePath, codecId, trackId = '', timestamp = 0 } = {}) {
    const header = protectedHeader && typeof protectedHeader === 'object' ? protectedHeader : null;
    validateProtectedHeader(header);
    const sender = normalizeUserId(publisherUserId);
    if (sender <= 0) throw new Error('wrong_key_id');
    if (runtimePath && header.runtime_path !== runtimePath) throw new Error('unsupported_capability');
    if (codecId && header.codec_id !== normalizeProtectedCodecId(codecId, runtimePath)) throw new Error('unsupported_capability');
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

    const replayKey = [
      sender,
      Number(header.epoch),
      asString(header.sender_key_id),
      asString(header.runtime_path),
      asString(header.track_kind),
      asString(trackId),
    ].join(':');
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
    const aadTrackId = nativeEncodedFrameAadTrackId(trackKind || nativeFrameKind(encodedFrame));
    const protectedFrame = await this.protectFrame({
      data: encodedFrame?.data,
      runtimePath: 'webrtc_native',
      codecId: 'webrtc_native',
      trackKind,
      frameKind: nativeFrameKind(encodedFrame),
      trackId: aadTrackId || trackId,
      timestamp: timestamp || Number(encodedFrame?.timestamp || Date.now()),
    });
    return protectedFrame.envelope;
  }

  async decryptNativeEncodedFrame(encodedFrame, senderUserId, { trackId = '', timestamp = 0 } = {}) {
    const envelope = decodeProtectedFrameEnvelope(encodedFrame?.data);
    const aadTrackId = nativeEncodedFrameAadTrackId(envelope?.header?.track_kind);
    return this.decryptFrame({
      data: envelope.ciphertext,
      protected: envelope.header,
      publisherUserId: senderUserId,
      runtimePath: 'webrtc_native',
      codecId: 'webrtc_native',
      trackId: aadTrackId || trackId,
      timestamp: timestamp || Number(encodedFrame?.timestamp || Date.now()),
    });
  }

  async decryptProtectedFrameEnvelope({ protectedFrame, envelope, publisherUserId, runtimePath, codecId, trackId = '', timestamp = 0 } = {}) {
    const decodedEnvelope = protectedFrame
      ? decodeProtectedFrameEnvelopeBase64Url(protectedFrame)
      : decodeProtectedFrameEnvelope(envelope);
    return this.decryptFrame({
      data: decodedEnvelope.ciphertext,
      protected: decodedEnvelope.header,
      publisherUserId,
      runtimePath,
      codecId,
      trackId,
      timestamp,
    });
  }

  reportNativeFrameTransformError(direction, error, details = {}) {
    const normalizedDirection = asString(direction) || 'unknown';
    const payload = {
      ...details,
      direction: normalizedDirection,
    };
    this.logger(`[MediaSecurity] native ${normalizedDirection} frame dropped`, error, payload);

    const handlers = [this.nativeFrameErrorHandler];
    if (normalizedDirection === 'sender') handlers.push(this.nativeSenderFrameErrorHandler);
    if (normalizedDirection === 'receiver') handlers.push(this.nativeReceiverFrameErrorHandler);

    for (const handler of handlers) {
      if (typeof handler !== 'function') continue;
      try {
        handler({
          ...payload,
          error,
        });
      } catch {
        // Diagnostics callbacks must never break the fail-closed transform.
      }
    }
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
            this.reportNativeFrameTransformError('sender', error, {
              trackKind,
              trackId,
            });
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
            this.reportNativeFrameTransformError('receiver', error, {
              senderUserId: normalizeUserId(senderUserId),
              trackId,
            });
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
  SESSION_CONTRACT_NAME, FRAME_CONTRACT_NAME, TRANSPORT_ENVELOPE_CONTRACT_NAME,
  CONTRACT_VERSION, KEX_SUITE, CLASSICAL_KEX_SUITE, HYBRID_KEX_SUITE,
  KEX_POLICIES, KEX_SUITES, MEDIA_SUITE, ACTIVE_STATE, MEDIA_NONCE_BYTES,
  PROTECTED_ENVELOPE_PREFIX_BYTES, MAX_PUBLIC_METADATA_BYTES, MAX_PROTECTED_CIPHERTEXT_BYTES,
  encodeProtectedFrameEnvelope, decodeProtectedFrameEnvelope,
  encodeProtectedFrameEnvelopeBase64Url, decodeProtectedFrameEnvelopeBase64Url,
  selectKexSuite,
});
