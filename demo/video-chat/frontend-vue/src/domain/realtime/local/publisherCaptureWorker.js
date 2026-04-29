import { PUBLISHER_CAPTURE_WORKER_MESSAGE_TYPES } from './publisherCaptureWorkerProtocol.js';
import { resolveContainFrameSizeFromDimensions } from './videoFrameSizing.js';

let workerGeneration = 0;
let captureCanvas = null;
let captureContext = null;

function highResolutionNowMs() {
  return typeof performance !== 'undefined' && typeof performance.now === 'function' ? performance.now() : Date.now();
}

function roundedMs(value) {
  const normalized = Number(value || 0);
  return Number.isFinite(normalized) ? Number(Math.max(0, normalized).toFixed(3)) : 0;
}

function positiveNumber(value) {
  const normalized = Number(value || 0);
  return Number.isFinite(normalized) && normalized > 0 ? normalized : 0;
}

function sourceDimension(source, keys) {
  for (const key of keys) {
    const value = positiveNumber(source?.[key]);
    if (value > 0) return value;
  }
  return 0;
}

function sourceDimensions(source, payload = {}) {
  return {
    width: positiveNumber(payload.sourceWidth)
      || sourceDimension(source, ['displayWidth', 'codedWidth', 'videoWidth', 'width']),
    height: positiveNumber(payload.sourceHeight)
      || sourceDimension(source, ['displayHeight', 'codedHeight', 'videoHeight', 'height']),
  };
}

function closeFrameSource(source) {
  if (source && typeof source.close === 'function') {
    try {
      source.close();
    } catch {
      // Source close failures must not hide the readback result.
    }
  }
}

function postCaptureWorkerError(requestId, error, extra = {}) {
  self.postMessage({
    type: PUBLISHER_CAPTURE_WORKER_MESSAGE_TYPES.ERROR,
    requestId: String(requestId || ''),
    generation: workerGeneration,
    reason: String(error?.message || error || 'publisher_capture_worker_error'),
    ...extra,
  });
}

function adoptCaptureCanvas(canvas) {
  if (!canvas || typeof canvas.getContext !== 'function') {
    throw new Error('publisher_capture_worker_canvas_missing');
  }
  captureCanvas = canvas;
  captureContext = captureCanvas.getContext('2d', {
    willReadFrequently: true,
    alpha: false,
  });
  if (!captureContext || typeof captureContext.drawImage !== 'function' || typeof captureContext.getImageData !== 'function') {
    throw new Error('publisher_capture_worker_2d_context_missing');
  }
}

function ensureCaptureCanvas(frameWidth, frameHeight) {
  if (!captureCanvas) {
    if (typeof OffscreenCanvas !== 'function') {
      throw new Error('publisher_capture_worker_offscreen_canvas_missing');
    }
    adoptCaptureCanvas(new OffscreenCanvas(frameWidth, frameHeight));
  }
  if (captureCanvas.width !== frameWidth) captureCanvas.width = frameWidth;
  if (captureCanvas.height !== frameHeight) captureCanvas.height = frameHeight;
  return captureContext;
}

function resolveWorkerFrameSize(source, payload = {}) {
  const dimensions = sourceDimensions(source, payload);
  const profileFrameWidth = positiveNumber(payload.profileFrameWidth) || positiveNumber(payload.frameWidth);
  const profileFrameHeight = positiveNumber(payload.profileFrameHeight) || positiveNumber(payload.frameHeight);
  return {
    ...resolveContainFrameSizeFromDimensions(
      dimensions.width,
      dimensions.height,
      profileFrameWidth,
      profileFrameHeight,
    ),
    profileFrameWidth,
    profileFrameHeight,
  };
}

async function handleReadback(payload = {}) {
  const requestId = String(payload.requestId || '');
  const source = payload.source || payload.bitmap || payload.videoFrame || null;
  if (!source) throw new Error('publisher_capture_worker_source_missing');
  const startedAtMs = highResolutionNowMs();

  let drawImageMs = 0;
  let readbackMs = 0;
  let imageData = null;
  let frameSize = null;
  try {
    frameSize = resolveWorkerFrameSize(source, payload);
    const context = ensureCaptureCanvas(frameSize.frameWidth, frameSize.frameHeight);

    const drawStartedAtMs = highResolutionNowMs();
    context.drawImage(source, 0, 0, frameSize.frameWidth, frameSize.frameHeight);
    drawImageMs = roundedMs(highResolutionNowMs() - drawStartedAtMs);

    const readbackStartedAtMs = highResolutionNowMs();
    imageData = context.getImageData(0, 0, frameSize.frameWidth, frameSize.frameHeight);
    readbackMs = roundedMs(highResolutionNowMs() - readbackStartedAtMs);
  } finally {
    closeFrameSource(source);
  }

  self.postMessage({
    type: PUBLISHER_CAPTURE_WORKER_MESSAGE_TYPES.READBACK_RESULT,
    requestId,
    generation: Math.max(0, Number(payload.generation || workerGeneration || 0)),
    timestamp: Math.max(0, Number(payload.timestamp || Date.now())),
    frameWidth: frameSize.frameWidth,
    frameHeight: frameSize.frameHeight,
    profileFrameWidth: frameSize.profileFrameWidth,
    profileFrameHeight: frameSize.profileFrameHeight,
    sourceWidth: frameSize.sourceWidth,
    sourceHeight: frameSize.sourceHeight,
    sourceAspectRatio: Number(frameSize.sourceAspectRatio.toFixed(6)),
    aspectMode: frameSize.aspectMode,
    rgba: imageData.data,
    drawImageMs,
    readbackMs,
    workerElapsedMs: roundedMs(highResolutionNowMs() - startedAtMs),
  }, [imageData.data.buffer]);
}

self.addEventListener('message', (event) => {
  const payload = event?.data && typeof event.data === 'object' ? event.data : {};
  const requestId = String(payload.requestId || '');
  try {
    switch (payload.type) {
      case PUBLISHER_CAPTURE_WORKER_MESSAGE_TYPES.INIT:
        workerGeneration = Math.max(0, Number(payload.generation || 0));
        if (payload.canvas) adoptCaptureCanvas(payload.canvas);
        break;
      case PUBLISHER_CAPTURE_WORKER_MESSAGE_TYPES.READBACK:
        void handleReadback(payload).catch((error) => postCaptureWorkerError(requestId, error));
        break;
      case PUBLISHER_CAPTURE_WORKER_MESSAGE_TYPES.RESET:
        workerGeneration += 1;
        captureCanvas = null;
        captureContext = null;
        break;
      case PUBLISHER_CAPTURE_WORKER_MESSAGE_TYPES.CLOSE:
        self.close();
        break;
      default:
        postCaptureWorkerError(requestId, 'publisher_capture_worker_unknown_message');
    }
  } catch (error) {
    postCaptureWorkerError(requestId, error);
  }
});
