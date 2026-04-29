import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';

function fail(message) {
  throw new Error(`[sfu-zero-copy-readback-gate-contract] FAIL: ${message}`);
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
  const packageJson = read('package.json');
  const sourceReadback = read('src/domain/realtime/local/publisherSourceReadback.js');
  const videoFrameCopy = read('src/domain/realtime/local/publisherVideoFrameCopy.js');

  requireContains(packageJson, 'sfu-zero-copy-readback-gate-contract.mjs', 'SFU contract suite includes zero-copy readback gate');
  requireContains(sourceReadback, 'ZERO_COPY_CAPTURE_GATE_STAGE', 'source readback defines zero-copy capture gate stage');
  requireContains(sourceReadback, 'video_frame_main_thread_canvas_blocked', 'source readback emits exact zero-copy gate failure source');
  requireContains(sourceReadback, 'zeroCopyCaptureGateRequired(sourceBackend, captureCapabilities)', 'source readback checks the gate before DOM canvas fallback');
  assert.ok(
    sourceReadback.indexOf('zeroCopyCaptureGateRequired(sourceBackend, captureCapabilities)') < sourceReadback.indexOf('context.drawImage(source'),
    'zero-copy gate must run before any main-thread canvas drawImage fallback',
  );
  requireContains(videoFrameCopy, 'resolveVideoFrameCopyFrameSize', 'copy helper can align VideoFrame copy to source dimensions');

  const copyModuleUrl = pathToFileURL(path.resolve(frontendRoot, 'src/domain/realtime/local/publisherVideoFrameCopy.js')).href;
  const copyModule = await import(copyModuleUrl);
  const frame = {
    displayWidth: 960,
    displayHeight: 540,
    async copyTo(destination, options) {
      assert.equal(options.format, 'RGBA');
      destination.fill(7);
    },
  };

  class FakeImageData {
    constructor(data, width, height) {
      this.data = data;
      this.width = width;
      this.height = height;
    }
  }

  const copyFrameSize = copyModule.resolveVideoFrameCopyFrameSize(frame, {
    frameWidth: 640,
    frameHeight: 360,
    profileFrameWidth: 640,
    profileFrameHeight: 360,
  });
  assert.equal(copyFrameSize.frameWidth, 960, 'copy frame size should use actual VideoFrame width');
  assert.equal(copyFrameSize.frameHeight, 540, 'copy frame size should use actual VideoFrame height');
  assert.equal(copyFrameSize.profileFrameWidth, 640, 'profile width remains observable');
  assert.equal(copyFrameSize.profileFrameHeight, 360, 'profile height remains observable');

  const copied = await copyModule.copyVideoFrameToRgbaImageData({
    frame,
    frameSize: copyFrameSize,
    ImageDataCtor: FakeImageData,
  });
  assert.equal(copied.ok, true, 'source-sized VideoFrame copy must stay on the copyTo path');
  assert.equal(copied.imageData.width, 960);
  assert.equal(copied.imageData.height, 540);
  assert.equal(copied.readbackBytes, 960 * 540 * 4);

  process.stdout.write('[sfu-zero-copy-readback-gate-contract] PASS\n');
} catch (error) {
  if (error instanceof Error) {
    fail(error.message);
  }
  fail('unknown failure');
}
