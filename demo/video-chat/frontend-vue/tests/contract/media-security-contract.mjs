import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

import {
  createMediaSecuritySession,
  MEDIA_SECURITY_SIGNAL_TYPES,
  mediaSecurityInternalsForTests,
} from '../../src/domain/realtime/mediaSecurity.js';

function fail(message) {
  throw new Error(`[media-security-frontend-contract] FAIL: ${message}`);
}

function read(relPath) {
  const __filename = fileURLToPath(import.meta.url);
  const __dirname = path.dirname(__filename);
  return fs.readFileSync(path.resolve(__dirname, relPath), 'utf8');
}

try {
  assert.deepEqual(MEDIA_SECURITY_SIGNAL_TYPES, ['media-security/hello', 'media-security/sender-key']);
  assert.equal(mediaSecurityInternalsForTests.KEX_SUITE, 'x25519_hkdf_sha256_v1');
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

  await bob.handleHelloSignal(101, aliceHello.payload);
  await alice.handleHelloSignal(202, bobHello.payload);

  const aliceSenderKey = await alice.buildSenderKeySignal(202);
  const bobSenderKey = await bob.buildSenderKeySignal(101);
  assert.equal(aliceSenderKey.type, 'media-security/sender-key');
  assert.ok(!('raw_media_key' in aliceSenderKey.payload), 'sender-key signal must not expose raw media key fields');
  assert.ok(String(aliceSenderKey.payload.encrypted_key || '').length > 20, 'sender key must be wrapped');
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
  assert.match(workspaceSource, /protectFrame\(\{[\s\S]*runtimePath: 'wlvc_sfu'[\s\S]*sendEncodedFrame\(\{[\s\S]*protectedFrame: protectedFrame\.protectedFrame/, 'workspace must protect WLVC frames before SFU send');
  assert.match(workspaceSource, /decryptProtectedFrameEnvelope\(\{[\s\S]*runtimePath: 'wlvc_sfu'/, 'workspace must decrypt WLVC transport envelopes before decode');
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
