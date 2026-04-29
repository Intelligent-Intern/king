import {
  buildPublisherCaptureWorkerInitMessage,
  buildPublisherCaptureWorkerReadbackMessage,
  PUBLISHER_CAPTURE_WORKER_MESSAGE_TYPES,
  publisherCaptureWorkerTransferListForInit,
  publisherCaptureWorkerTransferListForReadback,
} from './publisherCaptureWorkerProtocol.js';
import {
  canUsePublisherCaptureWorker,
  createPublisherCaptureWorker,
} from './publisherCaptureWorkerClient.js';

function imageDataFromWorkerPayload(payload = {}, ImageDataCtor = globalThis?.ImageData) {
  if (typeof ImageDataCtor !== 'function') {
    throw new Error('publisher_capture_worker_image_data_missing');
  }
  const frameWidth = Math.max(0, Number(payload.frameWidth || 0));
  const frameHeight = Math.max(0, Number(payload.frameHeight || 0));
  if (!frameWidth || !frameHeight || !payload.rgba) {
    throw new Error('publisher_capture_worker_result_malformed');
  }
  const rgba = payload.rgba instanceof Uint8ClampedArray
    ? payload.rgba
    : new Uint8ClampedArray(payload.rgba);
  return new ImageDataCtor(rgba, frameWidth, frameHeight);
}

function workerFrameSizeFromPayload(payload = {}) {
  return {
    frameWidth: Math.max(1, Number(payload.frameWidth || 1)),
    frameHeight: Math.max(1, Number(payload.frameHeight || 1)),
    profileFrameWidth: Math.max(0, Number(payload.profileFrameWidth || 0)),
    profileFrameHeight: Math.max(0, Number(payload.profileFrameHeight || 0)),
    sourceWidth: Math.max(0, Number(payload.sourceWidth || 0)),
    sourceHeight: Math.max(0, Number(payload.sourceHeight || 0)),
    sourceCropX: Math.max(0, Number(payload.sourceCropX || 0)),
    sourceCropY: Math.max(0, Number(payload.sourceCropY || 0)),
    sourceCropWidth: Math.max(0, Number(payload.sourceCropWidth || 0)),
    sourceCropHeight: Math.max(0, Number(payload.sourceCropHeight || 0)),
    sourceAspectRatio: Math.max(0, Number(payload.sourceAspectRatio || 0)),
    targetAspectRatio: Math.max(0, Number(payload.targetAspectRatio || 0)),
    framingMode: String(payload.framingMode || 'contain'),
    aspectMode: String(payload.aspectMode || 'contain'),
  };
}

export function createPublisherCaptureWorkerReadbackController({
  capabilities = {},
  WorkerCtor = globalThis?.Worker,
  workerUrl,
  ImageDataCtor = globalThis?.ImageData,
  timeoutMs = 1200,
  closeGraceMs = 250,
  mediaDebugLog = () => {},
} = {}) {
  if (!canUsePublisherCaptureWorker(capabilities)) {
    return null;
  }

  let generation = 0;
  let closed = false;
  let worker = null;
  const pending = new Map();

  function settlePending(requestId, result) {
    const request = pending.get(requestId);
    if (!request) return;
    pending.delete(requestId);
    if (request.timeoutId) clearTimeout(request.timeoutId);
    request.resolve(result);
  }

  function handleWorkerMessage(event) {
    const payload = event?.data && typeof event.data === 'object' ? event.data : {};
    const requestId = String(payload.requestId || '');
    if (!requestId) return;
    if (payload.type === PUBLISHER_CAPTURE_WORKER_MESSAGE_TYPES.READBACK_RESULT) {
      try {
        const imageData = imageDataFromWorkerPayload(payload, ImageDataCtor);
        settlePending(requestId, {
          ok: true,
          imageData,
          frameSize: workerFrameSizeFromPayload(payload),
          drawImageMs: Math.max(0, Number(payload.drawImageMs || 0)),
          readbackMs: Math.max(0, Number(payload.readbackMs || 0)),
          workerElapsedMs: Math.max(0, Number(payload.workerElapsedMs || 0)),
          readbackBytes: imageData.data.byteLength,
        });
      } catch (error) {
        settlePending(requestId, {
          ok: false,
          fatal: true,
          reason: 'publisher_capture_worker_result_failed',
          error,
        });
      }
      return;
    }
    if (payload.type === PUBLISHER_CAPTURE_WORKER_MESSAGE_TYPES.ERROR) {
      settlePending(requestId, {
        ok: false,
        fatal: true,
        reason: String(payload.reason || 'publisher_capture_worker_error'),
      });
    }
  }

  function ensureWorker() {
    if (worker || closed) return worker;
    worker = createPublisherCaptureWorker({ WorkerCtor, workerUrl });
    if (typeof worker.addEventListener === 'function') {
      worker.addEventListener('message', handleWorkerMessage);
    } else {
      worker.onmessage = handleWorkerMessage;
    }
    const initMessage = buildPublisherCaptureWorkerInitMessage({ generation });
    worker.postMessage(initMessage, publisherCaptureWorkerTransferListForInit(initMessage));
    return worker;
  }

  async function readFrame({
    source,
    frameSize,
    timestamp = Date.now(),
    timeout = timeoutMs,
  } = {}) {
    if (closed) {
      return { ok: false, fatal: true, reason: 'publisher_capture_worker_closed' };
    }
    if (!source || !frameSize) {
      return { ok: false, fatal: true, reason: 'publisher_capture_worker_source_missing' };
    }

    let activeWorker = null;
    try {
      activeWorker = ensureWorker();
    } catch (error) {
      return {
        ok: false,
        fatal: true,
        reason: 'publisher_capture_worker_start_failed',
        error,
      };
    }
    const message = buildPublisherCaptureWorkerReadbackMessage({
      source,
      generation,
      sourceWidth: frameSize.sourceWidth,
      sourceHeight: frameSize.sourceHeight,
      sourceCropX: frameSize.sourceCropX,
      sourceCropY: frameSize.sourceCropY,
      sourceCropWidth: frameSize.sourceCropWidth,
      sourceCropHeight: frameSize.sourceCropHeight,
      framingMode: frameSize.framingMode,
      targetAspectRatio: frameSize.targetAspectRatio,
      profileFrameWidth: frameSize.profileFrameWidth || frameSize.frameWidth,
      profileFrameHeight: frameSize.profileFrameHeight || frameSize.frameHeight,
      timestamp,
    });

    return new Promise((resolve) => {
      const requestId = message.requestId;
      const timeoutId = setTimeout(() => {
        generation += 1;
        pending.delete(requestId);
        resolve({ ok: false, fatal: true, reason: 'publisher_capture_worker_timeout' });
      }, Math.max(300, Number(timeout || timeoutMs || 1200)));
      pending.set(requestId, { resolve, timeoutId });
      try {
        activeWorker.postMessage(message, publisherCaptureWorkerTransferListForReadback(message));
      } catch (error) {
        clearTimeout(timeoutId);
        pending.delete(requestId);
        resolve({
          ok: false,
          fatal: true,
          reason: 'publisher_capture_worker_post_message_failed',
          error,
        });
      }
    });
  }

  function close() {
    if (closed) return;
    closed = true;
    for (const [requestId, request] of pending.entries()) {
      if (request.timeoutId) clearTimeout(request.timeoutId);
      request.resolve({ ok: false, fatal: true, reason: 'publisher_capture_worker_closed' });
      pending.delete(requestId);
    }
    if (worker) {
      const closingWorker = worker;
      try {
        if (typeof closingWorker.removeEventListener === 'function') {
          closingWorker.removeEventListener('message', handleWorkerMessage);
        }
      } catch {
        // Worker listener cleanup is best-effort during call teardown.
      }
      try {
        closingWorker.postMessage({ type: PUBLISHER_CAPTURE_WORKER_MESSAGE_TYPES.CLOSE });
      } catch {
        // Terminate below when close cannot be delivered.
      }
      if (typeof closingWorker.terminate === 'function') {
        setTimeout(() => {
          try {
            closingWorker.terminate();
          } catch (error) {
            mediaDebugLog('[SFU] capture worker termination failed', error);
          }
        }, Math.max(0, Number(closeGraceMs || 0)));
      }
    }
    worker = null;
  }

  return {
    readFrame,
    close,
    get active() {
      return Boolean(worker && !closed);
    },
  };
}
