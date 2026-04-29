import {
  detectPublisherCapturePipelineCapabilities,
  PUBLISHER_CAPTURE_BACKENDS,
} from './capturePipelineCapabilities.js';
import { createPublisherCaptureWorkerReadbackController } from './publisherCaptureWorkerReadback.js';
import {
  DOM_CANVAS_COMPATIBILITY_READBACK_METHOD,
  DOM_CANVAS_COMPATIBILITY_SOURCE_BACKEND,
  domCanvasCompatibilityReadbackIntervalMs,
  resolveDomCanvasCompatibilityFrameSize,
  resolveDomCanvasCompatibilityVideoFrameSize,
} from './domCanvasFallbackPolicy.js';
import {
  canUsePublisherVideoFrameSource,
  closePublisherVideoFrame,
  createPublisherVideoFrameSourceReader,
  PUBLISHER_VIDEO_FRAME_SOURCE_BACKEND,
} from './publisherVideoFrameSource.js';
import { copyVideoFrameToRgbaImageData } from './publisherVideoFrameCopy.js';
import {
  highResolutionNowMs,
  markPublisherFrameTraceStage,
  publisherFrameFailureDetails,
  roundedStageMs,
} from './publisherFrameTrace.js';
import {
  resolveContainFrameSizeFromDimensions,
  resolvePublisherFrameSize,
} from './videoFrameSizing.js';

const OFFSCREEN_CANVAS_WORKER_READBACK = PUBLISHER_CAPTURE_BACKENDS.OFFSCREEN_CANVAS_WORKER;

function positiveNumber(value) {
  const normalized = Number(value || 0);
  return Number.isFinite(normalized) && normalized > 0 ? normalized : 0;
}

function frameSourceDimensions(frame) {
  return {
    width: positiveNumber(frame?.displayWidth)
      || positiveNumber(frame?.codedWidth)
      || positiveNumber(frame?.visibleRect?.width)
      || positiveNumber(frame?.width),
    height: positiveNumber(frame?.displayHeight)
      || positiveNumber(frame?.codedHeight)
      || positiveNumber(frame?.visibleRect?.height)
      || positiveNumber(frame?.height),
  };
}

function resolveVideoFrameSize(frame, videoProfile = {}) {
  const source = frameSourceDimensions(frame);
  const maxWidth = positiveNumber(videoProfile.frameWidth);
  const maxHeight = positiveNumber(videoProfile.frameHeight);
  return {
    ...resolveContainFrameSizeFromDimensions(source.width, source.height, maxWidth, maxHeight),
    profileFrameWidth: maxWidth,
    profileFrameHeight: maxHeight,
  };
}

function updateTraceSource(trace, sourceBackend, frameSize, videoTrack) {
  if (!trace || typeof trace !== 'object') return;
  trace.sourceBackend = sourceBackend;
  trace.sourceTrackWidth = Math.max(0, Number(frameSize?.sourceWidth || trace.sourceTrackWidth || 0));
  trace.sourceTrackHeight = Math.max(0, Number(frameSize?.sourceHeight || trace.sourceTrackHeight || 0));
  try {
    const settings = typeof videoTrack?.getSettings === 'function' ? (videoTrack.getSettings() || {}) : {};
    trace.sourceTrackFrameRate = Math.max(0, Number(settings.frameRate || trace.sourceTrackFrameRate || 0));
  } catch {
    // Keep the frame trace best-effort if getSettings throws.
  }
}

function createDomCanvas(documentRef, frameSize) {
  const canvas = documentRef.createElement('canvas');
  canvas.width = frameSize.frameWidth;
  canvas.height = frameSize.frameHeight;
  const context = canvas.getContext('2d', { willReadFrequently: true });
  if (!context || typeof context.drawImage !== 'function' || typeof context.getImageData !== 'function') {
    throw new Error('publisher_source_readback_context_missing');
  }
  return { canvas, context };
}

export function createPublisherSourceReadbackController({
  video,
  videoTrack,
  videoProfile,
  documentRef = typeof document !== 'undefined' ? document : null,
  globalScope = typeof globalThis !== 'undefined' ? globalThis : {},
  captureCapabilities = detectPublisherCapturePipelineCapabilities({ globalScope, documentRef }),
  mediaDebugLog = () => {},
} = {}) {
  if (!documentRef || typeof documentRef.createElement !== 'function') {
    throw new Error('publisher_source_readback_document_missing');
  }

  const initialFrameSize = captureCapabilities.preferredCaptureBackend === PUBLISHER_CAPTURE_BACKENDS.DOM_CANVAS_FALLBACK
    ? resolveDomCanvasCompatibilityFrameSize(video, videoProfile, videoTrack)
    : resolvePublisherFrameSize(video, videoProfile, videoTrack);
  const { canvas, context } = createDomCanvas(documentRef, initialFrameSize);
  let videoFrameReader = null;
  let videoFrameSourceDisabled = false;
  let videoFrameCopyToDisabled = false;
  let captureWorkerReadback = null;
  let captureWorkerDisabled = false;
  let lastDomCanvasReadbackAtMs = 0;

  if (canUsePublisherVideoFrameSource(captureCapabilities)) {
    try {
      videoFrameReader = createPublisherVideoFrameSourceReader({
        videoTrack,
        MediaStreamTrackProcessorCtor: globalScope.MediaStreamTrackProcessor,
        readTimeoutMs: Math.max(600, Number(videoProfile?.encodeIntervalMs || 0) * 6),
      });
    } catch (error) {
      videoFrameSourceDisabled = true;
      mediaDebugLog('[SFU] VideoFrame source reader unavailable; using DOM video canvas fallback', error);
    }
  }

  if (!captureWorkerDisabled) {
    captureWorkerReadback = createPublisherCaptureWorkerReadbackController({
      capabilities: captureCapabilities,
      WorkerCtor: globalScope.Worker,
      ImageDataCtor: globalScope.ImageData,
      timeoutMs: Math.max(900, Number(videoProfile?.encodeIntervalMs || 0) * 8),
      mediaDebugLog,
    });
    captureWorkerDisabled = !captureWorkerReadback;
  }

  async function nextSource({ trace, videoProfile: activeProfile, videoTrack: activeTrack }) {
    if (videoFrameReader && !videoFrameSourceDisabled) {
      const readStartedAtMs = highResolutionNowMs();
      const result = await videoFrameReader.readFrame({
        timeoutMs: Math.max(600, Number(activeProfile?.encodeIntervalMs || 0) * 6),
      });
      markPublisherFrameTraceStage(trace, 'video_frame_processor_read', highResolutionNowMs() - readStartedAtMs);
      if (result.ok && result.frame) {
        const frameSize = resolveVideoFrameSize(result.frame, activeProfile);
        updateTraceSource(trace, PUBLISHER_VIDEO_FRAME_SOURCE_BACKEND, frameSize, activeTrack);
        return {
          source: result.frame,
          sourceBackend: PUBLISHER_VIDEO_FRAME_SOURCE_BACKEND,
          frameSize,
          closeSource: () => closePublisherVideoFrame(result.frame),
        };
      }
      videoFrameSourceDisabled = true;
      mediaDebugLog('[SFU] VideoFrame source reader failed; using DOM video canvas fallback', result.reason);
    }

    if (video?.readyState < 2 || !context) return null;
    const frameSize = resolveDomCanvasCompatibilityFrameSize(video, activeProfile, activeTrack);
    updateTraceSource(trace, DOM_CANVAS_COMPATIBILITY_SOURCE_BACKEND, frameSize, activeTrack);
    return {
      source: video,
      sourceBackend: DOM_CANVAS_COMPATIBILITY_SOURCE_BACKEND,
      frameSize,
      closeSource: () => {},
    };
  }

  async function readFrame({
    trace,
    timestamp,
    videoProfile: activeProfile = videoProfile,
    videoTrack: activeTrack = videoTrack,
  } = {}) {
    const sourceFrame = await nextSource({ trace, videoProfile: activeProfile, videoTrack: activeTrack });
    if (!sourceFrame) return null;

    const { source, sourceBackend, frameSize, closeSource } = sourceFrame;
    try {
      const drawBudgetMs = Math.max(1, Number(activeProfile.maxDrawImageMs || 0));
      const readbackBudgetMs = Math.max(1, Number(activeProfile.maxReadbackMs || 0));
      if (sourceBackend === PUBLISHER_VIDEO_FRAME_SOURCE_BACKEND && !videoFrameCopyToDisabled) {
        const copyStartedAtMs = highResolutionNowMs();
        const copyResult = await copyVideoFrameToRgbaImageData({
          frame: source,
          frameSize,
          ImageDataCtor: globalScope.ImageData,
        });
        const copyToMs = roundedStageMs(highResolutionNowMs() - copyStartedAtMs);
        if (copyResult.ok) {
          markPublisherFrameTraceStage(trace, 'video_frame_copy_to_rgba', copyToMs);
          if (copyToMs > readbackBudgetMs) {
            return {
              budgetExceeded: true,
              details: publisherFrameFailureDetails(trace, {
                reason: 'sfu_source_readback_budget_exceeded',
                stage: 'video_frame_copy_to_rgba',
                source: 'video_frame_copy_to_budget_exceeded',
                message: 'Publisher VideoFrame copyTo RGBA exceeded the active SFU profile budget before WLVC encode.',
                transportPath: 'publisher_source_readback',
                bufferedAmount: 0,
                payloadBytes: 0,
                wirePayloadBytes: 0,
                timestamp,
              }),
            };
          }
          return {
            imageData: copyResult.imageData,
            frameSize,
            drawImageMs: 0,
            readbackMs: copyToMs,
            drawBudgetMs,
            readbackBudgetMs,
            sourceBackend,
            readbackMethod: 'video_frame_copy_to_rgba',
            readbackBytes: copyResult.readbackBytes,
          };
        }
        if (copyResult.fatal) {
          videoFrameCopyToDisabled = true;
          mediaDebugLog('[SFU] VideoFrame copyTo RGBA failed; using canvas readback fallback', copyResult.reason, copyResult.error);
        }
      }

      if (
        sourceBackend === PUBLISHER_VIDEO_FRAME_SOURCE_BACKEND
          && captureWorkerReadback
          && !captureWorkerDisabled
      ) {
        const workerStartedAtMs = highResolutionNowMs();
        const workerResult = await captureWorkerReadback.readFrame({
          source,
          frameSize,
          timestamp,
          timeout: Math.max(900, Number(activeProfile?.encodeIntervalMs || 0) * 8),
        });
        if (workerResult.ok) {
          const workerElapsedMs = roundedStageMs(
            workerResult.workerElapsedMs || (highResolutionNowMs() - workerStartedAtMs),
          );
          markPublisherFrameTraceStage(trace, 'offscreen_worker_draw_image', workerResult.drawImageMs);
          markPublisherFrameTraceStage(trace, 'offscreen_worker_get_image_data', workerResult.readbackMs);
          markPublisherFrameTraceStage(trace, 'offscreen_worker_round_trip', workerElapsedMs);
          if (trace && typeof trace === 'object') {
            trace.sourceBackend = OFFSCREEN_CANVAS_WORKER_READBACK;
          }
          return {
            imageData: workerResult.imageData,
            frameSize: workerResult.frameSize || frameSize,
            drawImageMs: workerResult.drawImageMs,
            readbackMs: workerResult.readbackMs,
            drawBudgetMs,
            readbackBudgetMs,
            sourceBackend: OFFSCREEN_CANVAS_WORKER_READBACK,
            readbackMethod: 'offscreen_canvas_worker_readback',
            readbackBytes: workerResult.readbackBytes,
          };
        }
        if (workerResult.fatal) {
          captureWorkerDisabled = true;
          if (captureWorkerReadback && typeof captureWorkerReadback.close === 'function') {
            captureWorkerReadback.close();
          }
          captureWorkerReadback = null;
          mediaDebugLog('[SFU] OffscreenCanvas capture worker failed; using DOM canvas fallback', workerResult.reason, workerResult.error);
        }
      }

      const canvasFrameSize = sourceBackend === PUBLISHER_VIDEO_FRAME_SOURCE_BACKEND
        ? resolveDomCanvasCompatibilityVideoFrameSize(source, activeProfile)
        : frameSize;
      const compatibilityIntervalMs = domCanvasCompatibilityReadbackIntervalMs(activeProfile);
      const nowMs = highResolutionNowMs();
      if (lastDomCanvasReadbackAtMs > 0 && nowMs - lastDomCanvasReadbackAtMs < compatibilityIntervalMs) {
        markPublisherFrameTraceStage(trace, 'dom_canvas_compatibility_throttle', nowMs - lastDomCanvasReadbackAtMs);
        return null;
      }
      if (trace && typeof trace === 'object') {
        trace.sourceBackend = DOM_CANVAS_COMPATIBILITY_SOURCE_BACKEND;
      }
      if (canvas.width !== canvasFrameSize.frameWidth || canvas.height !== canvasFrameSize.frameHeight) {
        canvas.width = canvasFrameSize.frameWidth;
        canvas.height = canvasFrameSize.frameHeight;
      }
      const drawStartedAtMs = highResolutionNowMs();
      context.drawImage(source, 0, 0, canvas.width, canvas.height);
      const drawImageMs = roundedStageMs(highResolutionNowMs() - drawStartedAtMs);
      markPublisherFrameTraceStage(
        trace,
        sourceBackend === PUBLISHER_VIDEO_FRAME_SOURCE_BACKEND ? 'video_frame_canvas_draw_image' : 'dom_canvas_draw_image',
        drawImageMs,
      );
      markPublisherFrameTraceStage(trace, 'dom_canvas_compatibility_draw_image', drawImageMs);

      const readbackStartedAtMs = highResolutionNowMs();
      const imageData = context.getImageData(0, 0, canvas.width, canvas.height);
      const readbackMs = roundedStageMs(highResolutionNowMs() - readbackStartedAtMs);
      markPublisherFrameTraceStage(
        trace,
        sourceBackend === PUBLISHER_VIDEO_FRAME_SOURCE_BACKEND ? 'video_frame_canvas_get_image_data' : 'dom_canvas_get_image_data',
        readbackMs,
      );
      markPublisherFrameTraceStage(trace, 'dom_canvas_compatibility_get_image_data', readbackMs);
      lastDomCanvasReadbackAtMs = highResolutionNowMs();

      if (drawImageMs > drawBudgetMs || readbackMs > readbackBudgetMs) {
        const readbackReason = drawImageMs > drawBudgetMs
          ? 'dom_canvas_compatibility_draw_budget_exceeded'
          : 'dom_canvas_compatibility_get_image_data_budget_exceeded';
        return {
          budgetExceeded: true,
          details: publisherFrameFailureDetails(trace, {
            reason: 'sfu_source_readback_budget_exceeded',
            stage: DOM_CANVAS_COMPATIBILITY_READBACK_METHOD,
            source: readbackReason,
            message: 'Publisher source readback exceeded the active SFU profile budget before WLVC encode.',
            transportPath: 'publisher_source_readback',
            bufferedAmount: 0,
            payloadBytes: 0,
            wirePayloadBytes: 0,
            timestamp,
          }),
        };
      }

      return {
        imageData,
        frameSize: canvasFrameSize,
        drawImageMs,
        readbackMs,
        drawBudgetMs,
        readbackBudgetMs,
        sourceBackend: DOM_CANVAS_COMPATIBILITY_SOURCE_BACKEND,
        readbackMethod: DOM_CANVAS_COMPATIBILITY_READBACK_METHOD,
        compatibilityFallback: true,
      };
    } finally {
      closeSource();
    }
  }

  async function close() {
    if (videoFrameReader && typeof videoFrameReader.close === 'function') {
      await videoFrameReader.close('publisher_source_readback_controller_closed');
    }
    videoFrameReader = null;
    if (captureWorkerReadback && typeof captureWorkerReadback.close === 'function') {
      captureWorkerReadback.close();
    }
    captureWorkerReadback = null;
  }

  return {
    initialFrameSize,
    readFrame,
    close,
    get sourceBackend() {
      return videoFrameReader && !videoFrameSourceDisabled
        ? PUBLISHER_VIDEO_FRAME_SOURCE_BACKEND
        : DOM_CANVAS_COMPATIBILITY_SOURCE_BACKEND;
    },
  };
}
