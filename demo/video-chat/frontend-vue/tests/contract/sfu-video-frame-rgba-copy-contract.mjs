import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';

function fail(message) {
  throw new Error(`[sfu-video-frame-rgba-copy-contract] FAIL: ${message}`);
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
  const copySource = read('src/domain/realtime/local/publisherVideoFrameCopy.ts');
  const sourceReadback = read('src/domain/realtime/local/publisherSourceReadback.ts');
  const publisherFrameTrace = read('src/domain/realtime/local/publisherFrameTrace.ts');
  const packageJson = read('package.json');

  requireContains(copySource, 'await frame.copyTo(rgba, { format: \'RGBA\' })', 'VideoFrame RGBA copyTo hotpath');
  requireContains(copySource, 'new ImageDataCtor(new Uint8ClampedArray', 'VideoFrame copy produces ImageData for WLVC');
  requireContains(copySource, 'resolveVideoFrameCopyFrameSize', 'VideoFrame copy exposes source-dimension copy sizing');
  requireContains(copySource, "aspectMode: 'video_frame_copy_source'", 'VideoFrame copy labels source-dimension copies');
  requireContains(copySource, 'publisher_video_frame_copy_scale_required', 'VideoFrame copy refuses unscaled mismatches');
  requireContains(copySource, 'publisher_video_frame_copy_to_rgba_failed', 'VideoFrame copy reports fatal copyTo errors');
  assert.equal(copySource.includes('getImageData'), false, 'VideoFrame copy helper must not use canvas getImageData');
  assert.equal(copySource.includes('drawImage'), false, 'VideoFrame copy helper must not use canvas drawImage');

  requireContains(sourceReadback, 'copyVideoFrameToRgbaImageData({', 'source readback attempts VideoFrame copy before canvas fallback');
  requireContains(sourceReadback, 'const copyFrameSize = resolveVideoFrameCopyFrameSize(source, frameSize) || frameSize', 'source readback copies source dimensions before canvas fallback');
  requireContains(sourceReadback, 'video_frame_copy_to_rgba', 'source readback labels direct VideoFrame RGBA readback');
  requireContains(sourceReadback, 'video_frame_copy_to_rgba_scaled', 'source readback scales copied VideoFrames into the active profile without transferring them');
  requireContains(sourceReadback, 'video_frame_copy_scale_draw_image', 'source readback traces copied VideoFrame scale draw timing');
  requireContains(sourceReadback, 'video_frame_copy_scale_get_image_data', 'source readback traces copied VideoFrame scale readback timing');
  requireContains(sourceReadback, 'video_frame_copy_to_budget_exceeded', 'source readback budgets VideoFrame copyTo');
  assert.ok(
    sourceReadback.indexOf('copyVideoFrameToRgbaImageData({') < sourceReadback.indexOf('const imageData = context.getImageData('),
    'VideoFrame copyTo must run before DOM canvas getImageData fallback',
  );

  requireContains(publisherFrameTrace, 'trace_video_frame_copy_to_rgba_ms', 'publisher trace exposes VideoFrame copyTo timing');
  requireContains(packageJson, 'sfu-video-frame-rgba-copy-contract.mjs', 'SFU contract suite includes VideoFrame RGBA copy proof');

  const copyUrl = pathToFileURL(path.resolve(frontendRoot, 'src/domain/realtime/local/publisherVideoFrameCopy.ts')).href;
  const copyModule = await import(copyUrl);

  class FakeImageData {
    constructor(data, width, height) {
      this.data = data;
      this.width = width;
      this.height = height;
    }
  }

  const copiedDestinations = [];
  const frame = {
    displayWidth: 2,
    displayHeight: 2,
    async copyTo(destination, options) {
      assert.equal(options.format, 'RGBA');
      copiedDestinations.push(destination);
      destination.fill(17);
    },
  };

  const copied = await copyModule.copyVideoFrameToRgbaImageData({
    frame,
    frameSize: { frameWidth: 2, frameHeight: 2 },
    ImageDataCtor: FakeImageData,
  });
  assert.equal(copied.ok, true);
  assert.equal(copied.imageData.width, 2);
  assert.equal(copied.imageData.height, 2);
  assert.equal(copied.imageData.data.length, 16);
  assert.equal(copied.imageData.data[0], 17);
  assert.equal(copied.readbackBytes, 16);
  assert.equal(copiedDestinations.length, 1);

  const sourceSized = copyModule.resolveVideoFrameCopyFrameSize(frame, {
    frameWidth: 1,
    frameHeight: 1,
    profileFrameWidth: 1,
    profileFrameHeight: 1,
  });
  assert.equal(sourceSized.frameWidth, 2);
  assert.equal(sourceSized.frameHeight, 2);
  assert.equal(sourceSized.sourceWidth, 2);
  assert.equal(sourceSized.sourceHeight, 2);
  assert.equal(sourceSized.profileFrameWidth, 1);
  assert.equal(sourceSized.profileFrameHeight, 1);
  assert.equal(sourceSized.aspectMode, 'video_frame_copy_source');

  const scaled = await copyModule.copyVideoFrameToRgbaImageData({
    frame,
    frameSize: { frameWidth: 1, frameHeight: 1 },
    ImageDataCtor: FakeImageData,
  });
  assert.equal(scaled.ok, false);
  assert.equal(scaled.reason, 'publisher_video_frame_copy_scale_required');

  process.stdout.write('[sfu-video-frame-rgba-copy-contract] PASS\n');
} catch (error) {
  if (error instanceof Error) {
    fail(error.message);
  }
  fail('unknown failure');
}
