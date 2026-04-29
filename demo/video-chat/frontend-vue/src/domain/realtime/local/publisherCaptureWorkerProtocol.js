export const PUBLISHER_CAPTURE_WORKER_MESSAGE_TYPES = Object.freeze({
  INIT: 'kingrt/publisher-capture-worker/init',
  READBACK: 'kingrt/publisher-capture-worker/readback',
  READBACK_RESULT: 'kingrt/publisher-capture-worker/readback-result',
  ERROR: 'kingrt/publisher-capture-worker/error',
  RESET: 'kingrt/publisher-capture-worker/reset',
  CLOSE: 'kingrt/publisher-capture-worker/close',
});

let publisherCaptureWorkerRequestSequence = 0;

export function nextPublisherCaptureWorkerRequestId(prefix = 'capture') {
  publisherCaptureWorkerRequestSequence = (publisherCaptureWorkerRequestSequence + 1) % 1_000_000;
  return `${String(prefix || 'capture').trim() || 'capture'}_${Date.now().toString(36)}_${publisherCaptureWorkerRequestSequence.toString(36)}`;
}

export function buildPublisherCaptureWorkerInitMessage({
  canvas = null,
  generation = 0,
} = {}) {
  return {
    type: PUBLISHER_CAPTURE_WORKER_MESSAGE_TYPES.INIT,
    generation: Math.max(0, Number(generation || 0)),
    canvas,
  };
}

export function buildPublisherCaptureWorkerReadbackMessage({
  source = null,
  requestId = nextPublisherCaptureWorkerRequestId(),
  generation = 0,
  sourceWidth = 0,
  sourceHeight = 0,
  profileFrameWidth = 0,
  profileFrameHeight = 0,
  timestamp = Date.now(),
} = {}) {
  return {
    type: PUBLISHER_CAPTURE_WORKER_MESSAGE_TYPES.READBACK,
    requestId: String(requestId || nextPublisherCaptureWorkerRequestId()),
    generation: Math.max(0, Number(generation || 0)),
    source,
    sourceWidth: Math.max(0, Number(sourceWidth || 0)),
    sourceHeight: Math.max(0, Number(sourceHeight || 0)),
    profileFrameWidth: Math.max(0, Number(profileFrameWidth || 0)),
    profileFrameHeight: Math.max(0, Number(profileFrameHeight || 0)),
    timestamp: Math.max(0, Number(timestamp || 0)),
  };
}

export function publisherCaptureWorkerTransferListForInit(message = {}) {
  return message?.canvas ? [message.canvas] : [];
}

export function publisherCaptureWorkerTransferListForReadback(message = {}) {
  return message?.source ? [message.source] : [];
}
