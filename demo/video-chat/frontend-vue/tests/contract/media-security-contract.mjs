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

  const plaintext = new Uint8Array([1, 2, 3, 4, 5, 6, 7, 8]);
  const protectedFrame = await alice.protectFrame({
    data: plaintext,
    runtimePath: 'wlvc_sfu',
    trackKind: 'video',
    frameKind: 'delta',
    trackId: 'camera-a',
    timestamp: 1000,
  });
  assert.notDeepEqual(Array.from(new Uint8Array(protectedFrame.data).slice(0, plaintext.length)), Array.from(plaintext), 'protected frame must not carry plaintext bytes');
  assert.equal(protectedFrame.protected.contract_name, 'king-video-chat-protected-media-frame');
  assert.equal(protectedFrame.protected.runtime_path, 'wlvc_sfu');
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
    trackId: 'camera-a',
    timestamp: 1000,
  });
  assert.deepEqual(Array.from(new Uint8Array(decrypted)), Array.from(plaintext), 'receiver must decrypt protected WLVC frame');

  await assert.rejects(
    () => bob.decryptFrame({
      data: protectedFrame.data,
      protected: protectedFrame.protected,
      publisherUserId: 101,
      runtimePath: 'wlvc_sfu',
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
  assert.equal(
    await alice.buildSenderKeySignal(202),
    null,
    'sender-key must wait for a fresh hello when the participant set changes',
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
      trackId: 'camera-a',
      timestamp: 1000,
    }),
    /wrong_key_id/,
    'receiver must reject material after participant removal',
  );

  const workspaceSource = read('../../src/domain/realtime/CallWorkspaceView.vue');
  assert.match(workspaceSource, /MEDIA_SECURITY_SIGNAL_TYPES/, 'workspace must handle media-security signaling');
  assert.match(workspaceSource, /function scheduleMediaSecurityParticipantSync\(reason = 'unspecified', forceRekey = false\)/, 'workspace must expose a scheduled media-security participant sync helper');
  assert.match(workspaceSource, /scheduleMediaSecurityParticipantSync\('context_changed'\);/, 'workspace must resync media security after session context resets');
  assert.match(workspaceSource, /watch\(\s*\(\) => \[\s*String\(activeSocketCallId\.value \|\| activeCallId\.value \|\| ''\),\s*activeRoomId\.value,\s*String\(currentUserId\.value \|\| 0\),[\s\S]*scheduleMediaSecurityParticipantSync\('context_watch'\);/m, 'workspace must resync media security when call or room context changes');
  assert.match(workspaceSource, /const marked = session\.markParticipantSet\(\[[\s\S]*normalizedSenderUserId,[\s\S]*\]\);[\s\S]*if \(mediaSecurityTargetIds\(\)\.includes\(normalizedSenderUserId\)\) \{[\s\S]*scheduleMediaSecurityParticipantSync\('hello_accepted'\);[\s\S]*\}/m, 'workspace must pin the hello sender into the participant set and only schedule a non-forced follow-up sync once the sender is part of the current target set');
  assert.match(workspaceSource, /const accepted = await session\.handleSenderKeySignal\(normalizedSenderUserId, payloadBody \|\| \{\}\);[\s\S]*if \(!accepted && mediaSecurityTargetIds\(\)\.includes\(normalizedSenderUserId\)\) \{[\s\S]*scheduleMediaSecurityParticipantSync\('sender_key_pending'\);[\s\S]*\}/m, 'workspace must defer sender-key recovery sync until the sender is present in the current participant target set');
  assert.match(workspaceSource, /protectFrame\(\{[\s\S]*runtimePath: 'wlvc_sfu'[\s\S]*outgoingFrame\.protectedFrame = protectedFrame\.protectedFrame;[\s\S]*sendEncodedFrame\(outgoingFrame\);/, 'workspace must protect WLVC frames before SFU send');
  assert.match(workspaceSource, /decryptProtectedFrameEnvelope\(\{[\s\S]*runtimePath: 'wlvc_sfu'/, 'workspace must decrypt WLVC transport envelopes before decode');
  assert.match(workspaceSource, /shouldRecoverMediaSecurityFromFrameError\(error\)[\s\S]*recoverMediaSecurityForPublisher\(publisherUserId\);/, 'workspace must recover the media-security handshake when protected SFU frames arrive before keys');
  assert.match(workspaceSource, /await sendMediaSecurityHello\(normalizedUserId, true\);[\s\S]*await sendMediaSecuritySenderKey\(normalizedUserId, true\);/, 'workspace recovery must retry hello and sender-key signals for the remote publisher');
  assert.match(workspaceSource, /attachNativeSenderTransform/, 'workspace must attach native sender transform hooks');
  assert.match(workspaceSource, /attachNativeReceiverTransform/, 'workspace must attach native receiver transform hooks');

  const sfuClientSource = read('../../src/lib/sfu/sfuClient.ts');
  assert.match(sfuClientSource, /protectedFrame\?: string \| null/, 'SFU frame type must carry protected transport envelope');
  assert.match(sfuClientSource, /payload\.protected_frame = frame\.protectedFrame/, 'SFU sender must relay protected transport envelope');
  assert.match(sfuClientSource, /protectedFrame: protectedFrame \|\| null/, 'SFU receiver must surface protected transport envelope');
  assert.doesNotMatch(sfuClientSource, /payload\.protected = frame\.protected/, 'SFU sender must not use ad-hoc protected metadata JSON for protected frames');

  process.stdout.write('[media-security-frontend-contract] PASS\n');
} catch (error) {
  if (error instanceof Error) {
    fail(error.message);
  }
  fail('unknown failure');
}
