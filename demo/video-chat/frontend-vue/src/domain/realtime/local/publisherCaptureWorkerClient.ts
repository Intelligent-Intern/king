import { PUBLISHER_CAPTURE_BACKENDS } from './capturePipelineCapabilities.ts';

export function canUsePublisherCaptureWorker(capabilities = {}) {
  return Boolean(
    capabilities.supportsMediaStreamTrackProcessor
      && capabilities.supportsOffscreenCanvas
      && capabilities.supportsOffscreenCanvas2d
      && capabilities.supportsOffscreenCanvasTransfer
      && capabilities.supportsWorker,
  );
}

export function preferredCaptureWorkerBackend(capabilities = {}) {
  if (!canUsePublisherCaptureWorker(capabilities)) {
    return PUBLISHER_CAPTURE_BACKENDS.DOM_CANVAS_FALLBACK;
  }
  return capabilities.supportsVideoFrameCopyTo && capabilities.supportsVideoFrameClose
    ? PUBLISHER_CAPTURE_BACKENDS.VIDEO_FRAME_COPY
    : PUBLISHER_CAPTURE_BACKENDS.OFFSCREEN_CANVAS_WORKER;
}

export function createPublisherCaptureWorker({
  WorkerCtor = typeof Worker !== 'undefined' ? Worker : null,
  workerUrl = new URL('./publisherCaptureWorker.ts', import.meta.url),
  name = 'kingrt-publisher-capture-worker',
} = {}) {
  if (typeof WorkerCtor !== 'function') {
    throw new Error('publisher_capture_worker_unsupported');
  }
  return new WorkerCtor(workerUrl, {
    type: 'module',
    name,
  });
}
