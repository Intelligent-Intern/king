import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

import {
  nativeAudioPlaybackBlocked,
  nativeAudioPlaybackInterrupted,
  nativeSdpAudioSummary,
  nativeSdpAudioSummaries,
  nativeSdpHasSendableAudio,
} from '../../src/domain/realtime/nativeAudioBridgeHelpers.js';

function fail(message) {
  throw new Error(`[native-audio-bridge-contract] FAIL: ${message}`);
}

function readFrontend(relativePath) {
  const __filename = fileURLToPath(import.meta.url);
  const __dirname = path.dirname(__filename);
  return fs.readFileSync(path.resolve(__dirname, '../..', relativePath), 'utf8');
}

function requireContains(source, needle, label) {
  assert.ok(source.includes(needle), `${label} missing: ${needle}`);
}

try {
  const sendableAudioSdp = [
    'v=0',
    'o=- 0 0 IN IP4 127.0.0.1',
    's=-',
    't=0 0',
    'm=audio 9 UDP/TLS/RTP/SAVPF 111',
    'a=sendrecv',
    'a=msid:king-stream king-audio-track',
    '',
  ].join('\r\n');
  assert.deepEqual(nativeSdpAudioSummary(sendableAudioSdp), {
    has_audio: true,
    rejected: false,
    direction: 'sendrecv',
    has_msid: true,
  });
  assert.equal(nativeSdpHasSendableAudio(sendableAudioSdp), true, 'sendrecv audio with msid is sendable');
  const multiAudioSdp = [
    'v=0',
    'o=- 0 0 IN IP4 127.0.0.1',
    's=-',
    't=0 0',
    'm=audio 9 UDP/TLS/RTP/SAVPF 111',
    'a=recvonly',
    'm=audio 9 UDP/TLS/RTP/SAVPF 111',
    'a=sendonly',
    'a=msid:king-stream king-audio-track-2',
    '',
  ].join('\r\n');
  assert.equal(nativeSdpAudioSummaries(multiAudioSdp).length, 2, 'all audio m-sections are summarized');
  assert.equal(nativeSdpHasSendableAudio(multiAudioSdp), true, 'any sendable audio m-section is accepted');

  assert.equal(
    nativeSdpHasSendableAudio(sendableAudioSdp.replace('a=sendrecv', 'a=recvonly')),
    false,
    'recvonly audio is not sendable',
  );
  assert.equal(
    nativeSdpHasSendableAudio(sendableAudioSdp.replace('m=audio 9', 'm=audio 0')),
    false,
    'rejected audio m-section is not sendable',
  );
  assert.equal(
    nativeSdpHasSendableAudio(sendableAudioSdp.replace('a=msid:king-stream king-audio-track\r\n', '')),
    false,
    'audio without msid is not sendable',
  );

  assert.equal(nativeAudioPlaybackBlocked(new DOMException('user gesture required', 'NotAllowedError')), true);
  assert.equal(nativeAudioPlaybackInterrupted(new DOMException('play() request was interrupted by a new load request', 'AbortError')), true);

  const mediaSecurity = readFrontend('src/domain/realtime/mediaSecurity.js');
  requireContains(mediaSecurity, 'this.nativeFrameErrorHandler', 'media security session stores native frame error callback');
  requireContains(mediaSecurity, "function nativeEncodedFrameAadTrackId(trackKind = 'data')", 'native frame AAD uses stable track ids');
  requireContains(mediaSecurity, 'asString(header.runtime_path)', 'native replay counters are scoped by runtime path');
  requireContains(mediaSecurity, 'asString(header.track_kind)', 'native replay counters are scoped by track kind');
  requireContains(mediaSecurity, 'reportNativeFrameTransformError(direction, error, details = {})', 'native frame transform error reporter exists');
  requireContains(mediaSecurity, "this.reportNativeFrameTransformError('sender'", 'native sender transform reports frame errors');
  requireContains(mediaSecurity, "this.reportNativeFrameTransformError('receiver'", 'native receiver transform reports frame errors');
  requireContains(mediaSecurity, 'handler({', 'native frame transform error callbacks receive structured diagnostics');

  const workspace = readFrontend('src/domain/realtime/CallWorkspaceView.vue');
  requireContains(workspace, 'onNativeFrameError: handleNativeMediaSecurityFrameError', 'workspace wires native frame error callback');
  requireContains(workspace, 'NATIVE_FRAME_ERROR_LOG_COOLDOWN_MS', 'native frame transform errors are console-throttled');
  requireContains(workspace, 'function ensureNativeAudioBridgeSecurityReady', 'native bridge gates negotiation on active E2EE');
  requireContains(workspace, 'function handleNativeMediaSecurityFrameError(event = {})', 'workspace handles native frame errors');
  requireContains(workspace, 'function shouldTreatNativeFrameErrorAsTransient', 'workspace separates transient native frame drops from hard E2EE failures');
  requireContains(workspace, "code = direction === 'receiver'", 'workspace separates decrypt and encrypt diagnostics');
  requireContains(workspace, "'[KingRT] SFU/native E2EE frame transform failed'", 'native frame errors are visible in devtools');
  requireContains(workspace, 'recoverMediaSecurityForPublisher(senderUserId);', 'wrong-key native frame errors trigger E2EE recovery');
  requireContains(workspace, "resyncNativeAudioBridgePeerAfterSecurityReady(senderUserId, 'native_e2ee_frame_error')", 'native frame recovery resyncs audio bridge');
  requireContains(workspace, "peerState === 'capability_ready'", 'handshake retry covers capability_ready sender-key deadlocks');
  requireContains(workspace, 'mediaSecurityHelloSentAtByUserId.set(normalizedTargetId, Date.now());', 'missing sender-key schedules handshake watchdog');
  requireContains(workspace, "resyncNativeAudioBridgePeerAfterSecurityReady(normalizedSenderUserId, 'sender_key_accepted')", 'accepted sender-key resyncs native audio tracks');
  requireContains(workspace, "resyncNativeAudioBridgePeerAfterSecurityReady(targetUserId, 'native_bridge_availability_changed')", 'audio bridge availability watcher resyncs peers');
  requireContains(workspace, "resyncNativeAudioBridgePeerAfterSecurityReady(\n        normalizedUserId,\n        'native_audio_track_recovery_rejoin',\n        true", 'audio-track recovery may force a renegotiation offer');
  requireContains(workspace, "await peer.pc.setLocalDescription({ type: 'rollback' });", 'forced recovery offers handle native offer glare');
  requireContains(workspace, 'SFU_WLVC_BACKPRESSURE_HARD_RESET_AFTER_MS', 'sustained critical SFU websocket backpressure has a bounded hard-reset threshold');
  requireContains(workspace, "restartSfuAfterVideoStall('sfu_send_buffer_stuck'", 'only stuck critical SFU websocket buffers reconnect the SFU socket');
  requireContains(workspace, 'wlvcBackpressurePauseUntilMs', 'SFU websocket backpressure throttles the WLVC encoder instead of reconnecting immediately');
  requireContains(workspace, 'function nativePeerHasLocalLiveAudioSender', 'native bridge validates local audio sender before answering');
  requireContains(workspace, 'function shouldSyncNativeLocalTracksBeforeOffer', 'native bridge avoids pre-creating non-initiator audio transceivers before remote offers');
  requireContains(workspace, 'native_audio_sender_replace_track_failed', 'native bridge reports replaceTrack failures');
  requireContains(workspace, "ensureNativeAudioBridgeSecurityReady(peer, 'native_offer')", 'native offers wait for active E2EE before SDP');
  requireContains(workspace, "ensureNativeAudioBridgeSecurityReady(peer, 'native_offer_received')", 'native answers wait for active E2EE before SDP');
  requireContains(workspace, 'sdp_audio_summaries: nativeSdpAudioSummaries', 'native SDP diagnostics include every audio m-section');

  process.stdout.write('[native-audio-bridge-contract] PASS\n');
} catch (error) {
  if (error instanceof Error) {
    fail(error.message);
  }
  fail('unknown failure');
}
