import assert from 'node:assert/strict';

import {
  createMediaSecuritySession,
} from '../../src/domain/realtime/mediaSecurity.js';
import {
  nativeAudioPlaybackBlocked,
  nativeAudioPlaybackInterrupted,
  nativeSdpAudioSummary,
  nativeSdpAudioSummaries,
  nativeSdpHasSendableAudio,
} from '../../src/domain/realtime/nativeAudioBridgeHelpers.js';

try {
  const sendrecv = [
    'v=0',
    'm=audio 9 UDP/TLS/RTP/SAVPF 111',
    'a=sendrecv',
    'a=msid:stream audio-track',
    '',
  ].join('\r\n');
  assert.equal(nativeSdpHasSendableAudio(sendrecv), true);
  assert.equal(nativeSdpAudioSummary(sendrecv).direction, 'sendrecv');
  assert.equal(nativeSdpHasSendableAudio(sendrecv.replace('a=sendrecv', 'a=inactive')), false);
  assert.equal(nativeSdpHasSendableAudio(sendrecv.replace('a=msid:stream audio-track\r\n', '')), false);
  const multiAudio = [
    'v=0',
    'm=audio 9 UDP/TLS/RTP/SAVPF 111',
    'a=recvonly',
    'm=audio 9 UDP/TLS/RTP/SAVPF 111',
    'a=sendonly',
    'a=msid:stream second-audio-track',
    '',
  ].join('\r\n');
  assert.equal(nativeSdpAudioSummaries(multiAudio).length, 2);
  assert.equal(nativeSdpHasSendableAudio(multiAudio), true);

  assert.equal(nativeAudioPlaybackBlocked(new DOMException('play() failed because the user did not interact', 'NotAllowedError')), true);
  assert.equal(nativeAudioPlaybackInterrupted(new DOMException('interrupted by a new load request', 'AbortError')), true);

  const events = [];
  const receiverEvents = [];
  const session = createMediaSecuritySession({
    callId: 'call-audio-unit',
    roomId: 'room-audio-unit',
    userId: 1,
    onNativeFrameError: (event) => events.push(event),
    onNativeReceiverFrameError: (event) => receiverEvents.push(event),
  });
  const error = new Error('wrong_key_id');
  session.reportNativeFrameTransformError('receiver', error, {
    senderUserId: 2,
    trackId: 'audio-track',
  });
  assert.equal(events.length, 1, 'generic native frame error callback should run');
  assert.equal(receiverEvents.length, 1, 'receiver native frame error callback should run');
  assert.equal(events[0].direction, 'receiver');
  assert.equal(events[0].senderUserId, 2);
  assert.equal(events[0].trackId, 'audio-track');
  assert.equal(events[0].error, error);

  const alice = createMediaSecuritySession({ callId: 'call-audio-unit', roomId: 'room-audio-unit', userId: 1 });
  const bob = createMediaSecuritySession({ callId: 'call-audio-unit', roomId: 'room-audio-unit', userId: 2 });
  const aliceHello = await alice.buildHelloSignal(2, 'webrtc_native');
  const bobHello = await bob.buildHelloSignal(1, 'webrtc_native');
  assert.equal(await bob.handleHelloSignal(1, aliceHello.payload), true);
  assert.equal(await alice.handleHelloSignal(2, bobHello.payload), true);
  const aliceSenderKey = await alice.buildSenderKeySignal(2);
  const bobSenderKey = await bob.buildSenderKeySignal(1);
  assert.equal(await bob.handleSenderKeySignal(1, aliceSenderKey.payload), true);
  assert.equal(await alice.handleSenderKeySignal(2, bobSenderKey.payload), true);

  const nativePlaintext = new Uint8Array([1, 2, 3, 4]).buffer;
  const nativeEncrypted = await alice.protectNativeEncodedFrame(
    { data: nativePlaintext, timestamp: 123, type: 'audio' },
    { trackKind: 'audio', trackId: 'sender-local-track-id' },
  );
  const nativeDecrypted = await bob.decryptNativeEncodedFrame(
    { data: nativeEncrypted, timestamp: 123, type: 'audio' },
    1,
    { trackId: 'receiver-remote-track-id' },
  );
  assert.deepEqual(Array.from(new Uint8Array(nativeDecrypted)), [1, 2, 3, 4]);

  const audioFrame = await alice.protectFrame({
    data: new Uint8Array([5]).buffer,
    runtimePath: 'webrtc_native',
    trackKind: 'audio',
    frameKind: 'audio',
    trackId: 'native_audio',
    timestamp: 124,
  });
  const videoFrame = await alice.protectFrame({
    data: new Uint8Array([6]).buffer,
    runtimePath: 'wlvc_sfu',
    trackKind: 'video',
    frameKind: 'delta',
    trackId: 'wlvc-video-track',
    timestamp: 125,
  });
  assert.deepEqual(Array.from(new Uint8Array(await bob.decryptProtectedFrameEnvelope({
    envelope: videoFrame.envelope,
    publisherUserId: 1,
    runtimePath: 'wlvc_sfu',
    trackId: 'wlvc-video-track',
    timestamp: 125,
  }))), [6]);
  assert.deepEqual(Array.from(new Uint8Array(await bob.decryptProtectedFrameEnvelope({
    envelope: audioFrame.envelope,
    publisherUserId: 1,
    runtimePath: 'webrtc_native',
    trackId: 'native_audio',
    timestamp: 124,
  }))), [5]);

  process.stdout.write('[native-audio-bridge-unit] PASS\n');
} catch (error) {
  const message = error instanceof Error ? error.message : 'unknown failure';
  throw new Error(`[native-audio-bridge-unit] FAIL: ${message}`);
}
