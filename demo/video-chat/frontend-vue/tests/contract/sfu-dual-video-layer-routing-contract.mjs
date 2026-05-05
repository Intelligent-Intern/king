import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

function fail(message) {
  throw new Error(`[sfu-dual-video-layer-routing-contract] FAIL: ${message}`);
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
  const packageJson = read('package.json');
  const browserPublisher = read('src/domain/realtime/local/protectedBrowserVideoEncoder.js');
  const framePayload = read('src/lib/sfu/framePayload.ts');
  const sfuClient = read('src/lib/sfu/sfuClient.ts');
  const messageHandler = read('src/lib/sfu/sfuMessageHandler.ts');
  const sfuTypes = read('src/lib/sfu/sfuTypes.ts');
  const sfuClientTransportSample = read('src/lib/sfu/sfuClientTransportSample.ts');
  const remoteJitterBuffer = read('src/domain/realtime/sfu/remoteJitterBuffer.js');
  const remoteRenderScheduler = read('src/domain/realtime/sfu/remoteRenderScheduler.js');
  const frameDecode = read('src/domain/realtime/sfu/frameDecode.js');
  const sfuStore = readRepo('demo/video-chat/backend-king-php/domain/realtime/realtime_sfu_store.php');
  const sfuSubscriberBudget = readRepo('demo/video-chat/backend-king-php/domain/realtime/realtime_sfu_subscriber_budget.php');

  requireContains(packageJson, 'sfu-dual-video-layer-routing-contract.mjs', 'SFU contract suite includes dual layer routing proof');

  requireContains(browserPublisher, "buildBrowserEncoderConfig(videoProfile, { videoLayer: 'primary', frameSize })", 'browser publisher builds a primary encoder config from source-oriented frame size');
  requireContains(browserPublisher, "buildBrowserEncoderConfig(videoProfile, { videoLayer: 'thumbnail', frameSize })", 'browser publisher builds a thumbnail encoder config from source-oriented frame size');
  requireContains(browserPublisher, 'const createThumbnailEncoder = () => new VideoEncoderCtor({', 'browser publisher creates a second thumbnail encoder');
  requireContains(browserPublisher, 'thumbnailFrame = thumbnailFrameScaler.createScaledFrame(result.frame', 'thumbnail encoder receives a scaled VideoFrame derived from the direct source frame');
  requireContains(browserPublisher, 'thumbnailEncoder.encode(thumbnailFrame, { keyFrame: thumbnailForceKeyframe });', 'thumbnail encoder consumes the scaled VideoFrame before close');
  assert.ok(
    browserPublisher.indexOf('encoder.encode(primaryFrame || result.frame, { keyFrame: forceKeyframe });')
      < browserPublisher.indexOf('closePublisherVideoFrame(result.frame)'),
    'primary encode must happen before source VideoFrame close',
  );
  assert.ok(
    browserPublisher.indexOf('thumbnailEncoder.encode(thumbnailFrame, { keyFrame: thumbnailForceKeyframe });')
      < browserPublisher.indexOf('closePublisherVideoFrame(thumbnailFrame)'),
    'thumbnail encode must happen before scaled thumbnail VideoFrame close',
  );
  requireContains(browserPublisher, "videoLayer: 'primary'", 'browser publisher sends primary layer metadata');
  requireContains(browserPublisher, "videoLayer: 'thumbnail'", 'browser publisher sends thumbnail layer metadata');
  requireContains(browserPublisher, 'critical: false', 'thumbnail send failures are non-critical to the primary stream');
  requireContains(browserPublisher, 'sfu_browser_thumbnail_frame_skipped', 'thumbnail pressure is logged without downshifting primary');
  requireContains(browserPublisher, 'publisher_browser_encoder_layer', 'browser publisher exposes the encoded layer in telemetry');
  requireContains(browserPublisher, 'publisher_browser_encoder_thumbnail_enabled: true', 'browser publisher exposes thumbnail encoder activation');
  assert.equal(browserPublisher.includes('getImageData('), false, 'browser dual layer publisher must not regress to canvas getImageData');

  requireContains(framePayload, 'videoLayer?: SfuVideoLayer | string | null', 'frontend SFU payload accepts explicit video layer metadata');
  requireContains(framePayload, 'payload.video_layer = videoLayer', 'frontend SFU payload writes video_layer into JSON/binary metadata');
  requireContains(framePayload, 'metrics.video_layer = videoLayer', 'frontend SFU metrics preserve video_layer');
  requireContains(framePayload, 'export function normalizeVideoLayer', 'frontend SFU payload normalizes video layer aliases');
  requireContains(sfuClient, 'normalizeVideoLayer,', 'SFU client reuses canonical video layer normalization');
  requireContains(sfuClient, 'this.nextOutboundFrameSequence(', 'SFU client assigns outbound frame sequence before enqueue');
  requireContains(sfuClient, 'frame.videoLayer ?? frame.transportMetrics?.video_layer ?? frame.transportMetrics?.videoLayer', 'SFU client scopes outbound frame sequence by explicit video layer');
  requireContains(sfuClient, '`${trackKey}:${normalizedVideoLayer}`', 'primary and thumbnail layers must not share publisher sequence continuity');
  requireContains(messageHandler, 'videoLayer: videoLayerField(msg.videoLayer, msg.video_layer)', 'SFU message handler maps incoming video layer');
  requireContains(sfuTypes, "videoLayer?: 'primary' | 'thumbnail' | string | null", 'SFU frame type carries video layer');
  requireContains(sfuClientTransportSample, 'videoLayer: String(payload.video_layer || \'\')', 'transport sample reports sent video layer');
  requireContains(remoteJitterBuffer, '`${trackId}:${videoLayer}`', 'receiver jitter buffer isolates primary and thumbnail sequence gaps');
  requireContains(remoteRenderScheduler, '`${trackId}:${videoLayer}`', 'receiver render scheduler isolates primary and thumbnail render order');
  requireContains(frameDecode, '`${trackId}:${videoLayer}`', 'receiver continuity state isolates primary and thumbnail decoder recovery');

  requireContains(sfuSubscriberBudget, 'videochat_sfu_normalize_frame_video_layer', 'backend normalizes frame video layer metadata');
  requireContains(sfuSubscriberBudget, 'thumbnail_subscriber_primary_layer_pruned', 'thumbnail subscribers prune primary frames when dual layers are present');
  requireContains(sfuSubscriberBudget, 'primary_subscriber_thumbnail_layer_pruned', 'primary subscribers prune thumbnail frames when dual layers are present');
  requireContains(sfuSubscriberBudget, 'videochat_sfu_frame_requires_contiguous_decode', 'backend must not cadence-drop frames that depend on decode continuity');
  assert.ok(
    sfuSubscriberBudget.indexOf('videochat_sfu_frame_requires_contiguous_decode($frame)')
      < sfuSubscriberBudget.indexOf('$sequence = max(0, (int) ($frame[\'frame_sequence\'] ?? 0));'),
    'decode-dependent frames must bypass legacy thumbnail cadence pruning before sequence modulo routing',
  );
  assert.ok(
    sfuSubscriberBudget.indexOf('$frameVideoLayer !== \'\'')
      < sfuSubscriberBudget.indexOf('$layerPreference !== \'thumbnail\''),
    'backend must route explicit frame layers before legacy thumbnail cadence fallback',
  );
  requireContains(sfuStore, "$metadata['video_layer'] = $frameVideoLayer;", 'backend SFU store preserves video_layer in binary envelope metadata');
  requireContains(sfuStore, "$metadata['publisher_browser_encoder_layer']", 'backend SFU store preserves publisher encoder layer telemetry');

  process.stdout.write('[sfu-dual-video-layer-routing-contract] PASS\n');
} catch (error) {
  if (error instanceof Error) {
    fail(error.message);
  }
  fail('unknown failure');
}
