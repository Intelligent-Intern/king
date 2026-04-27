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
} from '../../src/domain/realtime/native/audioBridgeHelpers.js';
import {
  canTransitionNativeAudioBridgeState,
  createNativeAudioBridgeStateHelpers,
  NATIVE_AUDIO_BRIDGE_STATES,
} from '../../src/domain/realtime/native/audioBridgeState.js';

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
  assert.equal(canTransitionNativeAudioBridgeState('new', 'waiting_security'), true, 'audio bridge state machine allows security wait after peer bootstrap');
  assert.equal(canTransitionNativeAudioBridgeState('waiting_security', 'playing'), false, 'audio bridge state machine rejects skipping track/playback readiness');
  assert.equal(canTransitionNativeAudioBridgeState('track_received', 'playing'), true, 'audio bridge state machine allows playback after remote track arrival');
  assert.equal(canTransitionNativeAudioBridgeState('playing', 'stalled_no_track'), false, 'audio bridge state machine rejects direct regressions to missing-track failure');
  const audioBridgeStatusVersion = { value: 0 };
  const audioBridgeStateHelpers = createNativeAudioBridgeStateHelpers(audioBridgeStatusVersion);
  const peer = { audioBridgeState: '', audioBridgeErrorMessage: '', audioTrackDeadlineTimer: null };
  assert.equal(audioBridgeStateHelpers.setNativePeerAudioBridgeState(peer, NATIVE_AUDIO_BRIDGE_STATES.WAITING_SECURITY, ''), true, 'audio bridge state helper accepts initial security wait');
  assert.equal(audioBridgeStateHelpers.setNativePeerAudioBridgeState(peer, NATIVE_AUDIO_BRIDGE_STATES.PLAYING, ''), false, 'audio bridge state helper blocks illegal jumps');
  assert.equal(audioBridgeStateHelpers.setNativePeerAudioBridgeState(peer, NATIVE_AUDIO_BRIDGE_STATES.TRACK_RECEIVED, ''), true, 'audio bridge state helper allows track arrival after security wait');
  assert.equal(audioBridgeStateHelpers.setNativePeerAudioBridgeState(peer, NATIVE_AUDIO_BRIDGE_STATES.PLAYING, ''), true, 'audio bridge state helper allows playback after track arrival');
  assert.equal(audioBridgeStatusVersion.value > 0, true, 'audio bridge state helper bumps reactive status version when state changes');

  const mediaSecurity = readFrontend('src/domain/realtime/media/security.js');
  requireContains(mediaSecurity, 'this.nativeFrameErrorHandler', 'media security session stores native frame error callback');
  requireContains(mediaSecurity, "function nativeEncodedFrameAadTrackId(trackKind = 'data')", 'native frame AAD uses stable track ids');
  requireContains(mediaSecurity, 'asString(header.runtime_path)', 'native replay counters are scoped by runtime path');
  requireContains(mediaSecurity, 'asString(header.track_kind)', 'native replay counters are scoped by track kind');
  requireContains(mediaSecurity, 'reportNativeFrameTransformError(direction, error, details = {})', 'native frame transform error reporter exists');
  requireContains(mediaSecurity, "this.reportNativeFrameTransformError('sender'", 'native sender transform reports frame errors');
  requireContains(mediaSecurity, "this.reportNativeFrameTransformError('receiver'", 'native receiver transform reports frame errors');
  requireContains(mediaSecurity, 'handler({', 'native frame transform error callbacks receive structured diagnostics');

  const workspace = readFrontend('src/domain/realtime/CallWorkspaceView.vue');
  const mediaSecurityRuntime = readFrontend('src/domain/realtime/workspace/callWorkspace/mediaSecurityRuntime.js');
  const bridgeRuntime = readFrontend('src/domain/realtime/native/bridgeRuntime.js');
  const peerFactory = readFrontend('src/domain/realtime/native/peerFactory.js');
  const peerLifecycle = readFrontend('src/domain/realtime/native/peerLifecycle.js');
  const signaling = readFrontend('src/domain/realtime/native/signaling.js');
  const audioBridgeRecovery = readFrontend('src/domain/realtime/native/audioBridgeRecovery.js');
  const audioBridgeState = readFrontend('src/domain/realtime/native/audioBridgeState.js');
  const sfuTransport = readFrontend('src/domain/realtime/workspace/callWorkspace/sfuTransport.js');
  const runtimeConfig = readFrontend('src/domain/realtime/workspace/callWorkspace/runtimeConfig.js');
  requireContains(mediaSecurityRuntime, 'onNativeFrameError: handleNativeMediaSecurityFrameError', 'workspace wires native frame error callback');
  requireContains(runtimeConfig, 'NATIVE_FRAME_ERROR_LOG_COOLDOWN_MS', 'native frame transform errors are console-throttled');
  requireContains(mediaSecurityRuntime, "async function ensureNativeAudioBridgeSecurityReady(peer, reason = 'native_audio_negotiation')", 'native bridge gates negotiation on active media security');
  requireContains(mediaSecurityRuntime, 'function handleNativeMediaSecurityFrameError(event = {})', 'native bridge handles native frame errors');
  requireContains(mediaSecurityRuntime, 'function shouldTreatNativeFrameErrorAsTransient', 'native bridge separates transient native frame drops from hard media-security failures');
  requireContains(mediaSecurityRuntime, "code = direction === 'receiver'", 'native bridge separates decrypt and encrypt diagnostics');
  requireContains(mediaSecurityRuntime, "'[KingRT] SFU/native media-security frame transform failed'", 'native frame errors are visible in devtools');
  requireContains(mediaSecurityRuntime, 'recoverMediaSecurityForPublisher(senderUserId);', 'wrong-key native frame errors trigger media-security recovery');
  requireContains(mediaSecurityRuntime, "resyncNativeAudioBridgePeerAfterSecurityReady(senderUserId, 'native_media_frame_error')", 'native frame recovery resyncs audio bridge');
  requireContains(mediaSecurityRuntime, "scheduleNativeAudioTrackRecovery(peer, 'native_media_security_malformed_frame'", 'malformed native protected frames rebuild the audio bridge instead of staying stalled');
  requireContains(mediaSecurityRuntime, 'requireMissingTrack: false', 'malformed native frame recovery does not skip rebuild just because a muted remote track object exists');
  requireContains(mediaSecurityRuntime, 'function checkMediaSecurityHandshakeTimeouts()', 'handshake watchdog exists for native audio bridge deadlocks');
  requireContains(mediaSecurityRuntime, "eventType: 'media_security_handshake_timeout'", 'handshake watchdog emits diagnostics for native audio bridge deadlocks');
  requireContains(mediaSecurityRuntime, 'await sendMediaSecurityHello(normalizedTargetId, true);', 'handshake watchdog force-retries hello for native audio bridge deadlocks');
  requireContains(mediaSecurityRuntime, 'await sendMediaSecuritySenderKey(normalizedTargetId, true);', 'handshake watchdog force-retries sender-key for native audio bridge deadlocks');
  requireContains(mediaSecurityRuntime, 'mediaSecurityHelloSentAtByUserId.set(normalizedTargetId, Date.now());', 'missing sender-key schedules handshake watchdog');
  requireContains(mediaSecurityRuntime, "resyncNativeAudioBridgePeerAfterSecurityReady(normalizedSenderUserId, 'sender_key_accepted')", 'accepted sender-key resyncs native audio tracks');
  requireContains(mediaSecurityRuntime, "function shouldReleaseNativeAudioBridgeQuarantineForReason(reason = 'security_ready')", 'native audio quarantine release rules are centralized');
  requireContains(mediaSecurityRuntime, "normalizedReason === 'sender_key_accepted'", 'native audio quarantine can be released after sender-key acceptance');
  requireContains(mediaSecurityRuntime, "normalizedReason === 'native_audio_track_recovery_rejoin'", 'native audio quarantine can be released during explicit recovery rejoin');
  requireContains(mediaSecurityRuntime, "nativeAudioBridgeIsQuarantined(normalizedUserId)\n      && !shouldReleaseNativeAudioBridgeQuarantineForReason(reason)", 'native audio resync blocks immediate reattach while a peer is still quarantined');
  requireContains(mediaSecurityRuntime, 'clearNativeAudioBridgeQuarantine(normalizedUserId);', 'native audio resync clears quarantine only on allowed deterministic recovery reasons');
  requireContains(readFrontend('src/domain/realtime/workspace/callWorkspace/orchestration.js'), "resyncNativeAudioBridgePeerAfterSecurityReady(targetUserId, 'native_bridge_availability_changed')", 'audio bridge availability watcher resyncs peers');
  requireContains(audioBridgeRecovery, "resyncNativeAudioBridgePeerAfterSecurityReady(\n          normalizedUserId,\n          'native_audio_track_recovery_rejoin',\n          true", 'audio-track recovery may force a renegotiation offer');
  requireContains(signaling, "await peer.pc.setLocalDescription({ type: 'rollback' });", 'forced recovery offers handle native offer glare');
  requireContains(sfuTransport, 'socketLooksStuck', 'sustained critical SFU websocket backpressure has a bounded stuck-socket check');
  requireContains(sfuTransport, "restartSfuAfterVideoStall('sfu_send_buffer_stuck'", 'only stuck critical SFU websocket buffers reconnect the SFU socket');
  requireContains(sfuTransport, 'wlvcBackpressurePauseUntilMs', 'SFU websocket backpressure throttles the WLVC encoder instead of reconnecting immediately');
  requireContains(signaling, '!nativePeerHasLocalLiveAudioSender(peer)', 'native bridge validates local audio sender before answering');
  requireContains(peerLifecycle, 'function shouldSyncNativeLocalTracksBeforeOffer', 'native bridge avoids pre-creating non-initiator audio transceivers before remote offers');
  requireContains(bridgeRuntime, 'native_audio_sender_replace_track_failed', 'native bridge reports replaceTrack failures');
  requireContains(bridgeRuntime, "ensureNativeAudioBridgeSecurityReady(peer, 'native_offer')", 'native offers wait for active media security before SDP');
  requireContains(signaling, "ensureNativeAudioBridgeSecurityReady(peer, 'native_offer_received')", 'native answers wait for active media security before SDP');
  requireContains(signaling, 'sdp_audio_summaries: nativeSdpAudioSummaries', 'native SDP diagnostics include every audio m-section');
  requireContains(audioBridgeState, 'NATIVE_AUDIO_BRIDGE_ALLOWED_TRANSITIONS', 'audio bridge state machine defines explicit allowed transitions');
  requireContains(audioBridgeState, 'canTransitionNativeAudioBridgeState', 'audio bridge state machine exposes transition validation');
  requireContains(audioBridgeState, 'NATIVE_AUDIO_BRIDGE_STATES', 'audio bridge state machine centralizes state ids');

  process.stdout.write('[native-audio-bridge-contract] PASS\n');
} catch (error) {
  if (error instanceof Error) {
    fail(error.message);
  }
  fail('unknown failure');
}
