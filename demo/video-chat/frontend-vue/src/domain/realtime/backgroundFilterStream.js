import { selectBackgroundFilterBackend } from './backgroundFilterBackendSelector';
import { createBackgroundSegmentationBackend } from './backgroundFilterBackend';
import { createMediaPipeSegmentationBackend } from './backgroundFilterBackendMediapipe';
import { createTfjsSegmentationBackend } from './backgroundFilterBackendTfjs';

function parseEnvFlag(value, fallback = false) {
  if (value === undefined || value === null || value === '') return fallback;
  const normalized = String(value).trim().toLowerCase();
  if (['1', 'true', 'yes', 'on'].includes(normalized)) return true;
  if (['0', 'false', 'no', 'off'].includes(normalized)) return false;
  return fallback;
}

const MEDIAPIPE_SEGMENTATION_ENABLED = parseEnvFlag(import.meta.env.VITE_VIDEOCHAT_ENABLE_MEDIAPIPE, false);
const TFJS_SEGMENTATION_ENABLED = parseEnvFlag(import.meta.env.VITE_VIDEOCHAT_ENABLE_TFJS, false);

function toNumber(value, fallback) {
  const numeric = Number(value);
  return Number.isFinite(numeric) ? numeric : fallback;
}

function clampNumber(value, min, max) {
  return Math.max(min, Math.min(max, value));
}

function resolveProcessingSpec(sourceWidth, sourceHeight, sourceFps, maxProcessWidth, maxProcessFps) {
  const safeSourceWidth = Math.max(1, Math.round(toNumber(sourceWidth, 640)));
  const safeSourceHeight = Math.max(1, Math.round(toNumber(sourceHeight, 480)));
  const safeSourceFps = Math.max(8, Math.min(30, Math.round(toNumber(sourceFps, 24))));
  const widthLimit = Math.max(320, Math.min(1920, Math.round(toNumber(maxProcessWidth, 960))));
  const fpsLimit = Math.max(8, Math.min(30, Math.round(toNumber(maxProcessFps, 24))));

  const targetWidthRaw = Math.min(safeSourceWidth, widthLimit);
  const scale = targetWidthRaw / safeSourceWidth;
  const targetHeightRaw = Math.max(2, Math.round(safeSourceHeight * scale));

  // Keep processing dimensions even to avoid odd-pixel artifacts in some browsers.
  const width = Math.max(2, Math.round(targetWidthRaw / 2) * 2);
  const height = Math.max(2, Math.round(targetHeightRaw / 2) * 2);
  const fps = Math.max(8, Math.min(30, Math.round(Math.min(safeSourceFps, fpsLimit))));

  return { width, height, fps };
}

function uniqueMediaStreams(values) {
  const out = [];
  const seen = new Set();
  for (const value of values) {
    if (!(value instanceof MediaStream)) continue;
    if (seen.has(value)) continue;
    seen.add(value);
    out.push(value);
  }
  return out;
}

function stopStreamTracks(stream) {
  if (!(stream instanceof MediaStream)) return;
  for (const track of stream.getTracks()) {
    try {
      track.stop();
    } catch {
      // ignore
    }
  }
}

async function waitForVideoReady(video) {
  if (!(video instanceof HTMLVideoElement)) return false;
  if (video.readyState >= 2) return true;
  return await new Promise((resolve) => {
    let done = false;
    const finish = (value) => {
      if (done) return;
      done = true;
      cleanup();
      resolve(Boolean(value));
    };
    const cleanup = () => {
      video.removeEventListener('loadeddata', handleLoadedData);
      video.removeEventListener('canplay', handleCanPlay);
      video.removeEventListener('error', handleError);
    };
    const handleLoadedData = () => finish(true);
    const handleCanPlay = () => finish(true);
    const handleError = () => finish(false);
    video.addEventListener('loadeddata', handleLoadedData);
    video.addEventListener('canplay', handleCanPlay);
    video.addEventListener('error', handleError);
    setTimeout(() => finish(video.readyState >= 2), 2500);
  });
}

function resolveFps(track) {
  const settings = typeof track?.getSettings === 'function' ? track.getSettings() : null;
  return Math.max(8, Math.min(30, Math.round(toNumber(settings?.frameRate, 15))));
}

function scaleFaceBox(face, srcW, srcH, dstW, dstH) {
  const sx = dstW / Math.max(1, srcW);
  const sy = dstH / Math.max(1, srcH);
  const x = Math.max(0, Math.round(toNumber(face?.x, 0) * sx));
  const y = Math.max(0, Math.round(toNumber(face?.y, 0) * sy));
  const width = Math.max(0, Math.round(toNumber(face?.width, 0) * sx));
  const height = Math.max(0, Math.round(toNumber(face?.height, 0) * sy));
  return { x, y, width, height };
}

function beginRoundedRectPath(ctx, x, y, width, height, radius) {
  const w = Math.max(0, width);
  const h = Math.max(0, height);
  if (w <= 0 || h <= 0) {
    ctx.beginPath();
    return;
  }
  const r = Math.max(0, Math.min(radius, w / 2, h / 2));
  ctx.beginPath();
  if (r <= 0) {
    ctx.rect(x, y, w, h);
    return;
  }
  if (typeof ctx.roundRect === 'function') {
    ctx.roundRect(x, y, w, h, r);
    return;
  }
  ctx.moveTo(x + r, y);
  ctx.lineTo(x + w - r, y);
  ctx.quadraticCurveTo(x + w, y, x + w, y + r);
  ctx.lineTo(x + w, y + h - r);
  ctx.quadraticCurveTo(x + w, y + h, x + w - r, y + h);
  ctx.lineTo(x + r, y + h);
  ctx.quadraticCurveTo(x, y + h, x, y + h - r);
  ctx.lineTo(x, y + r);
  ctx.quadraticCurveTo(x, y, x + r, y);
}

function resolveMaskTuning(options, blurPx) {
  const maskVariant = Math.max(1, Math.min(10, Math.round(toNumber(options.maskVariant, 4))));
  const transitionGain = Math.max(1, Math.min(10, Math.round(toNumber(options.transitionGain, 6))));
  const faceScale = 0.86 + ((maskVariant - 1) * 0.06);
  const matteExpandPx = Math.max(0, Math.min(6, Math.round((maskVariant - 1) * 0.55)));
  const edgeFeatherPx = Math.max(
    1,
    Math.min(8, Math.round((transitionGain / 10) * Math.max(1, (blurPx * 0.35) + 1.2))),
  );
  const alphaThreshold = Math.max(0.18, Math.min(0.78, 0.52 - ((maskVariant - 5) * 0.03)));
  const alphaSoftness = Math.max(0.03, Math.min(0.22, 0.05 + ((transitionGain - 1) * 0.015)));
  return {
    faceScale,
    matteExpandPx,
    edgeFeatherPx,
    alphaThreshold,
    alphaSoftness,
  };
}

function smoothStep01(edge0, edge1, value) {
  if (edge1 <= edge0) return value >= edge1 ? 1 : 0;
  const t = Math.max(0, Math.min(1, (value - edge0) / (edge1 - edge0)));
  return t * t * (3 - (2 * t));
}

function normalizeMatteMaskCanvasAlpha(maskCanvas, maskCtx, width, height, maskTuning) {
  if (!(maskCanvas instanceof HTMLCanvasElement) || !maskCtx) return false;

  let imageData;
  try {
    imageData = maskCtx.getImageData(0, 0, width, height);
  } catch {
    return false;
  }
  const data = imageData.data;
  if (!data || data.length <= 0) return false;

  const pixelCount = Math.max(1, width * height);
  const sampleStride = Math.max(1, Math.round(pixelCount / 4096));
  let minAlpha = 255;
  let maxAlpha = 0;
  let minLuma = 255;
  let maxLuma = 0;

  for (let pixel = 0; pixel < pixelCount; pixel += sampleStride) {
    const i = pixel * 4;
    const r = data[i] || 0;
    const g = data[i + 1] || 0;
    const b = data[i + 2] || 0;
    const a = data[i + 3] || 0;
    const luma = Math.round((r * 0.299) + (g * 0.587) + (b * 0.114));
    if (a < minAlpha) minAlpha = a;
    if (a > maxAlpha) maxAlpha = a;
    if (luma < minLuma) minLuma = luma;
    if (luma > maxLuma) maxLuma = luma;
  }

  const alphaRange = maxAlpha - minAlpha;
  const lumaRange = maxLuma - minLuma;
  const useLumaSignal = alphaRange <= 8 && lumaRange > 8;
  if (!useLumaSignal && alphaRange <= 2) {
    return false;
  }

  const threshold = Math.max(0.1, Math.min(0.9, Number(maskTuning?.alphaThreshold ?? 0.45)));
  const softness = Math.max(0.02, Math.min(0.3, Number(maskTuning?.alphaSoftness ?? 0.1)));
  const edge0 = Math.max(0, threshold - (softness * 0.5));
  const edge1 = Math.min(1, threshold + (softness * 0.5));

  let opaqueCount = 0;
  let minOpaqueX = width;
  let minOpaqueY = height;
  let maxOpaqueX = -1;
  let maxOpaqueY = -1;
  for (let i = 0; i < data.length; i += 4) {
    const pixelIndex = i / 4;
    const x = pixelIndex % width;
    const y = Math.floor(pixelIndex / width);
    const r = data[i] || 0;
    const g = data[i + 1] || 0;
    const b = data[i + 2] || 0;
    const alphaSignal = (data[i + 3] || 0) / 255;
    const lumaSignal = ((r * 0.299) + (g * 0.587) + (b * 0.114)) / 255;
    const signal = useLumaSignal ? lumaSignal : alphaSignal;
    const normalized = smoothStep01(edge0, edge1, signal);
    const outAlpha = Math.max(0, Math.min(255, Math.round(normalized * 255)));
    data[i] = 255;
    data[i + 1] = 255;
    data[i + 2] = 255;
    data[i + 3] = outAlpha;
    if (outAlpha > 10) {
      opaqueCount += 1;
      if (x < minOpaqueX) minOpaqueX = x;
      if (y < minOpaqueY) minOpaqueY = y;
      if (x > maxOpaqueX) maxOpaqueX = x;
      if (y > maxOpaqueY) maxOpaqueY = y;
    }
  }

  const coverage = opaqueCount / pixelCount;
  if (coverage <= 0.002 || coverage >= 0.985) {
    return false;
  }
  if (maxOpaqueX < minOpaqueX || maxOpaqueY < minOpaqueY) {
    return false;
  }

  const bboxWidth = Math.max(1, (maxOpaqueX - minOpaqueX) + 1);
  const bboxHeight = Math.max(1, (maxOpaqueY - minOpaqueY) + 1);
  const bboxArea = Math.max(1, bboxWidth * bboxHeight);
  const bboxCoverage = bboxArea / pixelCount;
  const fillRatio = opaqueCount / bboxArea;
  const aspectRatio = bboxWidth / bboxHeight;
  const nearSquare = Math.abs(aspectRatio - 1) <= 0.24;
  if (
    (bboxCoverage >= 0.08 && fillRatio >= 0.9)
    || (nearSquare && bboxCoverage >= 0.16 && fillRatio >= 0.8)
  ) {
    return false;
  }

  try {
    maskCtx.putImageData(imageData, 0, 0);
  } catch {
    return false;
  }
  return true;
}

function ensureNormalizedMatteMask(cache, matteMask, width, height, maskTuning, nowMs) {
  if (!cache || !matteMask) return false;
  const {
    canvas,
    ctx,
  } = cache;
  if (!(canvas instanceof HTMLCanvasElement) || !ctx) return false;

  const signature = `${width}x${height}:${Number(maskTuning?.alphaThreshold ?? 0).toFixed(3)}:${Number(maskTuning?.alphaSoftness ?? 0).toFixed(3)}`;
  const refreshIntervalMs = Math.max(16, Number(cache.refreshIntervalMs || 64));
  const canReuse =
    cache.source === matteMask
    && cache.signature === signature
    && Number.isFinite(cache.lastNormalizedAtMs)
    && Number.isFinite(nowMs)
    && (nowMs - cache.lastNormalizedAtMs) < refreshIntervalMs;
  if (canReuse) {
    return cache.valid === true;
  }

  cache.source = matteMask;
  cache.signature = signature;
  cache.valid = false;
  cache.lastNormalizedAtMs = Number.isFinite(nowMs) ? nowMs : cache.lastNormalizedAtMs;

  canvas.width = width;
  canvas.height = height;
  ctx.clearRect(0, 0, width, height);
  ctx.globalCompositeOperation = 'source-over';
  ctx.globalAlpha = 1;

  try {
    ctx.drawImage(matteMask, 0, 0, width, height);
  } catch {
    return false;
  }

  cache.valid = normalizeMatteMaskCanvasAlpha(canvas, ctx, width, height, maskTuning);
  return cache.valid;
}

function blurMaskIfNeeded(maskCanvas, maskCtx, softMaskCanvas, softMaskCtx, width, height, featherPx) {
  if (
    featherPx <= 0
    || !(maskCanvas instanceof HTMLCanvasElement)
    || !(softMaskCanvas instanceof HTMLCanvasElement)
    || !maskCtx
    || !softMaskCtx
  ) {
    return;
  }
  softMaskCanvas.width = width;
  softMaskCanvas.height = height;
  softMaskCtx.clearRect(0, 0, width, height);
  softMaskCtx.filter = `blur(${featherPx}px)`;
  softMaskCtx.drawImage(maskCanvas, 0, 0, width, height);
  softMaskCtx.filter = 'none';

  maskCtx.clearRect(0, 0, width, height);
  maskCtx.drawImage(softMaskCanvas, 0, 0, width, height);
}

function compositeMaskedPerson(ctx, rawCanvas, maskCanvas, width, height, personCanvas, personCtx) {
  if (!(personCanvas instanceof HTMLCanvasElement) || !personCtx) return false;
  personCanvas.width = width;
  personCanvas.height = height;
  personCtx.clearRect(0, 0, width, height);
  personCtx.globalCompositeOperation = 'source-over';
  personCtx.drawImage(rawCanvas, 0, 0, width, height);
  personCtx.globalCompositeOperation = 'destination-in';
  try {
    personCtx.drawImage(maskCanvas, 0, 0, width, height);
  } catch {
    personCtx.globalCompositeOperation = 'source-over';
    return false;
  }
  personCtx.globalCompositeOperation = 'source-over';
  ctx.drawImage(personCanvas, 0, 0, width, height);
  return true;
}

function drawMatteMaskedPerson(
  ctx,
  rawCanvas,
  matteMask,
  width,
  height,
  personCanvas,
  personCtx,
  maskCanvas,
  maskCtx,
  softMaskCanvas,
  softMaskCtx,
  matteMaskCache,
  nowMs,
  maskTuning,
) {
  if (
    !matteMask
    || !(maskCanvas instanceof HTMLCanvasElement)
    || !maskCtx
    || !ensureNormalizedMatteMask(matteMaskCache, matteMask, width, height, maskTuning, nowMs)
  ) {
    return false;
  }

  const expandPx = Math.max(0, Math.round(maskTuning?.matteExpandPx || 0));
  const normalizedMask = matteMaskCache.canvas;
  maskCanvas.width = width;
  maskCanvas.height = height;
  maskCtx.clearRect(0, 0, width, height);
  maskCtx.globalCompositeOperation = 'source-over';
  maskCtx.globalAlpha = 1;

  try {
    maskCtx.drawImage(normalizedMask, 0, 0, width, height);
    if (expandPx > 0) {
      const diagonal = Math.max(1, Math.round(expandPx * 0.75));
      maskCtx.globalAlpha = 0.9;
      maskCtx.drawImage(normalizedMask, -expandPx, 0, width, height);
      maskCtx.drawImage(normalizedMask, expandPx, 0, width, height);
      maskCtx.drawImage(normalizedMask, 0, -expandPx, width, height);
      maskCtx.drawImage(normalizedMask, 0, expandPx, width, height);
      maskCtx.globalAlpha = 0.65;
      maskCtx.drawImage(normalizedMask, -diagonal, -diagonal, width, height);
      maskCtx.drawImage(normalizedMask, diagonal, -diagonal, width, height);
      maskCtx.drawImage(normalizedMask, -diagonal, diagonal, width, height);
      maskCtx.drawImage(normalizedMask, diagonal, diagonal, width, height);
      maskCtx.globalAlpha = 1;
    }
  } catch {
    maskCtx.globalAlpha = 1;
    return false;
  }

  blurMaskIfNeeded(
    maskCanvas,
    maskCtx,
    softMaskCanvas,
    softMaskCtx,
    width,
    height,
    Math.max(0, Math.round(maskTuning?.edgeFeatherPx || 0)),
  );

  return compositeMaskedPerson(ctx, rawCanvas, maskCanvas, width, height, personCanvas, personCtx);
}

function drawFacePatches(
  ctx,
  rawCanvas,
  faces,
  sourceW,
  sourceH,
  width,
  height,
  personCanvas,
  personCtx,
  maskCanvas,
  maskCtx,
  softMaskCanvas,
  softMaskCtx,
  maskTuning,
) {
  if (
    !Array.isArray(faces)
    || faces.length === 0
    || !(maskCanvas instanceof HTMLCanvasElement)
    || !maskCtx
  ) {
    return false;
  }

  const faceScale = Math.max(0.8, Number(maskTuning?.faceScale || 1));
  maskCanvas.width = width;
  maskCanvas.height = height;
  maskCtx.clearRect(0, 0, width, height);
  maskCtx.globalCompositeOperation = 'source-over';
  maskCtx.fillStyle = '#fff';

  const candidates = faces
    .map((face) => scaleFaceBox(face, sourceW, sourceH, width, height))
    .filter((box) => box.width > 0 && box.height > 0)
    .map((box) => {
      const area = box.width * box.height;
      const areaRatio = area / Math.max(1, width * height);
      const aspect = box.width / Math.max(1, box.height);
      const centerX = box.x + (box.width / 2);
      const centerDistanceRatio = Math.abs(centerX - (width / 2)) / Math.max(1, width / 2);
      return { box, areaRatio, aspect, centerDistanceRatio };
    });
  if (candidates.length === 0) return false;

  const plausible = candidates.filter((candidate) => (
    candidate.areaRatio >= 0.0015
    && candidate.areaRatio <= 0.22
    && candidate.aspect >= 0.45
    && candidate.aspect <= 1.9
  ));
  const pool = plausible.length > 0 ? plausible : candidates;
  pool.sort((a, b) => {
    if (a.centerDistanceRatio !== b.centerDistanceRatio) {
      return a.centerDistanceRatio - b.centerDistanceRatio;
    }
    return a.areaRatio - b.areaRatio;
  });

  const selected = pool[0];
  const box = selected?.box;
  if (!box) return false;

  const cx = box.x + (box.width / 2);
  const headCy = box.y + (box.height * 0.48);
  const headRx = clampNumber((box.width * 0.44) * faceScale, 12, width * 0.17);
  const headRy = clampNumber((box.height * 0.66) * faceScale, 14, height * 0.24);

  const crownCy = headCy - (headRy * 0.52);
  const crownRx = headRx * 1.08;
  const crownRy = headRy * 0.42;

  const bodyWidth = clampNumber(
    Math.max(headRx * 1.9, (box.width * 1.12) * faceScale),
    headRx * 1.5,
    width * 0.44,
  );
  const bodyHeight = clampNumber(
    Math.max(headRy * 1.42, (box.height * 1.08) * faceScale),
    headRy * 1.25,
    height * 0.58,
  );
  const bodyX = clampNumber(cx - (bodyWidth / 2), 0, Math.max(0, width - bodyWidth));
  const bodyY = clampNumber(headCy + (headRy * 0.36), 0, Math.max(0, height - bodyHeight));
  const bodyRadius = Math.max(10, Math.min(bodyWidth, bodyHeight) * 0.32);

  maskCtx.globalAlpha = 0.78;
  beginRoundedRectPath(maskCtx, bodyX, bodyY, bodyWidth, bodyHeight, bodyRadius);
  maskCtx.fill();

  maskCtx.globalAlpha = 0.9;
  maskCtx.beginPath();
  maskCtx.ellipse(cx, crownCy, crownRx, crownRy, 0, 0, Math.PI * 2);
  maskCtx.fill();

  maskCtx.globalAlpha = 1;
  maskCtx.beginPath();
  maskCtx.ellipse(cx, headCy, headRx, headRy, 0, 0, Math.PI * 2);
  maskCtx.fill();

  maskCtx.globalAlpha = 0.88;
  maskCtx.beginPath();
  maskCtx.ellipse(cx, bodyY + (bodyHeight * 0.03), bodyWidth * 0.4, headRy * 0.66, 0, 0, Math.PI * 2);
  maskCtx.fill();
  const rendered = true;
  if (!rendered) return false;

  maskCtx.globalAlpha = 1;
  blurMaskIfNeeded(
    maskCanvas,
    maskCtx,
    softMaskCanvas,
    softMaskCtx,
    width,
    height,
    Math.max(0, Math.round(maskTuning?.edgeFeatherPx || 0)),
  );

  return compositeMaskedPerson(ctx, rawCanvas, maskCanvas, width, height, personCanvas, personCtx);
}

async function resolveSegmentationBackend(selection, opts) {
  if (selection.backend === 'face_detector') {
    try {
      return createBackgroundSegmentationBackend('face_detector', opts);
    } catch {
      // fall through to async backends.
    }
  }

  if (MEDIAPIPE_SEGMENTATION_ENABLED) {
    try {
      const mediapipe = await createMediaPipeSegmentationBackend(opts);
      if (mediapipe) return mediapipe;
    } catch {
      // ignore and continue fallback chain.
    }
  }

  if (TFJS_SEGMENTATION_ENABLED) {
    try {
      const tfjs = await createTfjsSegmentationBackend(opts);
      if (tfjs) return tfjs;
    } catch {
      // ignore and continue fallback chain.
    }
  }

  return createBackgroundSegmentationBackend('center_mask_fallback', opts);
}

export async function createBackgroundFilterStream(sourceStream, options = {}) {
  if (!(sourceStream instanceof MediaStream)) {
    return {
      stream: sourceStream,
      active: false,
      reason: 'setup_failed',
      backend: 'none',
      dispose: () => {},
    };
  }

  const mode = String(options.mode || 'off').trim().toLowerCase();
  if (mode !== 'blur') {
    return {
      stream: sourceStream,
      active: false,
      reason: 'off',
      backend: 'none',
      dispose: () => {},
    };
  }

  const videoTrack = sourceStream.getVideoTracks()[0] || null;
  if (!videoTrack) {
    return {
      stream: sourceStream,
      active: false,
      reason: 'no_video_track',
      backend: 'none',
      dispose: () => {},
    };
  }

  if (typeof document === 'undefined') {
    return {
      stream: sourceStream,
      active: false,
      reason: 'unsupported',
      backend: 'none',
      dispose: () => {},
    };
  }
  const selection = selectBackgroundFilterBackend();
  if (!selection.supported) {
    return {
      stream: sourceStream,
      active: false,
      reason: 'unsupported',
      backend: 'none',
      dispose: () => {},
    };
  }

  const video = document.createElement('video');
  video.autoplay = true;
  video.playsInline = true;
  video.muted = true;
  video.srcObject = new MediaStream([videoTrack]);

  const ready = await waitForVideoReady(video);
  if (!ready) {
    try {
      video.pause();
    } catch {
      // ignore
    }
    video.srcObject = null;
    return {
      stream: sourceStream,
      active: false,
      reason: 'setup_failed',
      backend: 'none',
      dispose: () => {},
    };
  }

  try {
    await video.play();
  } catch {
    // keep processing attempt; frame loop guards readyState.
  }

  const settings = typeof videoTrack.getSettings === 'function' ? videoTrack.getSettings() : {};
  const sourceWidth = Math.max(1, Math.round(toNumber(settings?.width, 640)));
  const sourceHeight = Math.max(1, Math.round(toNumber(settings?.height, 480)));
  const sourceFps = resolveFps(videoTrack);
  const processing = resolveProcessingSpec(
    sourceWidth,
    sourceHeight,
    sourceFps,
    toNumber(options.maxProcessWidth, 960),
    toNumber(options.maxProcessFps, 24),
  );
  const width = processing.width;
  const height = processing.height;
  const fps = processing.fps;

  const blurPx = Math.max(0, Math.min(20, Math.round(toNumber(options.blurPx, 6))));
  const maskTuning = resolveMaskTuning(options, blurPx);
  const detectIntervalMs = Math.max(100, Math.min(1200, Math.round(toNumber(options.detectIntervalMs, 220))));
  const facePaddingPx = Math.max(4, Math.min(64, Math.round(toNumber(options.facePaddingPx, 14))));
  const statsIntervalMs = Math.max(500, Math.min(5000, Math.round(toNumber(options.statsIntervalMs, 1000))));
  const onStats = typeof options.onStats === 'function' ? options.onStats : null;
  const autoDisableOnOverload = options.autoDisableOnOverload !== false;
  const overloadFrameMs = Math.max(40, Math.min(400, toNumber(options.overloadFrameMs, 90)));
  const overloadConsecutiveFrames = Math.max(3, Math.min(60, Math.round(toNumber(options.overloadConsecutiveFrames, 12))));
  const onOverload = typeof options.onOverload === 'function' ? options.onOverload : null;

  const canvas = document.createElement('canvas');
  canvas.width = width;
  canvas.height = height;
  const ctx = canvas.getContext('2d', { alpha: false, desynchronized: true });
  if (!ctx || typeof canvas.captureStream !== 'function') {
    try {
      video.pause();
    } catch {
      // ignore
    }
    video.srcObject = null;
    return {
      stream: sourceStream,
      active: false,
      reason: 'unsupported',
      backend: 'none',
      dispose: () => {},
    };
  }

  const rawCanvas = document.createElement('canvas');
  rawCanvas.width = width;
  rawCanvas.height = height;
  const rawCtx = rawCanvas.getContext('2d', { alpha: false, desynchronized: true });
  const personCanvas = document.createElement('canvas');
  personCanvas.width = width;
  personCanvas.height = height;
  const personCtx = personCanvas.getContext('2d', { alpha: true, desynchronized: true });
  const maskCanvas = document.createElement('canvas');
  maskCanvas.width = width;
  maskCanvas.height = height;
  const maskCtx = maskCanvas.getContext('2d', { alpha: true, desynchronized: true });
  const softMaskCanvas = document.createElement('canvas');
  softMaskCanvas.width = width;
  softMaskCanvas.height = height;
  const softMaskCtx = softMaskCanvas.getContext('2d', { alpha: true, desynchronized: true });
  const matteSourceCanvas = document.createElement('canvas');
  matteSourceCanvas.width = width;
  matteSourceCanvas.height = height;
  const matteSourceCtx = matteSourceCanvas.getContext('2d', {
    alpha: true,
    desynchronized: true,
    willReadFrequently: true,
  });
  const matteMaskCache = {
    canvas: matteSourceCanvas,
    ctx: matteSourceCtx,
    source: null,
    signature: '',
    valid: false,
    lastNormalizedAtMs: -1,
    refreshIntervalMs: 64,
  };
  if (!rawCtx || !personCtx || !maskCtx || !softMaskCtx || !matteSourceCtx) {
    try {
      video.pause();
    } catch {
      // ignore
    }
    video.srcObject = null;
    return {
      stream: sourceStream,
      active: false,
      reason: 'setup_failed',
      backend: 'none',
      dispose: () => {},
    };
  }

  const segmentationBackend = await resolveSegmentationBackend(selection, {
    detectIntervalMs,
    facePaddingPx,
  });

  const captured = canvas.captureStream(fps);
  const output = new MediaStream();
  const filteredVideoTrack = captured.getVideoTracks()[0] || null;
  if (filteredVideoTrack) {
    output.addTrack(filteredVideoTrack);
  }
  for (const audioTrack of sourceStream.getAudioTracks()) {
    output.addTrack(audioTrack);
  }

  let disposed = false;
  let rafId = 0;
  let frameCount = 0;
  let detectCount = 0;
  let detectDurationSum = 0;
  let processDurationSum = 0;
  let statsWindowStartAt = performance.now();
  const frameBudgetMs = 1000 / Math.max(1, fps);
  let nextFrameDueAt = performance.now();
  let overloadCooldownUntil = 0;
  let overloadStreak = 0;
  let overloadDisabled = false;
  let lastFaces = [];
  let lastMatteMask = null;

  const draw = () => {
    if (disposed) return;
    const frameStartedAt = performance.now();
    if (frameStartedAt < nextFrameDueAt) {
      rafId = requestAnimationFrame(draw);
      return;
    }

    if (video.readyState >= 2 && !video.ended) {
      const vw = video.videoWidth || width;
      const vh = video.videoHeight || height;
      if (vw > 1 && vh > 1) {
        if (overloadDisabled) {
          ctx.save();
          ctx.filter = 'none';
          ctx.drawImage(video, 0, 0, width, height);
          ctx.restore();
        } else {
          const now = performance.now();
          const canRunSegmentation = now >= overloadCooldownUntil;
          let segmentation = { faces: lastFaces, detectSampleMs: null, matteMask: lastMatteMask };
          if (canRunSegmentation) {
            try {
              segmentation = segmentationBackend.nextFaces(video, vw, vh, now);
            } catch {
              segmentation = { faces: lastFaces, detectSampleMs: null, matteMask: lastMatteMask };
            }
          }
          const faces = Array.isArray(segmentation?.faces) ? segmentation.faces : [];
          const matteMask = segmentation?.matteMask || null;

          lastFaces = faces;
          lastMatteMask = matteMask;

          if (typeof segmentation?.detectSampleMs === 'number' && Number.isFinite(segmentation.detectSampleMs)) {
            detectCount += 1;
            detectDurationSum += Math.max(0, segmentation.detectSampleMs);
          }

          rawCtx.drawImage(video, 0, 0, width, height);
          ctx.save();
          ctx.filter = `blur(${blurPx}px)`;
          ctx.drawImage(rawCanvas, 0, 0, width, height);
          ctx.restore();

          if (!drawMatteMaskedPerson(
            ctx,
            rawCanvas,
            matteMask,
            width,
            height,
            personCanvas,
            personCtx,
            maskCanvas,
            maskCtx,
            softMaskCanvas,
            softMaskCtx,
            matteMaskCache,
            now,
            maskTuning,
          )) {
            drawFacePatches(
              ctx,
              rawCanvas,
              faces,
              vw,
              vh,
              width,
              height,
              personCanvas,
              personCtx,
              maskCanvas,
              maskCtx,
              softMaskCanvas,
              softMaskCtx,
              maskTuning,
            );
          }

          frameCount += 1;
          const frameProcessMs = Math.max(0, performance.now() - frameStartedAt);
          processDurationSum += frameProcessMs;

          if (frameProcessMs >= overloadFrameMs) {
            overloadStreak += 1;
          } else {
            overloadStreak = Math.max(0, overloadStreak - 1);
          }

          if (autoDisableOnOverload && overloadStreak >= overloadConsecutiveFrames) {
            overloadDisabled = true;
            if (onOverload) {
              try {
                onOverload({
                  avgProcessMs: frameProcessMs,
                  targetFps: fps,
                  thresholdMs: overloadFrameMs,
                });
              } catch {
                // ignore onOverload consumer errors
              }
            }
          }

          if (frameProcessMs > frameBudgetMs * 1.5) {
            overloadCooldownUntil = now + Math.max(frameBudgetMs * 3, 180);
          }

          if (onStats) {
            const elapsedMs = now - statsWindowStartAt;
            if (elapsedMs >= statsIntervalMs) {
              const elapsedSec = Math.max(0.001, elapsedMs / 1000);
              const avgProcessMs = frameCount > 0 ? processDurationSum / frameCount : 0;
              const processLoad = Math.max(0, Math.min(1, (avgProcessMs * (frameCount / elapsedSec)) / 1000));
              try {
                onStats({
                  fps: frameCount / elapsedSec,
                  detectFps: detectCount / elapsedSec,
                  avgDetectMs: detectCount > 0 ? detectDurationSum / detectCount : 0,
                  avgProcessMs,
                  processLoad,
                  width,
                  height,
                  targetFps: fps,
                  sourceWidth,
                  sourceHeight,
                  sourceFps,
                });
              } catch {
                // ignore onStats consumer errors
              }

              frameCount = 0;
              detectCount = 0;
              detectDurationSum = 0;
              processDurationSum = 0;
              statsWindowStartAt = now;
            }
          }
        }
      }
    }

    nextFrameDueAt = frameStartedAt + frameBudgetMs;
    rafId = requestAnimationFrame(draw);
  };
  rafId = requestAnimationFrame(draw);

  const dispose = () => {
    if (disposed) return;
    disposed = true;
    if (rafId) cancelAnimationFrame(rafId);
    try {
      video.pause();
    } catch {
      // ignore
    }
    video.srcObject = null;
    try {
      segmentationBackend.dispose();
    } catch {
      // ignore
    }
    matteMaskCache.source = null;
    matteMaskCache.valid = false;
    matteMaskCache.signature = '';
    for (const stream of uniqueMediaStreams([captured])) {
      stopStreamTracks(stream);
    }
  };

  return {
    stream: output,
    active: true,
    reason: segmentationBackend.kind === 'face_detector' ? 'ok' : 'ok_fallback',
    backend: segmentationBackend.kind,
    dispose,
  };
}
