import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

function fail(message) {
  throw new Error(`[sfu-protected-browser-encoder-contract] FAIL: ${message}`);
}

function requireContains(source, needle, label) {
  assert.ok(source.includes(needle), `${label} missing: ${needle}`);
}

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);
const frontendRoot = path.resolve(__dirname, '../..');
const repoRoot = path.resolve(frontendRoot, '../../..');

function read(relativePath) {
  return fs.readFileSync(path.resolve(frontendRoot, relativePath), 'utf8');
}

function readRepo(relativePath) {
  return fs.readFileSync(path.resolve(repoRoot, relativePath), 'utf8');
}

try {
  const publisherPipeline = read('src/domain/realtime/local/publisherPipeline.js');
  const browserPublisher = read('src/domain/realtime/local/protectedBrowserVideoEncoder.js');
  const browserEncoderConfig = read('src/domain/realtime/local/browserVideoEncoderConfig.js');
  const browserFrameScaler = read('src/domain/realtime/local/browserVideoFrameScaler.js');
  const frameDecode = read('src/domain/realtime/sfu/frameDecode.js');
  const browserRenderer = read('src/domain/realtime/sfu/remoteBrowserEncodedVideo.js');
  const framePayload = read('src/lib/sfu/framePayload.ts');
  const messageHandler = read('src/lib/sfu/sfuMessageHandler.ts');
  const security = read('src/domain/realtime/media/security.js');
  const securityCore = read('src/domain/realtime/media/securityCore.js');
  const lifecycle = read('src/domain/realtime/sfu/lifecycle.js');
  const mediaStack = read('src/domain/realtime/workspace/callWorkspace/mediaStack.js');
  const recoveryReasons = read('src/domain/realtime/sfu/recoveryReasons.js');
  const socketLifecycle = read('src/domain/realtime/workspace/callWorkspace/socketLifecycle.js');
  const sfuTransport = read('src/domain/realtime/workspace/callWorkspace/sfuTransport.js');
  const mediaSecurityTargets = read('src/domain/realtime/workspace/callWorkspace/mediaSecurityTargets.js');
  const packageJson = read('package.json');
  const backendSfuStore = readRepo('demo/video-chat/backend-king-php/domain/realtime/realtime_sfu_store.php');

  requireContains(packageJson, 'sfu-protected-browser-encoder-contract.mjs', 'SFU contract suite includes protected browser encoder proof');

  requireContains(browserPublisher, "PROTECTED_BROWSER_VIDEO_CODEC_ID = 'webcodecs_vp8'", 'browser publisher declares VP8 SFU codec id');
  requireContains(browserPublisher, "PROTECTED_BROWSER_VIDEO_READBACK_METHOD = 'video_frame_webcodecs_direct'", 'browser publisher labels direct VideoFrame/WebCodecs path');
  requireContains(browserPublisher, 'globalScope.VideoEncoder', 'browser publisher gates on VideoEncoder');
  requireContains(browserPublisher, 'globalScope.VideoDecoder', 'browser publisher gates on receiver decode support');
  requireContains(browserPublisher, 'globalScope.EncodedVideoChunk', 'browser publisher gates on encoded chunk support');
  requireContains(browserPublisher, 'createPublisherVideoFrameSourceReader({', 'browser publisher reads camera VideoFrames directly');
  requireContains(browserPublisher, 'resolveBrowserEncoderFrameSize(videoProfile, sourceFrame)', 'browser publisher derives encoder dimensions from the actual source VideoFrame');
  requireContains(browserEncoderConfig, 'resolveFramedFrameSizeFromDimensions', 'browser encoder config reuses portrait/cover frame sizing policy');
  requireContains(browserPublisher, 'videoFrameSourceDimensions(result.frame)', 'browser publisher observes real VideoFrame orientation before encode');
  requireContains(browserPublisher, 'encoder.encode(primaryFrame || result.frame', 'browser publisher encodes a source-aspect VideoFrame without RGBA conversion');
  requireContains(browserPublisher, 'primaryFrame = primaryFrameScaler.createScaledFrame(result.frame', 'browser publisher scales portrait sources into a matching WebCodecs frame before encode');
  requireContains(browserPublisher, 'thumbnailFrame = thumbnailFrameScaler.createScaledFrame(result.frame', 'browser publisher creates a scaled thumbnail VideoFrame without RGBA readback');
  requireContains(browserPublisher, 'thumbnailEncoder.encode(thumbnailFrame', 'browser publisher encodes the scaled thumbnail VideoFrame');
  requireContains(browserPublisher, 'closePublisherVideoFrame(result.frame)', 'browser publisher deterministically closes source VideoFrames');
  requireContains(browserPublisher, 'closePublisherVideoFrame(primaryFrame)', 'browser publisher deterministically closes scaled primary VideoFrames');
  requireContains(browserPublisher, 'closePublisherVideoFrame(thumbnailFrame)', 'browser publisher deterministically closes scaled thumbnail VideoFrames');
  requireContains(browserPublisher, 'stages: []', 'browser publisher initializes publisher trace stage list');
  requireContains(browserPublisher, 'stageMetrics: {}', 'browser publisher initializes publisher trace metrics');
  requireContains(browserPublisher, 'mediaSecurity.protectFrame({', 'browser publisher keeps King protected media envelopes');
  requireContains(browserPublisher, 'sendClient.sendEncodedFrame(outgoingFrame)', 'browser publisher still uses SFU binary frame sender');
  requireContains(browserPublisher, 'publisher_browser_encoder_codec', 'browser publisher emits backend telemetry for encoder path');
  requireContains(browserPublisher, 'publisher_browser_encoder_layer', 'browser publisher emits backend telemetry for encoder layer');
  requireContains(browserPublisher, 'publisher_browser_encoder_thumbnail_enabled: true', 'browser publisher reports dual encoder activation');
  requireContains(browserPublisher, 'onProtectedBrowserEncoderFailure(error)', 'browser publisher reports fatal browser encoder errors');
  requireContains(browserPublisher, 'maybeStartProtectedBrowserVideoEncoderPublisher', 'browser publisher exposes compatibility fallback startup gate');
  requireContains(browserPublisher, 'gate.disabledUntilMs = Date.now() + 30_000', 'browser publisher temporarily disables failed browser encoder path before WLVC fallback');
  requireContains(sfuTransport, 'sfuBrowserEncoderCompatibilityDisabledUntilMs', 'SFU transport state tracks receiver-forced browser encoder compatibility fallback');
  requireContains(browserPublisher, 'sfuBrowserEncoderCompatibilityDisabledUntilMs', 'browser publisher honors receiver-forced compatibility fallback state');
  assert.equal(browserPublisher.includes('getImageData('), false, 'browser publisher must not use canvas getImageData');
  requireContains(browserPublisher, 'createBrowserVideoFrameScaler', 'browser publisher keeps WebCodecs frame scaling isolated from RGBA readback');
  requireContains(browserFrameScaler, 'buildRgbaVideoFrameInitFromSource', 'browser frame scaler keeps a non-WebGL RGBA VideoFrame fallback for legacy graphics stacks');
  requireContains(browserFrameScaler, 'new VideoFrameCtor(\n          imageData.data,', 'browser frame scaler falls back when canvas-backed VideoFrame construction fails');
  requireContains(browserEncoderConfig, 'export function resolveBrowserEncoderBitrate(videoProfile, {', 'browser publisher must bound WebCodecs bitrate from resolution and frame rate, not raw wire budget');
  assert.equal(browserEncoderConfig.includes('Math.floor((maxWireBytesPerSecond || 1_500_000) * 8 * 0.62)'), false, 'browser publisher must not feed absurd wire-budget bitrates into WebCodecs configuration');
  requireContains(browserEncoderConfig, 'function browserEncoderConfigVariants(config)', 'browser publisher must probe hardware/software WebCodecs config variants before falling back');
  requireContains(browserPublisher, 'resolveSupportedBrowserEncoderConfig(VideoEncoderCtor, requestedPrimaryConfig)', 'browser publisher must select a supported primary WebCodecs config variant');
  requireContains(browserPublisher, 'resolveSupportedBrowserEncoderConfig(VideoEncoderCtor, requestedThumbnailConfig)', 'browser publisher must select a supported thumbnail WebCodecs config variant');
  requireContains(browserPublisher, "eventType: 'sfu_browser_encoder_capabilities_unavailable'", 'browser publisher must persist missing WebCodecs capabilities to backend diagnostics before fallback');
  requireContains(browserPublisher, "level: 'warning'", 'browser publisher WebCodecs fallback diagnostics must be stored server-side instead of disappearing as info-only events');
  requireContains(browserPublisher, "eventType: 'sfu_publish_waiting_for_media_security'", 'browser publisher must persist media-security gate waits to backend diagnostics');
  requireContains(browserPublisher, 'forceNextSecurityKeyframe = true', 'browser publisher must force a keyframe after media-security gates close');
  requireContains(browserPublisher, 'remoteKeyframeRequestPending(timestamp)', 'browser publisher must honor receiver-requested full-keyframe recovery state');
  requireContains(browserPublisher, "eventType: 'sfu_browser_keyframe_required_delta_dropped'", 'browser publisher must not send a delta when a reconnect/recovery keyframe is required');
  requireContains(browserPublisher, "const encodedFrameType = actualEncodedFrameType", 'browser publisher must not relabel browser delta chunks as keyframes');
  requireContains(browserPublisher, "new Error('sfu_browser_encoder_keyframe_unavailable')", 'browser publisher must fall back when WebCodecs cannot produce recovery keyframes');
  requireContains(browserPublisher, 'refs.sfuTransportState.wlvcRemoteKeyframeRequestUntilMs = 0', 'browser publisher must clear receiver keyframe recovery once a primary keyframe was sent');
  requireContains(browserPublisher, "hintMediaSecuritySync('sfu_publish_security_gate_waiting'", 'browser publisher must resync keys while the publish security gate is closed');
  requireContains(browserPublisher, "protect_browser_encoded_frame_unavailable_waiting_for_security", 'browser publisher must drop, not leak, frames when protectFrame becomes unavailable mid-frame');
  assert.equal(
    browserPublisher.includes("hintMediaSecuritySync('protect_browser_encoded_frame_unavailable',"),
    false,
    'browser publisher must not continue a transport-only fallback when protected media is enabled',
  );

  requireContains(publisherPipeline, "from './protectedBrowserVideoEncoder'", 'publisher pipeline imports browser encoder path');
  assert.ok(
    publisherPipeline.indexOf('maybeStartProtectedBrowserVideoEncoderPublisher({') < publisherPipeline.indexOf('createPublisherSourceReadbackController({'),
    'browser encoder path must be attempted before RGBA/WLVC source readback',
  );
  requireContains(publisherPipeline, "captureClientDiagnostic('sfu_publish_waiting_for_media_security'", 'RGBA fallback publisher must persist media-security gate waits to backend diagnostics');
  requireContains(publisherPipeline, "hintMediaSecuritySync('sfu_publish_security_gate_waiting'", 'RGBA fallback publisher must resync keys while the publish security gate is closed');
  requireContains(publisherPipeline, "protect_frame_unavailable_waiting_for_security", 'RGBA fallback publisher must drop, not leak, frames when protectFrame becomes unavailable mid-frame');
  assert.equal(
    publisherPipeline.includes('sending transport-only frame'),
    false,
    'RGBA fallback publisher must not continue a transport-only fallback when protected media is enabled',
  );
  requireContains(mediaSecurityTargets, 'return targetUserIds;', 'SFU media-security target set must come from connected remote participants, not delayed publisher discovery');
  requireContains(mediaStack, 'captureClientDiagnostic: callbacks.captureClientDiagnostic', 'browser encoder diagnostics are wired to backend telemetry');

  requireContains(browserRenderer, "PROTECTED_BROWSER_VIDEO_CODEC_ID = 'webcodecs_vp8'", 'browser renderer recognizes browser encoded frames');
  requireContains(browserRenderer, 'new VideoDecoderCtor({', 'browser renderer creates WebCodecs decoder');
  requireContains(browserRenderer, "decoder.configure({ codec: 'vp8' })", 'browser renderer must let VP8 bitstreams define dimensions instead of pinning unsupported layer configs');
  requireContains(browserRenderer, 'new globalScope.EncodedVideoChunk({', 'browser renderer feeds encoded chunks to WebCodecs');
  requireContains(browserRenderer, 'videoFrame?.close?.()', 'browser renderer deterministically closes decoded VideoFrames');
  requireContains(browserRenderer, 'noteSfuRemoteVideoFrameStable', 'browser renderer updates receiver liveness');
  requireContains(lifecycle, 'closeProtectedBrowserVideoDecoders(peer)', 'remote peer teardown closes all browser layer decoders');
  requireContains(browserRenderer, 'browserVideoDecoderByLayer', 'browser renderer keeps separate decoder state for primary and thumbnail layers');
  requireContains(browserRenderer, 'decoderState.pendingFrames.push(frame)', 'browser renderer queues per-chunk frame metadata until decoder output');
  requireContains(browserRenderer, 'decoderNeedsKeyframe && !frameIsKeyframe', 'browser renderer must not initialize or recover WebCodecs with a delta frame');
  requireContains(browserRenderer, 'isBrowserDecoderConfigured(existingDecoderState?.decoder)', 'browser renderer must not reuse unconfigured WebCodecs decoders after browser errors');
  requireContains(browserRenderer, "requestProtectedBrowserDecoderRecovery(peer, frame, 'sfu_remote_video_decoder_waiting_keyframe')", 'browser renderer asks for a full keyframe when only deltas arrive after subscription or reset');
  requireContains(browserRenderer, "eventType: 'sfu_remote_video_decoder_waiting_keyframe'", 'browser renderer persists keyframe-wait drops to backend diagnostics');
  requireContains(browserRenderer, 'publisher_user_id: positiveInteger(frame?.publisherUserId || peer?.userId || 0, 0)', 'browser renderer carries publisher user id into keyframe recovery before peer hydration');
  requireContains(browserRenderer, 'discardBrowserDecoderState(peer, frame, decoderState)', 'browser renderer discards poisoned WebCodecs decoders after decode failures');
  requireContains(browserRenderer, "requestProtectedBrowserDecoderRecovery(peer, frame, 'sfu_browser_decode_frame_failed')", 'browser renderer asks publisher for a full keyframe after decode failures');
  requireContains(browserRenderer, "requestProtectedBrowserDecoderRecovery(peer, frame, 'sfu_browser_decoder_error')", 'browser renderer asks publisher for a full keyframe after decoder errors');
  requireContains(browserRenderer, "requested_action: 'force_full_keyframe'", 'browser renderer sends explicit full-keyframe recovery action');
  requireContains(recoveryReasons, "SFU_COMPATIBILITY_CODEC_RECOVERY_ACTION = 'prefer_compatibility_video_codec'", 'receiver feedback declares a compatibility codec recovery action');
  requireContains(browserRenderer, 'requestProtectedBrowserCompatibilityFallback(peer, frame, reason', 'browser renderer can request publisher-side WLVC fallback when WebCodecs decode is unavailable');
  requireContains(browserRenderer, 'requested_action: SFU_COMPATIBILITY_CODEC_RECOVERY_ACTION', 'browser renderer sends explicit compatibility codec action');
  requireContains(browserRenderer, "requested_codec_id: 'wlvc_sfu'", 'browser renderer names the interoperable WLVC codec target');
  requireContains(mediaStack, 'shouldRequestSfuCompatibilityCodecFallback(feedbackAction, payload || {})', 'receiver feedback uses websocket signaling for codec compatibility fallback instead of SFU-only recovery rows');
  requireContains(mediaStack, 'payload?.publisher_user_id', 'receiver feedback can target the publisher user id before remote peer hydration');
  assert.ok(
    mediaStack.indexOf('requestPublisherMediaRecovery') < mediaStack.indexOf('const targetUserId = Number('),
    'SFU publisher recovery by publisher id must run before socket fallback requires a hydrated peer user id',
  );
  requireContains(socketLifecycle, 'shouldRequestSfuCompatibilityCodecFallback(requestedAction, payloadBody || {})', 'publisher socket lifecycle recognizes receiver codec compatibility requests');
  requireContains(lifecycle, 'shouldRequestSfuCompatibilityCodecFallback(requestedAction, details)', 'publisher SFU recovery path recognizes receiver codec compatibility requests');

  requireContains(frameDecode, "from './remoteBrowserEncodedVideo'", 'frame decode imports browser renderer');
  requireContains(frameDecode, 'sendRemoteSfuVideoQualityPressure,', 'browser renderer receives direct recovery signaling');
  requireContains(frameDecode, "errorCode === 'replay_detected'", 'frame decode must treat protected replayed broker frames as stale drops, not decoder resets');
  requireContains(frameDecode, "'protected_replay_detected'", 'frame decode must persist protected replay drops with an exact reason');
  assert.ok(
    frameDecode.indexOf('isProtectedBrowserEncodedVideoFrame(frame)') < frameDecode.indexOf('readWlvcFrameMetadata(frameData'),
    'browser encoded frames must bypass WLVC metadata/decode',
  );

  requireContains(framePayload, "'webcodecs_vp8'", 'frontend SFU payload normalization preserves webcodecs_vp8');
  requireContains(messageHandler, 'frameWidth: Math.max(0, integerField(0, msg.frameWidth, msg.frame_width))', 'message handler maps browser frame width');
  requireContains(messageHandler, 'frameHeight: Math.max(0, integerField(0, msg.frameHeight, msg.frame_height))', 'message handler maps browser frame height');
  requireContains(security, "normalized === 'webcodecs_vp8'", 'media security normalizes browser codec id');
  requireContains(securityCore, "'webcodecs_vp8'", 'protected header validator allows browser codec id');
  requireContains(backendSfuStore, "'wlvc_wasm', 'wlvc_ts', 'webcodecs_vp8'", 'backend SFU store preserves browser codec id');
  requireContains(backendSfuStore, 'publisher_browser_encoder_codec', 'backend SFU store preserves browser encoder telemetry');
  requireContains(
    read('src/domain/realtime/local/publisherFrameTrace.js'),
    'if (!Array.isArray(trace.stages)) trace.stages = [];',
    'publisher trace stage marker must not crash hand-built browser encoder traces',
  );

  requireContains(browserPublisher, 'supportsVideoEncoder: Boolean(VideoEncoderCtor)', 'browser publisher exposes VideoEncoder capability flag');
  requireContains(browserPublisher, 'supportsVideoDecoder: Boolean(VideoDecoderCtor)', 'browser publisher exposes VideoDecoder capability flag');
  requireContains(browserPublisher, 'supportsEncodedVideoChunk: Boolean(EncodedVideoChunkCtor)', 'browser publisher exposes EncodedVideoChunk capability flag');
  requireContains(browserPublisher, 'supportsMediaStreamTrackProcessor: Boolean(MediaStreamTrackProcessorCtor)', 'browser publisher exposes processor capability flag');
  requireContains(browserPublisher, "supportsVideoFrameClose: typeof VideoFrameCtor?.prototype?.close === 'function'", 'browser publisher requires deterministic VideoFrame close support');

  process.stdout.write('[sfu-protected-browser-encoder-contract] PASS\n');
} catch (error) {
  if (error instanceof Error) {
    fail(error.message);
  }
  fail('unknown failure');
}
