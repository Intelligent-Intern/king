import {
  buildPublisherCaptureWorkerInitMessage,
  buildPublisherCaptureWorkerReadbackMessage,
  PUBLISHER_CAPTURE_WORKER_MESSAGE_TYPES,
  isPublisherCaptureWorkerErrorMessage,
  isPublisherCaptureWorkerReadbackResultMessage,
  type PublisherCaptureWorkerOutboundMessage,
  type PublisherCaptureWorkerReadbackResultMessage,
  publisherCaptureWorkerTransferListForInit,
  publisherCaptureWorkerTransferListForReadback,
} from './publisherCaptureWorkerProtocol.ts';
import {
  canUsePublisherCaptureWorker,
  createPublisherCaptureWorker,
  type PublisherCaptureWorkerCapabilities,
  type PublisherCaptureWorkerCtor,
} from './publisherCaptureWorkerClient.ts';

export interface PublisherCaptureWorkerFrameSize {
  frameWidth: number;
  frameHeight: number;
  profileFrameWidth?: number;
  profileFrameHeight?: number;
  sourceWidth?: number;
  sourceHeight?: number;
  sourceCropX?: number;
  sourceCropY?: number;
  sourceCropWidth?: number;
  sourceCropHeight?: number;
  sourceAspectRatio?: number;
  targetAspectRatio?: number;
  framingMode?: string;
  aspectMode?: string;
}

export interface PublisherCaptureWorkerReadbackResult {
  ok: boolean;
  fatal?: boolean;
  reason?: string;
  error?: unknown;
  imageData?: ImageData;
  frameSize?: PublisherCaptureWorkerFrameSize;
  drawImageMs?: number;
  readbackMs?: number;
  workerElapsedMs?: number;
  readbackBytes?: number;
}

export interface PublisherCaptureWorkerReadbackController {
  readFrame(options: {
    source: Transferable;
    frameSize: PublisherCaptureWorkerFrameSize;
    timestamp?: number;
    timeout?: number;
  }): Promise<PublisherCaptureWorkerReadbackResult>;
  reset(): void;
  close(): void;
}

interface PendingReadbackRequest {
  timeoutId: ReturnType<typeof setTimeout> | null;
  resolve: (result: PublisherCaptureWorkerReadbackResult) => void;
}

export interface CreatePublisherCaptureWorkerReadbackControllerOptions {
  capabilities?: PublisherCaptureWorkerCapabilities;
  WorkerCtor?: PublisherCaptureWorkerCtor | null;
  workerUrl?: URL | string;
  ImageDataCtor?: typeof ImageData;
  timeoutMs?: number;
  closeGraceMs?: number;
  mediaDebugLog?: (message: string, payload?: Record<string, unknown>) => void;
}

function imageDataFromWorkerPayload(
  payload: Partial<PublisherCaptureWorkerReadbackResultMessage> = {},
  ImageDataCtor = globalThis?.ImageData,
): ImageData {
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

function workerFrameSizeFromPayload(payload: Partial<PublisherCaptureWorkerReadbackResultMessage> = {}): PublisherCaptureWorkerFrameSize {
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
}: CreatePublisherCaptureWorkerReadbackControllerOptions = {}): PublisherCaptureWorkerReadbackController | null {
  if (!canUsePublisherCaptureWorker(capabilities)) {
    return null;
  }

  let generation = 0;
  let closed = false;
  let worker: Worker | null = null;
  const pending = new Map<string, PendingReadbackRequest>();

  function settlePending(requestId: string, result: PublisherCaptureWorkerReadbackResult): void {
    const request = pending.get(requestId);
    if (!request) return;
    pending.delete(requestId);
    if (request.timeoutId) clearTimeout(request.timeoutId);
    request.resolve(result);
  }

  function handleWorkerMessage(event: MessageEvent<PublisherCaptureWorkerOutboundMessage>): void {
    const payload = event?.data && typeof event.data === 'object' ? event.data : {};
    const requestId = String(payload.requestId || '');
    if (!requestId) return;
    if (isPublisherCaptureWorkerReadbackResultMessage(payload)) {
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
    if (isPublisherCaptureWorkerErrorMessage(payload)) {
      settlePending(requestId, {
        ok: false,
        fatal: true,
        reason: String(payload.reason || 'publisher_capture_worker_error'),
      });
    }
  }

  function ensureWorker(): Worker | null {
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
  }: Parameters<PublisherCaptureWorkerReadbackController['readFrame']>[0]): Promise<PublisherCaptureWorkerReadbackResult> {
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
