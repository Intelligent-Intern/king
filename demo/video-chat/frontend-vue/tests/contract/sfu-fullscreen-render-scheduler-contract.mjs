import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { pathToFileURL, fileURLToPath } from 'node:url';

function fail(message) {
  throw new Error(`[sfu-fullscreen-render-scheduler-contract] FAIL: ${message}`);
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

async function main() {
  const packageJson = read('package.json');
  const schedulerSource = read('src/domain/realtime/sfu/remoteRenderScheduler.js');
  const videoLayout = read('src/domain/realtime/workspace/callWorkspace/videoLayout.js');
  const frameDecode = read('src/domain/realtime/sfu/frameDecode.js');
  const browserRenderer = read('src/domain/realtime/sfu/remoteBrowserEncodedVideo.js');
  const remotePeers = read('src/domain/realtime/sfu/remotePeers.js');
  const stageCss = read('src/domain/realtime/CallWorkspaceStage.css');

  requireContains(packageJson, 'sfu-fullscreen-render-scheduler-contract.mjs', 'SFU contract suite includes fullscreen render scheduler proof');

  requireContains(schedulerSource, 'REMOTE_RENDER_SURFACE_ROLES', 'scheduler declares explicit remote surface roles');
  requireContains(schedulerSource, 'applyRemoteVideoSurfaceRole', 'scheduler exposes DOM mount role binding');
  requireContains(schedulerSource, 'shouldDecodeRemoteFrame', 'scheduler protects browser decoder queues');
  requireContains(schedulerSource, 'shouldRenderRemoteFrame', 'scheduler exposes render cadence decision');
  requireContains(schedulerSource, 'markRemoteFrameRendered', 'scheduler records newest complete rendered frame');
  requireContains(schedulerSource, '[REMOTE_RENDER_SURFACE_ROLES.FULLSCREEN]: 0', 'fullscreen render path is never thumbnail-throttled');
  requireContains(schedulerSource, '[REMOTE_RENDER_SURFACE_ROLES.MAIN]: 0', 'main render path is never thumbnail-throttled');
  requireContains(schedulerSource, '[REMOTE_RENDER_SURFACE_ROLES.GRID]: 50', 'grid thumbnails have bounded render cadence');
  requireContains(schedulerSource, '[REMOTE_RENDER_SURFACE_ROLES.MINI]: 100', 'mini thumbnails have bounded render cadence');

  requireContains(videoLayout, "from '../../sfu/remoteRenderScheduler'", 'layout imports render surface role helper');
  requireContains(videoLayout, 'role: REMOTE_RENDER_SURFACE_ROLES.GRID', 'grid slots mark remote render role');
  requireContains(videoLayout, 'role: REMOTE_RENDER_SURFACE_ROLES.MINI', 'mini slots mark remote render role');
  requireContains(videoLayout, 'REMOTE_RENDER_SURFACE_ROLES.FULLSCREEN', 'main-only layout marks fullscreen render role');
  requireContains(videoLayout, 'applyRemoteVideoSurfaceRole(node', 'mount path binds role before DOM replacement');

  requireContains(frameDecode, "from './remoteRenderScheduler'", 'WLVC decoder imports render scheduler');
  assert.ok(
    frameDecode.indexOf('shouldRenderRemoteFrame(peer, frame, renderedAtMs)') < frameDecode.indexOf('new ImageData(decoded.data'),
    'WLVC receiver must skip non-priority canvas writes before allocating ImageData',
  );
  requireContains(frameDecode, 'markRemoteFrameRendered(peer, frame, renderedAtMs)', 'WLVC receiver records rendered sequence/timestamp');
  requireContains(frameDecode, 'sfu_receiver_render_scheduled_skip', 'WLVC receiver reports scheduled render skips to backend diagnostics');

  requireContains(browserRenderer, 'shouldDecodeRemoteFrame(peer, frame, Number(decoder.decodeQueueSize || 0))', 'browser renderer protects WebCodecs decode queue');
  requireContains(browserRenderer, 'shouldRenderRemoteFrame(peer, frame, renderedAtMs)', 'browser renderer uses surface-aware render scheduler');
  requireContains(browserRenderer, 'markRemoteFrameRendered(peer, frame, renderedAtMs)', 'browser renderer records rendered browser frames');
  requireContains(browserRenderer, 'sfu_browser_decoder_scheduled_skip', 'browser renderer reports scheduler skips to backend diagnostics');
  assert.equal(browserRenderer.includes('await decoder.flush();'), false, 'browser renderer must not serialize every decoded frame with per-frame flush');

  requireContains(remotePeers, 'remoteRenderStateByTrack: {}', 'remote peer continuity state includes render scheduler state');
  requireContains(remotePeers, 'existingPeer.remoteRenderStateByTrack', 'remote peer updates preserve render scheduler state');

  requireContains(stageCss, '[data-call-video-surface-role="main"]', 'main surface role has explicit CSS presentation');
  requireContains(stageCss, '[data-call-video-surface-role="fullscreen"]', 'fullscreen surface role has explicit CSS presentation');
  requireContains(stageCss, 'object-position: center center !important;', 'remote surfaces center letterboxed media');

  const schedulerUrl = pathToFileURL(path.resolve(frontendRoot, 'src/domain/realtime/sfu/remoteRenderScheduler.js')).href;
  const scheduler = await import(schedulerUrl);
  const node = { dataset: {} };
  assert.equal(
    scheduler.applyRemoteVideoSurfaceRole(node, { role: scheduler.REMOTE_RENDER_SURFACE_ROLES.FULLSCREEN, userId: 7, layoutMode: 'main_only' }),
    true,
    'fake DOM node must accept fullscreen role',
  );
  assert.equal(node.dataset.callVideoSurfaceRole, 'fullscreen', 'fullscreen role must be stored on dataset');
  assert.equal(node.dataset.callVideoRenderPriority, '4', 'fullscreen priority must outrank thumbnails');
  assert.equal(node.dataset.callVideoSurfaceUserId, '7', 'surface role binding must keep user id');

  const peer = { decodedCanvas: node, remoteRenderStateByTrack: {} };
  scheduler.markRemoteFrameRendered(peer, { trackId: 'camera', frameSequence: 10, timestamp: 1000 }, 1_000);
  assert.equal(
    scheduler.shouldRenderRemoteFrame(peer, { trackId: 'camera', type: 'delta', frameSequence: 11, timestamp: 1010 }, 1_001).render,
    true,
    'fullscreen deltas must render immediately',
  );

  scheduler.applyRemoteVideoSurfaceRole(node, { role: scheduler.REMOTE_RENDER_SURFACE_ROLES.MINI, userId: 7, layoutMode: 'main_mini' });
  scheduler.markRemoteFrameRendered(peer, { trackId: 'camera', frameSequence: 12, timestamp: 1020 }, 2_000);
  const miniDecision = scheduler.shouldRenderRemoteFrame(peer, { trackId: 'camera', type: 'delta', frameSequence: 13, timestamp: 1030 }, 2_020);
  assert.equal(miniDecision.render, false, 'mini delta frames inside cadence budget must skip render');
  assert.equal(miniDecision.reason, 'surface_render_throttle', 'mini skip reason must be explicit');
  assert.equal(
    scheduler.shouldRenderRemoteFrame(peer, { trackId: 'camera', type: 'keyframe', frameSequence: 14, timestamp: 1040 }, 2_021).render,
    true,
    'keyframes must not be skipped by thumbnail cadence',
  );
  assert.equal(
    scheduler.shouldDecodeRemoteFrame(peer, { trackId: 'camera', type: 'delta' }, 3).decode,
    false,
    'mini decode queue pressure must drop deltas before WebCodecs backlog grows',
  );

  process.stdout.write('[sfu-fullscreen-render-scheduler-contract] PASS\n');
}

main().catch((error) => {
  if (error instanceof Error) {
    fail(error.message);
  }
  fail('unknown failure');
});

