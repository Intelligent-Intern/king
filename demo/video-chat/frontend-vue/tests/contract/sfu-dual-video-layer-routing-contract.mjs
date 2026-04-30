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
  const messageHandler = read('src/lib/sfu/sfuMessageHandler.ts');
  const sfuTypes = read('src/lib/sfu/sfuTypes.ts');
  const sfuClientTransportSample = read('src/lib/sfu/sfuClientTransportSample.ts');
  const sfuStore = readRepo('demo/video-chat/backend-king-php/domain/realtime/realtime_sfu_store.php');
  const sfuSubscriberBudget = readRepo('demo/video-chat/backend-king-php/domain/realtime/realtime_sfu_subscriber_budget.php');

  requireContains(packageJson, 'sfu-dual-video-layer-routing-contract.mjs', 'SFU contract suite includes dual layer routing proof');

  requireContains(browserPublisher, "buildBrowserEncoderConfig(videoProfile, { videoLayer: 'primary' })", 'browser publisher builds a primary encoder config');
  requireContains(browserPublisher, "buildBrowserEncoderConfig(videoProfile, { videoLayer: 'thumbnail' })", 'browser publisher builds a thumbnail encoder config');
  requireContains(browserPublisher, 'const thumbnailEncoder = new VideoEncoderCtor({', 'browser publisher creates a second thumbnail encoder');
  requireContains(browserPublisher, 'thumbnailEncoder.encode(result.frame, { keyFrame: thumbnailForceKeyframe });', 'thumbnail encoder consumes the same direct VideoFrame before close');
  assert.ok(
    browserPublisher.indexOf('encoder.encode(result.frame, { keyFrame: forceKeyframe });')
      < browserPublisher.indexOf('closePublisherVideoFrame(result.frame)'),
    'primary encode must happen before source VideoFrame close',
  );
  assert.ok(
    browserPublisher.indexOf('thumbnailEncoder.encode(result.frame, { keyFrame: thumbnailForceKeyframe });')
      < browserPublisher.indexOf('closePublisherVideoFrame(result.frame)'),
    'thumbnail encode must happen before source VideoFrame close',
  );
  requireContains(browserPublisher, "videoLayer: 'primary'", 'browser publisher sends primary layer metadata');
  requireContains(browserPublisher, "videoLayer: 'thumbnail'", 'browser publisher sends thumbnail layer metadata');
  requireContains(browserPublisher, 'critical: false', 'thumbnail send failures are non-critical to the primary stream');
  requireContains(browserPublisher, 'sfu_browser_thumbnail_frame_skipped', 'thumbnail pressure is logged without downshifting primary');
  requireContains(browserPublisher, 'publisher_browser_encoder_layer', 'browser publisher exposes the encoded layer in telemetry');
  requireContains(browserPublisher, 'publisher_browser_encoder_thumbnail_enabled: true', 'browser publisher exposes thumbnail encoder activation');
  assert.equal(browserPublisher.includes('getImageData('), false, 'browser dual layer publisher must not regress to canvas getImageData');
  assert.equal(browserPublisher.includes('drawImage('), false, 'browser dual layer publisher must not regress to canvas drawImage');

  requireContains(framePayload, 'videoLayer?: SfuVideoLayer | string | null', 'frontend SFU payload accepts explicit video layer metadata');
  requireContains(framePayload, 'payload.video_layer = videoLayer', 'frontend SFU payload writes video_layer into JSON/binary metadata');
  requireContains(framePayload, 'metrics.video_layer = videoLayer', 'frontend SFU metrics preserve video_layer');
  requireContains(framePayload, 'export function normalizeVideoLayer', 'frontend SFU payload normalizes video layer aliases');
  requireContains(messageHandler, 'videoLayer: videoLayerField(msg.videoLayer, msg.video_layer)', 'SFU message handler maps incoming video layer');
  requireContains(sfuTypes, "videoLayer?: 'primary' | 'thumbnail' | string | null", 'SFU frame type carries video layer');
  requireContains(sfuClientTransportSample, 'videoLayer: String(payload.video_layer || \'\')', 'transport sample reports sent video layer');

  requireContains(sfuSubscriberBudget, 'videochat_sfu_normalize_frame_video_layer', 'backend normalizes frame video layer metadata');
  requireContains(sfuSubscriberBudget, 'thumbnail_subscriber_primary_layer_pruned', 'thumbnail subscribers prune primary frames when dual layers are present');
  requireContains(sfuSubscriberBudget, 'primary_subscriber_thumbnail_layer_pruned', 'primary subscribers prune thumbnail frames when dual layers are present');
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
