import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { pathToFileURL, fileURLToPath } from 'node:url';

function fail(message) {
  throw new Error(`[sfu-client-side-framing-crop-contract] FAIL: ${message}`);
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
  const template = read('src/domain/realtime/CallWorkspaceView.template.html');
  const stageCss = read('src/domain/realtime/CallWorkspaceStage.css');
  const videoLayout = read('src/domain/realtime/workspace/callWorkspace/videoLayout.js');
  const fullscreenToggle = read('src/domain/realtime/workspace/callWorkspace/videoFullscreenToggle.js');
  const participantUi = read('src/domain/realtime/workspace/callWorkspace/participantUi.js');
  const frameSizing = read('src/domain/realtime/local/videoFrameSizing.js');
  const sourceReadback = read('src/domain/realtime/local/publisherSourceReadback.js');
  const worker = read('src/domain/realtime/local/publisherCaptureWorker.js');
  const workerProtocol = read('src/domain/realtime/local/publisherCaptureWorkerProtocol.js');
  const workerReadback = read('src/domain/realtime/local/publisherCaptureWorkerReadback.js');

  requireContains(packageJson, 'sfu-client-side-framing-crop-contract.mjs', 'SFU contract suite includes framing crop proof');

  requireContains(template, '@dblclick.stop="toggleVideoFullscreen(primaryVideoUserId)"', 'main video double-click fullscreen toggle');
  requireContains(template, '@dblclick.stop="toggleVideoFullscreen(participant.userId)"', 'grid and mini video double-click fullscreen toggle');
  requireContains(fullscreenToggle, 'createVideoFullscreenToggle', 'fullscreen toggle helper stays outside CallWorkspaceView');
  requireContains(fullscreenToggle, "callLayoutState.mode = 'main_only'", 'double-click enters main-only fullscreen layout');
  requireContains(fullscreenToggle, "callLayoutState.mode = nextMode === 'main_only' ? 'main_mini' : nextMode", 'second double-click exits fullscreen');
  requireContains(participantUi, "from './videoFullscreenToggle'", 'participant UI uses focused fullscreen helper');

  requireContains(videoLayout, 'targetAspectRatioForSurface', 'layout computes target surface aspect ratio');
  requireContains(videoLayout, "role === REMOTE_RENDER_SURFACE_ROLES.MINI", 'mini surfaces are explicitly square framing targets');
  requireContains(videoLayout, 'framingMode: framing.framingMode', 'layout passes framing mode into surface role binding');
  requireContains(videoLayout, 'targetAspectRatio: framing.targetAspectRatio', 'layout passes target aspect into surface role binding');

  requireContains(stageCss, '[data-call-video-framing-mode="cover"]', 'CSS honors cover framing dataset');
  requireContains(stageCss, '.workspace-mini-video-slot :deep(video),', 'mini slot media CSS exists');
  requireContains(stageCss, 'object-fit: cover !important;', 'mini and cover-framed surfaces zoom/crop without stretching');

  requireContains(frameSizing, 'resolveCoverFrameSizeFromDimensions', 'publisher frame sizing has cover-crop mode');
  requireContains(frameSizing, "aspectMode: 'source_cover_crop'", 'cover-crop mode is explicit in transport metrics');
  requireContains(frameSizing, 'resolvePublisherFramingTarget', 'publisher reads layout framing dataset before encode');
  requireContains(sourceReadback, 'drawSourceFrame(context, source, canvasFrameSize', 'DOM canvas readback crops before encode');
  requireContains(sourceReadback, 'sourceCropForFrameSize', 'source readback carries source crop math');
  requireContains(workerProtocol, 'sourceCropX', 'worker protocol carries crop rectangle');
  requireContains(workerReadback, 'sourceCropX: frameSize.sourceCropX', 'worker readback sends crop rectangle');
  requireContains(worker, 'context.drawImage(', 'worker draws source into cropped output');
  requireContains(worker, 'crop.x', 'worker uses crop x before readback');

  const sizingUrl = pathToFileURL(path.resolve(frontendRoot, 'src/domain/realtime/local/videoFrameSizing.js')).href;
  const sizing = await import(sizingUrl);
  const landscape = sizing.resolveCoverFrameSizeFromDimensions(1920, 1080, 1280, 720, 1);
  assert.equal(landscape.frameWidth, 720, 'landscape-to-square output must use square width within profile height');
  assert.equal(landscape.frameHeight, 720, 'landscape-to-square output must use square height');
  assert.equal(landscape.sourceCropX, 420, 'landscape-to-square crop must remove equal side bands');
  assert.equal(landscape.sourceCropY, 0, 'landscape-to-square crop must keep full source height');
  assert.equal(landscape.sourceCropWidth, 1080, 'landscape-to-square crop width must match visible square');
  assert.equal(landscape.sourceCropHeight, 1080, 'landscape-to-square crop height must match visible square');
  assert.equal(landscape.aspectMode, 'source_cover_crop', 'square target must report cover crop mode');

  const portraitTarget = sizing.resolveCoverFrameSizeFromDimensions(1920, 1080, 1280, 720, 9 / 16);
  assert.equal(portraitTarget.frameWidth, 404, 'landscape-to-portrait output must spend bytes on visible portrait width');
  assert.equal(portraitTarget.frameHeight, 720, 'landscape-to-portrait output must use full profile height');
  assert.ok(portraitTarget.sourceCropWidth < 700, 'portrait crop must discard most side bands before encode');
  assert.ok(portraitTarget.sourceCropX > 600, 'portrait crop must be centered');

  const contain = sizing.resolveFramedFrameSizeFromDimensions(1920, 1080, 1280, 720, { mode: 'contain' });
  assert.equal(contain.frameWidth, 1280, 'contain mode must keep landscape fullscreen width');
  assert.equal(contain.frameHeight, 720, 'contain mode must keep landscape fullscreen height');
  assert.equal(contain.sourceCropX, 0, 'contain mode must not crop source x');
  assert.equal(contain.aspectMode, 'source_contain', 'contain mode must remain available for fullscreen landscape');

  process.stdout.write('[sfu-client-side-framing-crop-contract] PASS\n');
}

main().catch((error) => {
  if (error instanceof Error) {
    fail(error.message);
  }
  fail('unknown failure');
});
