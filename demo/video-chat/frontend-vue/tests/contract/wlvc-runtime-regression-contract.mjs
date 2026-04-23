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
  requireContains(switchRuntime, 'teardownNativePeerConnections();', 'WLVC switch tears down native peers');
  requireContains(switchRuntime, 'await startEncodingPipeline(videoTrack);', 'WLVC switch starts local encode pipeline');
  requireMatch(switchRuntime, /else\s*\{[\s\S]*stopLocalEncodingPipeline\(\);[\s\S]*teardownNativePeerConnections\(\);[\s\S]*teardownSfuRemotePeers\(\);[\s\S]*\}/, 'unsupported switch fail-closed teardown');

  const fallbackRuntime = extractFunction(workspace, 'maybeFallbackToNativeRuntime');
  requireContains(fallbackRuntime, 'if (SFU_RUNTIME_ENABLED) return false;', 'SFU runtime blocks native fallback');
  requireContains(fallbackRuntime, "return switchMediaRuntimePath('webrtc_native', reason)", 'native fallback runtime switch');
  requireContains(workspace, "await switchMediaRuntimePath('wlvc_wasm', 'capability_probe_stage_a')", 'capability probe WLVC switch');
  requireContains(workspace, "await switchMediaRuntimePath('webrtc_native', 'capability_probe_stage_b')", 'capability probe native switch');
  requireContains(workspace, "setMediaRuntimePath('unsupported', 'capability_probe_unsupported')", 'capability probe unsupported switch');

  const nativeSignalBlock = extractFunction(workspace, 'shouldBlockNativeRuntimeSignaling');
  requireContains(nativeSignalBlock, 'SFU_RUNTIME_ENABLED', 'native signaling block honors SFU runtime flag');
  requireContains(nativeSignalBlock, "mediaRuntimePath.value === 'pending'", 'native signaling block protects SFU startup');
  requireContains(nativeSignalBlock, 'isWlvcRuntimePath()', 'native signaling block protects active WLVC runtime');
  const handleSignal = extractFunction(workspace, 'handleSignalingEvent');
  requireContains(handleSignal, 'if (shouldBlockNativeRuntimeSignaling())', 'native signaling event block before fallback switch');
  requireContains(handleSignal, "mediaDebugLog('[WebRTC] ignoring native signal while SFU runtime is active', type);", 'native signaling event block diagnostic');
  requireContains(handleSignal, 'void handleNativeSignalingEvent(type, senderUserId, payloadBody || {});', 'native signaling fallback still available');
  const ensureNative = extractFunction(workspace, 'ensureNativeRuntimeForSignaling');
  requireContains(ensureNative, 'if (shouldBlockNativeRuntimeSignaling()) return false;', 'native runtime switch cannot preempt SFU');

  const createPeer = extractFunction(workspace, 'createOrUpdateSfuRemotePeer');
  requireContains(workspace, 'markRaw', 'Vue raw marker for native codec objects');
  requireContains(createPeer, 'Number.isInteger(publisherUserId)', 'SFU self-publisher integer guard');
  requireContains(createPeer, 'publisherUserId === currentUserId.value', 'SFU does not render local publisher as remote');
  requireContains(createPeer, 'decoder = markRaw(decoder);', 'remote WASM decoder is not Vue-proxied');
  requireContains(createPeer, "canvas.className = 'remote-video'", 'remote decoded canvas class');
  requireContains(createPeer, 'canvas.dataset.publisherId = publisherId', 'remote canvas publisher id');
  requireContains(createPeer, 'canvas.dataset.userId = String(publisherUserId)', 'remote canvas user id');
  requireContains(createPeer, 'setSfuRemotePeer(publisherId, peer);', 'remote peer map mutation');
  requireContains(createPeer, 'renderCallVideoLayout();', 'remote peer initial render');

  const ensurePeer = extractFunction(workspace, 'ensureSfuRemotePeerForFrame');
  requireContains(ensurePeer, 'const existingPeer = remotePeersRef.value.get(publisherId)', 'remote frame existing peer lookup');
  requireContains(ensurePeer, 'const pending = pendingSfuRemotePeerInitializers.get(publisherId)', 'remote frame pending peer guard');
  requireContains(ensurePeer, 'tracks: trackId ===', 'remote frame creates track placeholder');
  requireContains(ensurePeer, 'pendingSfuRemotePeerInitializers.set(publisherId, init)', 'remote frame stores pending initializer');

  const decodePeer = extractFunction(workspace, 'decodeSfuFrameForPeer');
  requireContains(decodePeer, 'markRemoteFrameActivity(publisherUserId);', 'remote frame activity marking');
  requireContains(decodePeer, 'decryptProtectedFrameEnvelope({', 'remote protected frame decrypt');
  requireContains(decodePeer, 'const decoded = peer.decoder.decodeFrame({', 'remote decode invocation');
  requireContains(decodePeer, 'ctx.putImageData(imageData, 0, 0);', 'remote decoded canvas paint');
  requireContains(decodePeer, 'renderCallVideoLayout();', 'remote decode render recovery');

  const transportOnlyFallback = extractFunction(workspace, 'shouldSendTransportOnlySfuFrame');
  requireContains(transportOnlyFallback, "message.includes('unsupported_capability')", 'transport-only fallback handles missing media security capability');
  requireContains(transportOnlyFallback, "message.includes('blocked_capability')", 'transport-only fallback handles blocked media security capability');
  const publishLocal = extractFunction(workspace, 'publishLocalTracks');
  requireMatch(publishLocal, /if \(localStreamRef\.value instanceof MediaStream\) \{[\s\S]*publishLocalTracksToSfuIfReady\(\);[\s\S]*await startEncodingPipeline\(videoTrack\);[\s\S]*return true;[\s\S]*\}/, 'existing local stream starts SFU encoding pipeline');
  const encodePipeline = extractFunction(workspace, 'startEncodingPipeline');
  requireContains(encodePipeline, 'videoEncoderRef.value = nextEncoder ? markRaw(nextEncoder) : null;', 'local WASM encoder is not Vue-proxied');
  requireNotContains(encodePipeline, 'document.hidden', 'SFU frame publishing must continue from background tabs');
  requireContains(encodePipeline, "protectionMode: 'transport_only'", 'SFU encoder defaults to transport-only frames');
  requireContains(encodePipeline, 'outgoingFrame.protectedFrame = protectedFrame.protectedFrame;', 'SFU encoder upgrades to protected frame when available');
  requireContains(encodePipeline, "outgoingFrame.protectionMode = 'protected';", 'SFU encoder marks protected frames');
  requireContains(encodePipeline, 'if (!shouldSendTransportOnlySfuFrame(securityError))', 'SFU encoder only falls back for media security capability failures');
  requireContains(encodePipeline, 'sfuClientRef.value.sendEncodedFrame(outgoingFrame);', 'SFU encoder sends transport-only or protected frame');

  const handleFrame = extractFunction(workspace, 'handleSFUEncodedFrame');
  requireContains(handleFrame, 'const init = ensureSfuRemotePeerForFrame(frame);', 'remote frame can create peer before tracks');
  requireContains(handleFrame, 'void decodeSfuFrameForPeer(publisherId, nextPeer, frame);', 'remote frame decodes after async peer create');
  requireContains(handleFrame, 'void decodeSfuFrameForPeer(publisherId, peer, frame);', 'remote frame decodes existing peer');

  const mediaNode = extractFunction(workspace, 'mediaNodeForUserId');
  requireContains(mediaNode, 'for (const peer of remotePeersRef.value.values())', 'remote media node user lookup');
  requireContains(mediaNode, 'return remotePeerMediaNode(peer);', 'remote media node return');
  const renderLayout = extractFunction(workspace, 'renderCallVideoLayout');
  requireContains(renderLayout, 'const primaryNode = mediaNodeForUserId(primaryUserId);', 'primary remote render lookup');
  requireContains(renderLayout, 'for (const participant of miniVideoParticipants.value)', 'mini remote render loop');
  requireContains(renderLayout, 'for (const participant of gridVideoParticipants.value)', 'grid remote render loop');
  requireContains(renderLayout, 'mountVideoNode(slot, node, assignedNodes)', 'slot render mount');

  const bumpRender = extractFunction(workspace, 'bumpMediaRenderVersion');
  const bumpCount = (bumpRender.match(/mediaRenderVersion\.value = mediaRenderVersion\.value >= 1_000_000 \? 0 : mediaRenderVersion\.value \+ 1;/g) || []).length;
  assert.equal(bumpCount, 1, 'render version must bump exactly once per peer-map mutation');
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
