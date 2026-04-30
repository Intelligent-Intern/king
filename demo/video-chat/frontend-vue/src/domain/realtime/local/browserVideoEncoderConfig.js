import { resolveFramedFrameSizeFromDimensions } from './videoFrameSizing.js';
import { videoFrameSourceDimensions } from './browserVideoFrameScaler.js';

function positiveInteger(value, fallback = 0) {
  const normalized = Number(value || 0);
  return Number.isFinite(normalized) && normalized > 0 ? Math.floor(normalized) : fallback;
}

function evenInteger(value, fallback = 2) {
  const normalized = Number(value || 0);
  if (!Number.isFinite(normalized) || normalized <= 0) return Math.max(2, Math.floor(fallback / 2) * 2);
  return Math.max(2, Math.floor(normalized / 2) * 2);
}

function clampNumber(value, min, max) {
  return Math.min(max, Math.max(min, value));
}

function normalizeVideoLayer(value) {
  const normalized = String(value || '').trim().toLowerCase();
  if (normalized === 'thumbnail' || normalized === 'thumb' || normalized === 'mini') return 'thumbnail';
  if (normalized === 'primary' || normalized === 'main' || normalized === 'fullscreen') return 'primary';
  return '';
}

function resolveThumbnailDimensions(sourceWidth, sourceHeight) {
  const normalizedWidth = positiveInteger(sourceWidth, 0);
  const normalizedHeight = positiveInteger(sourceHeight, 0);
  if (normalizedWidth <= 0 || normalizedHeight <= 0) {
    return { width: 0, height: 0 };
  }
  const longestEdge = Math.max(normalizedWidth, normalizedHeight);
  const scale = clampNumber(Math.min(0.5, 320 / longestEdge), 0.2, 0.5);
  return {
    width: evenInteger(Math.max(2, Math.round(normalizedWidth * scale)), normalizedWidth),
    height: evenInteger(Math.max(2, Math.round(normalizedHeight * scale)), normalizedHeight),
  };
}

export function resolveBrowserEncoderBitrate(videoProfile, {
  videoLayer = 'primary',
  width = 0,
  height = 0,
  frameRate = 12,
} = {}) {
  const normalizedVideoLayer = normalizeVideoLayer(videoLayer) || 'primary';
  const targetWidth = positiveInteger(width, 0);
  const targetHeight = positiveInteger(height, 0);
  const targetFrameRate = clampNumber(Number(frameRate || 0), 1, 30);
  const maxWireBytesPerSecond = positiveInteger(videoProfile?.maxWireBytesPerSecond, 0);
  const pixelsPerSecond = Math.max(1, targetWidth * targetHeight * targetFrameRate);
  const bitsPerPixel = normalizedVideoLayer === 'thumbnail' ? 0.12 : 0.42;
  const qualityBoundBitrate = Math.round(pixelsPerSecond * bitsPerPixel);
  const minBitrate = normalizedVideoLayer === 'thumbnail' ? 90_000 : 520_000;
  const maxBitrate = normalizedVideoLayer === 'thumbnail' ? 520_000 : 5_500_000;
  const wireBudgetBitrate = maxWireBytesPerSecond > 0
    ? Math.max(minBitrate, Math.floor(maxWireBytesPerSecond * 8 * 0.38))
    : maxBitrate;
  return clampNumber(
    Math.max(minBitrate, qualityBoundBitrate),
    minBitrate,
    Math.min(maxBitrate, wireBudgetBitrate),
  );
}

export function resolveBrowserEncoderFrameSize(videoProfile, sourceFrame, { framingTarget = {} } = {}) {
  const sourceDimensions = videoFrameSourceDimensions(sourceFrame);
  const maxWidth = positiveInteger(videoProfile?.frameWidth || videoProfile?.captureWidth, 0);
  const maxHeight = positiveInteger(videoProfile?.frameHeight || videoProfile?.captureHeight, 0);
  const frameSize = resolveFramedFrameSizeFromDimensions(
    sourceDimensions.width,
    sourceDimensions.height,
    maxWidth,
    maxHeight,
    framingTarget,
  );
  return {
    ...frameSize,
    profileFrameWidth: evenInteger(maxWidth, maxWidth),
    profileFrameHeight: evenInteger(maxHeight, maxHeight),
  };
}

export function buildBrowserEncoderConfig(videoProfile, { videoLayer = 'primary', frameSize = null } = {}) {
  const normalizedVideoLayer = normalizeVideoLayer(videoLayer) || 'primary';
  const sourceWidth = positiveInteger(frameSize?.frameWidth || videoProfile?.frameWidth || videoProfile?.captureWidth, 0);
  const sourceHeight = positiveInteger(frameSize?.frameHeight || videoProfile?.frameHeight || videoProfile?.captureHeight, 0);
  let width = sourceWidth;
  let height = sourceHeight;
  let frameRate = Math.max(1, Number(videoProfile?.captureFrameRate || videoProfile?.readbackFrameRate || 12));
  if (sourceWidth <= 0 || sourceHeight <= 0) {
    return {
      codec: 'vp8',
      width: 0,
      height: 0,
      bitrate: resolveBrowserEncoderBitrate(videoProfile, { videoLayer: normalizedVideoLayer, width: 0, height: 0, frameRate }),
      framerate: frameRate,
      latencyMode: 'realtime',
      hardwareAcceleration: 'prefer-hardware',
    };
  }
  if (normalizedVideoLayer === 'thumbnail') {
    const thumbnailDimensions = resolveThumbnailDimensions(sourceWidth, sourceHeight);
    width = thumbnailDimensions.width;
    height = thumbnailDimensions.height;
    frameRate = Math.max(4, Math.min(8, Math.floor(frameRate * 0.5)));
  }
  return {
    codec: 'vp8',
    width: evenInteger(width, sourceWidth),
    height: evenInteger(height, sourceHeight),
    bitrate: resolveBrowserEncoderBitrate(videoProfile, {
      videoLayer: normalizedVideoLayer,
      width,
      height,
      frameRate,
    }),
    framerate: frameRate,
    latencyMode: 'realtime',
    hardwareAcceleration: 'prefer-hardware',
  };
}

function browserEncoderConfigVariants(config) {
  const variants = [];
  const seen = new Set();
  const add = (candidate) => {
    const normalized = Object.fromEntries(
      Object.entries(candidate).filter(([, value]) => value !== undefined && value !== ''),
    );
    const key = JSON.stringify(normalized);
    if (seen.has(key)) return;
    seen.add(key);
    variants.push(normalized);
  };
  add(config);
  for (const hardwareAcceleration of ['prefer-software', 'no-preference', 'prefer-hardware', undefined]) {
    add({ ...config, hardwareAcceleration });
  }
  for (const hardwareAcceleration of ['prefer-software', 'no-preference', undefined]) {
    add({ ...config, latencyMode: undefined, hardwareAcceleration });
  }
  return variants;
}

export async function resolveSupportedBrowserEncoderConfig(VideoEncoderCtor, config) {
  if (typeof VideoEncoderCtor?.isConfigSupported !== 'function') return config;
  for (const candidate of browserEncoderConfigVariants(config)) {
    try {
      const result = await VideoEncoderCtor.isConfigSupported(candidate);
      if (result?.supported) return result.config || candidate;
    } catch {
      // Try the next WebCodecs configuration variant before falling back to WLVC.
    }
  }
  return null;
}

export function browserEncoderConfigKey(config) {
  if (!config || typeof config !== 'object') return '';
  return [
    String(config.codec || ''),
    positiveInteger(config.width, 0),
    positiveInteger(config.height, 0),
    positiveInteger(config.bitrate, 0),
    Number(config.framerate || 0),
    String(config.latencyMode || ''),
    String(config.hardwareAcceleration || ''),
  ].join(':');
}

export function shouldScaleBrowserFrame(sourceFrameSize, targetFrameSize) {
  return positiveInteger(sourceFrameSize?.width, 0) !== positiveInteger(targetFrameSize?.frameWidth, 0)
    || positiveInteger(sourceFrameSize?.height, 0) !== positiveInteger(targetFrameSize?.frameHeight, 0)
    || positiveInteger(targetFrameSize?.sourceCropX, 0) > 0
    || positiveInteger(targetFrameSize?.sourceCropY, 0) > 0
    || positiveInteger(targetFrameSize?.sourceCropWidth, 0) !== positiveInteger(targetFrameSize?.sourceWidth, 0)
    || positiveInteger(targetFrameSize?.sourceCropHeight, 0) !== positiveInteger(targetFrameSize?.sourceHeight, 0);
}

export function closeBrowserEncoder(encoder) {
  try {
    encoder?.close?.();
  } catch {
    // The encoder may already be closed after a browser error.
  }
}
