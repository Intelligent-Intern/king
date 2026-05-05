export const PUBLISHER_CAPTURE_WORKER_MESSAGE_TYPES = Object.freeze({
  INIT: 'kingrt/publisher-capture-worker/init',
  READBACK: 'kingrt/publisher-capture-worker/readback',
  READBACK_RESULT: 'kingrt/publisher-capture-worker/readback-result',
  ERROR: 'kingrt/publisher-capture-worker/error',
  RESET: 'kingrt/publisher-capture-worker/reset',
  CLOSE: 'kingrt/publisher-capture-worker/close',
});

export type PublisherCaptureWorkerMessageType =
  typeof PUBLISHER_CAPTURE_WORKER_MESSAGE_TYPES[keyof typeof PUBLISHER_CAPTURE_WORKER_MESSAGE_TYPES];

export type PublisherCaptureWorkerFrameSource = Transferable & {
  close?: () => void;
  displayWidth?: number;
  displayHeight?: number;
  codedWidth?: number;
  codedHeight?: number;
  videoWidth?: number;
  videoHeight?: number;
  width?: number;
  height?: number;
};

export interface PublisherCaptureWorkerInitMessage {
  type: typeof PUBLISHER_CAPTURE_WORKER_MESSAGE_TYPES.INIT;
  generation: number;
  canvas: Transferable | null;
}

export interface PublisherCaptureWorkerReadbackMessage {
  type: typeof PUBLISHER_CAPTURE_WORKER_MESSAGE_TYPES.READBACK;
  requestId: string;
  generation: number;
  source: PublisherCaptureWorkerFrameSource | null;
  sourceWidth: number;
  sourceHeight: number;
  sourceCropX: number;
  sourceCropY: number;
  sourceCropWidth: number;
  sourceCropHeight: number;
  framingMode: string;
  targetAspectRatio: number;
  profileFrameWidth: number;
  profileFrameHeight: number;
  timestamp: number;
}

export interface PublisherCaptureWorkerReadbackResultMessage {
  type: typeof PUBLISHER_CAPTURE_WORKER_MESSAGE_TYPES.READBACK_RESULT;
  requestId: string;
  generation?: number;
  rgba: ArrayBuffer | Uint8ClampedArray;
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
  drawImageMs?: number;
  readbackMs?: number;
  workerElapsedMs?: number;
}

export interface PublisherCaptureWorkerErrorMessage {
  type: typeof PUBLISHER_CAPTURE_WORKER_MESSAGE_TYPES.ERROR;
  requestId?: string;
  reason?: string;
  message?: string;
}

export type PublisherCaptureWorkerInboundMessage =
  | PublisherCaptureWorkerInitMessage
  | PublisherCaptureWorkerReadbackMessage;

export type PublisherCaptureWorkerOutboundMessage =
  | PublisherCaptureWorkerReadbackResultMessage
  | PublisherCaptureWorkerErrorMessage;

export function isPublisherCaptureWorkerReadbackResultMessage(value: unknown): value is PublisherCaptureWorkerReadbackResultMessage {
  return Boolean(
    value
      && typeof value === 'object'
      && (value as { type?: unknown }).type === PUBLISHER_CAPTURE_WORKER_MESSAGE_TYPES.READBACK_RESULT
      && typeof (value as { requestId?: unknown }).requestId === 'string'
      && Number.isFinite(Number((value as { frameWidth?: unknown }).frameWidth))
      && Number.isFinite(Number((value as { frameHeight?: unknown }).frameHeight)),
  );
}

export function isPublisherCaptureWorkerErrorMessage(value: unknown): value is PublisherCaptureWorkerErrorMessage {
  return Boolean(
    value
      && typeof value === 'object'
      && (value as { type?: unknown }).type === PUBLISHER_CAPTURE_WORKER_MESSAGE_TYPES.ERROR,
  );
}

let publisherCaptureWorkerRequestSequence = 0;

export function nextPublisherCaptureWorkerRequestId(prefix = 'capture'): string {
  publisherCaptureWorkerRequestSequence = (publisherCaptureWorkerRequestSequence + 1) % 1_000_000;
  return `${String(prefix || 'capture').trim() || 'capture'}_${Date.now().toString(36)}_${publisherCaptureWorkerRequestSequence.toString(36)}`;
}

export function buildPublisherCaptureWorkerInitMessage({
  canvas = null,
  generation = 0,
}: Partial<Pick<PublisherCaptureWorkerInitMessage, 'canvas' | 'generation'>> = {}): PublisherCaptureWorkerInitMessage {
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
  sourceCropX = 0,
  sourceCropY = 0,
  sourceCropWidth = 0,
  sourceCropHeight = 0,
  framingMode = 'contain',
  targetAspectRatio = 0,
  profileFrameWidth = 0,
  profileFrameHeight = 0,
  timestamp = Date.now(),
}: Partial<Omit<PublisherCaptureWorkerReadbackMessage, 'type'>> = {}): PublisherCaptureWorkerReadbackMessage {
  return {
    type: PUBLISHER_CAPTURE_WORKER_MESSAGE_TYPES.READBACK,
    requestId: String(requestId || nextPublisherCaptureWorkerRequestId()),
    generation: Math.max(0, Number(generation || 0)),
    source,
    sourceWidth: Math.max(0, Number(sourceWidth || 0)),
    sourceHeight: Math.max(0, Number(sourceHeight || 0)),
    sourceCropX: Math.max(0, Number(sourceCropX || 0)),
    sourceCropY: Math.max(0, Number(sourceCropY || 0)),
    sourceCropWidth: Math.max(0, Number(sourceCropWidth || 0)),
    sourceCropHeight: Math.max(0, Number(sourceCropHeight || 0)),
    framingMode: String(framingMode || 'contain'),
    targetAspectRatio: Math.max(0, Number(targetAspectRatio || 0)),
    profileFrameWidth: Math.max(0, Number(profileFrameWidth || 0)),
    profileFrameHeight: Math.max(0, Number(profileFrameHeight || 0)),
    timestamp: Math.max(0, Number(timestamp || 0)),
  };
}

export function publisherCaptureWorkerTransferListForInit(message: Partial<PublisherCaptureWorkerInitMessage> = {}): Transferable[] {
  return message?.canvas ? [message.canvas] : [];
}

export function publisherCaptureWorkerTransferListForReadback(message: Partial<PublisherCaptureWorkerReadbackMessage> = {}): Transferable[] {
  return message?.source ? [message.source] : [];
}
