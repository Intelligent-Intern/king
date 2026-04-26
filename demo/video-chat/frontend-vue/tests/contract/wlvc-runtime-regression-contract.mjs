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

  const capabilities = readFromFrontend('src/domain/realtime/media/runtimeCapabilities.js');
  requireContains(capabilities, "preferredPath = 'wlvc_wasm'", 'WLVC preferred runtime');
  requireContains(capabilities, "preferredPath = 'webrtc_native'", 'native fallback runtime');
  requireContains(capabilities, "preferredPath = 'unsupported'", 'unsupported fail-closed runtime');
  requireContains(capabilities, 'const stageA = Boolean(wlvcWasm.encoder)', 'WLVC stage A gate');
  requireContains(capabilities, 'const stageB = Boolean(webRtcNative)', 'native stage B gate');

  const callMediaPreferences = readFromFrontend('src/domain/realtime/media/preferences.js');
  requireContains(callMediaPreferences, 'outgoing_video_quality_profile', 'outgoing video quality profile persists in call media preferences');
  requireContains(callMediaPreferences, 'outgoing_video_quality_profile_version', 'HD baseline migration versions persisted outgoing video quality');
  requireContains(callMediaPreferences, 'CALL_MEDIA_PREFS_OUTGOING_VIDEO_PROFILE_VERSION', 'outgoing video quality migration version is explicit');
  requireContains(callMediaPreferences, 'outgoingVideoQualityProfile', 'call media preferences expose outgoing video quality profile');
  requireContains(callMediaPreferences, 'export function setCallOutgoingVideoQualityProfile(profile)', 'call media preferences export outgoing video quality setter');

  const workspaceConfig = readFromFrontend('src/domain/realtime/workspace/config.js');
  requireContains(workspaceConfig, 'export const SFU_VIDEO_QUALITY_PROFILES', 'WLVC video quality profiles exist');
  requireContains(workspaceConfig, 'export const SFU_VIDEO_QUALITY_PROFILE_OPTIONS', 'WLVC video quality profile options exist');
  requireContains(workspaceConfig, 'export function resolveSfuVideoQualityProfile(value)', 'WLVC video quality profile resolver exists');
  requireContains(workspaceConfig, "export const DEFAULT_SFU_VIDEO_QUALITY_PROFILE = 'quality';", 'HD quality profile is the default while stabilizing transport');
  requireContains(workspaceConfig, 'export const LOCAL_CAMERA_CAPTURE_FRAME_RATE = 30;', 'HD baseline captures 30fps camera video');
  requireContains(workspaceConfig, 'export const SFU_WLVC_FRAME_WIDTH = 1280;', 'HD baseline encodes 720p video width');
  requireContains(workspaceConfig, 'export const SFU_WLVC_FRAME_HEIGHT = 720;', 'HD baseline encodes 720p video height');
  requireContains(workspaceConfig, 'frameWidth: 1280,', 'quality WLVC profile exposes a 720p output option');
  requireContains(workspaceConfig, 'export const SFU_WLVC_SEND_BUFFER_HIGH_WATER_BYTES', 'WLVC encode loop has a websocket backpressure high-water mark');
  requireContains(workspaceConfig, 'export const SFU_WLVC_SEND_BUFFER_CRITICAL_BYTES', 'WLVC encode loop has a websocket backpressure critical mark');

  const workspaceShell = readFromFrontend('src/layouts/WorkspaceShell.vue');
  requireContains(workspaceShell, 'id="call-left-video-quality"', 'workspace sidebar exposes outgoing video quality select');
  requireContains(workspaceShell, 'setCallOutgoingVideoQualityProfile', 'workspace sidebar can update outgoing video quality');
  requireContains(workspaceShell, 'callVideoQualityOptions', 'workspace sidebar renders outgoing video quality options');

  const telemetry = readFromFrontend('src/domain/realtime/media/runtimeTelemetry.js');
  requireContains(telemetry, "type: 'media_runtime_transition'", 'runtime transition telemetry');
  requireContains(telemetry, 'preferred_path: normalizePath(event?.capabilities?.preferred_path)', 'runtime transition capability telemetry');

  const workspace = readFromFrontend('src/domain/realtime/CallWorkspaceView.vue');
  const sfuWlvcFrameMetadata = readFromFrontend('src/domain/realtime/sfu/wlvcFrameMetadata.js');
  const nativeAudioBridgeHelpers = readFromFrontend('src/domain/realtime/native/audioBridgeHelpers.js');
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
  requireContains(workspace, "return 'Audio is unavailable because this browser cannot run the native WebRTC audio bridge required for protected audio.';", 'audio bridge banner reports missing native WebRTC capability honestly');
  requireContains(workspace, "return 'Audio is unavailable because protected media could not be initialized on this device.';", 'audio bridge banner reports blocked local capability honestly');
  requireContains(workspace, "return 'Audio is unavailable because no protected remote audio track arrived from the other participant.';", 'audio bridge banner reports missing protected remote audio');
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
  requireContains(decodePeer, 'const frameMetadata = readWlvcFrameMetadata(frameData, {', 'remote decode reads WLVC frame metadata from payload');
  requireContains(decodePeer, 'await ensureSfuRemotePeerDecoderForFrame(publisherId, peer, frameMetadata);', 'remote decode reconfigures decoder for payload dimensions');
  requireContains(decodePeer, 'const frameDescriptor = buildSfuFrameDescriptor(frameData, frame.timestamp, frameMetadata, frame.type);', 'remote decode descriptor uses metadata dimensions');
  requireContains(decodePeer, 'let decoded = peer.decoder.decodeFrame(frameDescriptor);', 'remote decode invocation');
  requireContains(decodePeer, 'renderDecodedSfuFrame(peer, decoded)', 'remote decode delegates canvas paint');
  const frameMetadataHelper = extractFunction(sfuWlvcFrameMetadata, 'readWlvcFrameMetadata');
  requireContains(frameMetadataHelper, 'wlvcDecodeFrame(frameData)', 'WLVC metadata helper parses the payload header');
  requireContains(frameMetadataHelper, 'width: normalizePositiveInteger(decoded.frame.width, fallbackWidth)', 'WLVC metadata helper returns payload width');
  requireContains(frameMetadataHelper, 'type: normalizeSfuFrameType(decoded.frame.frame_type, fallbackType)', 'WLVC metadata helper returns payload frame type');
  const frameDescriptorHelper = extractFunction(sfuWlvcFrameMetadata, 'buildSfuFrameDescriptor');
  requireContains(frameDescriptorHelper, 'width: normalizePositiveInteger(metadata?.width, SFU_WLVC_FRAME_WIDTH)', 'WLVC descriptor helper uses payload width with config fallback');
  requireContains(frameDescriptorHelper, 'height: normalizePositiveInteger(metadata?.height, SFU_WLVC_FRAME_HEIGHT)', 'WLVC descriptor helper uses payload height with config fallback');
  const ensureDecoderForFrame = extractFunction(workspace, 'ensureSfuRemotePeerDecoderForFrame');
  requireContains(ensureDecoderForFrame, 'width: nextWidth', 'remote decoder reconfigure uses payload width');
  requireContains(ensureDecoderForFrame, 'height: nextHeight', 'remote decoder reconfigure uses payload height');
  requireContains(ensureDecoderForFrame, 'peer.decoder = markRaw(nextDecoder);', 'remote decoder reconfigure preserves raw codec instances');
  const renderDecodedFrame = extractFunction(workspace, 'renderDecodedSfuFrame');
  requireContains(renderDecodedFrame, 'ctx.putImageData(imageData, 0, 0);', 'remote decoded canvas paint');
  requireContains(renderDecodedFrame, 'markRemotePeerRenderable(peer);', 'remote decoded frame reveals renderable media');
  requireContains(renderDecodedFrame, 'renderCallVideoLayout();', 'remote decode render recovery');

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
  requireContains(nativeAudioBridgeHelpers, 'function nativeAudioPlaybackInterrupted', 'native audio bridge recognizes benign playback interruptions');
  requireContains(workspace, "eventType: 'native_audio_track_missing'", 'native audio bridge emits diagnostics when encrypted audio track never arrives');
  requireContains(workspace, "blocked ? 'native_audio_play_blocked' : 'native_audio_play_failed'", 'native audio bridge emits distinct diagnostics for autoplay blocking and playback failure');
  requireContains(workspace, "'native_audio_bridge_waiting'", 'native audio bridge waiting state emits warning diagnostics');
  requireContains(workspace, "'native_audio_bridge_blocked'", 'native audio bridge blocked state emits error diagnostics');
  requireContains(workspace, 'eventType: diagnosticEventType', 'native audio bridge banner diagnostics use the resolved state');
  requireContains(workspace, 'mediaSecurityStateVersion.value += 1;', 'media security state changes bump the reactive version');
  requireContains(workspace, 'function createNativePeerAudioElement', 'hybrid runtime creates hidden native audio elements');
  requireContains(workspace, 'const NATIVE_AUDIO_TRACK_RECOVERY_MAX_ATTEMPTS = 2;', 'native audio bridge missing-track recovery is bounded');
  requireContains(nativeAudioBridgeHelpers, 'function nativePeerConnectionTelemetry', 'native audio bridge exposes peer connection telemetry for missing-track diagnostics');
  requireContains(workspace, 'function scheduleNativeAudioTrackRecovery', 'native audio bridge can recover when connected peers never receive audio tracks');
  const nativeTrackRecovery = extractFunction(workspace, 'scheduleNativeAudioTrackRecovery');
  requireContains(nativeTrackRecovery, "eventType: 'native_audio_track_recovery'", 'native audio bridge recovery emits diagnostics');
  requireContains(nativeTrackRecovery, 'void syncMediaSecurityWithParticipants(true);', 'native audio bridge recovery forces a media-security rekey before rebuilding');
  requireContains(nativeTrackRecovery, 'closeNativePeerConnection(normalizedUserId);', 'native audio bridge recovery rebuilds the stuck peer connection');
  requireContains(nativeTrackRecovery, 'syncNativePeerConnectionsWithRoster();', 'native audio bridge recovery rejoins the roster after rebuild');
  const syncNativeMedia = extractFunction(workspace, 'synchronizeNativePeerMediaElements');
  requireContains(syncNativeMedia, 'const audioNeedsRebind = peer.audio.srcObject !== nextAudioStream;', 'native audio bridge only rebinds the audio element when the stream object changed');
  requireContains(syncNativeMedia, "void playNativePeerAudio(peer, audioNeedsRebind ? 'bind_stream' : 'resume_stream');", 'native audio bridge retries playback without forcing a fresh load on every sync');
  requireContains(workspace, 'function ensureNativePeerAudioTransceiver', 'native audio bridge ensures an explicit audio transceiver for negotiation');
  requireContains(workspace, 'function findNativePeerAudioTransceiver', 'native audio bridge selects the correct offered audio transceiver');
  const findAudioTransceiver = extractFunction(workspace, 'findNativePeerAudioTransceiver');
  requireContains(findAudioTransceiver, 'peer.pc.getTransceivers()', 'native audio bridge reuses transceivers created by the remote offer');
  requireContains(findAudioTransceiver, "receiverKind === 'audio'", 'native audio bridge detects remote-offered audio transceivers by receiver kind');
  requireContains(findAudioTransceiver, "mid !== '' || currentDirection !== ''", 'native audio bridge prefers the negotiated remote-offer audio transceiver');
  const ensureAudioTransceiver = extractFunction(workspace, 'ensureNativePeerAudioTransceiver');
  requireContains(ensureAudioTransceiver, "existing.direction = 'sendrecv';", 'native audio bridge upgrades remote-offered audio transceivers to sendrecv');
  const localTracksByKind = extractFunction(workspace, 'localTracksByKind');
  requireContains(localTracksByKind, "if (track?.readyState === 'ended') continue;", 'native audio bridge must not negotiate ended local tracks');
  requireContains(workspace, 'function nativeAudioBridgeHasLocalAudioTrack', 'native audio bridge can check for a live local mic before SDP negotiation');
  requireContains(workspace, 'function shouldExpectLocalNativeAudioTrack', 'native audio bridge only requires local sendable SDP when the local mic is expected');
  requireContains(workspace, 'function shouldExpectRemoteNativeAudioTrack', 'native audio bridge only rejects remote recvonly SDP when that peer is expected to send');
  requireContains(workspace, 'nativeSdpHasSendableAudio,', 'workspace imports native audio SDP validation from the helper module');
  requireContains(nativeAudioBridgeHelpers, 'function nativeSdpHasSendableAudio', 'native audio bridge validates that SDP contains a sendable audio track');
  requireContains(nativeAudioBridgeHelpers, 'function nativePeerConnectionTelemetry', 'native audio bridge peer telemetry lives outside the workspace view');
  requireContains(nativeAudioBridgeHelpers, 'function nativeAudioPlaybackInterrupted', 'native audio playback interruption detection lives outside the workspace view');
  const nativeSdpSendable = extractFunction(nativeAudioBridgeHelpers, 'nativeSdpHasSendableAudio');
  requireContains(nativeSdpSendable, 'if (summary.rejected) return false;', 'native audio bridge rejects port-0 audio SDP');
  requireContains(nativeSdpSendable, "summary.direction !== 'sendrecv' && summary.direction !== 'sendonly'", 'native audio bridge rejects recvonly/inactive audio SDP');
  requireContains(nativeSdpSendable, 'return summary.has_msid;', 'native audio bridge requires an msid-backed audio track in SDP');
  requireContains(workspace, 'function reportNativeAudioSdpRejected', 'native audio bridge reports rejected SDP to console and diagnostics');
  requireContains(workspace, "eventType: normalizedCode", 'native audio bridge rejected SDP diagnostics are classified by rejection code');
  const sendNativeOffer = extractFunction(workspace, 'sendNativeOffer');
  requireContains(sendNativeOffer, 'const mediaReady = await ensureLocalMediaForNativeNegotiation();', 'native audio bridge offer checks local media readiness');
  requireContains(sendNativeOffer, 'shouldExpectLocalNativeAudioTrack()', 'native audio bridge offer does not block receive-only negotiation when the local mic is muted');
  requireContains(sendNativeOffer, 'nativeAudioBridgeHasLocalAudioTrack()', 'native audio bridge offer requires a live local mic');
  requireContains(sendNativeOffer, "native_audio_offer_without_send_audio", 'native audio bridge rejects offers without send-capable audio');
  const handleNativeOffer = extractFunction(workspace, 'handleNativeOfferSignal');
  requireContains(handleNativeOffer, 'shouldExpectRemoteNativeAudioTrack(senderUserId)', 'native audio bridge offer validation respects remote mic state');
  requireContains(handleNativeOffer, "native_audio_remote_offer_without_send_audio", 'native audio bridge rejects remote offers that cannot send audio');
  requireContains(handleNativeOffer, 'shouldExpectLocalNativeAudioTrack()', 'native audio bridge answer does not block receive-only negotiation when the local mic is muted');
  requireContains(handleNativeOffer, 'nativeAudioBridgeHasLocalAudioTrack()', 'native audio bridge answer requires a live local mic');
  requireMatch(handleNativeOffer, /setRemoteDescription[\s\S]*syncNativePeerLocalTracks[\s\S]*createAnswer/, 'native audio bridge answers reuse the offered audio transceiver before creating an answer');
  requireContains(handleNativeOffer, "native_audio_answer_without_send_audio", 'native audio bridge refuses to send recvonly/no-track answers');
  const handleNativeAnswer = extractFunction(workspace, 'handleNativeAnswerSignal');
  requireContains(handleNativeAnswer, 'shouldExpectRemoteNativeAudioTrack(senderUserId)', 'native audio bridge answer validation respects remote mic state');
  requireContains(handleNativeAnswer, "native_audio_remote_answer_without_send_audio", 'native audio bridge ignores answers without send-capable audio');
  requireContains(handleNativeAnswer, "scheduleNativeOfferRetry(peer, 'answer_without_send_audio');", 'native audio bridge retries when a stale/no-mic answer is ignored');
  const ensureLocalNativeMedia = extractFunction(workspace, 'ensureLocalMediaForNativeNegotiation');
  requireContains(ensureLocalNativeMedia, "controlState.micEnabled !== false && !streamHasLiveTrackKind(localStreamRef.value, 'audio')", 'native audio bridge reacquires a live mic track before negotiation');
  requireContains(ensureLocalNativeMedia, 'return reconfigureLocalTracksFromSelectedDevices();', 'native audio bridge recovers a missing enabled mic from devices');
  const stopRetiredLocal = extractFunction(workspace, 'stopRetiredLocalStreams');
  requireContains(stopRetiredLocal, 'const preservedTrackIds = new Set();', 'local stream cleanup tracks preserved shared tracks');
  requireContains(stopRetiredLocal, 'if (track?.id) preservedTrackIds.add(track.id);', 'local stream cleanup collects preserved track ids');
  requireContains(stopRetiredLocal, 'if (track?.id && preservedTrackIds.has(track.id)) continue;', 'local stream cleanup must not stop shared raw mic tracks during blur reconfigure');
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
  requireContains(encodePipeline, 'if (wlvcEncodeInFlight) return;', 'WLVC encode loop avoids overlapping async interval work');
  requireNotContains(encodePipeline, 'setInterval(async () =>', 'WLVC encode loop is self-paced instead of a fixed interval timer');
  requireContains(encodePipeline, 'setTimeout(runWlvcEncodeTick', 'WLVC encode loop schedules the next tick only after the previous encode finishes');
  requireContains(encodePipeline, 'if (shouldThrottleWlvcEncodeLoop()) return;', 'WLVC encode loop honors adaptive backpressure pacing');
  requireContains(encodePipeline, 'getSfuClientBufferedAmount()', 'WLVC encode loop checks websocket bufferedAmount');
  requireContains(encodePipeline, 'shouldDelayWlvcFrameForBackpressure(bufferedAmount)', 'WLVC encode loop waits for websocket low-water recovery');
  requireContains(encodePipeline, 'handleWlvcEncodeBackpressure(bufferedAmount, videoTrack.id);', 'WLVC encode loop skips frames under websocket backpressure');
  requireContains(encodePipeline, "handleWlvcFrameSendFailure(\n          getSfuClientBufferedAmount(),\n          videoTrack.id,\n          'sfu_chunk_send_failed'", 'WLVC encoder separates failed sends from websocket backpressure');
  requireContains(encodePipeline, 'const encodedFrameType = sfuFrameTypeFromWlvcData(encoded.data, encoded.type);', 'WLVC encode loop derives keyframe state from encoded payload header');
  requireContains(encodePipeline, "protectionMode: 'transport_only'", 'SFU encoder defaults to transport-only frames');
  requireContains(encodePipeline, 'outgoingFrame.protectedFrame = protectedFrame.protectedFrame;', 'SFU encoder upgrades to protected frame when available');
  requireContains(encodePipeline, "outgoingFrame.protectionMode = 'protected';", 'SFU encoder marks protected frames');
  requireContains(encodePipeline, 'if (!shouldSendTransportOnlySfuFrame(securityError))', 'SFU encoder only falls back for media security capability failures');
  requireContains(encodePipeline, 'sfuClientRef.value.sendEncodedFrame(outgoingFrame);', 'SFU encoder sends transport-only or protected frame');
  requireContains(encodePipeline, 'videoProfile.frameQuality', 'encode pipeline honors configured frame quality');
  requireContains(encodePipeline, 'videoProfile.keyFrameInterval', 'encode pipeline honors configured keyframe interval');
  requireContains(encodePipeline, 'videoProfile.encodeIntervalMs', 'encode pipeline honors configured encode interval');
  requireContains(encodePipeline, "downgradeSfuVideoQualityAfterEncodePressure('wlvc_encode_runtime_error')", 'repeated encode failures lower SFU quality before fallback');
  requireContains(workspace, 'function handleWlvcEncodeBackpressure', 'workspace exposes WLVC send-buffer backpressure handling');
  const handleBackpressure = extractFunction(workspace, 'handleWlvcEncodeBackpressure');
  requireContains(handleBackpressure, "resetWlvcEncoderAfterDroppedEncodedFrame('sfu_send_backpressure_skip')", 'WLVC backpressure skips force the next frame to be a keyframe');
  requireContains(handleBackpressure, 'hd_baseline_no_auto_downgrade: true', 'HD baseline does not silently solve transport pressure by lowering quality');
  requireNotContains(handleBackpressure, "downgradeSfuVideoQualityAfterEncodePressure('sfu_send_backpressure')", 'send-buffer backpressure must not silently downgrade the HD baseline');
  requireContains(workspace, 'function handleWlvcFrameSendFailure', 'workspace handles failed SFU frame sends separately from bufferedAmount backpressure');
  const handleFrameSendFailure = extractFunction(workspace, 'handleWlvcFrameSendFailure');
  requireContains(handleFrameSendFailure, 'if (shouldDelayWlvcFrameForBackpressure(normalizedBuffered))', 'failed SFU frame sends only become backpressure when bufferedAmount is actually high');
  requireContains(handleFrameSendFailure, "eventType: 'sfu_frame_send_failed'", 'failed SFU frame sends emit their own diagnostics');
  requireContains(handleFrameSendFailure, 'hd_baseline_no_auto_downgrade: true', 'failed SFU frame sends do not silently lower the HD baseline');
  requireContains(workspace, 'function resetWlvcEncoderAfterDroppedEncodedFrame', 'workspace can reset WLVC encoder state after an unsent encoded frame');
  requireContains(workspace, 'wlvcBackpressurePauseUntilMs', 'WLVC send-buffer backpressure uses adaptive pacing instead of reconnect loops');
  requireContains(workspace, "eventType: 'sfu_video_backpressure'", 'WLVC send-buffer backpressure emits diagnostics');
  requireNotContains(workspace, "restartSfuAfterVideoStall('sfu_send_backpressure'", 'WLVC send-buffer backpressure must not reconnect as a normal control path');
  requireContains(workspace, "restartSfuAfterVideoStall('sfu_send_buffer_stuck'", 'WLVC send-buffer hard reset is reserved for stuck critical buffers');
  requireContains(workspace, "eventType: 'sfu_remote_video_frozen'", 'remote video freeze detector emits diagnostics');
  requireContains(workspace, "eventType: 'sfu_delta_before_keyframe_dropped'", 'remote decoder drops stale deltas until a keyframe arrives');
  requireContains(workspace, 'peer.needsKeyframe = true;', 'remote decoder marks keyframe required after reset or decode failure');
  requireContains(workspace, 'peer.needsKeyframe = false;', 'remote decoder clears keyframe wait after a renderable keyframe');
  requireContains(workspace, 'function shouldDropRemoteSfuFrameForContinuity(publisherId, peer, frame)', 'remote receiver enforces SFU frame continuity before decode');
  requireContains(workspace, "'sequence_gap_delta'", 'remote receiver drops deltas after sequence gaps');
  requireContains(workspace, 'REMOTE_SFU_FRAME_STALE_TTL_MS', 'remote receiver drops stale SFU frames by TTL');
  requireContains(workspace, 'function restartSfuAfterVideoStall', 'workspace can reconnect SFU after hard video stalls');

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
  requireContains(syncNativeLocalTracks, 'const audioTransceiver = ensureNativePeerAudioTransceiver(peer);', 'native local track sync provisions an audio transceiver before negotiation');
  requireContains(syncNativeLocalTracks, 'sender !== primaryAudioSender', 'native local track sync detaches stale pre-offer audio senders');

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
