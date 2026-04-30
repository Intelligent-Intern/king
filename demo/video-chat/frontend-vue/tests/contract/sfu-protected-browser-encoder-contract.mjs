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
  const frameDecode = read('src/domain/realtime/sfu/frameDecode.js');
  const browserRenderer = read('src/domain/realtime/sfu/remoteBrowserEncodedVideo.js');
  const framePayload = read('src/lib/sfu/framePayload.ts');
  const messageHandler = read('src/lib/sfu/sfuMessageHandler.ts');
  const security = read('src/domain/realtime/media/security.js');
  const securityCore = read('src/domain/realtime/media/securityCore.js');
  const lifecycle = read('src/domain/realtime/sfu/lifecycle.js');
  const mediaStack = read('src/domain/realtime/workspace/callWorkspace/mediaStack.js');
  const packageJson = read('package.json');
  const backendSfuStore = readRepo('demo/video-chat/backend-king-php/domain/realtime/realtime_sfu_store.php');

  requireContains(packageJson, 'sfu-protected-browser-encoder-contract.mjs', 'SFU contract suite includes protected browser encoder proof');

  requireContains(browserPublisher, "PROTECTED_BROWSER_VIDEO_CODEC_ID = 'webcodecs_vp8'", 'browser publisher declares VP8 SFU codec id');
  requireContains(browserPublisher, "PROTECTED_BROWSER_VIDEO_READBACK_METHOD = 'video_frame_webcodecs_direct'", 'browser publisher labels direct VideoFrame/WebCodecs path');
  requireContains(browserPublisher, 'globalScope.VideoEncoder', 'browser publisher gates on VideoEncoder');
  requireContains(browserPublisher, 'globalScope.VideoDecoder', 'browser publisher gates on receiver decode support');
  requireContains(browserPublisher, 'globalScope.EncodedVideoChunk', 'browser publisher gates on encoded chunk support');
  requireContains(browserPublisher, 'createPublisherVideoFrameSourceReader({', 'browser publisher reads camera VideoFrames directly');
  requireContains(browserPublisher, 'encoder.encode(result.frame', 'browser publisher encodes VideoFrame without RGBA conversion');
  requireContains(browserPublisher, 'thumbnailFrame = thumbnailFrameScaler.createScaledFrame(result.frame', 'browser publisher creates a scaled thumbnail VideoFrame without RGBA readback');
  requireContains(browserPublisher, 'thumbnailEncoder.encode(thumbnailFrame', 'browser publisher encodes the scaled thumbnail VideoFrame');
  requireContains(browserPublisher, 'closePublisherVideoFrame(result.frame)', 'browser publisher deterministically closes source VideoFrames');
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
  assert.equal(browserPublisher.includes('getImageData('), false, 'browser publisher must not use canvas getImageData');
  requireContains(browserPublisher, 'createBrowserThumbnailFrameScaler', 'browser publisher keeps thumbnail scaling isolated from the primary WebCodecs path');
  requireContains(browserPublisher, 'function resolveBrowserEncoderBitrate(videoProfile, {', 'browser publisher must bound WebCodecs bitrate from resolution and frame rate, not raw wire budget');
  assert.equal(browserPublisher.includes('Math.floor((maxWireBytesPerSecond || 1_500_000) * 8 * 0.62)'), false, 'browser publisher must not feed absurd wire-budget bitrates into WebCodecs configuration');
  requireContains(browserPublisher, 'function browserEncoderConfigVariants(config)', 'browser publisher must probe hardware/software WebCodecs config variants before falling back');
  requireContains(browserPublisher, 'resolveSupportedBrowserEncoderConfig(VideoEncoderCtor, requestedPrimaryConfig)', 'browser publisher must select a supported primary WebCodecs config variant');
  requireContains(browserPublisher, 'resolveSupportedBrowserEncoderConfig(VideoEncoderCtor, requestedThumbnailConfig)', 'browser publisher must select a supported thumbnail WebCodecs config variant');
  requireContains(browserPublisher, "eventType: 'sfu_browser_encoder_capabilities_unavailable'", 'browser publisher must persist missing WebCodecs capabilities to backend diagnostics before fallback');
  requireContains(browserPublisher, "level: 'warning'", 'browser publisher WebCodecs fallback diagnostics must be stored server-side instead of disappearing as info-only events');

  requireContains(publisherPipeline, "from './protectedBrowserVideoEncoder'", 'publisher pipeline imports browser encoder path');
  assert.ok(
    publisherPipeline.indexOf('maybeStartProtectedBrowserVideoEncoderPublisher({') < publisherPipeline.indexOf('createPublisherSourceReadbackController({'),
    'browser encoder path must be attempted before RGBA/WLVC source readback',
  );
  requireContains(mediaStack, 'captureClientDiagnostic: callbacks.captureClientDiagnostic', 'browser encoder diagnostics are wired to backend telemetry');

  requireContains(browserRenderer, "PROTECTED_BROWSER_VIDEO_CODEC_ID = 'webcodecs_vp8'", 'browser renderer recognizes browser encoded frames');
  requireContains(browserRenderer, 'new VideoDecoderCtor({', 'browser renderer creates WebCodecs decoder');
  requireContains(browserRenderer, 'new globalScope.EncodedVideoChunk({', 'browser renderer feeds encoded chunks to WebCodecs');
  requireContains(browserRenderer, 'videoFrame?.close?.()', 'browser renderer deterministically closes decoded VideoFrames');
  requireContains(browserRenderer, 'noteSfuRemoteVideoFrameStable', 'browser renderer updates receiver liveness');
  requireContains(lifecycle, 'closeProtectedBrowserVideoDecoders(peer)', 'remote peer teardown closes all browser layer decoders');
  requireContains(browserRenderer, 'browserVideoDecoderByLayer', 'browser renderer keeps separate decoder state for primary and thumbnail layers');
  requireContains(browserRenderer, 'decoderState.pendingFrames.push(frame)', 'browser renderer queues per-chunk frame metadata until decoder output');

  requireContains(frameDecode, "from './remoteBrowserEncodedVideo'", 'frame decode imports browser renderer');
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
