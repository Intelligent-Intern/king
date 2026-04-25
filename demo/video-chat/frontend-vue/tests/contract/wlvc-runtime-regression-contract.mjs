import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

import {
  WLVC_HEADER_BYTES,
  WLVC_MAGIC_U32_BE,
  wlvcDecodeFrame,
  wlvcEncodeFrame,
  wlvcFrameToHex,
} from '../../src/support/wlvcFrame.js';

function fail(message) {
  throw new Error(`[wlvc-runtime-regression-contract] FAIL: ${message}`);
}

function requireContains(source, needle, label) {
  assert.ok(source.includes(needle), `${label} missing: ${needle}`);
}

function requireNotContains(source, needle, label) {
  assert.ok(!source.includes(needle), `${label} must not contain: ${needle}`);
}

function requireMatch(source, pattern, label) {
  assert.ok(pattern.test(source), `${label} missing pattern: ${pattern}`);
}

function extractFunction(source, name) {
  const match = new RegExp(`(?:async\\s+)?function\\s+${name}\\s*\\(`).exec(source);
  assert.ok(match, `function ${name} missing`);
  const signatureEnd = source.indexOf(') {', match.index);
  assert.notEqual(signatureEnd, -1, `function ${name} signature is not closed`);
  const braceStart = signatureEnd + 2;
  assert.notEqual(braceStart, -1, `function ${name} has no body`);

  let depth = 0;
  for (let index = braceStart; index < source.length; index += 1) {
    const char = source[index];
    if (char === '{') depth += 1;
    if (char === '}') {
      depth -= 1;
      if (depth === 0) return source.slice(match.index, index + 1);
    }
  }
  throw new Error(`function ${name} body is not closed`);
}

function assertDecodeFailure(label, decode, expectedCode) {
  let result = null;
  assert.doesNotThrow(() => {
    result = decode();
  }, `${label} must not throw`);
  assert.equal(result?.ok, false, `${label} must fail closed`);
  assert.equal(result?.error_code, expectedCode, `${label} error code`);
}

function buildHeaderOnlyFrame({ yLength = 0, uLength = 0, vLength = 0, quality = 70 } = {}) {
  const bytes = new Uint8Array(WLVC_HEADER_BYTES);
  const view = new DataView(bytes.buffer);
  view.setUint32(0, WLVC_MAGIC_U32_BE, false);
  view.setUint8(4, 1);
  view.setUint8(5, 0);
  view.setUint8(6, quality);
  view.setUint8(7, 3);
  view.setUint16(8, 64, false);
  view.setUint16(10, 48, false);
  view.setUint32(12, yLength, false);
  view.setUint32(16, uLength, false);
  view.setUint32(20, vLength, false);
  view.setUint16(24, 32, false);
  view.setUint16(26, 24, false);
  return bytes;
}

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);
const frontendRoot = path.resolve(__dirname, '../..');
const repoRoot = path.resolve(frontendRoot, '../../..');

function readFromFrontend(relativePath) {
  return fs.readFileSync(path.resolve(frontendRoot, relativePath), 'utf8');
}

function readFromRepo(relativePath) {
  return fs.readFileSync(path.resolve(repoRoot, relativePath), 'utf8');
}

try {
  const parityFrame = {
    version: 1,
    frame_type: 1,
    quality: 82,
    dwt_levels: 3,
    width: 17,
    height: 9,
    uv_width: 9,
    uv_height: 5,
    y_data: Uint8Array.from([0, 1, 2, 3, 4, 5, 250, 251, 252]),
    u_data: Uint8Array.from([17, 34, 51, 68]),
    v_data: Uint8Array.from([85, 102, 119, 136, 153]),
  };
  const encoded = wlvcEncodeFrame(parityFrame);
  assert.equal(encoded.ok, true, `encode parity failed: ${encoded.error_code ?? 'unknown'}`);
  const decoded = wlvcDecodeFrame(encoded.bytes);
  assert.equal(decoded.ok, true, `decode parity failed: ${decoded.error_code ?? 'unknown'}`);
  assert.equal(decoded.frame.width, parityFrame.width);
  assert.equal(decoded.frame.height, parityFrame.height);
  assert.equal(decoded.frame.uv_width, parityFrame.uv_width);
  assert.equal(decoded.frame.uv_height, parityFrame.uv_height);
  assert.equal(wlvcFrameToHex(decoded.frame.y_data), wlvcFrameToHex(parityFrame.y_data));
  assert.equal(wlvcFrameToHex(decoded.frame.u_data), wlvcFrameToHex(parityFrame.u_data));
  assert.equal(wlvcFrameToHex(decoded.frame.v_data), wlvcFrameToHex(parityFrame.v_data));
  const reencoded = wlvcEncodeFrame({
    version: decoded.frame.version,
    frame_type: decoded.frame.frame_type,
    quality: decoded.frame.quality,
    dwt_levels: decoded.frame.dwt_levels,
    width: decoded.frame.width,
    height: decoded.frame.height,
    uv_width: decoded.frame.uv_width,
    uv_height: decoded.frame.uv_height,
    y_data: decoded.frame.y_data,
    u_data: decoded.frame.u_data,
    v_data: decoded.frame.v_data,
  });
  assert.equal(reencoded.ok, true, `re-encode parity failed: ${reencoded.error_code ?? 'unknown'}`);
  assert.equal(wlvcFrameToHex(reencoded.bytes), wlvcFrameToHex(encoded.bytes), 're-encode bytes must stay stable');

  assertDecodeFailure('null input', () => wlvcDecodeFrame(null), 'wlvc_payload_type_invalid');
  assertDecodeFailure('odd hex input', () => wlvcDecodeFrame('abc'), 'wlvc_hex_invalid');
  assertDecodeFailure('short header', () => wlvcDecodeFrame(new Uint8Array(WLVC_HEADER_BYTES - 1)), 'frame_too_short');
  assertDecodeFailure('payload mismatch', () => wlvcDecodeFrame(encoded.bytes.slice(0, encoded.bytes.length - 1)), 'payload_length_mismatch');
  assertDecodeFailure('oversized channel', () => wlvcDecodeFrame(buildHeaderOnlyFrame({ yLength: 16 * 1024 * 1024 + 1 })), 'channel_too_large');
  assertDecodeFailure('invalid quality', () => wlvcDecodeFrame(buildHeaderOnlyFrame({ quality: 0 })), 'quality_invalid');

  const capabilities = readFromFrontend('src/domain/realtime/mediaRuntimeCapabilities.js');
  requireContains(capabilities, "preferredPath = 'wlvc_wasm'", 'WLVC preferred runtime');
  requireContains(capabilities, "preferredPath = 'webrtc_native'", 'native fallback runtime');
  requireContains(capabilities, "preferredPath = 'unsupported'", 'unsupported fail-closed runtime');
  requireContains(capabilities, 'const stageA = Boolean(wlvcWasm.encoder)', 'WLVC stage A gate');
  requireContains(capabilities, 'const stageB = Boolean(webRtcNative)', 'native stage B gate');

  const callMediaPreferences = readFromFrontend('src/domain/realtime/callMediaPreferences.js');
  requireContains(callMediaPreferences, 'outgoing_video_quality_profile', 'outgoing video quality profile persists in call media preferences');
  requireContains(callMediaPreferences, 'outgoingVideoQualityProfile', 'call media preferences expose outgoing video quality profile');
  requireContains(callMediaPreferences, 'export function setCallOutgoingVideoQualityProfile(profile)', 'call media preferences export outgoing video quality setter');

  const workspaceConfig = readFromFrontend('src/domain/realtime/callWorkspaceConfig.js');
  requireContains(workspaceConfig, 'export const SFU_VIDEO_QUALITY_PROFILES', 'WLVC video quality profiles exist');
  requireContains(workspaceConfig, 'export const SFU_VIDEO_QUALITY_PROFILE_OPTIONS', 'WLVC video quality profile options exist');
  requireContains(workspaceConfig, 'export function resolveSfuVideoQualityProfile(value)', 'WLVC video quality profile resolver exists');

  const workspaceShell = readFromFrontend('src/layouts/WorkspaceShell.vue');
  requireContains(workspaceShell, 'id="call-left-video-quality"', 'workspace sidebar exposes outgoing video quality select');
  requireContains(workspaceShell, 'setCallOutgoingVideoQualityProfile', 'workspace sidebar can update outgoing video quality');
  requireContains(workspaceShell, 'callVideoQualityOptions', 'workspace sidebar renders outgoing video quality options');

  const telemetry = readFromFrontend('src/domain/realtime/mediaRuntimeTelemetry.js');
  requireContains(telemetry, "type: 'media_runtime_transition'", 'runtime transition telemetry');
  requireContains(telemetry, 'preferred_path: normalizePath(event?.capabilities?.preferred_path)', 'runtime transition capability telemetry');

  const workspace = readFromFrontend('src/domain/realtime/CallWorkspaceView.vue');
  const setRuntime = extractFunction(workspace, 'setMediaRuntimePath');
  requireContains(setRuntime, 'appendMediaRuntimeTransitionEvent({', 'runtime switch telemetry append');
  requireContains(setRuntime, 'from_path: previousPath', 'runtime switch previous path telemetry');
  requireContains(setRuntime, 'to_path: normalizedPath', 'runtime switch next path telemetry');

  const switchRuntime = extractFunction(workspace, 'switchMediaRuntimePath');
  requireContains(switchRuntime, "if (!['wlvc_wasm', 'webrtc_native', 'unsupported'].includes(normalizedNextPath))", 'runtime path allow-list');
  requireContains(switchRuntime, "normalizedNextPath === 'wlvc_wasm' && !mediaRuntimeCapabilities.value.stageA", 'WLVC runtime capability gate');
  requireContains(switchRuntime, "normalizedNextPath === 'webrtc_native' && !mediaRuntimeCapabilities.value.stageB", 'native runtime capability gate');
  requireContains(switchRuntime, "if (normalizedNextPath === 'webrtc_native')", 'native switch branch');
  requireContains(switchRuntime, 'teardownSfuRemotePeers();', 'native switch tears down SFU peers');
  requireContains(switchRuntime, 'syncNativePeerConnectionsWithRoster();', 'native switch syncs roster');
  requireContains(switchRuntime, "else if (normalizedNextPath === 'wlvc_wasm')", 'WLVC switch branch');
  requireContains(switchRuntime, 'if (shouldUseNativeAudioBridge())', 'WLVC switch preserves native audio bridge when supported');
  requireMatch(switchRuntime, /if \(shouldUseNativeAudioBridge\(\)\) \{[\s\S]*teardownNativePeerConnections\(\);[\s\S]*syncNativePeerConnectionsWithRoster\(\);/, 'WLVC switch rebuilds native peers before audio bridge reuse');
  requireContains(switchRuntime, 'synchronizeNativePeerMediaElements(peer);', 'WLVC switch rebinds native peers for audio bridge mode');
  requireContains(switchRuntime, 'void syncNativePeerLocalTracks(peer);', 'WLVC switch resyncs native audio tracks');
  requireContains(switchRuntime, 'teardownNativePeerConnections();', 'WLVC switch still tears down native peers when no native bridge exists');
  requireContains(switchRuntime, 'await startEncodingPipeline(videoTrack);', 'WLVC switch starts local encode pipeline');
  requireMatch(switchRuntime, /else\s*\{[\s\S]*stopLocalEncodingPipeline\(\);[\s\S]*teardownNativePeerConnections\(\);[\s\S]*teardownSfuRemotePeers\(\);[\s\S]*\}/, 'unsupported switch fail-closed teardown');

  const fallbackRuntime = extractFunction(workspace, 'maybeFallbackToNativeRuntime');
  requireContains(fallbackRuntime, 'if (SFU_RUNTIME_ENABLED) return false;', 'SFU runtime blocks native fallback');
  requireContains(fallbackRuntime, "return switchMediaRuntimePath('webrtc_native', reason)", 'native fallback runtime switch');
  requireContains(workspace, "await switchMediaRuntimePath('wlvc_wasm', 'capability_probe_stage_a')", 'capability probe WLVC switch');
  requireContains(workspace, "await switchMediaRuntimePath('webrtc_native', 'capability_probe_stage_b')", 'capability probe native switch');
  requireContains(workspace, "setMediaRuntimePath('unsupported', 'capability_probe_unsupported')", 'capability probe unsupported switch');

  const nativeAudioBridge = extractFunction(workspace, 'shouldUseNativeAudioBridge');
  requireContains(nativeAudioBridge, 'SFU_RUNTIME_ENABLED', 'native audio bridge requires SFU runtime');
  requireContains(nativeAudioBridge, 'isWlvcRuntimePath()', 'native audio bridge only runs under WLVC runtime');
  requireContains(nativeAudioBridge, 'mediaRuntimeCapabilities.value.stageB', 'native audio bridge requires native capability');
  requireContains(nativeAudioBridge, 'MediaSecuritySession.supportsNativeTransforms()', 'native audio bridge requires insertable streams for E2E audio');
  const toggleCamera = extractFunction(workspace, 'toggleCamera');
  requireContains(toggleCamera, 'void reconfigureLocalTracksFromSelectedDevices();', 'camera toggle reconfigures device capture instead of only muting');
  requireNotContains(toggleCamera, 'track.enabled = controlState.cameraEnabled;', 'camera toggle must not keep the camera track open');
  const toggleMicrophone = extractFunction(workspace, 'toggleMicrophone');
  requireContains(toggleMicrophone, 'void reconfigureLocalTracksFromSelectedDevices();', 'microphone toggle reconfigures device capture instead of only muting');
  requireNotContains(toggleMicrophone, 'track.enabled = controlState.micEnabled;', 'microphone toggle must not keep the microphone track open');
  const strictConstraints = extractFunction(workspace, 'buildLocalMediaConstraints');
  requireContains(strictConstraints, 'const wantsVideo = controlState.cameraEnabled !== false;', 'strict media constraints honor camera toggle state');
  requireContains(strictConstraints, 'const wantsAudio = controlState.micEnabled !== false;', 'strict media constraints honor microphone toggle state');
  requireContains(strictConstraints, 'const videoProfile = currentSfuVideoProfile();', 'strict media constraints resolve the configured SFU video profile');
  requireContains(strictConstraints, 'width: { ideal: videoProfile.captureWidth }', 'strict media constraints honor configured capture width');
  requireContains(strictConstraints, 'height: { ideal: videoProfile.captureHeight }', 'strict media constraints honor configured capture height');
  requireContains(strictConstraints, 'frameRate: { ideal: videoProfile.captureFrameRate, max: 30 }', 'strict media constraints honor configured capture frame rate');
  requireContains(strictConstraints, 'return { video: false, audio: false };', 'strict media constraints can release both devices completely');
  const looseConstraints = extractFunction(workspace, 'buildLooseLocalMediaConstraints');
  requireContains(looseConstraints, 'const videoProfile = currentSfuVideoProfile();', 'loose media constraints resolve the configured SFU video profile');
  requireContains(looseConstraints, 'width: { ideal: videoProfile.captureWidth }', 'loose media constraints keep configured capture width preference');
  requireContains(looseConstraints, 'height: { ideal: videoProfile.captureHeight }', 'loose media constraints keep configured capture height preference');
  requireContains(looseConstraints, 'frameRate: { ideal: videoProfile.captureFrameRate, max: 30 }', 'loose media constraints keep configured frame rate preference');
  requireContains(looseConstraints, 'audio: wantsAudio ? true : false,', 'loose media constraints release disabled microphone devices');
  const acquireLocal = extractFunction(workspace, 'acquireLocalMediaStreamWithFallback');
  requireContains(acquireLocal, 'return new MediaStream();', 'local media acquisition returns an empty stream when both devices are disabled');
  requireContains(acquireLocal, 'const fallbackConstraints = {', 'local media acquisition preserves disabled device state through fallback retries');
  requireContains(workspace, 'function nativeAudioBridgeBlockedReason', 'audio bridge blocked-reason helper exists');
  requireContains(workspace, 'function nativeAudioBridgePeerStatusMessage', 'audio bridge peer-status helper exists');
  requireContains(workspace, "return 'Audio is unavailable because this browser cannot run the native WebRTC audio bridge required for end-to-end encrypted audio.';", 'audio bridge banner reports missing native WebRTC capability honestly');
  requireContains(workspace, "return 'Audio is unavailable because end-to-end encryption could not be initialized on this device.';", 'audio bridge banner reports blocked local capability honestly');
  requireContains(workspace, "return 'Audio is unavailable because no encrypted remote audio track arrived from the other participant.';", 'audio bridge banner reports missing encrypted remote audio');
  requireContains(workspace, "return 'Audio is blocked by the browser autoplay policy on this device.';", 'audio bridge banner reports autoplay blocking');
  const nativePeerPolicy = extractFunction(workspace, 'shouldMaintainNativePeerConnections');
  requireContains(nativePeerPolicy, 'isNativeWebRtcRuntimePath()', 'native peer policy keeps full native peers');
  requireContains(nativePeerPolicy, 'shouldUseNativeAudioBridge()', 'native peer policy keeps hybrid audio peers');
  const nativeSignalBlock = extractFunction(workspace, 'shouldBlockNativeRuntimeSignaling');
  requireContains(nativeSignalBlock, 'SFU_RUNTIME_ENABLED', 'native signaling block honors SFU runtime flag');
  requireContains(nativeSignalBlock, "mediaRuntimePath.value === 'pending'", 'native signaling block protects SFU startup');
  const handleSignal = extractFunction(workspace, 'handleSignalingEvent');
  requireContains(handleSignal, "...CALL_STATE_SIGNAL_TYPES, ...MEDIA_SECURITY_SIGNAL_TYPES", 'signaling handler accepts dedicated call state signal types');
  requireContains(handleSignal, 'if (shouldBlockNativeRuntimeSignaling())', 'native signaling event block before fallback switch');
  requireContains(handleSignal, "mediaDebugLog('[WebRTC] ignoring native signal while runtime is still pending', type);", 'native signaling event block diagnostic');
  requireContains(handleSignal, 'void handleNativeSignalingEvent(type, senderUserId, payloadBody || {});', 'native signaling fallback still available');
  const ensureNative = extractFunction(workspace, 'ensureNativeRuntimeForSignaling');
  requireContains(ensureNative, 'if (shouldMaintainNativePeerConnections() && !runtimeSwitchInFlight) return true;', 'hybrid audio bridge accepts native signaling without preempting WLVC');
  requireContains(ensureNative, 'if (shouldBlockNativeRuntimeSignaling()) return false;', 'native runtime switch cannot preempt SFU');
  requireContains(ensureNative, 'if (SFU_RUNTIME_ENABLED && !isNativeWebRtcRuntimePath()) {', 'SFU mode must not switch the global media runtime to native just because auxiliary native signaling arrived');
  requireContains(ensureNative, 'return shouldMaintainNativePeerConnections() && !runtimeSwitchInFlight;', 'SFU auxiliary signaling must wait for WLVC hybrid-audio readiness instead of forcing a native runtime switch');

  const createPeer = extractFunction(workspace, 'createOrUpdateSfuRemotePeer');
  requireContains(workspace, 'markRaw', 'Vue raw marker for native codec objects');
  requireContains(createPeer, 'Number.isInteger(publisherUserId)', 'SFU self-publisher integer guard');
  requireContains(createPeer, 'publisherUserId === currentUserId.value', 'SFU does not render local publisher as remote');
  requireContains(createPeer, 'if (tracks.length > 0 && !sfuTrackListHasVideo(tracks))', 'audio-only SFU track updates must not keep a remote video peer alive');
  requireContains(createPeer, 'decoder = markRaw(decoder);', 'remote WASM decoder is not Vue-proxied');
  requireContains(createPeer, "canvas.className = 'remote-video'", 'remote decoded canvas class');
  requireContains(createPeer, 'canvas.dataset.publisherId = publisherId', 'remote canvas publisher id');
  requireContains(createPeer, 'canvas.dataset.userId = String(publisherUserId)', 'remote canvas user id');
  requireContains(createPeer, 'setSfuRemotePeer(publisherId, peer);', 'remote peer map mutation');
  requireContains(createPeer, 'renderCallVideoLayout();', 'remote peer initial render');

  const handleUnpublished = extractFunction(workspace, 'handleSFUUnpublished');
  requireContains(handleUnpublished, 'const nextTracks = sfuTrackRows(peer.tracks)', 'track unpublish computes the remaining track list');
  requireContains(handleUnpublished, 'if (nextTracks.length > 0 && sfuTrackListHasVideo(nextTracks))', 'track unpublish keeps the peer while a video track still exists');
  requireContains(handleUnpublished, 'setSfuRemotePeer(normalizedPublisherId, updatedPeer);', 'track unpublish updates peer tracks in place');
  requireContains(handleUnpublished, 'deleteSfuRemotePeer(normalizedPublisherId);', 'track unpublish still removes peers with no remaining video');

  const ensurePeer = extractFunction(workspace, 'ensureSfuRemotePeerForFrame');
  requireContains(ensurePeer, 'const fallbackPeer = getSfuRemotePeerByFrameIdentity(publisherId, frame?.publisherUserId);', 'remote frame existing peer lookup');
  requireContains(ensurePeer, 'const pending = pendingSfuRemotePeerInitializers.get(publisherId)', 'remote frame pending peer guard');
  requireContains(ensurePeer, 'tracks: trackId ===', 'remote frame creates track placeholder');
  requireContains(ensurePeer, 'pendingSfuRemotePeerInitializers.set(publisherId, init)', 'remote frame stores pending initializer');

  const decodePeer = extractFunction(workspace, 'decodeSfuFrameForPeer');
  requireContains(decodePeer, 'markRemoteFrameActivity(publisherUserId);', 'remote frame activity marking');
  requireContains(decodePeer, 'decryptProtectedFrameEnvelope({', 'remote protected frame decrypt');
  requireContains(decodePeer, 'const decoded = peer.decoder.decodeFrame({', 'remote decode invocation');
  requireContains(decodePeer, 'ctx.putImageData(imageData, 0, 0);', 'remote decoded canvas paint');
  requireContains(decodePeer, 'markRemotePeerRenderable(peer);', 'remote decoded frame reveals renderable media');
  requireContains(decodePeer, 'renderCallVideoLayout();', 'remote decode render recovery');

  const transportOnlyFallback = extractFunction(workspace, 'shouldSendTransportOnlySfuFrame');
  requireContains(transportOnlyFallback, "message.includes('unsupported_capability')", 'transport-only fallback handles missing media security capability');
  requireContains(transportOnlyFallback, "message.includes('blocked_capability')", 'transport-only fallback handles blocked media security capability');
  const nativeWebRtcConfig = extractFunction(workspace, 'nativeWebRtcConfig');
  requireContains(nativeWebRtcConfig, 'config.encodedInsertableStreams = true;', 'native WebRTC config enables encoded insertable streams when available');
  const attachNativeSender = extractFunction(workspace, 'attachMediaSecurityNativeSender');
  requireContains(attachNativeSender, 'session.attachNativeSenderTransform(sender, {', 'native sender transform attaches immediately');
  requireContains(attachNativeSender, 'return true;', 'native sender transform helper returns success explicitly');
  requireContains(attachNativeSender, 'return false;', 'native sender transform helper returns failure explicitly');
  requireNotContains(attachNativeSender, 'session.ensureReady()', 'native sender transform must not wait and leak unencrypted audio');
  const attachNativeReceiver = extractFunction(workspace, 'attachMediaSecurityNativeReceiver');
  requireContains(attachNativeReceiver, 'session.attachNativeReceiverTransform(receiver, senderUserId, {', 'native receiver transform attaches immediately');
  requireContains(attachNativeReceiver, 'return true;', 'native receiver transform helper returns success explicitly');
  requireContains(attachNativeReceiver, 'return false;', 'native receiver transform helper returns failure explicitly');
  requireNotContains(attachNativeReceiver, 'session.ensureReady()', 'native receiver transform must not wait and leak unencrypted audio');
  const syncControlState = extractFunction(workspace, 'syncControlStateToPeers');
  requireContains(syncControlState, "type: 'call/control-state'", 'control-state sync uses dedicated signaling type');
  requireNotContains(syncControlState, "type: 'call/ice'", 'control-state sync must not masquerade as ICE');
  const syncModerationState = extractFunction(workspace, 'syncModerationStateToPeersWithPayload');
  requireContains(syncModerationState, "type: 'call/moderation-state'", 'moderation-state sync uses dedicated signaling type');
  requireNotContains(syncModerationState, "type: 'call/ice'", 'moderation-state sync must not masquerade as ICE');
  requireContains(workspace, 'function playNativePeerAudio', 'native audio bridge playback helper exists');
  requireContains(workspace, 'function scheduleNativePeerAudioTrackDeadline', 'native audio bridge track deadline helper exists');
  requireContains(workspace, 'function nativeAudioPlaybackInterrupted', 'native audio bridge recognizes benign playback interruptions');
  requireContains(workspace, "eventType: 'native_audio_track_missing'", 'native audio bridge emits diagnostics when encrypted audio track never arrives');
  requireContains(workspace, "blocked ? 'native_audio_play_blocked' : 'native_audio_play_failed'", 'native audio bridge emits distinct diagnostics for autoplay blocking and playback failure');
  requireContains(workspace, "eventType: 'native_audio_bridge_blocked'", 'native audio bridge blocked state emits diagnostics');
  requireContains(workspace, 'mediaSecurityStateVersion.value += 1;', 'media security state changes bump the reactive version');
  requireContains(workspace, 'function createNativePeerAudioElement', 'hybrid runtime creates hidden native audio elements');
  const syncNativeMedia = extractFunction(workspace, 'synchronizeNativePeerMediaElements');
  requireContains(syncNativeMedia, 'const audioNeedsRebind = peer.audio.srcObject !== nextAudioStream;', 'native audio bridge only rebinds the audio element when the stream object changed');
  requireContains(syncNativeMedia, "void playNativePeerAudio(peer, audioNeedsRebind ? 'bind_stream' : 'resume_stream');", 'native audio bridge retries playback without forcing a fresh load on every sync');
  requireContains(workspace, 'function ensureNativePeerAudioTransceiver', 'native audio bridge ensures an explicit audio transceiver for negotiation');
  requireContains(workspace, 'function clearMediaSecuritySignalCaches', 'workspace exposes media security signal cache reset helper');
  const clearSecurityCaches = extractFunction(workspace, 'clearMediaSecuritySignalCaches');
  requireContains(clearSecurityCaches, 'mediaSecurityHelloSentAtByUserId.clear();', 'media security cache reset clears handshake timeout tracking');
  requireContains(clearSecurityCaches, 'mediaSecurityHandshakeRetryingByUserId.clear();', 'media security cache reset clears in-flight handshake retries');
  const connectSocket = extractFunction(workspace, 'connectSocket');
  requireContains(connectSocket, 'clearMediaSecuritySignalCaches();', 'websocket open always clears media security dedupe caches');
  requireContains(connectSocket, 'startMediaSecurityHandshakeWatchdog();', 'websocket open starts media security handshake watchdog');
  requireNotContains(connectSocket, 'if (isReconnectOpen) {', 'media security cache clearing must not depend on reconnectAttempt');
  requireContains(workspace, 'const MEDIA_SECURITY_HANDSHAKE_TIMEOUT_MS = 5000;', 'media security handshake timeout is explicitly bounded to 5s');
  requireContains(workspace, 'function startMediaSecurityHandshakeWatchdog', 'media security handshake watchdog starter exists');
  const handshakeWatchdog = extractFunction(workspace, 'checkMediaSecurityHandshakeTimeouts');
  requireContains(handshakeWatchdog, "eventType: 'media_security_handshake_timeout'", 'media security handshake timeout emits diagnostics');
  requireContains(handshakeWatchdog, 'await sendMediaSecurityHello(normalizedTargetId, true);', 'media security handshake timeout force-retries Hello');
  requireContains(handshakeWatchdog, 'await sendMediaSecuritySenderKey(normalizedTargetId, true);', 'media security handshake timeout force-retries SenderKey');
  const sendHello = extractFunction(workspace, 'sendMediaSecurityHello');
  requireContains(sendHello, 'startMediaSecurityHandshakeWatchdog();', 'sending Hello arms the media security handshake watchdog');
  requireContains(workspace, "() => callMediaPrefs.outgoingVideoQualityProfile", 'workspace watches outgoing video quality profile changes');
  requireContains(workspace, 'function downgradeSfuVideoQualityAfterEncodePressure', 'workspace can lower SFU video quality after encode pressure');
  const downgradeQuality = extractFunction(workspace, 'downgradeSfuVideoQualityAfterEncodePressure');
  requireContains(downgradeQuality, 'setCallOutgoingVideoQualityProfile(nextProfile);', 'automatic SFU quality downgrade updates the persisted profile');
  requireContains(downgradeQuality, "eventType: 'sfu_encode_quality_downgraded'", 'automatic SFU quality downgrade emits diagnostics');
  const publishLocal = extractFunction(workspace, 'publishLocalTracks');
  requireMatch(publishLocal, /if \(localStreamRef\.value instanceof MediaStream\) \{[\s\S]*publishLocalTracksToSfuIfReady\(\);[\s\S]*await startEncodingPipeline\(videoTrack\);[\s\S]*return true;[\s\S]*\}/, 'existing local stream starts SFU encoding pipeline');
  requireContains(publishLocal, 'stopLocalEncodingPipeline();', 'local publish tears down encoding when no camera track remains');
  requireContains(publishLocal, 'clearLocalPreviewElement();', 'local publish clears stale preview when no camera track remains');
  const encodePipeline = extractFunction(workspace, 'startEncodingPipeline');
  requireContains(encodePipeline, 'const videoProfile = currentSfuVideoProfile();', 'encode pipeline resolves the configured SFU video profile');
  requireContains(encodePipeline, 'videoEncoderRef.value = nextEncoder ? markRaw(nextEncoder) : null;', 'local WASM encoder is not Vue-proxied');
  requireNotContains(encodePipeline, 'document.hidden', 'SFU frame publishing must continue from background tabs');
  requireContains(encodePipeline, "protectionMode: 'transport_only'", 'SFU encoder defaults to transport-only frames');
  requireContains(encodePipeline, 'outgoingFrame.protectedFrame = protectedFrame.protectedFrame;', 'SFU encoder upgrades to protected frame when available');
  requireContains(encodePipeline, "outgoingFrame.protectionMode = 'protected';", 'SFU encoder marks protected frames');
  requireContains(encodePipeline, 'if (!shouldSendTransportOnlySfuFrame(securityError))', 'SFU encoder only falls back for media security capability failures');
  requireContains(encodePipeline, 'sfuClientRef.value.sendEncodedFrame(outgoingFrame);', 'SFU encoder sends transport-only or protected frame');
  requireContains(encodePipeline, 'videoProfile.frameQuality', 'encode pipeline honors configured frame quality');
  requireContains(encodePipeline, 'videoProfile.keyFrameInterval', 'encode pipeline honors configured keyframe interval');
  requireContains(encodePipeline, 'videoProfile.encodeIntervalMs', 'encode pipeline honors configured encode interval');
  requireContains(encodePipeline, "downgradeSfuVideoQualityAfterEncodePressure('wlvc_encode_runtime_error')", 'repeated encode failures lower SFU quality before fallback');

  const handleFrame = extractFunction(workspace, 'handleSFUEncodedFrame');
  requireContains(handleFrame, 'let peerLookup = getSfuRemotePeerByFrameIdentity(publisherId, frame?.publisherUserId);', 'remote frame peer lookup supports publisher-key aliasing');
  requireContains(handleFrame, 'const init = ensureSfuRemotePeerForFrame(frame);', 'remote frame can create peer before tracks');
  requireContains(handleFrame, 'void decodeSfuFrameForPeer(publisherId, nextPeer, frame);', 'remote frame decodes after async peer create');
  requireContains(handleFrame, 'void decodeSfuFrameForPeer(publisherId, peer, frame);', 'remote frame decodes existing peer');

  const ensurePeerForFrame = extractFunction(workspace, 'ensureSfuRemotePeerForFrame');
  requireContains(ensurePeerForFrame, 'const fallbackPeer = getSfuRemotePeerByFrameIdentity(publisherId, frame?.publisherUserId);', 'frame peer bootstrap reuses existing peer by publisher identity');

  const remoteRenderable = extractFunction(workspace, 'remotePeerHasRenderableMedia');
  requireContains(remoteRenderable, "&& Number(peer.frameCount || 0) > 0", 'decoded canvas only counts as renderable after real frames');
  requireContains(remoteRenderable, "streamHasLiveTrackKind(peer.remoteStream, 'video')", 'audio-only native streams must not count as renderable media');
  requireNotContains(remoteRenderable, 'streamHasTracks(peer.remoteStream)', 'audio-only native streams must not satisfy renderability');
  requireContains(workspace, 'if (isSocketOnline.value) {', 'connected participant watcher resyncs security only on an open socket');
  requireContains(workspace, 'void syncMediaSecurityWithParticipants();', 'connected participant and bridge watchers resync media security');
  requireContains(workspace, "shouldUseNativeAudioBridge() ? '1' : '0'", 'media security watcher reacts when hybrid audio bridge availability flips');
  const syncMediaSecurity = extractFunction(workspace, 'syncMediaSecurityWithParticipants');
  requireContains(syncMediaSecurity, 'clearMediaSecuritySignalCaches();', 'participant churn clears deduplicated media security hello and sender-key caches');

  const mediaNode = extractFunction(workspace, 'mediaNodeForUserId');
  requireContains(mediaNode, 'for (const peer of remotePeersRef.value.values())', 'remote media node user lookup');
  requireContains(mediaNode, 'remotePeerHasRenderableMedia(peer)', 'remote media node prefers renderable peer');
  requireContains(mediaNode, 'return remotePeerMediaNode(peer);', 'remote media node return');
  const renderLayout = extractFunction(workspace, 'renderCallVideoLayout');
  requireContains(renderLayout, 'const primaryNode = mediaNodeForUserId(primaryUserId);', 'primary remote render lookup');
  requireContains(renderLayout, 'for (const participant of miniVideoParticipants.value)', 'mini remote render loop');
  requireContains(renderLayout, 'for (const participant of gridVideoParticipants.value)', 'grid remote render loop');
  requireContains(renderLayout, 'mountVideoNode(slot, node, assignedNodes)', 'slot render mount');
  const ensureNativePeer = extractFunction(workspace, 'ensureNativePeerConnection');
  requireContains(ensureNativePeer, "audioBridgeState: ''", 'native peer initializes audio bridge state');
  requireContains(ensureNativePeer, "reportNativeAudioBridgeFailure(", 'native peer reports explicit bridge failures');
  requireContains(ensureNativePeer, "'native_audio_receiver_transform_failed'", 'native peer distinguishes encrypted audio receiver attach failures');
  requireContains(ensureNativePeer, "setNativePeerAudioBridgeState(peer, 'track_received', '');", 'native peer marks encrypted audio track arrival');
  requireContains(ensureNativePeer, "void playNativePeerAudio(peer, 'remote_track');", 'native peer retries playback when encrypted audio track arrives');
  requireContains(ensureNativePeer, 'scheduleNativePeerAudioTrackDeadline(peer);', 'native peer schedules encrypted audio-track deadline once connected');
  requireContains(ensureNativePeer, 'clearNativePeerAudioTrackDeadline(peer);', 'native peer clears encrypted audio-track deadlines on disconnect');
  const rebuildAudioOnlyPeer = extractFunction(workspace, 'nativePeerRequiresAudioOnlyRebuild');
  requireContains(rebuildAudioOnlyPeer, 'if (!shouldUseNativeAudioBridge()) return false;', 'audio-only peer rebuild only applies in hybrid audio bridge mode');
  requireContains(rebuildAudioOnlyPeer, "=== 'video'", 'audio-only peer rebuild detects stale video senders');
  const syncNativePeers = extractFunction(workspace, 'syncNativePeerConnectionsWithRoster');
  requireContains(syncNativePeers, 'if (nativePeerRequiresAudioOnlyRebuild(existing))', 'roster sync rebuilds stale native peers for audio-only bridge mode');
  const syncNativeLocalTracks = extractFunction(workspace, 'syncNativePeerLocalTracks');
  requireContains(syncNativeLocalTracks, 'ensureNativePeerAudioTransceiver(peer);', 'native local track sync provisions an audio transceiver before negotiation');

  const bumpRender = extractFunction(workspace, 'bumpMediaRenderVersion');
  const bumpCount = (bumpRender.match(/mediaRenderVersion\.value = mediaRenderVersion\.value >= 1_000_000 \? 0 : mediaRenderVersion\.value \+ 1;/g) || []).length;
  assert.equal(bumpCount, 1, 'render version must bump exactly once per peer-map mutation');
  requireContains(extractFunction(workspace, 'setSfuRemotePeer'), 'previousPublisherId =', 'remote peer setter accepts rekey source');
  requireContains(extractFunction(workspace, 'setSfuRemotePeer'), 'if (existingPeer === peer)', 'remote peer setter removes duplicate peer-object aliases');
  requireContains(extractFunction(workspace, 'setSfuRemotePeer'), 'bumpMediaRenderVersion();', 'remote peer add/update render bump');
  requireContains(extractFunction(workspace, 'deleteSfuRemotePeer'), 'bumpMediaRenderVersion();', 'remote peer delete render bump');

  requireContains(readFromRepo('documentation/experiment-intake-provenance.md'), 'Explicit WLVC regression checks:', 'provenance regression section');

  process.stdout.write('[wlvc-runtime-regression-contract] PASS\n');
} catch (error) {
  if (error instanceof Error) {
    fail(error.message);
  }
  fail('unknown failure');
}
