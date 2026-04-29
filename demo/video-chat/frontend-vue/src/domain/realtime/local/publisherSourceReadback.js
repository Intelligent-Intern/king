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
import {
  copyVideoFrameToRgbaImageData,
  resolveVideoFrameCopyFrameSize,
} from './publisherVideoFrameCopy.js';
import {
  highResolutionNowMs,
  markPublisherFrameTraceStage,
  publisherFrameFailureDetails,
  roundedStageMs,
} from './publisherFrameTrace.js';
import {
  resolveContainFrameSizeFromDimensions,
  resolveProfileReadbackIntervalMs,
  resolvePublisherFrameSize,
} from './videoFrameSizing.js';

const OFFSCREEN_CANVAS_WORKER_READBACK = PUBLISHER_CAPTURE_BACKENDS.OFFSCREEN_CANVAS_WORKER;
const ZERO_COPY_CAPTURE_GATE_STAGE = 'video_frame_zero_copy_gate';
const ZERO_COPY_CAPTURE_GATE_SOURCE = 'video_frame_main_thread_canvas_blocked';
const VIDEO_FRAME_READER_RETRY_COOLDOWN_MS = 250;
const VIDEO_FRAME_READER_FALLBACK_COOLDOWN_MS = 1500;
const VIDEO_FRAME_READER_TRANSIENT_FAILURE_LIMIT = 3;

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

function sourceReadbackBudgetFailureDetails(trace, {
  stage,
  source,
  message,
  timestamp,
}) {
  return publisherFrameFailureDetails(trace, {
    reason: 'sfu_source_readback_budget_exceeded',
    stage,
    source,
    message,
    transportPath: 'publisher_source_readback',
    bufferedAmount: 0,
    payloadBytes: 0,
    wirePayloadBytes: 0,
    timestamp,
  });
}

function zeroCopyCaptureGateRequired(sourceBackend, captureCapabilities = {}) {
  return Boolean(
    sourceBackend === PUBLISHER_VIDEO_FRAME_SOURCE_BACKEND
      && captureCapabilities.preferredCaptureBackend === PUBLISHER_CAPTURE_BACKENDS.VIDEO_FRAME_COPY,
  );
}

function videoFrameCopyWithinProfileCaptureBudget(copyFrameSize, videoProfile = {}) {
  const frameWidth = positiveNumber(copyFrameSize?.frameWidth);
  const frameHeight = positiveNumber(copyFrameSize?.frameHeight);
  if (frameWidth <= 0 || frameHeight <= 0) return false;
  const captureWidth = positiveNumber(videoProfile.captureWidth) || positiveNumber(videoProfile.frameWidth);
  const captureHeight = positiveNumber(videoProfile.captureHeight) || positiveNumber(videoProfile.frameHeight);
  if (captureWidth <= 0 || captureHeight <= 0) return true;
  return frameWidth <= captureWidth * 1.1 && frameHeight <= captureHeight * 1.1;
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
  let videoFrameReaderRetryAfterMs = 0;
  let videoFrameReaderTransientFailures = 0;
  let videoFrameCopyToDisabled = false;
  let captureWorkerReadback = null;
  let captureWorkerDisabled = false;
  let lastDomCanvasReadbackAtMs = 0;

  function closeVideoFrameReader(reason = 'publisher_video_frame_reader_replaced') {
    const reader = videoFrameReader;
    videoFrameReader = null;
    if (reader && typeof reader.close === 'function') {
      void reader.close(reason).catch(() => {});
    }
  }

  function shouldTryVideoFrameReader(nowMs = Date.now()) {
    return canUsePublisherVideoFrameSource(captureCapabilities)
      && nowMs >= videoFrameReaderRetryAfterMs;
  }

  function ensureVideoFrameReader(reason = 'publisher_video_frame_reader_required') {
    if (videoFrameReader) return videoFrameReader;
    if (!shouldTryVideoFrameReader()) return null;
    try {
      videoFrameReader = createPublisherVideoFrameSourceReader({
        videoTrack,
        MediaStreamTrackProcessorCtor: globalScope.MediaStreamTrackProcessor,
        readTimeoutMs: Math.max(600, resolveProfileReadbackIntervalMs(videoProfile) * 6),
      });
    } catch (error) {
      videoFrameReader = null;
      videoFrameReaderTransientFailures += 1;
      const cooldownMs = videoFrameReaderTransientFailures >= VIDEO_FRAME_READER_TRANSIENT_FAILURE_LIMIT
        ? VIDEO_FRAME_READER_FALLBACK_COOLDOWN_MS
        : VIDEO_FRAME_READER_RETRY_COOLDOWN_MS;
      videoFrameReaderRetryAfterMs = Date.now() + cooldownMs;
      mediaDebugLog(
        '[SFU] VideoFrame source reader unavailable; retrying before DOM video canvas fallback',
        reason,
        error,
        `failures=${videoFrameReaderTransientFailures}`,
        `retry_after_ms=${cooldownMs}`,
      );
    }
    return videoFrameReader;
  }

  ensureVideoFrameReader('publisher_source_readback_init');

  function markVideoFrameReaderFailure(result) {
    closeVideoFrameReader(String(result?.reason || 'publisher_video_frame_source_failed'));
    videoFrameReaderTransientFailures += 1;
    const cooldownMs = videoFrameReaderTransientFailures >= VIDEO_FRAME_READER_TRANSIENT_FAILURE_LIMIT
      ? VIDEO_FRAME_READER_FALLBACK_COOLDOWN_MS
      : VIDEO_FRAME_READER_RETRY_COOLDOWN_MS;
    videoFrameReaderRetryAfterMs = Date.now() + cooldownMs;
    mediaDebugLog(
      '[SFU] VideoFrame source reader failed; retrying processor path before DOM canvas fallback',
      result?.reason,
      `failures=${videoFrameReaderTransientFailures}`,
      `retry_after_ms=${cooldownMs}`,
    );
  }

  if (!captureWorkerDisabled) {
    captureWorkerReadback = createPublisherCaptureWorkerReadbackController({
      capabilities: captureCapabilities,
      WorkerCtor: globalScope.Worker,
      ImageDataCtor: globalScope.ImageData,
      timeoutMs: Math.max(900, resolveProfileReadbackIntervalMs(videoProfile) * 8),
      mediaDebugLog,
    });
    captureWorkerDisabled = !captureWorkerReadback;
  }

  async function nextSource({ trace, videoProfile: activeProfile, videoTrack: activeTrack }) {
    const reader = ensureVideoFrameReader('publisher_source_readback_tick');
    if (reader) {
      const readStartedAtMs = highResolutionNowMs();
      const result = await reader.readFrame({
        timeoutMs: Math.max(600, resolveProfileReadbackIntervalMs(activeProfile) * 6),
      });
      markPublisherFrameTraceStage(trace, 'video_frame_processor_read', highResolutionNowMs() - readStartedAtMs);
      if (result.ok && result.frame) {
        videoFrameReaderTransientFailures = 0;
        const frameSize = resolveVideoFrameSize(result.frame, activeProfile);
        updateTraceSource(trace, PUBLISHER_VIDEO_FRAME_SOURCE_BACKEND, frameSize, activeTrack);
        return {
          source: result.frame,
          sourceBackend: PUBLISHER_VIDEO_FRAME_SOURCE_BACKEND,
          frameSize,
          closeSource: () => closePublisherVideoFrame(result.frame),
        };
      }
      markVideoFrameReaderFailure(result);
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
        const copyFrameSize = resolveVideoFrameCopyFrameSize(source, frameSize) || frameSize;
        if (videoFrameCopyWithinProfileCaptureBudget(copyFrameSize, activeProfile)) {
          const copyStartedAtMs = highResolutionNowMs();
          const copyResult = await copyVideoFrameToRgbaImageData({
            frame: source,
            frameSize: copyFrameSize,
            ImageDataCtor: globalScope.ImageData,
          });
          const copyToMs = roundedStageMs(highResolutionNowMs() - copyStartedAtMs);
          if (copyResult.ok) {
            markPublisherFrameTraceStage(trace, 'video_frame_copy_to_rgba', copyToMs);
            if (copyToMs > readbackBudgetMs) {
              return {
                budgetExceeded: true,
                details: sourceReadbackBudgetFailureDetails(trace, {
                  stage: 'video_frame_copy_to_rgba',
                  source: 'video_frame_copy_to_budget_exceeded',
                  message: 'Publisher VideoFrame copyTo RGBA exceeded the active SFU profile budget before WLVC encode.',
                  timestamp,
                }),
              };
            }
            return {
              imageData: copyResult.imageData,
              frameSize: copyFrameSize,
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
        } else {
          markPublisherFrameTraceStage(trace, 'video_frame_copy_profile_oversize', 0);
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
          timeout: Math.max(900, resolveProfileReadbackIntervalMs(activeProfile) * 8),
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
          if (workerResult.drawImageMs > drawBudgetMs || workerResult.readbackMs > readbackBudgetMs) {
            const readbackReason = workerResult.drawImageMs > drawBudgetMs
              ? 'offscreen_worker_draw_image_budget_exceeded'
              : 'offscreen_worker_get_image_data_budget_exceeded';
            return {
              budgetExceeded: true,
              details: sourceReadbackBudgetFailureDetails(trace, {
                stage: 'offscreen_canvas_worker_readback',
                source: readbackReason,
                message: 'Publisher OffscreenCanvas worker source readback exceeded the active SFU profile budget before WLVC encode.',
                timestamp,
              }),
            };
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
          return null;
        }
      }

      if (zeroCopyCaptureGateRequired(sourceBackend, captureCapabilities)) {
        markPublisherFrameTraceStage(trace, ZERO_COPY_CAPTURE_GATE_STAGE, 0);
        return {
          budgetExceeded: true,
          details: sourceReadbackBudgetFailureDetails(trace, {
            stage: ZERO_COPY_CAPTURE_GATE_STAGE,
            source: ZERO_COPY_CAPTURE_GATE_SOURCE,
            message: 'Publisher zero-copy capture gate blocked main-thread canvas readback for a VideoFrame source.',
            timestamp,
          }),
        };
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
          details: sourceReadbackBudgetFailureDetails(trace, {
            stage: DOM_CANVAS_COMPATIBILITY_READBACK_METHOD,
            source: readbackReason,
            message: 'Publisher source readback exceeded the active SFU profile budget before WLVC encode.',
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
      return videoFrameReader
        ? PUBLISHER_VIDEO_FRAME_SOURCE_BACKEND
        : DOM_CANVAS_COMPATIBILITY_SOURCE_BACKEND;
    },
  };
}
