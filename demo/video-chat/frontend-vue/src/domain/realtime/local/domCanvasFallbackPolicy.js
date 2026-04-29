import { resolveContainFrameSizeFromDimensions, resolvePublisherFrameSize } from './videoFrameSizing.js';

export const DOM_CANVAS_COMPATIBILITY_SOURCE_BACKEND = 'dom_canvas_compatibility_fallback';
export const DOM_CANVAS_COMPATIBILITY_READBACK_METHOD = 'dom_canvas_compatibility_readback';
export const DOM_CANVAS_COMPATIBILITY_MAX_FRAME_WIDTH = 320;
export const DOM_CANVAS_COMPATIBILITY_MAX_FRAME_HEIGHT = 180;
export const DOM_CANVAS_COMPATIBILITY_MAX_FPS = 6;

function positiveNumber(value) {
  const normalized = Number(value || 0);
  return Number.isFinite(normalized) && normalized > 0 ? normalized : 0;
}

function evenFloor(value, fallback = 2) {
  const normalized = Math.floor(positiveNumber(value));
  if (normalized <= 0) return Math.max(2, Math.floor(fallback / 2) * 2);
  return Math.max(2, Math.floor(normalized / 2) * 2);
}

export function resolveDomCanvasCompatibilityProfile(videoProfile = {}) {
  const activeWidth = positiveNumber(videoProfile.frameWidth) || DOM_CANVAS_COMPATIBILITY_MAX_FRAME_WIDTH;
  const activeHeight = positiveNumber(videoProfile.frameHeight) || DOM_CANVAS_COMPATIBILITY_MAX_FRAME_HEIGHT;
  const cappedFrameWidth = evenFloor(Math.min(activeWidth, DOM_CANVAS_COMPATIBILITY_MAX_FRAME_WIDTH), DOM_CANVAS_COMPATIBILITY_MAX_FRAME_WIDTH);
  const cappedFrameHeight = evenFloor(Math.min(activeHeight, DOM_CANVAS_COMPATIBILITY_MAX_FRAME_HEIGHT), DOM_CANVAS_COMPATIBILITY_MAX_FRAME_HEIGHT);
  const maxFpsIntervalMs = Math.ceil(1000 / DOM_CANVAS_COMPATIBILITY_MAX_FPS);
  return {
    ...videoProfile,
    frameWidth: cappedFrameWidth,
    frameHeight: cappedFrameHeight,
    captureFrameRate: Math.min(
      positiveNumber(videoProfile.captureFrameRate) || DOM_CANVAS_COMPATIBILITY_MAX_FPS,
      DOM_CANVAS_COMPATIBILITY_MAX_FPS,
    ),
    encodeIntervalMs: Math.max(positiveNumber(videoProfile.encodeIntervalMs), maxFpsIntervalMs),
    domCanvasCompatibilityFallback: true,
  };
}

export function resolveDomCanvasCompatibilityFrameSize(video, videoProfile = {}, videoTrack = null) {
  return resolvePublisherFrameSize(video, resolveDomCanvasCompatibilityProfile(videoProfile), videoTrack);
}

export function resolveDomCanvasCompatibilityVideoFrameSize(frame, videoProfile = {}) {
  const sourceWidth = positiveNumber(frame?.displayWidth)
    || positiveNumber(frame?.codedWidth)
    || positiveNumber(frame?.visibleRect?.width)
    || positiveNumber(frame?.width);
  const sourceHeight = positiveNumber(frame?.displayHeight)
    || positiveNumber(frame?.codedHeight)
    || positiveNumber(frame?.visibleRect?.height)
    || positiveNumber(frame?.height);
  const compatibilityProfile = resolveDomCanvasCompatibilityProfile(videoProfile);
  return {
    ...resolveContainFrameSizeFromDimensions(
      sourceWidth,
      sourceHeight,
      compatibilityProfile.frameWidth,
      compatibilityProfile.frameHeight,
    ),
    profileFrameWidth: compatibilityProfile.frameWidth,
    profileFrameHeight: compatibilityProfile.frameHeight,
  };
}

export function domCanvasCompatibilityReadbackIntervalMs(videoProfile = {}) {
  return Math.max(
    positiveNumber(videoProfile.encodeIntervalMs),
    Math.ceil(1000 / DOM_CANVAS_COMPATIBILITY_MAX_FPS),
  );
}
