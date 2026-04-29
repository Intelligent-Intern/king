import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';

function fail(message) {
  throw new Error(`[sfu-offscreen-canvas-fallback-contract] FAIL: ${message}`);
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
  const workerReadbackSource = read('src/domain/realtime/local/publisherCaptureWorkerReadback.js');
  const sourceReadback = read('src/domain/realtime/local/publisherSourceReadback.js');
  const videoFrameSource = read('src/domain/realtime/local/publisherVideoFrameSource.js');
  const publisherFrameTrace = read('src/domain/realtime/local/publisherFrameTrace.js');
  const packageJson = read('package.json');

  requireContains(workerReadbackSource, 'createPublisherCaptureWorkerReadbackController', 'worker readback controller export');
  requireContains(workerReadbackSource, 'buildPublisherCaptureWorkerReadbackMessage({', 'worker readback message builder');
  requireContains(workerReadbackSource, 'publisherCaptureWorkerTransferListForReadback(message)', 'worker readback transfers frame source');
  requireContains(workerReadbackSource, 'new ImageDataCtor(rgba, frameWidth, frameHeight)', 'worker readback reconstructs ImageData');
  requireContains(workerReadbackSource, 'publisher_capture_worker_timeout', 'worker readback timeout recovery');
  requireContains(workerReadbackSource, 'publisher_capture_worker_post_message_failed', 'worker readback postMessage fallback reason');
  assert.equal(workerReadbackSource.includes("from 'vue'"), false, 'worker readback helper must not import Vue');

  requireContains(sourceReadback, 'createPublisherCaptureWorkerReadbackController({', 'source readback creates capture worker controller');
  requireContains(sourceReadback, 'offscreen_canvas_worker_readback', 'source readback labels worker readback method');
  requireContains(sourceReadback, "'offscreen_worker_draw_image'", 'source readback traces worker draw timing');
  requireContains(sourceReadback, "'offscreen_worker_get_image_data'", 'source readback traces worker getImageData timing');
  requireContains(sourceReadback, "'offscreen_worker_round_trip'", 'source readback traces worker round trip timing');
  assert.ok(
    sourceReadback.indexOf('copyVideoFrameToRgbaImageData({') < sourceReadback.indexOf('captureWorkerReadback.readFrame({'),
    'VideoFrame copyTo must remain first choice before worker fallback',
  );
  assert.ok(
    sourceReadback.indexOf('captureWorkerReadback.readFrame({') < sourceReadback.indexOf('context.getImageData('),
    'worker fallback must run before main-thread canvas getImageData fallback',
  );

  requireContains(videoFrameSource, 'supportsVideoFrameClose', 'VideoFrame source keeps explicit close support');
  assert.equal(
    videoFrameSource.includes('&& capabilities.supportsVideoFrameCopyTo'),
    false,
    'VideoFrame source must remain available for copyless OffscreenCanvas worker fallback',
  );

  requireContains(publisherFrameTrace, 'trace_offscreen_worker_draw_image_ms', 'trace exposes worker draw timing');
  requireContains(publisherFrameTrace, 'trace_offscreen_worker_get_image_data_ms', 'trace exposes worker readback timing');
  requireContains(publisherFrameTrace, 'trace_offscreen_worker_round_trip_ms', 'trace exposes worker round trip timing');
  requireContains(packageJson, 'sfu-offscreen-canvas-fallback-contract.mjs', 'SFU contract suite includes worker fallback proof');

  const moduleUrl = pathToFileURL(path.resolve(frontendRoot, 'src/domain/realtime/local/publisherCaptureWorkerReadback.js')).href;
  const workerModule = await import(moduleUrl);

  class FakeImageData {
    constructor(data, width, height) {
      this.data = data;
      this.width = width;
      this.height = height;
    }
  }

  const posted = [];
  class FakeWorker {
    constructor(url, options) {
      this.url = String(url);
      this.options = options;
      this.listeners = [];
    }

    addEventListener(type, listener) {
      assert.equal(type, 'message');
      this.listeners.push(listener);
    }

    removeEventListener(type, listener) {
      assert.equal(type, 'message');
      this.listeners = this.listeners.filter((candidate) => candidate !== listener);
    }

    postMessage(message, transfers = []) {
      posted.push({ message, transfers });
      if (message.type !== 'kingrt/publisher-capture-worker/readback') return;
      assert.equal(transfers.length, 1);
      queueMicrotask(() => {
        this.listeners.forEach((listener) => listener({
          data: {
            type: 'kingrt/publisher-capture-worker/readback-result',
            requestId: message.requestId,
            frameWidth: 2,
            frameHeight: 2,
            profileFrameWidth: 2,
            profileFrameHeight: 2,
            sourceWidth: 2,
            sourceHeight: 2,
            sourceAspectRatio: 1,
            aspectMode: 'contain',
            rgba: new Uint8ClampedArray(16).fill(23),
            drawImageMs: 1.25,
            readbackMs: 0.75,
            workerElapsedMs: 2.5,
          },
        }));
      });
    }

    terminate() {}
  }

  const controller = workerModule.createPublisherCaptureWorkerReadbackController({
    capabilities: {
      supportsMediaStreamTrackProcessor: true,
      supportsOffscreenCanvas: true,
      supportsOffscreenCanvas2d: true,
      supportsOffscreenCanvasTransfer: true,
      supportsWorker: true,
    },
    WorkerCtor: FakeWorker,
    workerUrl: 'fake-worker.js',
    ImageDataCtor: FakeImageData,
    timeoutMs: 100,
  });

  assert.ok(controller, 'worker readback controller should be created for worker-capable browsers');
  const source = { close() {} };
  const result = await controller.readFrame({
    source,
    frameSize: {
      frameWidth: 2,
      frameHeight: 2,
      profileFrameWidth: 2,
      profileFrameHeight: 2,
      sourceWidth: 2,
      sourceHeight: 2,
    },
    timestamp: 123,
  });
  assert.equal(result.ok, true);
  assert.equal(result.imageData.width, 2);
  assert.equal(result.imageData.height, 2);
  assert.equal(result.imageData.data[0], 23);
  assert.equal(result.drawImageMs, 1.25);
  assert.equal(result.readbackMs, 0.75);
  assert.equal(result.workerElapsedMs, 2.5);
  assert.equal(posted[0].message.type, 'kingrt/publisher-capture-worker/init');
  assert.equal(posted[1].message.type, 'kingrt/publisher-capture-worker/readback');
  assert.deepEqual(posted[1].transfers, [source]);
  controller.close();

  process.stdout.write('[sfu-offscreen-canvas-fallback-contract] PASS\n');
} catch (error) {
  if (error instanceof Error) {
    fail(error.message);
  }
  fail('unknown failure');
}
