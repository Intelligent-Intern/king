import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';

function fail(message) {
  throw new Error(`[sfu-capture-pipeline-capabilities-contract] FAIL: ${message}`);
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

function fakeDocument({ readback = true } = {}) {
  return {
    createElement(tagName) {
      assert.equal(tagName, 'canvas');
      return {
        getContext(type) {
          assert.equal(type, '2d');
          return {
            drawImage() {},
            ...(readback ? { getImageData() {} } : {}),
          };
        },
      };
    },
  };
}

function fakeOffscreenCanvas({ context = true } = {}) {
  return class OffscreenCanvas {
    constructor(width, height) {
      this.width = width;
      this.height = height;
    }

    getContext(type) {
      assert.equal(type, '2d');
      return context ? { drawImage() {}, getImageData() {} } : null;
    }
  };
}

class FakeMessageChannel {
  constructor() {
    this.port1 = {
      postMessage() {},
      close() {},
    };
    this.port2 = {
      close() {},
    };
  }
}

class BrokenTransferMessageChannel {
  constructor() {
    this.port1 = {
      postMessage() {
        throw new Error('transfer_failed');
      },
      close() {},
    };
    this.port2 = {
      close() {},
    };
  }
}

class FakeVideoFrame {
  copyTo() {}
  close() {}
}

class CopylessVideoFrame {
  close() {}
}

try {
  const detectorSource = read('src/domain/realtime/local/capturePipelineCapabilities.ts');
  const mediaOrchestration = read('src/domain/realtime/local/mediaOrchestration.ts');
  const captureProfileConstraints = read('src/domain/realtime/local/sfuCaptureProfileConstraints.ts');
  requireContains(detectorSource, 'MediaStreamTrackProcessor', 'MediaStreamTrackProcessor detection');
  requireContains(detectorSource, "prototypeMethod(VideoFrameCtor, 'copyTo')", 'VideoFrame.copyTo detection');
  requireContains(detectorSource, "prototypeMethod(VideoFrameCtor, 'close')", 'VideoFrame.close detection');
  requireContains(detectorSource, 'OffscreenCanvas', 'OffscreenCanvas detection');
  requireContains(detectorSource, 'MessageChannel', 'worker transfer detection');
  requireContains(detectorSource, 'supportsDomCanvasFallback', 'DOM canvas fallback detection');
  requireContains(detectorSource, 'publisherCaptureCapabilityDiagnosticPayload', 'diagnostic payload helper');
  requireContains(mediaOrchestration, "from './sfuCaptureProfileConstraints'", 'local media orchestration imports capture profile enforcement');
  requireContains(captureProfileConstraints, "from './capturePipelineCapabilities'", 'capture profile enforcement imports capture capability detector');
  requireContains(captureProfileConstraints, 'detectPublisherCapturePipelineCapabilities()', 'local capture diagnostics probe browser capability state');
  requireContains(captureProfileConstraints, 'publisherCaptureCapabilityDiagnosticPayload(captureCapabilities)', 'local capture diagnostics include capability payload');

  const moduleUrl = pathToFileURL(path.resolve(frontendRoot, 'src/domain/realtime/local/capturePipelineCapabilities.ts')).href;
  const {
    PUBLISHER_CAPTURE_BACKENDS,
    detectPublisherCapturePipelineCapabilities,
    publisherCaptureCapabilityDiagnosticPayload,
  } = await import(moduleUrl);

  const full = detectPublisherCapturePipelineCapabilities({
    globalScope: {
      MediaStreamTrackProcessor: class MediaStreamTrackProcessor {},
      VideoFrame: FakeVideoFrame,
      OffscreenCanvas: fakeOffscreenCanvas(),
      Worker: class Worker {},
      MessageChannel: FakeMessageChannel,
    },
    documentRef: fakeDocument(),
  });
  assert.equal(full.supportsMediaStreamTrackProcessor, true);
  assert.equal(full.supportsVideoFrameCopyTo, true);
  assert.equal(full.supportsVideoFrameClose, true);
  assert.equal(full.supportsOffscreenCanvas2d, true);
  assert.equal(full.supportsOffscreenCanvasTransfer, true);
  assert.equal(full.supportsDomCanvasFallback, true);
  assert.equal(full.preferredCaptureBackend, PUBLISHER_CAPTURE_BACKENDS.VIDEO_FRAME_COPY);
  assert.equal(full.hasWorkerCapturePath, true);
  assert.equal(full.hasAnyCapturePath, true);

  const workerFallback = detectPublisherCapturePipelineCapabilities({
    globalScope: {
      MediaStreamTrackProcessor: class MediaStreamTrackProcessor {},
      VideoFrame: CopylessVideoFrame,
      OffscreenCanvas: fakeOffscreenCanvas(),
      Worker: class Worker {},
      MessageChannel: FakeMessageChannel,
    },
    documentRef: fakeDocument(),
  });
  assert.equal(workerFallback.supportsVideoFrameCopyTo, false);
  assert.equal(workerFallback.preferredCaptureBackend, PUBLISHER_CAPTURE_BACKENDS.OFFSCREEN_CANVAS_WORKER);

  const domFallback = detectPublisherCapturePipelineCapabilities({
    globalScope: {
      MediaStreamTrackProcessor: class MediaStreamTrackProcessor {},
      VideoFrame: CopylessVideoFrame,
      OffscreenCanvas: fakeOffscreenCanvas(),
      Worker: class Worker {},
      MessageChannel: BrokenTransferMessageChannel,
    },
    documentRef: fakeDocument(),
  });
  assert.equal(domFallback.supportsOffscreenCanvasTransfer, false);
  assert.equal(domFallback.preferredCaptureBackend, PUBLISHER_CAPTURE_BACKENDS.DOM_CANVAS_FALLBACK);

  const unsupported = detectPublisherCapturePipelineCapabilities({
    globalScope: {},
    documentRef: null,
  });
  assert.equal(unsupported.preferredCaptureBackend, PUBLISHER_CAPTURE_BACKENDS.UNSUPPORTED);
  assert.equal(unsupported.hasAnyCapturePath, false);

  const diagnostic = publisherCaptureCapabilityDiagnosticPayload(workerFallback);
  assert.equal(diagnostic.capture_backend, PUBLISHER_CAPTURE_BACKENDS.OFFSCREEN_CANVAS_WORKER);
  assert.equal(diagnostic.supports_media_stream_track_processor, true);
  assert.equal(diagnostic.supports_video_frame_copy_to, false);
  assert.equal(diagnostic.supports_offscreen_canvas_transfer, true);
  assert.equal(diagnostic.supports_dom_canvas_fallback, true);

  process.stdout.write('[sfu-capture-pipeline-capabilities-contract] PASS\n');
} catch (error) {
  if (error instanceof Error) {
    fail(error.message);
  }
  fail('unknown failure');
}
