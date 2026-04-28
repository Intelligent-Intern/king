import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

function fail(message) {
  throw new Error(`[sfu-selective-tile-runtime-contract] FAIL: ${message}`);
}

function requireContains(source, needle, label) {
  assert.ok(source.includes(needle), `${label} missing: ${needle}`);
}

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);
const frontendRoot = path.resolve(__dirname, '../..');

function read(relativePath) {
  return fs.readFileSync(path.resolve(frontendRoot, relativePath), 'utf8');
}

try {
  const helper = read('src/lib/sfu/selectiveTileTransport.ts');
  requireContains(helper, 'export function planSelectiveTilePatch', 'selective tile planner');
  requireContains(helper, 'export function planBackgroundSnapshotPatch', 'background snapshot planner');
  requireContains(helper, "layoutMode: 'tile_foreground'", 'selective tile planner layout mode');
  requireContains(helper, "layoutMode: 'background_snapshot'", 'background snapshot planner layout mode');
  requireContains(helper, 'patchAreaRatio', 'selective tile planner patch area guard');
  requireContains(helper, 'tileMatchesLayoutMask', 'mask-aware tile classification helper');
  requireContains(helper, 'matteMaskImageData?: ImageData | null', 'mask-aware planner options');

  const workspace = read('src/domain/realtime/CallWorkspaceView.vue');
  const runtimeConfig = read('src/domain/realtime/workspace/callWorkspace/runtimeConfig.js');
  const publisherPipeline = read('src/domain/realtime/local/publisherPipeline.js');
  const frameDecode = read('src/domain/realtime/sfu/frameDecode.js');

  requireContains(runtimeConfig, 'export const SFU_SELECTIVE_TILE_PATCH_ENABLED = true', 'workspace selective tile switch');
  requireContains(runtimeConfig, 'export const SFU_BACKGROUND_SNAPSHOT_ENABLED = true', 'workspace background snapshot switch');
  requireContains(workspace, 'SFU_SELECTIVE_TILE_PATCH_ENABLED', 'workspace imports selective tile runtime switch');
  requireContains(workspace, 'SFU_BACKGROUND_SNAPSHOT_ENABLED', 'workspace imports background snapshot runtime switch');
  requireContains(publisherPipeline, 'planSelectiveTilePatch(imageData, previousFullFrameImageData', 'workspace selective patch send planning');
  requireContains(publisherPipeline, 'planBackgroundSnapshotPatch(imageData, previousFullFrameImageData', 'workspace background snapshot send planning');
  requireContains(publisherPipeline, 'backgroundFilterController.getCurrentMatteMaskSnapshot()', 'workspace sender reads current matte mask snapshot');
  requireContains(publisherPipeline, 'ensureSelectivePatchEncoder', 'workspace selective patch encoder path');
  requireContains(publisherPipeline, 'layoutMode: tilePatchMetadata.layoutMode', 'workspace outgoing frame carries tile patch metadata');
  requireContains(publisherPipeline, "layoutMode: 'full_frame'", 'workspace full-frame sends carry explicit layout metadata');
  requireContains(publisherPipeline, 'cacheEpoch: outgoingCacheEpoch', 'workspace full-frame sends carry cache epoch');
  requireContains(frameDecode, 'ensureSfuRemotePatchDecoderForFrame', 'workspace remote patch decoder path');
  requireContains(frameDecode, "layoutMode === 'tile_foreground' || layoutMode === 'background_snapshot'", 'workspace remote selective patch render branch');
  requireContains(frameDecode, 'function invalidateRemoteSfuTrackCache', 'workspace remote cache invalidation helper');
  requireContains(frameDecode, 'function resetRemoteSfuDecoderAfterSequenceGap', 'workspace sequence gaps preserve remote render cache');
  requireContains(frameDecode, 'sequence_gap_delta', 'workspace drops gap deltas without clearing the composited tile base');
  requireContains(frameDecode, 'function shouldDropRemoteSfuFrameForCacheEpoch', 'workspace remote cache epoch guard');
  requireContains(frameDecode, "cache_epoch_mismatch", 'workspace logs cache epoch mismatch drops');
  requireContains(frameDecode, 'function ensureRemoteSfuTrackRenderLayers', 'workspace remote layered render cache helper');
  requireContains(frameDecode, 'function composeRemoteSfuTrackLayers', 'workspace remote layer compositing helper');
  requireContains(frameDecode, 'trackRenderState.foregroundLayerActive = false', 'workspace clears stale foreground layer before recomposite');
  requireContains(frameDecode, "frameMetadata.type === 'keyframe' && !isSelectiveTileFrame", 'selective patch keyframes must not clear full-frame keyframe wait');

  const queue = read('src/lib/sfu/outboundFrameQueue.ts');
  requireContains(queue, 'SFU_FRAME_SEND_QUEUE_BACKGROUND_SNAPSHOT_MAX_AGE_MS', 'background snapshot queue max age');
  requireContains(queue, 'dropQueuedBackgroundSnapshots', 'background snapshot queue prioritization');
  requireContains(queue, 'background_snapshot_deprioritized', 'background snapshot queue deprioritization reason');

  process.stdout.write('[sfu-selective-tile-runtime-contract] PASS\n');
} catch (error) {
  if (error instanceof Error) {
    fail(error.message);
  }
  fail('unknown failure');
}
