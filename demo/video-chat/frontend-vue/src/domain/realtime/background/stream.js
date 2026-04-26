import { createMediaPipeSegmentationBackend } from './backendMediapipe';
import { createTfjsSegmentationBackend } from './backendTfjs';
import { clamp01, lerp, smoothstep, toNumber } from './math';

// Default matte shaping values for inner mask refinement.
// Keep these at module scope so they are easy to tune and audit.
const DEFAULT_INNER_CONTRACT_PX = 16;
const DEFAULT_INNER_FEATHER_PX = 24;
const DEFAULT_INNER_FEATHER_STOPS = Object.freeze([
  { progress: 0.0, alpha: 0.05 },
  { progress: 0.2, alpha: 0.15 },
  { progress: 0.4, alpha: 0.4 },
  { progress: 0.6, alpha: 0.7 },
  { progress: 0.8, alpha: 0.9 },
  { progress: 1.0, alpha: 1.0 },
]);
const LONG_RAF_FRAME_MS = 300;
const BACKGROUND_FILTER_READY_TIMEOUT_MS = 500;

// ITU-R BT.601 coefficients for RGB -> YCbCr conversion.
// Keeping these as named constants documents intent and avoids "magic numbers".
const BT601_Y_R = 0.299;
const BT601_Y_G = 0.587;
const BT601_Y_B = 0.114;
const BT601_CHROMA_OFFSET = 128;
const BT601_CB_R = -0.168736;
const BT601_CB_G = -0.331264;
const BT601_CB_B = 0.5;
const BT601_CR_R = 0.5;
const BT601_CR_G = -0.418688;
const BT601_CR_B = -0.081312;

function sampleInnerFeatherRamp(progress) {
  const clamped = clamp01(progress);
  const first = DEFAULT_INNER_FEATHER_STOPS[0];
  if (clamped <= first.progress) return first.alpha;
  for (let i = 1; i < DEFAULT_INNER_FEATHER_STOPS.length; i += 1) {
    const prev = DEFAULT_INNER_FEATHER_STOPS[i - 1];
    const next = DEFAULT_INNER_FEATHER_STOPS[i];
    if (clamped > next.progress) continue;
    const localT = clamp01((clamped - prev.progress) / Math.max(1e-6, next.progress - prev.progress));
    return lerp(prev.alpha, next.alpha, smoothstep(0, 1, localT));
  }
  return DEFAULT_INNER_FEATHER_STOPS[DEFAULT_INNER_FEATHER_STOPS.length - 1].alpha;
}
async function loadBackgroundImage(url) {
  const src = url.trim();
  if (!src) return null;
  return await new Promise((resolve) => {
    const img = new Image();
    img.decoding = "async";
    img.referrerPolicy = "no-referrer";
    img.onload = () => {
      resolve(img);
    };
    img.onerror = () => {
      resolve(null);
    };
    img.src = src;
  });
}
function drawCoverImage(ctx, image, width, height) {
  const iw = Math.max(1, image.naturalWidth || image.width || width);
  const ih = Math.max(1, image.naturalHeight || image.height || height);
  const scale = Math.max(width / iw, height / ih);
  const dw = iw * scale;
  const dh = ih * scale;
  const dx = (width - dw) * 0.5;
  const dy = (height - dh) * 0.5;
  ctx.drawImage(image, dx, dy, dw, dh);
}
function rgbToYCbCr(r, g, b) {
  const y = BT601_Y_R * r + BT601_Y_G * g + BT601_Y_B * b;
  const cb = BT601_CHROMA_OFFSET + BT601_CB_R * r + BT601_CB_G * g + BT601_CB_B * b;
  const cr = BT601_CHROMA_OFFSET + BT601_CR_R * r + BT601_CR_G * g + BT601_CR_B * b;
  return { y, cb, cr };
}
function estimateSkinProfileFromFaces(videoData, width, height, faces, vw, vh) {
  if (!faces.length) return { valid: false, cb: 0, cr: 0, tolerance: 0 };
  const face = faces[0];
  const x0 = Math.max(0, Math.floor(face.x / vw * width));
  const y0 = Math.max(0, Math.floor(face.y / vh * height));
  const x1 = Math.min(width - 1, Math.ceil((face.x + face.width) / vw * width));
  const y1 = Math.min(height - 1, Math.ceil((face.y + face.height) / vh * height));
  let sumCb = 0;
  let sumCr = 0;
  let count = 0;
  for (let y = y0; y <= y1; y += 3) {
    for (let x = x0; x <= x1; x += 3) {
      const i = (y * width + x) * 4;
      const r = videoData[i] ?? 0;
      const g = videoData[i + 1] ?? 0;
      const b = videoData[i + 2] ?? 0;
      const ycbcr = rgbToYCbCr(r, g, b);
      if (ycbcr.y < 35 || ycbcr.y > 240) continue;
      sumCb += ycbcr.cb;
      sumCr += ycbcr.cr;
      count += 1;
    }
  }
  if (count < 16) return { valid: false, cb: 0, cr: 0, tolerance: 0 };
  const cb = sumCb / count;
  const cr = sumCr / count;
  return { valid: true, cb, cr, tolerance: 24 };
}
function refineAlphaInPlace(alpha, width, height, radius = 2) {
  if (radius <= 0) return;
  const tmp = new Float32Array(alpha.length);
  const out = new Float32Array(alpha.length);
  for (let y = 0; y < height; y += 1) {
    const row = y * width;
    for (let x = 0; x < width; x += 1) {
      let sum = 0;
      let n = 0;
      for (let k = -radius; k <= radius; k += 1) {
        const xx = x + k;
        if (xx < 0 || xx >= width) continue;
        sum += alpha[row + xx] ?? 0;
        n += 1;
      }
      tmp[row + x] = n > 0 ? sum / n : 0;
    }
  }
  for (let y = 0; y < height; y += 1) {
    const row = y * width;
    for (let x = 0; x < width; x += 1) {
      let sum = 0;
      let n = 0;
      for (let k = -radius; k <= radius; k += 1) {
        const yy = y + k;
        if (yy < 0 || yy >= height) continue;
        sum += tmp[yy * width + x] ?? 0;
        n += 1;
      }
      out[row + x] = n > 0 ? sum / n : 0;
    }
  }
  for (let i = 0; i < alpha.length; i += 1) {
    alpha[i] = Math.max(0, Math.min(255, Math.round(out[i] ?? 0)));
  }
}
function applyTemporalMaskHysteresis(alpha, previous, riseRate, fallRate) {
  if (!previous || previous.length !== alpha.length) return alpha;
  const rise = clamp01(riseRate);
  const fall = clamp01(fallRate);
  for (let i = 0; i < alpha.length; i += 1) {
    const target = alpha[i] ?? 0;
    const prev = previous[i] ?? 0;
    const rate = target >= prev ? rise : fall;
    alpha[i] = Math.max(0, Math.min(255, Math.round(prev + (target - prev) * rate)));
  }
  return alpha;
}
function buildInnerDistanceFeatherAlpha(base, width, height, threshold = 110) {
  const n = width * height;
  const fg = new Uint8Array(n);
  const dist = new Float32Array(n);
  for (let i = 0; i < n; i += 1) {
    const alpha = base[i] ?? 0;
    fg[i] = alpha >= threshold ? 1 : 0;
    dist[i] = fg[i] ? 1e9 : 0;
  }
  const diag = 1.41421356;
  for (let y = 0; y < height; y += 1) {
    for (let x = 0; x < width; x += 1) {
      const i = y * width + x;
      if (!fg[i]) continue;
      let d = dist[i];
      if (x > 0) d = Math.min(d, (dist[i - 1] ?? 0) + 1);
      if (y > 0) d = Math.min(d, (dist[i - width] ?? 0) + 1);
      if (x > 0 && y > 0) d = Math.min(d, (dist[i - width - 1] ?? 0) + diag);
      if (x + 1 < width && y > 0) d = Math.min(d, (dist[i - width + 1] ?? 0) + diag);
      dist[i] = d;
    }
  }
  for (let y = height - 1; y >= 0; y -= 1) {
    for (let x = width - 1; x >= 0; x -= 1) {
      const i = y * width + x;
      if (!fg[i]) continue;
      let d = dist[i];
      if (x + 1 < width) d = Math.min(d, (dist[i + 1] ?? 0) + 1);
      if (y + 1 < height) d = Math.min(d, (dist[i + width] ?? 0) + 1);
      if (x + 1 < width && y + 1 < height) d = Math.min(d, (dist[i + width + 1] ?? 0) + diag);
      if (x > 0 && y + 1 < height) d = Math.min(d, (dist[i + width - 1] ?? 0) + diag);
      dist[i] = d;
    }
  }
  const innerContractPx = DEFAULT_INNER_CONTRACT_PX;
  const innerFeatherPx = DEFAULT_INNER_FEATHER_PX;
  const outAlpha = new Uint8ClampedArray(n);
  for (let i = 0; i < n; i += 1) {
    if (!fg[i]) {
      outAlpha[i] = 0;
      continue;
    }
    const t = clamp01(((dist[i] ?? 0) - innerContractPx) / innerFeatherPx);
    const inside = sampleInnerFeatherRamp(t);
    outAlpha[i] = Math.round(inside * (base[i] / 255) * 255);
  }
  return outAlpha;
}
function buildInnerFeatherMask(outputCtx, maskSource, videoCtx, video, width, height, faces, vw, vh, previousAlpha, fastMatte) {
  outputCtx.clearRect(0, 0, width, height);
  outputCtx.drawImage(maskSource, 0, 0, width, height);
  let maskData;
  try {
    maskData = outputCtx.getImageData(0, 0, width, height);
  } catch {
    return false;
  }
  const n = width * height;
  const base = new Uint8ClampedArray(n);
  if (fastMatte) {
    for (let i = 0; i < n; i += 1) {
      const p = i * 4;
      const r = maskData.data[p] ?? 0;
      const g = maskData.data[p + 1] ?? 0;
      const b = maskData.data[p + 2] ?? 0;
      const a = maskData.data[p + 3] ?? 0;
      const raw = Math.max(a, r, g, b);
      const prob = smoothstep(28, 176, raw) * 255;
      base[i] = Math.max(0, Math.min(255, Math.round(prob)));
    }
    refineAlphaInPlace(base, width, height, 1);
    const outFastAlpha = buildInnerDistanceFeatherAlpha(base, width, height);
    refineAlphaInPlace(outFastAlpha, width, height, 2);
    applyTemporalMaskHysteresis(outFastAlpha, previousAlpha, 0.86, 0.74);
    if (previousAlpha && previousAlpha.length === outFastAlpha.length) previousAlpha.set(outFastAlpha);
    const outFast = outputCtx.createImageData(width, height);
    for (let i = 0; i < n; i += 1) {
      const p = i * 4;
      outFast.data[p] = 255;
      outFast.data[p + 1] = 255;
      outFast.data[p + 2] = 255;
      outFast.data[p + 3] = outFastAlpha[i] ?? 0;
    }
    outputCtx.putImageData(outFast, 0, 0);
    return true;
  }
  videoCtx.clearRect(0, 0, width, height);
  videoCtx.drawImage(video, 0, 0, width, height);
  let videoData;
  try {
    videoData = videoCtx.getImageData(0, 0, width, height);
  } catch {
    return false;
  }
  const skin = estimateSkinProfileFromFaces(videoData.data, width, height, faces, vw, vh);
  for (let i = 0; i < n; i += 1) {
    const p = i * 4;
    const r = maskData.data[p] ?? 0;
    const g = maskData.data[p + 1] ?? 0;
    const b = maskData.data[p + 2] ?? 0;
    const a = maskData.data[p + 3] ?? 0;
    const raw = Math.max(a, r, g, b);
    let prob = smoothstep(24, 168, raw) * 255;
    if (skin.valid && prob > 20 && prob < 235) {
      const vr = videoData.data[p] ?? 0;
      const vg = videoData.data[p + 1] ?? 0;
      const vb = videoData.data[p + 2] ?? 0;
      const yc = rgbToYCbCr(vr, vg, vb);
      const d = Math.hypot(yc.cb - skin.cb, yc.cr - skin.cr);
      if (yc.y > 32 && d < skin.tolerance) {
        const gain = 1 - d / skin.tolerance;
        prob = Math.min(255, prob + gain * 42);
      }
    }
    const alpha = Math.max(0, Math.min(255, Math.round(prob)));
    base[i] = alpha;
  }
  const outAlpha = buildInnerDistanceFeatherAlpha(base, width, height);
  refineAlphaInPlace(outAlpha, width, height, 2);
  applyTemporalMaskHysteresis(outAlpha, previousAlpha, 0.84, 0.72);
  if (previousAlpha && previousAlpha.length === outAlpha.length) {
    previousAlpha.set(outAlpha);
  }
  const out = outputCtx.createImageData(width, height);
  for (let i = 0; i < n; i += 1) {
    const p = i * 4;
    out.data[p] = 255;
    out.data[p + 1] = 255;
    out.data[p + 2] = 255;
    out.data[p + 3] = outAlpha[i] ?? 0;
  }
  outputCtx.putImageData(out, 0, 0);
  return true;
}
function adaptiveSmoothingAlpha(previous, next, baseAlpha) {
  const prevCx = previous.x + previous.width * 0.5;
  const prevCy = previous.y + previous.height * 0.5;
  const nextCx = next.x + next.width * 0.5;
  const nextCy = next.y + next.height * 0.5;
  const dx = nextCx - prevCx;
  const dy = nextCy - prevCy;
  const distance = Math.hypot(dx, dy);
  const scale = Math.max(1, Math.hypot(previous.width, previous.height));
  const normalizedMotion = distance / scale;
  const widthDelta = Math.abs(next.width - previous.width) / Math.max(1, previous.width);
  const heightDelta = Math.abs(next.height - previous.height) / Math.max(1, previous.height);
  const shapeMotion = Math.max(widthDelta, heightDelta);
  const motion = Math.max(normalizedMotion, shapeMotion);
  if (motion >= 0.18) return 0.08;
  if (motion >= 0.1) return 0.18;
  if (motion >= 0.05) return 0.3;
  return baseAlpha;
}
function smoothFaceBoxes(previous, current, smoothingAlpha) {
  if (!current.length) return [];
  const alpha = Math.max(0, Math.min(0.95, smoothingAlpha));
  return current.map((nextFace, index) => {
    const prev = previous[index];
    if (!prev) return nextFace;
    const adaptiveAlpha = adaptiveSmoothingAlpha(prev, nextFace, alpha);
    const weightCurrent = 1 - adaptiveAlpha;
    return {
      x: lerp(prev.x, nextFace.x, weightCurrent),
      y: lerp(prev.y, nextFace.y, weightCurrent),
      width: lerp(prev.width, nextFace.width, weightCurrent),
      height: lerp(prev.height, nextFace.height, weightCurrent)
    };
  });
}
function resolveProcessingSpec(sourceWidth, sourceHeight, sourceFps, maxProcessWidth, maxProcessFps) {
  const inW = Math.max(1, Math.round(toNumber(sourceWidth, 1280)));
  const inH = Math.max(1, Math.round(toNumber(sourceHeight, 720)));
  const inFps = Math.max(8, Math.min(30, Math.round(toNumber(sourceFps, 24))));
  const capW = Math.max(320, Math.round(toNumber(maxProcessWidth, 960)));
  const capFps = Math.max(8, Math.min(30, Math.round(toNumber(maxProcessFps, 24))));
  const ratio = Math.min(1, capW / inW);
  return {
    width: Math.max(1, Math.round(inW * ratio)),
    height: Math.max(1, Math.round(inH * ratio)),
    fps: Math.max(8, Math.min(capFps, inFps))
  };
}
async function waitForVideoReady(video) {
  if (video.readyState >= 2) return;
  await new Promise((resolve) => {
    const onReady = () => {
      video.removeEventListener("loadedmetadata", onReady);
      video.removeEventListener("canplay", onReady);
      resolve();
    };
    video.addEventListener("loadedmetadata", onReady);
    video.addEventListener("canplay", onReady);
    setTimeout(onReady, 500);
  });
}
async function createBackgroundFilterStream(sourceStream, options = {}) {
  const mode = String(options.mode || "off").trim().toLowerCase();
  const videoTrack = sourceStream.getVideoTracks()[0] ?? null;
  if (!videoTrack) {
    return { stream: sourceStream, active: false, reason: "no_video_track", backend: "none", dispose: () => {
    } };
  }
  if (mode !== "blur") {
    return { stream: sourceStream, active: false, reason: "off", backend: "none", dispose: () => {
    } };
  }
  if (typeof document === "undefined") {
    return { stream: sourceStream, active: false, reason: "unsupported", backend: "none", dispose: () => {
    } };
  }
  const settings = videoTrack.getSettings?.() ?? {};
  const sourceWidth = Math.max(1, Math.round(toNumber(settings.width, 1280)));
  const sourceHeight = Math.max(1, Math.round(toNumber(settings.height, 720)));
  const sourceFps = Math.max(8, Math.min(30, Math.round(toNumber(settings.frameRate, 24))));
  const processing = resolveProcessingSpec(
    sourceWidth,
    sourceHeight,
    sourceFps,
    toNumber(options.maxProcessWidth, 960),
    toNumber(options.maxProcessFps, 24)
  );
  const width = processing.width;
  const height = processing.height;
  const fps = processing.fps;
  const statsIntervalMs = Math.max(500, Math.min(5e3, Math.round(toNumber(options.statsIntervalMs, 1e3))));
  const onStats = typeof options.onStats === "function" ? options.onStats : null;
  const blurPx = Math.max(1, Math.min(28, Math.round(toNumber(options.blurPx, 3))));
  const backgroundColor = String(options.backgroundColor ?? "").trim();
  const backgroundImageUrl = String(options.backgroundImageUrl ?? "").trim();
  const facePaddingPx = Math.max(4, Math.min(64, Math.round(toNumber(options.facePaddingPx, 14))));
  const temporalSmoothingAlpha = Math.max(0, Math.min(0.95, toNumber(options.temporalSmoothingAlpha, 0.3)));
  const detectIntervalMs = Math.max(66, Math.min(1200, Math.round(toNumber(options.detectIntervalMs, 140))));
  const preferFastMatte = options.preferFastMatte === true;
  const autoDisableOnOverload = options.autoDisableOnOverload !== false;
  const overloadFrameMs = Math.max(40, Math.min(400, toNumber(options.overloadFrameMs, 90)));
  const overloadConsecutiveFrames = Math.max(3, Math.min(60, Math.round(toNumber(options.overloadConsecutiveFrames, 12))));
  const onOverload = typeof options.onOverload === "function" ? options.onOverload : null;
  let disposed = false;
  const video = document.createElement("video");
  video.autoplay = true;
  video.playsInline = true;
  video.muted = true;
  video.srcObject = new MediaStream([videoTrack]);
  try {
    await waitForVideoReady(video);
    await video.play().catch(() => void 0);
  } catch {
    return { stream: sourceStream, active: false, reason: "setup_failed", backend: "none", dispose: () => {
    } };
  }
  let backgroundImage = null;
  if (backgroundImageUrl) {
    void loadBackgroundImage(backgroundImageUrl).then((img) => {
      if (disposed) return;
      backgroundImage = img;
    });
  }
  const canvas = document.createElement("canvas");
  canvas.width = Math.max(1, width);
  canvas.height = Math.max(1, height);
  const ctx = canvas.getContext("2d", { alpha: false, desynchronized: true });
  if (!ctx) {
    video.pause();
    video.srcObject = null;
    return { stream: sourceStream, active: false, reason: "setup_failed", backend: "none", dispose: () => {
    } };
  }
  const personLayerCanvas = document.createElement("canvas");
  personLayerCanvas.width = canvas.width;
  personLayerCanvas.height = canvas.height;
  const personLayer = personLayerCanvas.getContext("2d", { alpha: true, desynchronized: true });
  const maskLayerCanvas = document.createElement("canvas");
  maskLayerCanvas.width = canvas.width;
  maskLayerCanvas.height = canvas.height;
  const maskLayer = maskLayerCanvas.getContext("2d", { alpha: true, desynchronized: true, willReadFrequently: true });
  const videoSampleCanvas = document.createElement("canvas");
  videoSampleCanvas.width = canvas.width;
  videoSampleCanvas.height = canvas.height;
  const videoSampleLayer = videoSampleCanvas.getContext("2d", { alpha: true, desynchronized: true, willReadFrequently: true });
  const backgroundLayerCanvas = document.createElement("canvas");
  backgroundLayerCanvas.width = Math.max(1, Math.round(canvas.width * 0.5));
  backgroundLayerCanvas.height = Math.max(1, Math.round(canvas.height * 0.5));
  const backgroundLayer = backgroundLayerCanvas.getContext("2d", { alpha: false, desynchronized: true });
  if (!personLayer || !maskLayer || !videoSampleLayer) {
    video.pause();
    video.srcObject = null;
    return { stream: sourceStream, active: false, reason: "setup_failed", backend: "none", dispose: () => {
    } };
  }
  if (!backgroundLayer) {
    video.pause();
    video.srcObject = null;
    return { stream: sourceStream, active: false, reason: "setup_failed", backend: "none", dispose: () => {
    } };
  }
  let segmentationBackend = null;
  try {
    try {
      segmentationBackend = await createMediaPipeSegmentationBackend({ detectIntervalMs });
    } catch {
      segmentationBackend = null;
    }
    if (!segmentationBackend) {
      try {
        segmentationBackend = await createTfjsSegmentationBackend({ detectIntervalMs, facePaddingPx });
      } catch {
        segmentationBackend = null;
      }
    }
  } catch {
    video.pause();
    video.srcObject = null;
    return { stream: sourceStream, active: false, reason: "setup_failed", backend: "none", dispose: () => {
    } };
  }
  if (!segmentationBackend) {
    video.pause();
    video.srcObject = null;
    return { stream: sourceStream, active: false, reason: "setup_failed", backend: "none", dispose: () => {
    } };
  }
  const captureStream = canvas.captureStream;
  if (typeof captureStream !== "function") {
    segmentationBackend.dispose();
    video.pause();
    video.srcObject = null;
    return { stream: sourceStream, active: false, reason: "setup_failed", backend: "none", dispose: () => {
    } };
  }
  const filteredVideoStream = captureStream.call(canvas, fps);
  const out = new MediaStream();
  const filteredTrack = filteredVideoStream.getVideoTracks()[0] ?? null;
  if (filteredTrack) out.addTrack(filteredTrack);
  for (const audioTrack of sourceStream.getAudioTracks()) out.addTrack(audioTrack);
  let rafId = 0;
  let faces = [];
  let smoothedFaces = [];
  let hasMatteMask = false;
  let previousMaskAlpha = null;
  let readyResolved = false;
  let frameCount = 0;
  let detectCount = 0;
  let detectDurationSum = 0;
  let processDurationSum = 0;
  let statsWindowStartAt = performance.now();
  const targetFps = Math.max(8, Math.min(30, Math.round(fps)));
  let dynamicFps = targetFps;
  let dynamicFrameBudgetMs = 1e3 / dynamicFps;
  let nextFrameDueAt = performance.now();
  let overloadCooldownUntil = 0;
  let overloadStreak = 0;
  let overloadDisabled = false;
  let lastFrameProcessMs = 0;
  let lastBackgroundRefreshAt = 0;
  let stableFrameStreak = 0;
  let resolveReady = () => {};
  const backgroundRefreshIntervalMs = 180;
  const markReady = () => {
    if (readyResolved) return;
    readyResolved = true;
    resolveReady();
  };
  const ready = new Promise((resolve) => {
    resolveReady = resolve;
  });
  const readyTimer = setTimeout(markReady, Math.max(BACKGROUND_FILTER_READY_TIMEOUT_MS, detectIntervalMs + 100));
  const buildMaskFromPayload = (job) => {
    if (!job || !job.matteMask) return false;
    if (!previousMaskAlpha || previousMaskAlpha.length !== canvas.width * canvas.height) {
      previousMaskAlpha = new Uint8ClampedArray(canvas.width * canvas.height);
    }
    return buildInnerFeatherMask(
      maskLayer,
      job.matteMask,
      videoSampleLayer,
      video,
      canvas.width,
      canvas.height,
      job.faces,
      job.vw,
      job.vh,
      previousMaskAlpha,
      job.useFastMatte
    );
  };
  const draw = () => {
    if (disposed) return;
    const frameStartedAt = performance.now();
    if (frameStartedAt < nextFrameDueAt) {
      rafId = requestAnimationFrame(draw);
      return;
    }
    if (overloadDisabled) {
      const vwFast = video.videoWidth || canvas.width;
      const vhFast = video.videoHeight || canvas.height;
      if (vwFast > 1 && vhFast > 1) {
        ctx.save();
        ctx.filter = "none";
        ctx.drawImage(video, 0, 0, canvas.width, canvas.height);
        ctx.restore();
      }
      nextFrameDueAt = frameStartedAt + dynamicFrameBudgetMs;
      rafId = requestAnimationFrame(draw);
      return;
    }
    const vw = video.videoWidth || canvas.width;
    const vh = video.videoHeight || canvas.height;
    if (vw <= 1 || vh <= 1) {
      rafId = requestAnimationFrame(draw);
      return;
    }
    const now = performance.now();
    const canRunSegmentation = now >= overloadCooldownUntil;
    const segmentationWidth = Math.max(1, Math.round(Math.min(vw, canvas.width)));
    const segmentationHeight = Math.max(1, Math.round(Math.min(vh, canvas.height)));
    const segmentation = canRunSegmentation
      ? segmentationBackend.nextFaces(video, segmentationWidth, segmentationHeight, now)
      : { faces, detectSampleMs: null, matteMask: null };
    faces = segmentation.faces;
    smoothedFaces = smoothFaceBoxes(smoothedFaces, faces, temporalSmoothingAlpha);
    if (typeof segmentation.detectSampleMs === "number" && Number.isFinite(segmentation.detectSampleMs)) {
      detectCount += 1;
      detectDurationSum += Math.max(0, segmentation.detectSampleMs);
    }
    const underLoad = lastFrameProcessMs > Math.min(LONG_RAF_FRAME_MS, dynamicFrameBudgetMs * 0.75);
    const shouldRefreshMask = Boolean(segmentation.matteMask)
      && (segmentation.detectSampleMs !== null || !hasMatteMask)
      && (!underLoad || !hasMatteMask);
    if (shouldRefreshMask && segmentation.matteMask) {
      const useFastMatte = preferFastMatte || underLoad;
      const updatedMask = buildMaskFromPayload({
        matteMask: segmentation.matteMask,
        faces: smoothedFaces.map((face) => ({
          x: Number(face?.x || 0),
          y: Number(face?.y || 0),
          width: Number(face?.width || 0),
          height: Number(face?.height || 0)
        })),
        vw,
        vh,
        useFastMatte
      });
      if (updatedMask) {
        hasMatteMask = true;
      }
    }
    if (backgroundImage) {
      ctx.save();
      drawCoverImage(ctx, backgroundImage, canvas.width, canvas.height);
      ctx.restore();
    } else if (backgroundColor) {
      ctx.save();
      ctx.fillStyle = backgroundColor;
      ctx.fillRect(0, 0, canvas.width, canvas.height);
      ctx.restore();
    } else {
      if (now - lastBackgroundRefreshAt >= backgroundRefreshIntervalMs || lastBackgroundRefreshAt === 0) {
        backgroundLayer.save();
        backgroundLayer.filter = `blur(${Math.max(1, Math.round(blurPx * 0.9))}px)`;
        backgroundLayer.drawImage(video, 0, 0, backgroundLayerCanvas.width, backgroundLayerCanvas.height);
        backgroundLayer.restore();
        lastBackgroundRefreshAt = now;
      }
      ctx.drawImage(backgroundLayerCanvas, 0, 0, canvas.width, canvas.height);
    }
    if (hasMatteMask) {
      personLayer.save();
      personLayer.globalCompositeOperation = "copy";
      personLayer.filter = "none";
      personLayer.drawImage(video, 0, 0, canvas.width, canvas.height);
      personLayer.restore();
      personLayer.save();
      personLayer.globalCompositeOperation = "destination-in";
      personLayer.drawImage(maskLayerCanvas, 0, 0, canvas.width, canvas.height);
      personLayer.restore();
      ctx.drawImage(personLayerCanvas, 0, 0, canvas.width, canvas.height);
    } else {
      // Keep the whole frame blurred until the fresh matte is ready instead of
      // briefly exposing the raw camera frame during a blur-strength handoff.
      ctx.save();
      ctx.filter = `blur(${blurPx}px)`;
      ctx.drawImage(video, 0, 0, canvas.width, canvas.height);
      ctx.restore();
    }
    markReady();
    frameCount += 1;
    const frameProcessMs = Math.max(0, performance.now() - frameStartedAt);
    lastFrameProcessMs = frameProcessMs;
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
            targetFps: dynamicFps,
            thresholdMs: overloadFrameMs
          });
        } catch {
        }
      }
    }
    if (frameProcessMs > Math.min(LONG_RAF_FRAME_MS, dynamicFrameBudgetMs * 1.5)) {
      overloadCooldownUntil = now + Math.max(dynamicFrameBudgetMs * 4, 240);
    }
    if (frameProcessMs > Math.min(LONG_RAF_FRAME_MS, dynamicFrameBudgetMs * 1.15)) {
      stableFrameStreak = 0;
      if (dynamicFps > 8) {
        dynamicFps = Math.max(8, dynamicFps - 2);
        dynamicFrameBudgetMs = 1e3 / dynamicFps;
      }
    } else {
      stableFrameStreak += 1;
      if (stableFrameStreak >= 45 && dynamicFps < targetFps) {
        dynamicFps = Math.min(targetFps, dynamicFps + 1);
        dynamicFrameBudgetMs = 1e3 / dynamicFps;
        stableFrameStreak = 0;
      }
    }
    if (onStats) {
      const elapsedMs = now - statsWindowStartAt;
      if (elapsedMs >= statsIntervalMs) {
        const elapsedSec = Math.max(1e-3, elapsedMs / 1e3);
        const avgProcessMs = frameCount > 0 ? processDurationSum / frameCount : 0;
        const processLoad = Math.max(0, Math.min(1, avgProcessMs * (frameCount / elapsedSec) / 1e3));
        const stats = {
          fps: frameCount / elapsedSec,
          detectFps: detectCount / elapsedSec,
          avgDetectMs: detectCount > 0 ? detectDurationSum / detectCount : 0,
          avgProcessMs,
          processLoad,
          width: canvas.width,
          height: canvas.height,
          targetFps: dynamicFps,
          sourceWidth,
          sourceHeight,
          sourceFps
        };
        try {
          onStats(stats);
        } catch {
        }
        frameCount = 0;
        detectCount = 0;
        detectDurationSum = 0;
        processDurationSum = 0;
        statsWindowStartAt = now;
      }
    }
    nextFrameDueAt = frameStartedAt + dynamicFrameBudgetMs;
    rafId = requestAnimationFrame(draw);
  };
  rafId = requestAnimationFrame(draw);
  const dispose = () => {
    if (disposed) return;
    disposed = true;
    if (rafId) cancelAnimationFrame(rafId);
    clearTimeout(readyTimer);
    markReady();
    try {
      video.pause();
      video.srcObject = null;
    } catch {
    }
    for (const track of filteredVideoStream.getTracks()) {
      try {
        track.stop();
      } catch {
      }
    }
    segmentationBackend.dispose();
    smoothedFaces = [];
    previousMaskAlpha = null;
  };
  return {
    stream: out,
    active: true,
    reason: "ok",
    backend: segmentationBackend.kind,
    ready,
    dispose
  };
}
export {
  createBackgroundFilterStream,
  resolveProcessingSpec
};
