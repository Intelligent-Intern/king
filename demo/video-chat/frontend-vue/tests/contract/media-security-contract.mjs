import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

import {
  createMediaSecuritySession,
  MEDIA_SECURITY_SIGNAL_TYPES,
  mediaSecurityInternalsForTests,
} from '../../src/domain/realtime/media/security.js';

function fail(message) {
  throw new Error(`[media-security-frontend-contract] FAIL: ${message}`);
}

function read(relPath) {
  const __filename = fileURLToPath(import.meta.url);
  const __dirname = path.dirname(__filename);
  return fs.readFileSync(path.resolve(__dirname, relPath), 'utf8');
}

function createHybridProvider(label) {
  const encoder = new TextEncoder();
  const base64Url = (bytes) => Buffer.from(bytes).toString('base64').replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/g, '');
  return {
    async generateKeyPair(context) {
      const publicKey = new Uint8Array(await crypto.subtle.digest('SHA-256', encoder.encode(`${label}:${context.userId}:${context.deviceId}`)));
      return { publicKey };
    },
    async deriveSharedSecret({ localPublicKey, peerPublicKey, transcriptHash }) {
      const local = base64Url(new Uint8Array(localPublicKey));
      const peer = base64Url(new Uint8Array(peerPublicKey));
      const ordered = [local, peer].sort().join('.');
      return crypto.subtle.digest('SHA-256', encoder.encode(`hybrid-test:${ordered}:${transcriptHash}`));
    },
  };
}

try {
  assert.deepEqual(MEDIA_SECURITY_SIGNAL_TYPES, ['media-security/hello', 'media-security/sender-key']);
  assert.equal(mediaSecurityInternalsForTests.KEX_SUITE, 'x25519_hkdf_sha256_v1');
  assert.equal(mediaSecurityInternalsForTests.CLASSICAL_KEX_SUITE, 'x25519_hkdf_sha256_v1');
  assert.equal(mediaSecurityInternalsForTests.HYBRID_KEX_SUITE, 'hybrid_x25519_mlkem768_hkdf_sha256_v1');
  assert.equal(mediaSecurityInternalsForTests.KEX_SUITES.x25519_hkdf_sha256_v1.production, true);
  assert.equal(mediaSecurityInternalsForTests.KEX_SUITES.hybrid_x25519_mlkem768_hkdf_sha256_v1.policyGated, true);
  assert.equal(mediaSecurityInternalsForTests.MEDIA_SUITE, 'aes_256_gcm_v1');
  assert.equal(mediaSecurityInternalsForTests.MEDIA_NONCE_BYTES, 24);
  assert.equal(mediaSecurityInternalsForTests.TRANSPORT_ENVELOPE_CONTRACT_NAME, 'king-video-chat-protected-media-transport-envelope');
  assert.equal(mediaSecurityInternalsForTests.PROTECTED_ENVELOPE_PREFIX_BYTES, 8);

  const alice = createMediaSecuritySession({ callId: 'call-1', roomId: 'room-1', userId: 101 });
  const bob = createMediaSecuritySession({ callId: 'call-1', roomId: 'room-1', userId: 202 });
  alice.markParticipantSet([202]);
  bob.markParticipantSet([101]);

  const aliceHello = await alice.buildHelloSignal(202, 'wlvc_sfu');
  const bobHello = await bob.buildHelloSignal(101, 'wlvc_sfu');
  assert.equal(aliceHello.type, 'media-security/hello');
  assert.equal(aliceHello.payload.capability.supports_protected_media_frame_v1, true);
  assert.equal(aliceHello.payload.capability.supported_kex_suites.includes('x25519_hkdf_sha256_v1'), true);
  assert.equal(aliceHello.payload.capability.kex_policy, 'classical_required');

  await bob.handleHelloSignal(101, aliceHello.payload);
  await alice.handleHelloSignal(202, bobHello.payload);

  const aliceSenderKey = await alice.buildSenderKeySignal(202);
  const bobSenderKey = await bob.buildSenderKeySignal(101);
  assert.equal(aliceSenderKey.type, 'media-security/sender-key');
  assert.equal(aliceSenderKey.payload.kex_suite, 'x25519_hkdf_sha256_v1');
  assert.ok(String(aliceSenderKey.payload.kex_transcript_hash || '').length > 20, 'sender-key signal must pin KEX transcript');
  assert.ok(String(aliceSenderKey.payload.participant_set_hash || '').length > 20, 'sender-key signal must pin participant set');
  assert.ok(!('raw_media_key' in aliceSenderKey.payload), 'sender-key signal must not expose raw media key fields');
  assert.ok(String(aliceSenderKey.payload.encrypted_key || '').length > 20, 'sender key must be wrapped');
  await assert.rejects(
    () => bob.handleSenderKeySignal(101, {
      ...aliceSenderKey.payload,
      kex_suite: 'hybrid_x25519_mlkem768_hkdf_sha256_v1',
    }),
    /downgrade_attempt/,
    'receiver must reject sender-key suite downgrade attempts',
  );
  await bob.handleSenderKeySignal(101, aliceSenderKey.payload);
  await alice.handleSenderKeySignal(202, bobSenderKey.payload);

  const staleParticipantAlice = createMediaSecuritySession({ callId: 'call-stale', roomId: 'room-stale', userId: 101 });
  const staleParticipantBob = createMediaSecuritySession({ callId: 'call-stale', roomId: 'room-stale', userId: 202 });
  staleParticipantAlice.markParticipantSet([202]);
  staleParticipantBob.markParticipantSet([101]);
  const staleAliceHello = await staleParticipantAlice.buildHelloSignal(202, 'wlvc_sfu');
  const staleBobHello = await staleParticipantBob.buildHelloSignal(101, 'wlvc_sfu');
  await staleParticipantAlice.handleHelloSignal(202, staleBobHello.payload);
  await staleParticipantBob.handleHelloSignal(101, staleAliceHello.payload);
  const staleSenderKey = await staleParticipantAlice.buildSenderKeySignal(202);
  staleParticipantBob.markParticipantSet([101, 303]);
  await assert.rejects(
    () => staleParticipantBob.handleSenderKeySignal(101, staleSenderKey.payload),
    /participant_set_mismatch/,
    'receiver must classify stale participant-set sender keys as reconnectable churn, not a KEX downgrade',
  );

  const pendingStaleBob = createMediaSecuritySession({ callId: 'call-stale', roomId: 'room-stale', userId: 202 });
  pendingStaleBob.markParticipantSet([101, 303]);
  assert.equal(await pendingStaleBob.handleSenderKeySignal(101, staleSenderKey.payload), false, 'sender-key may arrive before hello and be held pending');
  assert.equal(
    await pendingStaleBob.handleHelloSignal(101, staleAliceHello.payload),
    true,
    'fresh hello handling must survive a stale pending sender-key from an earlier participant set',
  );

  const plaintext = new Uint8Array([1, 2, 3, 4, 5, 6, 7, 8]);
  const protectedFrame = await alice.protectFrame({
    data: plaintext,
    runtimePath: 'wlvc_sfu',
    codecId: 'wlvc_ts',
    trackKind: 'video',
    frameKind: 'delta',
    trackId: 'camera-a',
    timestamp: 1000,
  });
  assert.notDeepEqual(Array.from(new Uint8Array(protectedFrame.data).slice(0, plaintext.length)), Array.from(plaintext), 'protected frame must not carry plaintext bytes');
  assert.equal(protectedFrame.protected.contract_name, 'king-video-chat-protected-media-frame');
  assert.equal(protectedFrame.protected.runtime_path, 'wlvc_sfu');
  assert.equal(protectedFrame.protected.codec_id, 'wlvc_ts');
  assert.equal(protectedFrame.protected.kex_suite, 'x25519_hkdf_sha256_v1');
  assert.ok(String(protectedFrame.protectedFrame || '').length > 40, 'protected frame must expose a transport envelope');
  assert.equal(protectedFrame.envelope instanceof ArrayBuffer, true, 'protected frame must expose typed binary envelope bytes');
  for (const forbidden of ['raw_media_key', 'private_key', 'shared_secret', 'plaintext_frame', 'decoded_audio', 'decoded_video']) {
    assert.ok(!(forbidden in protectedFrame.protected), `protected frame metadata must not expose ${forbidden}`);
  }

  const decrypted = await bob.decryptFrame({
    data: protectedFrame.data,
    protected: protectedFrame.protected,
    publisherUserId: 101,
    runtimePath: 'wlvc_sfu',
    codecId: 'wlvc_ts',
    trackId: 'camera-a',
    timestamp: 1000,
  });
  assert.deepEqual(Array.from(new Uint8Array(decrypted)), Array.from(plaintext), 'receiver must decrypt protected WLVC frame');

  const aliceDeviceA = createMediaSecuritySession({
    callId: 'call-multi-device',
    roomId: 'room-multi-device',
    userId: 101,
    deviceId: 'alice-device-a',
  });
  const aliceDeviceB = createMediaSecuritySession({
    callId: 'call-multi-device',
    roomId: 'room-multi-device',
    userId: 101,
    deviceId: 'alice-device-b',
  });
  const bobMultiDevice = createMediaSecuritySession({
    callId: 'call-multi-device',
    roomId: 'room-multi-device',
    userId: 202,
    deviceId: 'bob-device',
  });
  aliceDeviceA.markParticipantSet([202]);
  aliceDeviceB.markParticipantSet([202]);
  bobMultiDevice.markParticipantSet([101]);
  const bobMultiHello = await bobMultiDevice.buildHelloSignal(101, 'wlvc_sfu');
  await aliceDeviceA.handleHelloSignal(202, bobMultiHello.payload);
  await aliceDeviceB.handleHelloSignal(202, bobMultiHello.payload);
  await bobMultiDevice.handleHelloSignal(101, (await aliceDeviceA.buildHelloSignal(202, 'wlvc_sfu')).payload);
  await bobMultiDevice.handleHelloSignal(101, (await aliceDeviceB.buildHelloSignal(202, 'wlvc_sfu')).payload);
  await bobMultiDevice.handleSenderKeySignal(101, (await aliceDeviceA.buildSenderKeySignal(202)).payload);
  await bobMultiDevice.handleSenderKeySignal(101, (await aliceDeviceB.buildSenderKeySignal(202)).payload);
  const protectedFromDeviceA = await aliceDeviceA.protectFrame({
    data: new Uint8Array([9, 8, 7, 6]),
    runtimePath: 'wlvc_sfu',
    codecId: 'wlvc_ts',
    trackKind: 'video',
    frameKind: 'keyframe',
    trackId: 'camera-a',
    timestamp: 3000,
  });
  const protectedFromDeviceB = await aliceDeviceB.protectFrame({
    data: new Uint8Array([5, 4, 3, 2]),
    runtimePath: 'wlvc_sfu',
    codecId: 'wlvc_ts',
    trackKind: 'video',
    frameKind: 'keyframe',
    trackId: 'camera-b',
    timestamp: 4000,
  });
  assert.deepEqual(
    Array.from(new Uint8Array(await bobMultiDevice.decryptFrame({
      data: protectedFromDeviceA.data,
      protected: protectedFromDeviceA.protected,
      publisherUserId: 101,
      runtimePath: 'wlvc_sfu',
      codecId: 'wlvc_ts',
      trackId: 'camera-a',
      timestamp: 3000,
    }))),
    [9, 8, 7, 6],
    'receiver must keep sender keys for multiple active devices of the same user',
  );
  assert.deepEqual(
    Array.from(new Uint8Array(await bobMultiDevice.decryptFrame({
      data: protectedFromDeviceB.data,
      protected: protectedFromDeviceB.protected,
      publisherUserId: 101,
      runtimePath: 'wlvc_sfu',
      codecId: 'wlvc_ts',
      trackId: 'camera-b',
      timestamp: 4000,
    }))),
    [5, 4, 3, 2],
    'receiver must not overwrite one device key with another device from the same user',
  );

  await assert.rejects(
    () => bob.decryptFrame({
      data: protectedFrame.data,
      protected: protectedFrame.protected,
      publisherUserId: 101,
      runtimePath: 'wlvc_sfu',
      codecId: 'wlvc_ts',
      trackId: 'camera-a',
      timestamp: 1000,
    }),
    /replay_detected/,
    'receiver must reject replayed protected frames',
  );

  const tampered = new Uint8Array(protectedFrame.data);
  tampered[0] ^= 0xff;
  await assert.rejects(
    () => bob.decryptFrame({
      data: tampered,
      protected: { ...protectedFrame.protected, sequence: protectedFrame.protected.sequence + 1 },
      publisherUserId: 101,
      runtimePath: 'wlvc_sfu',
      codecId: 'wlvc_ts',
      trackId: 'camera-a',
      timestamp: 1000,
    }),
    /decrypt|OperationError/i,
    'receiver must reject tampered ciphertext',
  );

  const nativeEnvelope = mediaSecurityInternalsForTests.encodeProtectedFrameEnvelope(protectedFrame.protected, protectedFrame.data);
  const decodedEnvelope = mediaSecurityInternalsForTests.decodeProtectedFrameEnvelope(nativeEnvelope);
  assert.equal(decodedEnvelope.header.contract_name, 'king-video-chat-protected-media-frame');
  assert.deepEqual(Array.from(new Uint8Array(decodedEnvelope.ciphertext)), Array.from(new Uint8Array(protectedFrame.data)));
  const decodedBase64Envelope = mediaSecurityInternalsForTests.decodeProtectedFrameEnvelopeBase64Url(protectedFrame.protectedFrame);
  assert.equal(decodedBase64Envelope.header.sequence, protectedFrame.protected.sequence, 'base64 transport envelope must parse back to header');

  await assert.rejects(
    () => bob.decryptFrame({
      data: protectedFrame.data,
      protected: { ...protectedFrame.protected, kex_suite: 'hybrid_x25519_mlkem768_hkdf_sha256_v1', sequence: protectedFrame.protected.sequence + 2 },
      publisherUserId: 101,
      runtimePath: 'wlvc_sfu',
      codecId: 'wlvc_ts',
      trackId: 'camera-a',
      timestamp: 1000,
    }),
    /downgrade_attempt/,
    'receiver must reject protected-frame suite downgrade attempts',
  );

  const previousEpoch = alice.epoch;
  alice.markParticipantSet([202, 303]);
  bob.markParticipantSet([101, 303]);
  await alice.forceRekey('forced_rekey');
  assert.equal(alice.epoch, previousEpoch + 1, 'forced rekey must advance epoch after participant churn');
  await assert.rejects(
    () => alice.buildSenderKeySignal(202),
    /participant_set_mismatch/,
    'sender-key must fail closed until a fresh hello re-pins the participant set after churn',
  );
  const aliceRehello = await alice.buildHelloSignal(202, 'wlvc_sfu');
  const bobRehello = await bob.buildHelloSignal(101, 'wlvc_sfu');
  await bob.handleHelloSignal(101, aliceRehello.payload);
  await alice.handleHelloSignal(202, bobRehello.payload);
  const rekeyedSenderKey = await alice.buildSenderKeySignal(202);
  assert.equal(rekeyedSenderKey.payload.rekey_reason, 'forced_rekey');
  await bob.handleSenderKeySignal(101, rekeyedSenderKey.payload);
  const rekeyedFrame = await alice.protectFrame({
    data: plaintext,
    runtimePath: 'wlvc_sfu',
    codecId: 'wlvc_ts',
    trackKind: 'video',
    frameKind: 'delta',
    trackId: 'camera-a',
    timestamp: 2000,
  });
  const rekeyedDecrypted = await bob.decryptFrame({
    data: rekeyedFrame.data,
    protected: rekeyedFrame.protected,
    publisherUserId: 101,
    runtimePath: 'wlvc_sfu',
    codecId: 'wlvc_ts',
    trackId: 'camera-a',
    timestamp: 2000,
  });
  assert.deepEqual(Array.from(new Uint8Array(rekeyedDecrypted)), Array.from(plaintext), 'rekeyed receiver must decrypt new epoch');

  const telemetry = alice.telemetrySnapshot('wlvc_sfu');
  assert.equal(telemetry.kex_suite, 'x25519_hkdf_sha256_v1');
  assert.equal(telemetry.kex_family, 'classical');
  for (const forbidden of ['raw_media_key', 'private_key', 'shared_secret', 'plaintext_frame']) {
    assert.ok(!(forbidden in telemetry), `telemetry must not expose ${forbidden}`);
  }

  const hybridBlocked = createMediaSecuritySession({ callId: 'call-h', roomId: 'room-h', userId: 301, kexPolicy: 'hybrid_required' });
  assert.equal(await hybridBlocked.ensureReady(), false, 'hybrid_required must fail closed without a provider');
  assert.throws(
    () => mediaSecurityInternalsForTests.selectKexSuite(
      { supported_kex_suites: ['x25519_hkdf_sha256_v1'] },
      { supported_kex_suites: ['x25519_hkdf_sha256_v1'] },
      'hybrid_required',
    ),
    /unsupported_capability/,
    'hybrid_required must not downgrade to classical',
  );

  const hybridAlice = createMediaSecuritySession({
    callId: 'call-h',
    roomId: 'room-h',
    userId: 301,
    kexPolicy: 'hybrid_required',
    hybridKexProvider: createHybridProvider('a'),
  });
  const hybridBob = createMediaSecuritySession({
    callId: 'call-h',
    roomId: 'room-h',
    userId: 302,
    kexPolicy: 'hybrid_required',
    hybridKexProvider: createHybridProvider('b'),
  });
  hybridAlice.markParticipantSet([302]);
  hybridBob.markParticipantSet([301]);
  const hybridAliceHello = await hybridAlice.buildHelloSignal(302, 'wlvc_sfu');
  const hybridBobHello = await hybridBob.buildHelloSignal(301, 'wlvc_sfu');
  assert.equal(hybridAliceHello.payload.capability.supported_kex_suites[0], 'hybrid_x25519_mlkem768_hkdf_sha256_v1');
  await hybridBob.handleHelloSignal(301, hybridAliceHello.payload);
  await hybridAlice.handleHelloSignal(302, hybridBobHello.payload);
  const hybridAliceSenderKey = await hybridAlice.buildSenderKeySignal(302);
  const hybridBobSenderKey = await hybridBob.buildSenderKeySignal(301);
  assert.equal(hybridAliceSenderKey.payload.kex_suite, 'hybrid_x25519_mlkem768_hkdf_sha256_v1');
  await hybridBob.handleSenderKeySignal(301, hybridAliceSenderKey.payload);
  await hybridAlice.handleSenderKeySignal(302, hybridBobSenderKey.payload);
  const hybridProtectedFrame = await hybridAlice.protectFrame({
    data: plaintext,
    runtimePath: 'wlvc_sfu',
    codecId: 'wlvc_ts',
    trackKind: 'video',
    frameKind: 'delta',
    trackId: 'camera-h',
    timestamp: 3000,
  });
  assert.equal(hybridProtectedFrame.protected.kex_suite, 'hybrid_x25519_mlkem768_hkdf_sha256_v1');
  const hybridDecrypted = await hybridBob.decryptFrame({
    data: hybridProtectedFrame.data,
    protected: hybridProtectedFrame.protected,
    publisherUserId: 301,
    runtimePath: 'wlvc_sfu',
    codecId: 'wlvc_ts',
    trackId: 'camera-h',
    timestamp: 3000,
  });
  assert.deepEqual(Array.from(new Uint8Array(hybridDecrypted)), Array.from(plaintext), 'hybrid KEX provider path must protect and decrypt media under the same frame contract');
  assert.equal(hybridAlice.telemetrySnapshot('wlvc_sfu').kex_family, 'hybrid');

  const reconnectSession = createMediaSecuritySession({ callId: 'call-r', roomId: 'room-r', userId: 404 });
  const firstJoin = reconnectSession.markParticipantSet([505]);
  assert.equal(firstJoin.changed, true, 'first non-empty participant set must count as a handshake change');
  const stableJoin = reconnectSession.markParticipantSet([505]);
  assert.equal(stableJoin.changed, false, 'unchanged participant set must not trigger redundant rekeys');

  bob.markPeerRemoved(101);
  await assert.rejects(
    () => bob.decryptFrame({
      data: protectedFrame.data,
      protected: { ...protectedFrame.protected, sequence: protectedFrame.protected.sequence + 2 },
      publisherUserId: 101,
      runtimePath: 'wlvc_sfu',
      codecId: 'wlvc_ts',
      trackId: 'camera-a',
      timestamp: 1000,
    }),
    /wrong_key_id/,
    'receiver must reject material after participant removal',
  );

  await assert.rejects(
    () => bob.decryptFrame({
      data: protectedFrame.data,
      protected: { ...protectedFrame.protected, sequence: protectedFrame.protected.sequence + 3 },
      publisherUserId: 101,
      runtimePath: 'wlvc_sfu',
      codecId: 'wlvc_wasm',
      trackId: 'camera-a',
      timestamp: 1000,
    }),
    /unsupported_capability/,
    'receiver must reject protected frames when codec identity mismatches the transport envelope',
  );

  const mediaSecurityRuntimeSource = read('../../src/domain/realtime/workspace/callWorkspace/mediaSecurityRuntime.js');
  const runtimeConfigSource = read('../../src/domain/realtime/workspace/callWorkspace/runtimeConfig.js');
  const orchestrationSource = read('../../src/domain/realtime/workspace/callWorkspace/orchestration.js');
  const publisherPipelineSource = read('../../src/domain/realtime/local/publisherPipeline.js');
  const frameDecodeSource = read('../../src/domain/realtime/sfu/frameDecode.js');
  const sfuLifecycleSource = read('../../src/domain/realtime/sfu/lifecycle.js');
  const mediaStackSource = read('../../src/domain/realtime/workspace/callWorkspace/mediaStack.js');
  const securitySource = read('../../src/domain/realtime/media/security.js');
  const securityCoreSource = read('../../src/domain/realtime/media/securityCore.js');
  assert.match(mediaSecurityRuntimeSource, /function scheduleMediaSecurityParticipantSync\(reason = 'unspecified', forceRekey = false\)/, 'media-security runtime must expose a scheduled participant sync helper');
  assert.match(mediaSecurityRuntimeSource, /scheduleMediaSecurityParticipantSync\('context_changed'\);/, 'media-security runtime must resync after session context resets');
  assert.match(orchestrationSource, /scheduleMediaSecurityParticipantSync\('context_watch'\);/, 'workspace orchestration must resync media security when call or room context changes');
  assert.match(mediaSecurityRuntimeSource, /function normalizeRemoteMediaSecurityUserId\(userId\)[\s\S]*normalizedUserId === currentUserId\.value[\s\S]*return 0;/m, 'media-security runtime must reject self user ids before they can enter the remote handshake set');
  assert.match(mediaSecurityRuntimeSource, /function remoteMediaSecurityEligibleTargetIds\(\)[\s\S]*mediaSecurityEligibleTargetIds\(\)[\s\S]*normalizeRemoteMediaSecurityUserId\(userId\)/m, 'media-security runtime must normalize connected remote SFU targets through the remote-user guard');
  assert.match(mediaSecurityRuntimeSource, /const targetIds = remoteMediaSecurityEligibleTargetIds\(\);/, 'handshake timeout watchdog must operate on the connected remote SFU target set');
  assert.match(runtimeConfigSource, /MEDIA_SECURITY_HANDSHAKE_RETRY_TIMEOUTS_MS = Object\.freeze\(\[1000, 3000, 6000\]\)/, 'handshake retry watchdog must retry after 1s, then 3s, then 6s');
  assert.match(mediaSecurityRuntimeSource, /function mediaSecurityHandshakeRetryTimeoutMsForAttempt\(retryAttempt\)/, 'handshake retry watchdog must derive timeout from retry attempt');
  assert.match(mediaSecurityRuntimeSource, /state\.mediaSecurityHandshakeRetryCountByUserId\.set\(normalizedTargetId, retryAttempt \+ 1\);/, 'handshake retry watchdog must advance retry attempt after each timeout');
  assert.match(mediaSecurityRuntimeSource, /state\.mediaSecurityHandshakeRetryCountByUserId\.delete\(normalizedSenderUserId\);/, 'handshake retry watchdog must reset retry attempts when sender-key is accepted');
  assert.match(mediaSecurityRuntimeSource, /message\.includes\('participant_set_mismatch'\)/, 'media-security recovery must treat participant-set churn as a reconnectable key path');
  assert.match(mediaSecurityRuntimeSource, /const shouldForceRekeyAfterSignalFailure = errorCode === 'downgrade_attempt';/, 'downgrade-attempt signal failures must be treated as forced rekey recovery');
  assert.match(mediaSecurityRuntimeSource, /session\.markPeerRemoved\?\.\(normalizedSenderUserId\);/, 'downgrade-attempt recovery must clear stale peer key state before rebuilding the handshake');
  assert.match(mediaSecurityRuntimeSource, /scheduleMediaSecurityParticipantSync\('signal_failed_reconnect', shouldForceRekeyAfterSignalFailure\);/, 'media-security signal failures must trigger a reconnect-style participant sync and force rekey after downgrade attempts');
  assert.match(mediaSecurityRuntimeSource, /async function sendMediaSecurityHello\(targetUserId, force = false\)[\s\S]*const normalizedTargetId = normalizeRemoteMediaSecurityUserId\(targetUserId\);[\s\S]*if \(normalizedTargetId <= 0\) return false;/m, 'media-security hello sender must not emit self-targeted handshake signals');
  assert.match(mediaSecurityRuntimeSource, /async function sendMediaSecuritySenderKey\(targetUserId, force = false\)[\s\S]*const normalizedTargetId = normalizeRemoteMediaSecurityUserId\(targetUserId\);[\s\S]*if \(normalizedTargetId <= 0\) return false;/m, 'media-security sender-key sender must not emit self-targeted key signals');
  assert.match(mediaSecurityRuntimeSource, /async function handleMediaSecuritySignal\(type, senderUserId, payloadBody\)[\s\S]*const normalizedSenderUserId = normalizeRemoteMediaSecurityUserId\(senderUserId\);[\s\S]*if \(normalizedSenderUserId <= 0\) return;/m, 'media-security signal receiver must ignore self-origin signals before mutating participant state');
  assert.match(mediaSecurityRuntimeSource, /function shouldForceReplyToIncomingMediaSecurityHello\(senderUserId, payloadBody, session\)[\s\S]*incomingMediaSecurityHelloResponseKey\(senderUserId, payloadBody, session\)[\s\S]*state\.mediaSecurityHelloSignalsSent\.add\(key\);/m, 'accepted remote hello responses must be deduped by incoming hello identity to avoid broker replay echo loops');
  assert.match(mediaSecurityRuntimeSource, /const forceReply = shouldForceReplyToIncomingMediaSecurityHello\([\s\S]*normalizedSenderUserId,[\s\S]*payloadBody \|\| \{\},[\s\S]*session,[\s\S]*\);[\s\S]*await sendMediaSecurityHello\(normalizedSenderUserId, forceReply\);[\s\S]*await sendMediaSecuritySenderKey\(normalizedSenderUserId, forceReply\);/m, 'accepted remote hello must force exactly one fresh response per unique hello so reconnecting peers can unwrap sender keys without flooding the broker');
  assert.doesNotMatch(mediaSecurityRuntimeSource, /if \(accepted\) \{[\s\S]*await sendMediaSecurityHello\(normalizedSenderUserId, true\);[\s\S]*await sendMediaSecuritySenderKey\(normalizedSenderUserId, true\);/m, 'accepted remote hello must not force-answer every broker replay');
  assert.match(mediaSecurityRuntimeSource, /const marked = session\.markParticipantSet\(\[[\s\S]*\.\.\.remoteMediaSecurityTargetIds\(\),[\s\S]*normalizedSenderUserId,[\s\S]*\]\);[\s\S]*if \(remoteMediaSecurityTargetIds\(\)\.includes\(normalizedSenderUserId\)\) \{[\s\S]*scheduleMediaSecurityParticipantSync\('hello_accepted'\);[\s\S]*\}/m, 'media-security runtime must pin only a remote hello sender into the participant set and only schedule follow-up sync once the sender is in the current remote target set');
  assert.match(mediaSecurityRuntimeSource, /const accepted = await session\.handleSenderKeySignal\(normalizedSenderUserId, payloadBody \|\| \{\}\);[\s\S]*if \(!accepted && remoteMediaSecurityTargetIds\(\)\.includes\(normalizedSenderUserId\)\) \{[\s\S]*scheduleMediaSecurityParticipantSync\('sender_key_pending'\);[\s\S]*\}/m, 'media-security runtime must defer sender-key recovery sync until the sender is present in the current remote participant target set');
  assert.doesNotMatch(mediaSecurityRuntimeSource, /elapsed=\$\{Date\.now\(\) - helloSentAt\}ms — force-retrying Hello/, 'participant sync must not hide the join race behind a multi-second inline Hello retry loop');
  assert.match(publisherPipelineSource, /protectFrame\(\{[\s\S]*runtimePath: 'wlvc_sfu'[\s\S]*codecId: outgoingFrame\.codecId[\s\S]*outgoingFrame\.protectedFrame = protectedFrame\.protectedFrame;/m, 'publisher pipeline must protect WLVC frames with explicit codec identity before SFU send');
  assert.match(frameDecodeSource, /decryptProtectedFrameEnvelope\(\{[\s\S]*runtimePath: 'wlvc_sfu'[\s\S]*codecId: frame\.codecId/m, 'decode pipeline must decrypt WLVC transport envelopes with codec identity');
  assert.match(frameDecodeSource, /shouldRecoverMediaSecurityFromFrameError\(error\)[\s\S]*recoverMediaSecurityForPublisher\(publisherUserId\);/m, 'decode pipeline must recover the media-security handshake when protected SFU frames arrive before keys');
  assert.match(frameDecodeSource, /function invalidateRemoteSfuTrackAfterProtectedDecryptFailure\(peer, frame, reason = 'unknown'\)/, 'protected SFU decrypt failures must invalidate stale remote decoder state');
  assert.match(frameDecodeSource, /keyframe_required_after_recovery: true/, 'protected SFU decrypt failures must require a fresh keyframe after media-security recovery');
  assert.match(mediaSecurityRuntimeSource, /await sendMediaSecurityHello\(normalizedUserId, true\);[\s\S]*await sendMediaSecuritySenderKey\(normalizedUserId, true\);/m, 'media-security recovery must retry hello and sender-key signals for the remote publisher');
  assert.match(sfuLifecycleSource, /clearMediaSecuritySfuPublisherSeen\(peerUserId\)[\s\S]*scheduleMediaSecurityParticipantSync\('sfu_publisher_left'\);/m, 'SFU publisher leave events must remove stale media-security targets');
  assert.match(mediaStackSource, /callbacks\.clearMediaSecuritySfuPublisherSeen\?\.\(peerUserId\);/, 'bulk SFU teardown must clear stale media-security publisher targets');
  assert.match(securitySource, /attachNativeSenderTransform/, 'media-security library must attach native sender transform hooks');
  assert.match(securitySource, /attachNativeReceiverTransform/, 'media-security library must attach native receiver transform hooks');
  assert.match(securitySource, /canProtectNativeForTargets\(userIds\) \{[\s\S]*if \(this\.state !== ACTIVE_STATE\) return false;[\s\S]*this\.canProtectForTargets\(normalized\)/m, 'native audio/video transforms must wait for an active media-security session instead of attaching during rekeying');
  assert.match(securitySource, /codec_id: normalizeProtectedCodecId\(codecId, runtimePath\)/, 'protected frame header must carry normalized codec identity');
  assert.match(securitySource, /if \(codecId && header\.codec_id !== normalizeProtectedCodecId\(codecId, runtimePath\)\) throw new Error\('unsupported_capability'\);/, 'frame decrypt must reject codec identity mismatches');
  assert.match(securityCoreSource, /codec_id: asString\(header\?\.codec_id\)/, 'AAD must bind codec identity into the protected-frame contract');
  assert.match(securityCoreSource, /if \(!\['webrtc_native', 'wlvc_wasm', 'wlvc_ts', 'webcodecs_vp8', 'wlvc_unknown'\]\.includes\(asString\(header\.codec_id\)\)\) throw new Error\('unsupported_capability'\);/, 'protected-frame header validation must restrict codec identity to supported values');

  const sfuClientSource = read('../../src/lib/sfu/sfuClient.ts');
  const sfuMessageHandlerSource = read('../../src/lib/sfu/sfuMessageHandler.ts');
  const sfuTypesSource = read('../../src/lib/sfu/sfuTypes.ts');
  const sfuFramePayloadSource = read('../../src/lib/sfu/framePayload.ts');
  assert.match(sfuTypesSource, /protectedFrame\?: string \| null/, 'SFU frame type must carry protected transport envelope');
  assert.match(sfuFramePayloadSource, /const protectedFrame = protectionMode === 'transport_only' \? null : arrayBufferToBase64Url\(payloadBytes\)/, 'binary SFU envelope decode must reconstruct protected transport envelopes');
  assert.match(sfuFramePayloadSource, /\.\.\.\(protectedFrame \? \{ protected_frame: protectedFrame \} : \{\}\)/, 'decoded binary SFU frame must surface protected_frame without JSON chunk transport');
  assert.match(sfuMessageHandlerSource, /protectedFrame: protectedFrame \|\| null/, 'SFU receiver must surface protected transport envelope');
  assert.doesNotMatch(sfuClientSource, /payload\.protected = frame\.protected/, 'SFU sender must not use ad-hoc protected metadata JSON for protected frames');
  assert.doesNotMatch(sfuMessageHandlerSource, /payload\.protected = frame\.protected/, 'SFU receiver must not use ad-hoc protected metadata JSON for protected frames');

  process.stdout.write('[media-security-frontend-contract] PASS\n');
} catch (error) {
  if (error instanceof Error) {
    fail(error.message);
  }
  fail('unknown failure');
}
