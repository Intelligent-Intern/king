import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';

function fail(message) {
  throw new Error(`[sfu-video-frame-primary-path-contract] FAIL: ${message}`);
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

class FakeVideoFrame {
  constructor() {
    this.displayWidth = 720;
    this.displayHeight = 1280;
    this.closed = false;
  }

  copyTo() {}

  close() {
    this.closed = true;
  }
}

try {
  const videoFrameSource = read('src/domain/realtime/local/publisherVideoFrameSource.js');
  const sourceReadback = read('src/domain/realtime/local/publisherSourceReadback.js');
  const publisherPipeline = read('src/domain/realtime/local/publisherPipeline.js');
  const packageJson = read('package.json');

  requireContains(videoFrameSource, 'new MediaStreamTrackProcessorCtor({ track: videoTrack, maxBufferSize: 1 })', 'VideoFrame source constructs bounded track processor');
  requireContains(videoFrameSource, 'processor.readable.getReader()', 'VideoFrame source locks readable stream');
  requireContains(videoFrameSource, 'reader.read()', 'VideoFrame source pulls camera frames');
  requireContains(videoFrameSource, 'publisher_video_frame_read_timeout', 'VideoFrame source has timeout recovery');
  requireContains(videoFrameSource, 'frame.close()', 'VideoFrame source closes consumed frames');
  requireContains(videoFrameSource, 'readPromise.then(closePublisherVideoFrameReadResult)', 'VideoFrame source closes late frames after timeout');
  requireContains(videoFrameSource, 'closePendingReadResults()', 'VideoFrame source closes late frames after manual close');
  requireContains(videoFrameSource, 'publisher_video_frame_read_failed', 'VideoFrame source converts read failures into fatal recovery');
  assert.equal(videoFrameSource.includes("from 'vue'"), false, 'VideoFrame source must not import Vue');
  assert.equal(videoFrameSource.includes('document.createElement'), false, 'VideoFrame source must not create DOM canvas');

  requireContains(sourceReadback, 'createPublisherVideoFrameSourceReader', 'source readback imports VideoFrame source reader');
  requireContains(sourceReadback, 'ensureVideoFrameReader(', 'source readback recreates the VideoFrame reader after transient stalls');
  requireContains(sourceReadback, 'VIDEO_FRAME_READER_RETRY_COOLDOWN_MS', 'source readback keeps transient reader failures on the primary path');
  requireContains(sourceReadback, 'VideoFrame source reader failed; retrying processor path before DOM canvas fallback', 'source readback does not permanently demote to DOM canvas after one reader timeout');
  requireContains(sourceReadback, 'PUBLISHER_VIDEO_FRAME_SOURCE_BACKEND', 'source readback labels VideoFrame backend');
  requireContains(sourceReadback, 'context.drawImage(source, 0, 0, canvas.width, canvas.height)', 'source readback draws generic frame source, not only video element');
  requireContains(sourceReadback, 'video_frame_processor_read', 'source readback traces processor reads');
  requireContains(sourceReadback, 'video_frame_canvas_draw_image', 'source readback traces VideoFrame canvas draw stage');
  requireContains(sourceReadback, 'video_frame_canvas_get_image_data', 'source readback traces VideoFrame canvas readback stage');
  requireContains(sourceReadback, 'closePublisherVideoFrame(result.frame)', 'source readback closes VideoFrame after readback');

  requireContains(publisherPipeline, "from './publisherSourceReadback'", 'publisher pipeline imports source readback controller');
  requireContains(publisherPipeline, 'createPublisherSourceReadbackController({', 'publisher pipeline creates source readback controller');
  requireContains(publisherPipeline, 'sourceReadbackController.readFrame({', 'publisher pipeline reads from source controller');
  assert.equal(publisherPipeline.includes('ctx.drawImage(video'), false, 'publisher pipeline must not draw the video element directly');
  assert.equal(publisherPipeline.includes('ctx.getImageData'), false, 'publisher pipeline must not own DOM readback directly');

  requireContains(packageJson, 'sfu-video-frame-primary-path-contract.mjs', 'SFU contract suite includes VideoFrame primary path proof');

  const sourceUrl = pathToFileURL(path.resolve(frontendRoot, 'src/domain/realtime/local/publisherVideoFrameSource.js')).href;
  const sourceModule = await import(sourceUrl);

  assert.equal(sourceModule.canUsePublisherVideoFrameSource({
    supportsMediaStreamTrackProcessor: true,
    supportsVideoFrame: true,
    supportsVideoFrameCopyTo: true,
    supportsVideoFrameClose: true,
  }), true);

  const frames = [new FakeVideoFrame()];
  class FakeMediaStreamTrackProcessor {
    constructor(input) {
      assert.equal(input.track.id, 'track-1');
      this.readable = {
        getReader() {
          return {
            async read() {
              return { done: false, value: frames.shift() };
            },
            async cancel() {},
            releaseLock() {},
          };
        },
      };
    }
  }

  const reader = sourceModule.createPublisherVideoFrameSourceReader({
    videoTrack: { id: 'track-1' },
    MediaStreamTrackProcessorCtor: FakeMediaStreamTrackProcessor,
    readTimeoutMs: 100,
  });
  const result = await reader.readFrame({ timeoutMs: 100 });
  assert.equal(result.ok, true);
  assert.equal(result.sourceBackend, sourceModule.PUBLISHER_VIDEO_FRAME_SOURCE_BACKEND);
  sourceModule.closePublisherVideoFrame(result.frame);
  assert.equal(result.frame.closed, true);
  await reader.close();

  const lateFrame = new FakeVideoFrame();
  let cancelReason = '';
  class SlowMediaStreamTrackProcessor {
    constructor(input) {
      assert.equal(input.track.id, 'track-late');
      this.readable = {
        getReader() {
          return {
            read() {
              return new Promise((resolve) => {
                setTimeout(() => resolve({ done: false, value: lateFrame }), 20);
              });
            },
            async cancel(reason) {
              cancelReason = String(reason || '');
            },
            releaseLock() {},
          };
        },
      };
    }
  }

  const slowReader = sourceModule.createPublisherVideoFrameSourceReader({
    videoTrack: { id: 'track-late' },
    MediaStreamTrackProcessorCtor: SlowMediaStreamTrackProcessor,
    readTimeoutMs: 1,
  });
  const timeoutResult = await slowReader.readFrame({ timeoutMs: 1 });
  assert.equal(timeoutResult.ok, false);
  assert.equal(timeoutResult.reason, 'publisher_video_frame_read_timeout');
  assert.equal(cancelReason, 'publisher_video_frame_read_timeout');
  await new Promise((resolve) => setTimeout(resolve, 30));
  assert.equal(lateFrame.closed, true, 'late VideoFrame returned after timeout must be closed');
  await slowReader.close();

  const closeLateFrame = new FakeVideoFrame();
  let closeCancelReason = '';
  class CloseDuringReadMediaStreamTrackProcessor {
    constructor(input) {
      assert.equal(input.track.id, 'track-close');
      assert.equal(input.maxBufferSize, 1);
      this.readable = {
        getReader() {
          return {
            read() {
              return new Promise((resolve) => {
                setTimeout(() => resolve({ done: false, value: closeLateFrame }), 20);
              });
            },
            async cancel(reason) {
              closeCancelReason = String(reason || '');
            },
            releaseLock() {},
          };
        },
      };
    }
  }

  const closeReader = sourceModule.createPublisherVideoFrameSourceReader({
    videoTrack: { id: 'track-close' },
    MediaStreamTrackProcessorCtor: CloseDuringReadMediaStreamTrackProcessor,
    readTimeoutMs: 100,
  });
  const closeReadResultPromise = closeReader.readFrame({ timeoutMs: 100 });
  await closeReader.close('manual_pipeline_close');
  const closeReadResult = await closeReadResultPromise;
  assert.equal(closeReadResult.ok, false);
  assert.equal(closeReadResult.reason, 'publisher_video_frame_source_closed');
  assert.equal(closeCancelReason, 'manual_pipeline_close');
  assert.equal(closeLateFrame.closed, true, 'late VideoFrame returned after reader close must be closed');

  process.stdout.write('[sfu-video-frame-primary-path-contract] PASS\n');
} catch (error) {
  if (error instanceof Error) {
    fail(error.message);
  }
  fail('unknown failure');
}
