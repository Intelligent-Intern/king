import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

import {
  WLVC_HEADER_BYTES,
  WLVC_HEADER_BYTES_V2,
  WLVC_MAGIC_U32_BE,
  wlvcDecodeFrame,
  wlvcEncodeFrame,
  wlvcFrameToHex,
} from '../../src/support/wlvcFrame.js';

function fail(message) {
  throw new Error(`[wlvc-runtime-regression-contract] FAIL: ${message}`);
}

/*
 * Contract anchors for extension/tests/737-wlvc-runtime-regression-contract.phpt:
 * re-encode bytes must stay stable
 * payload_length_mismatch
 * channel_too_large
 * runtime path allow-list
 * native switch tears down SFU peers
 * WLVC switch tears down native peers
 * remote frame can create peer before tracks
 * remote decoded canvas paint
 * render version must bump exactly once per peer-map mutation
 */

function requireContains(source, needle, label) {
  assert.ok(source.includes(needle), `${label} missing: ${needle}`);
}

function assertDecodeFailure(label, decode, expectedCode) {
  let result = null;
  assert.doesNotThrow(() => {
    result = decode();
  }, `${label} must not throw`);
  assert.equal(result?.ok, false, `${label} must fail closed`);
  assert.equal(result?.error_code, expectedCode, `${label} error code`);
}

function buildHeaderOnlyFrame({
  yLength = 0,
  uLength = 0,
  vLength = 0,
  quality = 70,
  version = 1,
  frameType = 0,
} = {}) {
  const headerBytes = version >= 2 ? WLVC_HEADER_BYTES_V2 : WLVC_HEADER_BYTES;
  const bytes = new Uint8Array(headerBytes);
  const view = new DataView(bytes.buffer);
  view.setUint32(0, WLVC_MAGIC_U32_BE, false);
  view.setUint8(4, version);
  view.setUint8(5, frameType);
  view.setUint8(6, quality);
  view.setUint8(7, 3);
  view.setUint16(8, 64, false);
  view.setUint16(10, 48, false);
  view.setUint32(12, yLength, false);
  view.setUint32(16, uLength, false);
  view.setUint32(20, vLength, false);
  view.setUint16(24, 32, false);
  view.setUint16(26, 24, false);
  if (version >= 2) {
    view.setUint8(28, 0);
    view.setUint8(29, 0);
    view.setUint8(30, 0);
    view.setUint8(31, 0);
    view.setUint8(32, 0);
  }
  return bytes;
}

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);
const frontendRoot = path.resolve(__dirname, '../..');

function readFromFrontend(relativePath) {
  return fs.readFileSync(path.resolve(frontendRoot, relativePath), 'utf8');
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

  assertDecodeFailure('null input', () => wlvcDecodeFrame(null), 'wlvc_payload_type_invalid');
  assertDecodeFailure('odd hex input', () => wlvcDecodeFrame('abc'), 'wlvc_hex_invalid');
  assertDecodeFailure('short header', () => wlvcDecodeFrame(new Uint8Array(WLVC_HEADER_BYTES - 1)), 'frame_too_short');
  assertDecodeFailure('payload mismatch', () => wlvcDecodeFrame(encoded.bytes.slice(0, encoded.bytes.length - 1)), 'payload_length_mismatch');
  assertDecodeFailure('oversized channel', () => wlvcDecodeFrame(buildHeaderOnlyFrame({ yLength: 16 * 1024 * 1024 + 1 })), 'channel_too_large');
  assertDecodeFailure('invalid quality', () => wlvcDecodeFrame(buildHeaderOnlyFrame({ quality: 0 })), 'quality_invalid');

  const v2Delta = wlvcDecodeFrame(buildHeaderOnlyFrame({ version: 2, frameType: 1 }));
  assert.equal(v2Delta.ok, true, `decode v2 delta failed: ${v2Delta.error_code ?? 'unknown'}`);
  assert.equal(v2Delta.frame.version, 2);
  assert.equal(v2Delta.frame.frame_type, 1);
  assert.equal(v2Delta.frame.header_length, WLVC_HEADER_BYTES_V2);

  const capabilities = readFromFrontend('src/domain/realtime/media/runtimeCapabilities.js');
  requireContains(capabilities, "preferredPath = 'wlvc_wasm'", 'WLVC preferred runtime');
  requireContains(capabilities, "preferredPath = 'webrtc_native'", 'native fallback runtime');
  requireContains(capabilities, "preferredPath = 'unsupported'", 'unsupported fail-closed runtime');
  requireContains(capabilities, 'const stageA = Boolean(wlvcWasm.encoder)', 'WLVC stage A gate');
  requireContains(capabilities, 'const stageB = Boolean(webRtcNative)', 'native stage B gate');

  const callMediaPreferences = readFromFrontend('src/domain/realtime/media/preferences.js');
  requireContains(callMediaPreferences, 'outgoing_video_quality_profile', 'outgoing video quality profile persists in call media preferences');
  requireContains(callMediaPreferences, 'CALL_MEDIA_PREFS_OUTGOING_VIDEO_PROFILE_VERSION', 'outgoing video quality migration version is explicit');
  requireContains(callMediaPreferences, 'export function setCallOutgoingVideoQualityProfile(profile)', 'call media preferences export outgoing video quality setter');

  const workspaceConfig = readFromFrontend('src/domain/realtime/workspace/config.js');
  requireContains(workspaceConfig, 'export const SFU_VIDEO_QUALITY_PROFILES', 'WLVC video quality profiles exist');
  requireContains(workspaceConfig, 'rescue: Object.freeze({', 'WLVC backpressure has a low-bitrate rescue profile');
  assert.ok(!workspaceConfig.includes('export const SFU_VIDEO_QUALITY_PROFILE_OPTIONS'), 'WLVC quality profiles must not be exported as user-facing select options');
  requireContains(workspaceConfig, "export const DEFAULT_SFU_VIDEO_QUALITY_PROFILE = 'balanced';", 'balanced profile starts production calls below the HD stress profile');
  requireContains(workspaceConfig, 'quality: Object.freeze({', 'HD quality profile stays available for the HD acceptance gate');
  requireContains(workspaceConfig, 'export const LOCAL_CAMERA_CAPTURE_FRAME_RATE = 27;', 'HD baseline captures 27fps camera video after the 10% quality tradeoff');
  requireContains(workspaceConfig, 'export const SFU_WLVC_FRAME_WIDTH = 1280;', 'HD baseline encodes 720p width');
  requireContains(workspaceConfig, 'export const SFU_WLVC_FRAME_HEIGHT = 720;', 'HD baseline encodes 720p height');
  requireContains(workspaceConfig, 'export const SFU_WLVC_SEND_BUFFER_HIGH_WATER_BYTES', 'WLVC encode loop has backpressure high-water mark');
  requireContains(workspaceConfig, 'export const SFU_WLVC_SEND_BUFFER_CRITICAL_BYTES', 'WLVC encode loop has backpressure critical mark');

  const runtimeSwitching = readFromFrontend('src/domain/realtime/workspace/callWorkspace/runtimeSwitching.js');
  const runtimeConfig = readFromFrontend('src/domain/realtime/workspace/callWorkspace/runtimeConfig.js');
  requireContains(runtimeConfig, "realtime: 'rescue'", 'WLVC auto-downgrade can leave realtime when websocket pressure persists');
  requireContains(runtimeSwitching, "if (!['wlvc_wasm', 'webrtc_native', 'unsupported'].includes(normalizedNextPath))", 'runtime path allow-list');
  requireContains(runtimeSwitching, "if (normalizedNextPath === 'wlvc_wasm' && !refs.mediaRuntimeCapabilities.value.stageA)", 'WLVC runtime capability gate');
  requireContains(runtimeSwitching, "if (normalizedNextPath === 'webrtc_native' && !refs.mediaRuntimeCapabilities.value.stageB)", 'native runtime capability gate');
  requireContains(runtimeSwitching, 'appendMediaRuntimeTransitionEvent({', 'runtime switch telemetry append');
  requireContains(runtimeSwitching, 'stopLocalEncodingPipeline();', 'runtime switch can stop local encode pipeline');
  requireContains(runtimeSwitching, 'teardownSfuRemotePeers();', 'native switch tears down SFU peers');
  requireContains(runtimeSwitching, 'teardownNativePeerConnections();', 'WLVC/native switch tears down native peers when needed');
  requireContains(runtimeSwitching, 'syncNativePeerConnectionsWithRoster();', 'runtime switch syncs native roster');
  requireContains(runtimeSwitching, 'await startEncodingPipeline(videoTrack);', 'WLVC switch restarts local encode pipeline');
  requireContains(runtimeSwitching, "return switchMediaRuntimePath('webrtc_native', reason);", 'native fallback runtime switch');

  const runtimeHealth = readFromFrontend('src/domain/realtime/workspace/callWorkspace/runtimeHealth.js');
  const videoConnectionStatus = readFromFrontend('src/domain/realtime/sfu/videoConnectionStatus.js');
  requireContains(runtimeHealth, "return mediaRuntimePath.value === 'wlvc_wasm';", 'WLVC runtime path helper');
  requireContains(runtimeHealth, "return mediaRuntimePath.value === 'webrtc_native';", 'native runtime path helper');
  requireContains(runtimeHealth, 'if (!mediaSecuritySessionClass.supportsNativeTransforms()) {', 'native audio bridge requires native transforms');
  requireContains(runtimeHealth, "return sfuRuntimeEnabled && mediaRuntimePath.value === 'pending';", 'native signaling block protects SFU startup');
  requireContains(runtimeHealth, "'[KingRT] 📵 No video signal from SFU publisher'", 'remote stall diagnostic remains wired');
  requireContains(runtimeHealth, "'[KingRT] SFU remote video frozen'", 'remote freeze diagnostic remains wired');
  requireContains(videoConnectionStatus, "eventType: 'sfu_remote_video_stable'", 'remote video stable status is routed to backend diagnostics');
  requireContains(videoConnectionStatus, 'local_user_id: normalizeUserId(currentUserId)', 'remote video status includes local participant identity');

  const nativeSignaling = readFromFrontend('src/domain/realtime/native/signaling.js');
  requireContains(nativeSignaling, "if (sfuRuntimeEnabled() && String(mediaRuntimePath.value || '').trim() !== 'webrtc_native') {", 'native signaling will not hijack WLVC runtime');

  const workspace = readFromFrontend('src/domain/realtime/CallWorkspaceView.vue');
  const workspaceStage = readFromFrontend('src/domain/realtime/CallWorkspaceStage.css');
  const layoutStrategies = readFromFrontend('src/domain/realtime/layout/strategies.js');
  const mediaStack = readFromFrontend('src/domain/realtime/workspace/callWorkspace/mediaStack.js');
  const videoLayout = readFromFrontend('src/domain/realtime/workspace/callWorkspace/videoLayout.js');
  requireContains(workspace, "import { createCallWorkspaceRuntimeSwitchingHelpers }", 'workspace must use extracted runtime switching helper');
  requireContains(layoutStrategies, "const remoteMainUserId = mode === 'main_mini'", 'main+mini layout must prefer remote participant as main video');
  requireContains(layoutStrategies, "mainUserId === Number(currentUserId)", 'main+mini layout must not keep unpinned local self as main video');
  requireContains(mediaStack, "import { createCallWorkspaceRuntimeHealthHelpers }", 'media stack must use extracted runtime health helper');
  requireContains(mediaStack, 'markRemotePeerRenderable: (peer) => markRemotePeerRenderable(peer)', 'media stack must lazily route remote render callback after video layout is initialized');
  requireContains(videoLayout, 'function scheduleDeferredVideoLayout()', 'video layout must retry after Vue has materialized remote participant slots');
  requireContains(videoLayout, 'function mountRemotePeerFallback(peer, assignedNodes)', 'video layout must retain remote peer media nodes when primary selection misses the node lookup');
  requireContains(videoLayout, "document.getElementById('decoded-video-container')", 'video layout must visibly mount decoded SFU peers even before roster slots exist');
  requireContains(videoLayout, 'mountRemotePeerFallback(peer, assignedNodes);', 'video layout must mount remote peer fallback before removing unassigned nodes');
  requireContains(workspace, 'const liveCurrentLayoutMode = computed(() => currentLayoutMode.value);', 'media stack must read the live layout mode after participant UI replaces placeholders');
  requireContains(workspace, 'currentLayoutMode: liveCurrentLayoutMode,', 'media stack current layout ref must not capture the initial placeholder');
  requireContains(workspace, 'gridVideoParticipants: liveGridVideoParticipants,', 'media stack grid participants ref must not capture the initial placeholder');
  requireContains(workspaceStage, '.workspace-grid-video-slot :deep(canvas)', 'grid layout must style decoded canvas nodes inside grid slots');
  requireContains(workspaceStage, 'object-fit: contain !important;', 'grid video must fit inside its tile instead of cropping oversized frames');
  requireContains(workspaceStage, 'z-index: 15;', 'decoded fallback layer must sit above local preview when used');

  process.stdout.write('[wlvc-runtime-regression-contract] PASS\n');
} catch (error) {
  if (error instanceof Error) {
    fail(error.message);
  }
  fail('unknown failure');
}
