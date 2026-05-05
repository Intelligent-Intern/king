export const PUBLISHER_CAPTURE_BACKENDS = Object.freeze({
  VIDEO_FRAME_COPY: 'video_frame_copy',
  OFFSCREEN_CANVAS_WORKER: 'offscreen_canvas_worker',
  DOM_CANVAS_FALLBACK: 'dom_canvas_fallback',
  UNSUPPORTED: 'unsupported',
});

function functionRef(value) {
  return typeof value === 'function' ? value : null;
}

function prototypeMethod(constructorRef, methodName) {
  const prototype = constructorRef && typeof constructorRef === 'function' ? constructorRef.prototype : null;
  return prototype && typeof prototype[methodName] === 'function';
}

function detectOffscreenCanvas2d(globalScope) {
  const OffscreenCanvasCtor = functionRef(globalScope?.OffscreenCanvas);
  if (!OffscreenCanvasCtor) return false;
  try {
    const canvas = new OffscreenCanvasCtor(1, 1);
    const context = typeof canvas?.getContext === 'function' ? canvas.getContext('2d', { willReadFrequently: true }) : null;
    return Boolean(context && typeof context.drawImage === 'function');
  } catch {
    return false;
  }
}

function detectOffscreenCanvasTransfer(globalScope) {
  const OffscreenCanvasCtor = functionRef(globalScope?.OffscreenCanvas);
  const MessageChannelCtor = functionRef(globalScope?.MessageChannel);
  if (!OffscreenCanvasCtor || !MessageChannelCtor) return false;
  try {
    const canvas = new OffscreenCanvasCtor(1, 1);
    const channel = new MessageChannelCtor();
    try {
      channel.port1.postMessage({ canvas }, [canvas]);
      return true;
    } finally {
      if (typeof channel.port1?.close === 'function') channel.port1.close();
      if (typeof channel.port2?.close === 'function') channel.port2.close();
    }
  } catch {
    return false;
  }
}

function detectDomCanvasFallback(documentRef) {
  if (!documentRef || typeof documentRef.createElement !== 'function') {
    return false;
  }
  try {
    const canvas = documentRef.createElement('canvas');
    const context = typeof canvas?.getContext === 'function' ? canvas.getContext('2d', { willReadFrequently: true }) : null;
    return Boolean(
      context
        && typeof context.drawImage === 'function'
        && typeof context.getImageData === 'function',
    );
  } catch {
    return false;
  }
}

function choosePreferredCaptureBackend(capabilities) {
  if (
    capabilities.supportsMediaStreamTrackProcessor
    && capabilities.supportsVideoFrameCopyTo
    && capabilities.supportsVideoFrameClose
  ) {
    return PUBLISHER_CAPTURE_BACKENDS.VIDEO_FRAME_COPY;
  }
  if (
    capabilities.supportsMediaStreamTrackProcessor
    && capabilities.supportsOffscreenCanvas2d
    && capabilities.supportsOffscreenCanvasTransfer
    && capabilities.supportsWorker
  ) {
    return PUBLISHER_CAPTURE_BACKENDS.OFFSCREEN_CANVAS_WORKER;
  }
  if (capabilities.supportsDomCanvasFallback) {
    return PUBLISHER_CAPTURE_BACKENDS.DOM_CANVAS_FALLBACK;
  }
  return PUBLISHER_CAPTURE_BACKENDS.UNSUPPORTED;
}

export function detectPublisherCapturePipelineCapabilities({
  globalScope = typeof globalThis !== 'undefined' ? globalThis : {},
  documentRef = typeof document !== 'undefined' ? document : null,
} = {}) {
  const MediaStreamTrackProcessorCtor = functionRef(globalScope?.MediaStreamTrackProcessor);
  const VideoFrameCtor = functionRef(globalScope?.VideoFrame);
  const OffscreenCanvasCtor = functionRef(globalScope?.OffscreenCanvas);
  const WorkerCtor = functionRef(globalScope?.Worker);
  const MessageChannelCtor = functionRef(globalScope?.MessageChannel);
  const capabilities = {
    supportsMediaStreamTrackProcessor: Boolean(MediaStreamTrackProcessorCtor),
    supportsVideoFrame: Boolean(VideoFrameCtor),
    supportsVideoFrameCopyTo: prototypeMethod(VideoFrameCtor, 'copyTo'),
    supportsVideoFrameClose: prototypeMethod(VideoFrameCtor, 'close'),
    supportsOffscreenCanvas: Boolean(OffscreenCanvasCtor),
    supportsOffscreenCanvas2d: detectOffscreenCanvas2d(globalScope),
    supportsWorker: Boolean(WorkerCtor),
    supportsMessageChannelTransfer: Boolean(MessageChannelCtor),
    supportsOffscreenCanvasTransfer: detectOffscreenCanvasTransfer(globalScope),
    supportsDomCanvasFallback: detectDomCanvasFallback(documentRef),
  };
  capabilities.supportsDomCanvasReadback = capabilities.supportsDomCanvasFallback;
  capabilities.preferredCaptureBackend = choosePreferredCaptureBackend(capabilities);
  capabilities.hasWorkerCapturePath = capabilities.preferredCaptureBackend === PUBLISHER_CAPTURE_BACKENDS.VIDEO_FRAME_COPY
    || capabilities.preferredCaptureBackend === PUBLISHER_CAPTURE_BACKENDS.OFFSCREEN_CANVAS_WORKER;
  capabilities.hasAnyCapturePath = capabilities.preferredCaptureBackend !== PUBLISHER_CAPTURE_BACKENDS.UNSUPPORTED;
  return capabilities;
}

export function publisherCaptureCapabilityDiagnosticPayload(capabilities) {
  const detected = capabilities && typeof capabilities === 'object'
    ? capabilities
    : detectPublisherCapturePipelineCapabilities();
  return {
    capture_backend: String(detected.preferredCaptureBackend || PUBLISHER_CAPTURE_BACKENDS.UNSUPPORTED),
    supports_media_stream_track_processor: Boolean(detected.supportsMediaStreamTrackProcessor),
    supports_video_frame: Boolean(detected.supportsVideoFrame),
    supports_video_frame_copy_to: Boolean(detected.supportsVideoFrameCopyTo),
    supports_video_frame_close: Boolean(detected.supportsVideoFrameClose),
    supports_offscreen_canvas: Boolean(detected.supportsOffscreenCanvas),
    supports_offscreen_canvas_2d: Boolean(detected.supportsOffscreenCanvas2d),
    supports_worker: Boolean(detected.supportsWorker),
    supports_message_channel_transfer: Boolean(detected.supportsMessageChannelTransfer),
    supports_offscreen_canvas_transfer: Boolean(detected.supportsOffscreenCanvasTransfer),
    supports_dom_canvas_fallback: Boolean(detected.supportsDomCanvasFallback),
    supports_dom_canvas_readback: Boolean(detected.supportsDomCanvasReadback),
    has_worker_capture_path: Boolean(detected.hasWorkerCapturePath),
    has_any_capture_path: Boolean(detected.hasAnyCapturePath),
  };
}
