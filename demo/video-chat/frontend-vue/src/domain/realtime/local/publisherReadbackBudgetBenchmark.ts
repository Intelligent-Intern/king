import { PUBLISHER_CAPTURE_BACKENDS } from './capturePipelineCapabilities.ts';
import {
  DOM_CANVAS_COMPATIBILITY_READBACK_METHOD,
  DOM_CANVAS_COMPATIBILITY_SOURCE_BACKEND,
  resolveDomCanvasCompatibilityProfile,
} from './domCanvasFallbackPolicy.ts';
import { PUBLISHER_VIDEO_FRAME_SOURCE_BACKEND } from './publisherVideoFrameSource.ts';
import { resolveContainFrameSizeFromDimensions } from './videoFrameSizing.ts';

const MEGAPIXEL = 1_000_000;

export const HIGH_MOTION_READBACK_BENCHMARK_SOURCE = Object.freeze({
  width: 1920,
  height: 1080,
  changedPixelRatio: 1,
  motionPattern: 'full_frame_high_motion',
});

export const HIGH_MOTION_READBACK_BACKEND_COSTS = Object.freeze({
  [PUBLISHER_CAPTURE_BACKENDS.VIDEO_FRAME_COPY]: Object.freeze({
    sourceBackend: PUBLISHER_VIDEO_FRAME_SOURCE_BACKEND,
    readbackMethod: 'video_frame_copy_to_rgba',
    fixedDrawImageMs: 0,
    drawImageMsPerMegapixel: 0,
    fixedReadbackMs: 1.2,
    readbackMsPerMegapixel: 14,
  }),
  [PUBLISHER_CAPTURE_BACKENDS.OFFSCREEN_CANVAS_WORKER]: Object.freeze({
    sourceBackend: PUBLISHER_CAPTURE_BACKENDS.OFFSCREEN_CANVAS_WORKER,
    readbackMethod: 'offscreen_canvas_worker_readback',
    fixedDrawImageMs: 0.8,
    drawImageMsPerMegapixel: 8,
    fixedReadbackMs: 1.1,
    readbackMsPerMegapixel: 13,
  }),
  [PUBLISHER_CAPTURE_BACKENDS.DOM_CANVAS_FALLBACK]: Object.freeze({
    sourceBackend: DOM_CANVAS_COMPATIBILITY_SOURCE_BACKEND,
    readbackMethod: DOM_CANVAS_COMPATIBILITY_READBACK_METHOD,
    fixedDrawImageMs: 1,
    drawImageMsPerMegapixel: 40,
    fixedReadbackMs: 1.5,
    readbackMsPerMegapixel: 80,
  }),
});

function positiveNumber(value) {
  const normalized = Number(value || 0);
  return Number.isFinite(normalized) && normalized > 0 ? normalized : 0;
}

function roundedMs(value) {
  const normalized = Number(value || 0);
  if (!Number.isFinite(normalized) || normalized <= 0) return 0;
  return Math.round(normalized * 1000) / 1000;
}

function selectedBackendForCapabilities(capabilities = {}) {
  const preferred = String(capabilities?.preferredCaptureBackend || '');
  if (Object.prototype.hasOwnProperty.call(HIGH_MOTION_READBACK_BACKEND_COSTS, preferred)) {
    return preferred;
  }
  if (
    capabilities?.supportsMediaStreamTrackProcessor
    && capabilities?.supportsVideoFrameCopyTo
    && capabilities?.supportsVideoFrameClose
  ) {
    return PUBLISHER_CAPTURE_BACKENDS.VIDEO_FRAME_COPY;
  }
  if (
    capabilities?.supportsMediaStreamTrackProcessor
    && capabilities?.supportsOffscreenCanvas2d
    && capabilities?.supportsOffscreenCanvasTransfer
    && capabilities?.supportsWorker
  ) {
    return PUBLISHER_CAPTURE_BACKENDS.OFFSCREEN_CANVAS_WORKER;
  }
  if (capabilities?.supportsDomCanvasFallback || capabilities?.supportsDomCanvasReadback) {
    return PUBLISHER_CAPTURE_BACKENDS.DOM_CANVAS_FALLBACK;
  }
  return PUBLISHER_CAPTURE_BACKENDS.UNSUPPORTED;
}

function effectiveProfileForBackend(videoProfile, backend) {
  return backend === PUBLISHER_CAPTURE_BACKENDS.DOM_CANVAS_FALLBACK
    ? resolveDomCanvasCompatibilityProfile(videoProfile)
    : videoProfile;
}

function estimateStageMs(fixedMs, msPerMegapixel, megapixels) {
  return roundedMs(positiveNumber(fixedMs) + positiveNumber(msPerMegapixel) * megapixels);
}

export function evaluateHighMotionReadbackBudget({
  videoProfile = {},
  captureCapabilities = {},
  source = HIGH_MOTION_READBACK_BENCHMARK_SOURCE,
} = {}) {
  const selectedCaptureBackend = selectedBackendForCapabilities(captureCapabilities);
  const backendCost = HIGH_MOTION_READBACK_BACKEND_COSTS[selectedCaptureBackend] || null;
  const effectiveProfile = effectiveProfileForBackend(videoProfile, selectedCaptureBackend);
  const frameSize = resolveContainFrameSizeFromDimensions(
    positiveNumber(source.width),
    positiveNumber(source.height),
    positiveNumber(effectiveProfile.frameWidth),
    positiveNumber(effectiveProfile.frameHeight),
  );
  const framePixels = Math.max(0, frameSize.frameWidth * frameSize.frameHeight);
  const megapixels = framePixels / MEGAPIXEL;
  const drawBudgetMs = Math.max(1, Number(videoProfile.maxDrawImageMs || 0));
  const readbackBudgetMs = Math.max(1, Number(videoProfile.maxReadbackMs || 0));

  if (!backendCost) {
    return {
      ok: false,
      profileId: String(videoProfile.id || ''),
      selectedCaptureBackend,
      reason: 'unsupported_capture_backend',
      drawBudgetMs,
      readbackBudgetMs,
    };
  }

  const drawImageMs = estimateStageMs(
    backendCost.fixedDrawImageMs,
    backendCost.drawImageMsPerMegapixel,
    megapixels,
  );
  const readbackMs = estimateStageMs(
    backendCost.fixedReadbackMs,
    backendCost.readbackMsPerMegapixel,
    megapixels,
  );

  return {
    ok: drawImageMs <= drawBudgetMs && readbackMs <= readbackBudgetMs,
    profileId: String(videoProfile.id || ''),
    selectedCaptureBackend,
    sourceBackend: backendCost.sourceBackend,
    readbackMethod: backendCost.readbackMethod,
    frameWidth: frameSize.frameWidth,
    frameHeight: frameSize.frameHeight,
    sourceWidth: frameSize.sourceWidth,
    sourceHeight: frameSize.sourceHeight,
    highMotionChangedPixelRatio: positiveNumber(source.changedPixelRatio),
    captureFrameRate: positiveNumber(effectiveProfile.captureFrameRate),
    readbackFrameRate: positiveNumber(effectiveProfile.readbackFrameRate),
    readbackIntervalMs: positiveNumber(effectiveProfile.readbackIntervalMs),
    drawImageMs,
    readbackMs,
    drawBudgetMs,
    readbackBudgetMs,
    drawBudgetRatio: roundedMs(drawImageMs / drawBudgetMs),
    readbackBudgetRatio: roundedMs(readbackMs / readbackBudgetMs),
  };
}

export function evaluateHighMotionReadbackBudgets({
  profiles = {},
  captureCapabilities = {},
  source = HIGH_MOTION_READBACK_BENCHMARK_SOURCE,
} = {}) {
  return Object.values(profiles).map((videoProfile) => evaluateHighMotionReadbackBudget({
    videoProfile,
    captureCapabilities,
    source,
  }));
}
