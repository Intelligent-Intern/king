import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { createServer } from 'vite';

function fail(message) {
  throw new Error(`[sfu-portrait-aspect-preservation-contract] FAIL: ${message}`);
}

function requireContains(source, needle, label) {
  assert.ok(source.includes(needle), `${label} missing: ${needle}`);
}

function requireCssRuleContains(source, selector, expected, label) {
  const selectorIndex = source.indexOf(selector);
  assert.notEqual(selectorIndex, -1, `${label} selector missing: ${selector}`);
  const blockEnd = source.indexOf('}', selectorIndex);
  assert.notEqual(blockEnd, -1, `${label} block missing: ${selector}`);
  const block = source.slice(selectorIndex, blockEnd);
  assert.ok(block.includes(expected), `${label} missing ${expected}`);
  assert.ok(!block.includes('object-fit: cover'), `${label} must not crop portrait media`);
}

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);
const frontendRoot = path.resolve(__dirname, '../..');

function read(relativePath) {
  return fs.readFileSync(path.resolve(frontendRoot, relativePath), 'utf8');
}

function assertAspectClose(actualWidth, actualHeight, sourceWidth, sourceHeight, label) {
  const actual = actualWidth / actualHeight;
  const expected = sourceWidth / sourceHeight;
  assert.ok(Math.abs(actual - expected) < 0.015, `${label} aspect ratio ${actual} must preserve source ${expected}`);
}

async function main() {
  const packageJson = read('package.json');
  const videoFrameSizing = read('src/domain/realtime/local/videoFrameSizing.js');
  const captureWorker = read('src/domain/realtime/local/publisherCaptureWorker.js');
  const publisherFrameTrace = read('src/domain/realtime/local/publisherFrameTrace.js');
  const framePayload = read('src/lib/sfu/framePayload.ts');
  const frameDecode = read('src/domain/realtime/sfu/frameDecode.js');
  const remoteCanvas = read('src/domain/realtime/sfu/remoteCanvas.js');
  const remotePeers = read('src/domain/realtime/sfu/remotePeers.js');
  const stageCss = read('src/domain/realtime/CallWorkspaceStage.css');

  requireContains(packageJson, 'sfu-portrait-aspect-preservation-contract.mjs', 'SFU contract suite includes portrait preservation proof');
  requireContains(videoFrameSizing, 'source_contain', 'publisher frame sizing preserves source aspect');
  requireContains(captureWorker, 'sourceAspectRatio: Number(frameSize.sourceAspectRatio.toFixed(6))', 'worker returns source aspect ratio');
  requireContains(captureWorker, 'aspectMode: frameSize.aspectMode', 'worker returns aspect mode');
  requireContains(publisherFrameTrace, 'source_aspect_ratio: Number(frameSize.sourceAspectRatio.toFixed(6))', 'publisher transport metrics keep aspect ratio');
  requireContains(publisherFrameTrace, 'publisher_aspect_mode: frameSize.aspectMode', 'publisher transport metrics keep aspect mode');
  requireContains(framePayload, "['frame_width', source.frame_width ?? source.frameWidth]", 'SFU payload preserves encoded frame width');
  requireContains(framePayload, "['frame_height', source.frame_height ?? source.frameHeight]", 'SFU payload preserves encoded frame height');
  requireContains(frameDecode, 'readWlvcFrameMetadata(frameData', 'receiver reads WLVC metadata width and height');
  requireContains(frameDecode, 'resizeCanvasPreservingFrame(peer.decodedCanvas, nextWidth, nextHeight)', 'receiver decoder resize preserves current frame');
  requireContains(frameDecode, 'resizeCanvas(canvas, decoded.width, decoded.height)', 'receiver canvas matches decoded portrait dimensions');
  requireContains(remoteCanvas, 'Math.min(nextWidth / previousWidth, nextHeight / previousHeight)', 'remote canvas resize uses contain scale');
  requireContains(remoteCanvas, 'offsetX', 'remote canvas resize centers horizontal letterbox');
  requireContains(remoteCanvas, 'offsetY', 'remote canvas resize centers vertical letterbox');
  requireContains(remotePeers, "canvas.className = 'remote-video'", 'SFU remote canvas receives remote-video class');
  requireCssRuleContains(stageCss, '.video-container :deep(video),', 'object-fit: contain !important;', 'main video surface');
  requireCssRuleContains(stageCss, '.workspace-grid-video-slot :deep(video),', 'object-fit: contain !important;', 'grid video surface');
  requireContains(stageCss, '[data-call-video-framing-mode="cover"]', 'portrait or square targets use explicit framing mode instead of CSS stretching');

  const server = await createServer({
    root: frontendRoot,
    logLevel: 'error',
    server: { middlewareMode: true, hmr: false },
  });

  try {
    const config = await server.ssrLoadModule('/src/domain/realtime/workspace/config.js');
    const sizing = await server.ssrLoadModule('/src/domain/realtime/local/videoFrameSizing.js');
    const domPolicy = await server.ssrLoadModule('/src/domain/realtime/local/domCanvasFallbackPolicy.js');
    const portraitSource = { width: 1080, height: 1920 };
    const landscapeSource = { width: 1920, height: 1080 };

    for (const [profileId, profile] of Object.entries(config.SFU_VIDEO_QUALITY_PROFILES)) {
      const portrait = sizing.resolveContainFrameSizeFromDimensions(
        portraitSource.width,
        portraitSource.height,
        profile.frameWidth,
        profile.frameHeight,
      );
      assert.ok(portrait.frameHeight <= profile.frameHeight, `${profileId} portrait height must stay inside profile`);
      assert.ok(portrait.frameWidth < portrait.frameHeight, `${profileId} portrait output must remain portrait`);
      assert.equal(portrait.aspectMode, 'source_contain', `${profileId} portrait must use contain sizing`);
      assertAspectClose(portrait.frameWidth, portrait.frameHeight, portraitSource.width, portraitSource.height, `${profileId} portrait`);

      const landscape = sizing.resolveContainFrameSizeFromDimensions(
        landscapeSource.width,
        landscapeSource.height,
        profile.frameWidth,
        profile.frameHeight,
      );
      assert.ok(landscape.frameWidth <= profile.frameWidth, `${profileId} landscape width must stay inside profile`);
      assert.ok(landscape.frameWidth > landscape.frameHeight, `${profileId} landscape output must remain landscape`);
      assertAspectClose(landscape.frameWidth, landscape.frameHeight, landscapeSource.width, landscapeSource.height, `${profileId} landscape`);
    }

    const domPortrait = domPolicy.resolveDomCanvasCompatibilityVideoFrameSize({
      displayWidth: portraitSource.width,
      displayHeight: portraitSource.height,
    }, config.SFU_VIDEO_QUALITY_PROFILES.quality);
    assert.ok(domPortrait.frameWidth <= domPolicy.DOM_CANVAS_COMPATIBILITY_MAX_FRAME_WIDTH, 'DOM portrait fallback width must stay capped');
    assert.ok(domPortrait.frameHeight <= domPolicy.DOM_CANVAS_COMPATIBILITY_MAX_FRAME_HEIGHT, 'DOM portrait fallback height must stay capped');
    assert.ok(domPortrait.frameWidth < domPortrait.frameHeight, 'DOM portrait fallback must remain portrait');
    assertAspectClose(domPortrait.frameWidth, domPortrait.frameHeight, portraitSource.width, portraitSource.height, 'DOM portrait fallback');

    process.stdout.write('[sfu-portrait-aspect-preservation-contract] PASS\n');
  } finally {
    await server.close();
  }
}

main().catch((error) => {
  if (error instanceof Error) {
    fail(error.message);
  }
  fail('unknown failure');
});
