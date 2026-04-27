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
  requireContains(workspace, 'SFU_SELECTIVE_TILE_PATCH_ENABLED = true', 'workspace selective tile switch');
  requireContains(workspace, 'SFU_BACKGROUND_SNAPSHOT_ENABLED = true', 'workspace background snapshot switch');
  requireContains(workspace, 'planSelectiveTilePatch(imageData, previousFullFrameImageData', 'workspace selective patch send planning');
  requireContains(workspace, 'planBackgroundSnapshotPatch(imageData, previousFullFrameImageData', 'workspace background snapshot send planning');
  requireContains(workspace, 'backgroundFilterController.getCurrentMatteMaskSnapshot()', 'workspace sender reads current matte mask snapshot');
  requireContains(workspace, 'ensureSelectivePatchEncoder', 'workspace selective patch encoder path');
  requireContains(workspace, 'layoutMode: tilePatchMetadata.layoutMode', 'workspace outgoing frame carries tile patch metadata');
  requireContains(workspace, "layoutMode: 'full_frame'", 'workspace full-frame sends carry explicit layout metadata');
  requireContains(workspace, 'cacheEpoch: outgoingCacheEpoch', 'workspace full-frame sends carry cache epoch');
  requireContains(workspace, 'ensureSfuRemotePatchDecoderForFrame', 'workspace remote patch decoder path');
  requireContains(workspace, "layoutMode === 'tile_foreground' || layoutMode === 'background_snapshot'", 'workspace remote selective patch render branch');
  requireContains(workspace, 'function invalidateRemoteSfuTrackCache', 'workspace remote cache invalidation helper');
  requireContains(workspace, 'function shouldDropRemoteSfuFrameForCacheEpoch', 'workspace remote cache epoch guard');
  requireContains(workspace, "cache_epoch_mismatch", 'workspace logs cache epoch mismatch drops');
  requireContains(workspace, 'function ensureRemoteSfuTrackRenderLayers', 'workspace remote layered render cache helper');
  requireContains(workspace, 'function composeRemoteSfuTrackLayers', 'workspace remote layer compositing helper');
  requireContains(workspace, 'trackRenderState.foregroundLayerActive = false', 'workspace clears stale foreground layer before recomposite');

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
