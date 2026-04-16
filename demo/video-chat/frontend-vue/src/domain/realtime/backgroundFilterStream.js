import { selectBackgroundFilterBackend } from "./backgroundFilterBackendSelector";
import {
  createBackgroundSegmentationBackend
} from "./backgroundFilterBackend";
import { createMediaPipeSegmentationBackend } from "./backgroundFilterBackendMediapipe";
import { createTfjsSegmentationBackend } from "./backgroundFilterBackendTfjs";

function parseEnvFlag(value, fallback = false) {
  if (value === void 0 || value === null || value === "") return fallback;
  const normalized = String(value).trim().toLowerCase();
  if (["1", "true", "yes", "on"].includes(normalized)) return true;
  if (["0", "false", "no", "off"].includes(normalized)) return false;
  return fallback;
}

const MEDIAPIPE_SEGMENTATION_ENABLED = parseEnvFlag(import.meta.env.VITE_VIDEOCHAT_ENABLE_MEDIAPIPE, false);
const TFJS_SEGMENTATION_ENABLED = parseEnvFlag(import.meta.env.VITE_VIDEOCHAT_ENABLE_TFJS, false);
function toNumber(value, fallback) {
  return typeof value === "number" && Number.isFinite(value) ? value : fallback;
}
function lerp(a, b, t) {
  return a + (b - a) * t;
}
function clamp01(value) {
  return Math.max(0, Math.min(1, value));
}
function smoothstep(edge0, edge1, x) {
  const t = clamp01((x - edge0) / Math.max(1e-6, edge1 - edge0));
  return t * t * (3 - 2 * t);
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
  const y = 0.299 * r + 0.587 * g + 0.114 * b;
  const cb = 128 - 0.168736 * r - 0.331264 * g + 0.5 * b;
  const cr = 128 + 0.5 * r - 0.418688 * g - 0.081312 * b;
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
    applyTemporalMaskHysteresis(base, previousAlpha, 0.35, 0.62);
    if (previousAlpha && previousAlpha.length === base.length) previousAlpha.set(base);
    const outFast = outputCtx.createImageData(width, height);
    for (let i = 0; i < n; i += 1) {
      const p = i * 4;
      outFast.data[p] = 255;
      outFast.data[p + 1] = 255;
      outFast.data[p + 2] = 255;
      outFast.data[p + 3] = base[i] ?? 0;
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
  const fg = new Uint8Array(n);
  const dist = new Float32Array(n);
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
    fg[i] = alpha >= 110 ? 1 : 0;
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
  const innerFeatherPx = 12;
  const outAlpha = new Uint8ClampedArray(n);
  for (let i = 0; i < n; i += 1) {
    if (!fg[i]) {
      outAlpha[i] = 0;
      continue;
    }
    const t = clamp01((dist[i] ?? 0) / innerFeatherPx);
    const inside = smoothstep(0, 1, t);
    outAlpha[i] = Math.round(inside * (base[i] / 255) * 255);
  }
  refineAlphaInPlace(outAlpha, width, height, 2);
  applyTemporalMaskHysteresis(outAlpha, previousAlpha, 0.3, 0.62);
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
function resolveBlurTransitionProfile(stageRaw) {
  const stage = Math.max(1, Math.min(10, Math.round(stageRaw)));
  const coreAlphaByStage = [0, 0.2, 0.22, 0.32, 0.45, 0.58, 0.7, 0.82, 0.92, 1];
  const idx = stage - 1;
  const coreAlpha = coreAlphaByStage[idx] ?? 1;
  return {
    coreAlpha,
    fallbackCenterBlurAlpha: Math.max(0, Math.min(1, 1 - coreAlpha))
  };
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
function drawFeatheredFacePatch(ctx, video, canvasWidth, canvasHeight, blurPx, vw, vh, face, edgeFeatherPx, maskVariant, transitionGain) {
  void transitionGain;
  const x = face.x / vw * canvasWidth;
  const y = face.y / vh * canvasHeight;
  const w = face.width / vw * canvasWidth;
  const h = face.height / vh * canvasHeight;
  if (w <= 0 || h <= 0) return;
  const feather = Math.max(0, edgeFeatherPx);
  const cx = x + w * 0.5;
  const cy = y + h * 0.294;
  const variant = resolveBlurMaskVariant(maskVariant);
  const coreTighten = 0.4;
  const coreRxTop = Math.max(4, w * variant.coreRxTopScale * coreTighten);
  const coreRxBottom = Math.max(8, w * variant.coreRxBottomScale * coreTighten);
  const coreRyTop = Math.max(6, h * variant.coreRyTopScale * coreTighten);
  const coreRyBottom = Math.max(12, h * variant.coreRyBottomScale * coreTighten);
  const clearCoreScale = 0.72;
  const clearRxTop = Math.max(3, coreRxTop * clearCoreScale);
  const clearRxBottom = Math.max(6, coreRxBottom * clearCoreScale);
  const clearRyTop = Math.max(4, coreRyTop * clearCoreScale);
  const clearRyBottom = Math.max(8, coreRyBottom * clearCoreScale);
  const radialFalloff = Math.max(
    60,
    feather * variant.falloffFeatherScale * 3.6 + Math.max(w, h) * variant.falloffSizeScale * 4.05
  );
  const outerRxTop = coreRxTop + radialFalloff * variant.outerRxTopScale;
  const outerRxBottom = coreRxBottom + radialFalloff * variant.outerRxBottomScale;
  const outerRyTop = coreRyTop + radialFalloff * variant.outerRyTopScale;
  const outerRyBottom = coreRyBottom + radialFalloff * variant.outerRyBottomScale;
  const steps = feather > 0 ? 9 : 5;
  const blurUnitsByLevel = [0, 15, 0, 0, 0, 0, 0, 0, 0, 0];
  const maxBlurUnits = 15;
  for (let i = 1; i <= steps; i += 1) {
    const tOuter = i / steps;
    const tInner = (i - 1) / steps;
    const rxTopOuter = lerp(clearRxTop, outerRxTop, tOuter);
    const rxBottomOuter = lerp(clearRxBottom, outerRxBottom, tOuter);
    const ryTopOuter = lerp(clearRyTop, outerRyTop, tOuter);
    const ryBottomOuter = lerp(clearRyBottom, outerRyBottom, tOuter);
    const rxTopInner = lerp(clearRxTop, outerRxTop, tInner);
    const rxBottomInner = lerp(clearRxBottom, outerRxBottom, tInner);
    const ryTopInner = lerp(clearRyTop, outerRyTop, tInner);
    const ryBottomInner = lerp(clearRyBottom, outerRyBottom, tInner);
    const level = Math.max(2, Math.min(10, i + 1));
    const blurUnits = Math.max(0, blurUnitsByLevel[level - 1] ?? 0);
    ctx.save();
    ctx.globalAlpha = 1;
    if (blurUnits > 0) {
      const ringBlurPx = Math.max(1, blurPx * blurUnits / maxBlurUnits);
      ctx.filter = `blur(${ringBlurPx}px)`;
    } else {
      ctx.filter = "none";
    }
    ctx.beginPath();
    appendConeMaskPath(ctx, cx, cy, rxTopOuter, rxBottomOuter, ryTopOuter, ryBottomOuter, variant);
    appendConeMaskPath(ctx, cx, cy, rxTopInner, rxBottomInner, ryTopInner, ryBottomInner, variant);
    ctx.clip("evenodd");
    ctx.drawImage(video, 0, 0, canvasWidth, canvasHeight);
    ctx.restore();
  }
  const coreUnits = blurUnitsByLevel[0] ?? 0;
  ctx.save();
  ctx.globalAlpha = 1;
  drawConeMaskPath(ctx, cx, cy, clearRxTop, clearRxBottom, clearRyTop, clearRyBottom, variant);
  ctx.clip();
  if (coreUnits > 0) {
    const coreBlurPx = Math.max(1, blurPx * coreUnits / maxBlurUnits);
    ctx.filter = `blur(${coreBlurPx}px)`;
  }
  ctx.drawImage(video, 0, 0, canvasWidth, canvasHeight);
  ctx.restore();
}
function drawQuickFacePatch(ctx, video, canvasWidth, canvasHeight, vw, vh, face) {
  const x = face.x / vw * canvasWidth;
  const y = face.y / vh * canvasHeight;
  const w = face.width / vw * canvasWidth;
  const h = face.height / vh * canvasHeight;
  if (w <= 0 || h <= 0) return;
  const cx = x + w * 0.5;
  const cy = y + h * 0.42;
  const rx = Math.max(6, w * 0.42);
  const ry = Math.max(8, h * 0.62);
  ctx.save();
  ctx.beginPath();
  ctx.ellipse(cx, cy, rx, ry, 0, 0, Math.PI * 2);
  ctx.clip();
  ctx.drawImage(video, 0, 0, canvasWidth, canvasHeight);
  ctx.restore();
}
function drawConeMaskPath(ctx, cx, cy, rxTop, rxBottom, ryTop, ryBottom, variant) {
  ctx.beginPath();
  appendConeMaskPath(ctx, cx, cy, rxTop, rxBottom, ryTop, ryBottom, variant);
}
function appendConeMaskPath(ctx, cx, cy, rxTop, rxBottom, ryTop, ryBottom, variant) {
  const headCenterY = cy - ryTop * variant.headCenterYScale;
  const topY = headCenterY - ryTop;
  const neckY = cy - ryTop * variant.neckYScale;
  const leftNeckX = cx - rxTop * variant.neckWidthScale;
  const rightNeckX = cx + rxTop * variant.neckWidthScale;
  const bottomY = cy + ryBottom;
  const bottomLeftX = cx - rxBottom;
  const bottomRightX = cx + rxBottom;
  const bottomArcDrop = Math.max(3, ryBottom * variant.bottomArcDropScale);
  ctx.moveTo(cx, topY);
  ctx.bezierCurveTo(
    cx + rxTop * variant.topControlScale,
    topY,
    rightNeckX,
    headCenterY - ryTop * variant.topArcLiftScale,
    rightNeckX,
    neckY
  );
  ctx.bezierCurveTo(
    rightNeckX + (bottomRightX - rightNeckX) * variant.midWidenScale,
    cy + ryBottom * variant.midWidenYScale,
    bottomRightX,
    cy + ryBottom * variant.bottomShoulderYScale,
    bottomRightX,
    bottomY
  );
  ctx.quadraticCurveTo(cx, bottomY + bottomArcDrop, bottomLeftX, bottomY);
  ctx.bezierCurveTo(
    bottomLeftX,
    cy + ryBottom * variant.bottomShoulderYScale,
    leftNeckX - (leftNeckX - bottomLeftX) * variant.midWidenScale,
    cy + ryBottom * variant.midWidenYScale,
    leftNeckX,
    neckY
  );
  ctx.bezierCurveTo(
    leftNeckX,
    headCenterY - ryTop * variant.topArcLiftScale,
    cx - rxTop * variant.topControlScale,
    topY,
    cx,
    topY
  );
  ctx.closePath();
}
function resolveBlurMaskVariant(value) {
  const presets = [
    { coreRxTopScale: 0.17, coreRxBottomScale: 0.43, coreRyTopScale: 0.23, coreRyBottomScale: 0.53, falloffFeatherScale: 1.05, falloffSizeScale: 0.09, outerRxTopScale: 0.44, outerRxBottomScale: 1.15, outerRyTopScale: 0.55, outerRyBottomScale: 1.34, headCenterYScale: 0.18, neckYScale: 0.08, neckWidthScale: 0.84, bottomArcDropScale: 0.22, topControlScale: 0.52, topArcLiftScale: 0.16, midWidenScale: 0.52, midWidenYScale: 0.24, bottomShoulderYScale: 0.7 },
    { coreRxTopScale: 0.18, coreRxBottomScale: 0.45, coreRyTopScale: 0.24, coreRyBottomScale: 0.56, falloffFeatherScale: 1.08, falloffSizeScale: 0.1, outerRxTopScale: 0.46, outerRxBottomScale: 1.2, outerRyTopScale: 0.58, outerRyBottomScale: 1.38, headCenterYScale: 0.19, neckYScale: 0.09, neckWidthScale: 0.86, bottomArcDropScale: 0.24, topControlScale: 0.54, topArcLiftScale: 0.17, midWidenScale: 0.54, midWidenYScale: 0.25, bottomShoulderYScale: 0.72 },
    { coreRxTopScale: 0.19, coreRxBottomScale: 0.47, coreRyTopScale: 0.25, coreRyBottomScale: 0.58, falloffFeatherScale: 1.1, falloffSizeScale: 0.11, outerRxTopScale: 0.48, outerRxBottomScale: 1.24, outerRyTopScale: 0.61, outerRyBottomScale: 1.42, headCenterYScale: 0.2, neckYScale: 0.1, neckWidthScale: 0.88, bottomArcDropScale: 0.25, topControlScale: 0.56, topArcLiftScale: 0.18, midWidenScale: 0.56, midWidenYScale: 0.26, bottomShoulderYScale: 0.74 },
    { coreRxTopScale: 0.2, coreRxBottomScale: 0.49, coreRyTopScale: 0.26, coreRyBottomScale: 0.6, falloffFeatherScale: 1.12, falloffSizeScale: 0.115, outerRxTopScale: 0.5, outerRxBottomScale: 1.28, outerRyTopScale: 0.64, outerRyBottomScale: 1.46, headCenterYScale: 0.21, neckYScale: 0.1, neckWidthScale: 0.9, bottomArcDropScale: 0.26, topControlScale: 0.57, topArcLiftScale: 0.19, midWidenScale: 0.57, midWidenYScale: 0.27, bottomShoulderYScale: 0.75 },
    { coreRxTopScale: 0.21, coreRxBottomScale: 0.5, coreRyTopScale: 0.27, coreRyBottomScale: 0.61, falloffFeatherScale: 1.14, falloffSizeScale: 0.12, outerRxTopScale: 0.52, outerRxBottomScale: 1.31, outerRyTopScale: 0.66, outerRyBottomScale: 1.49, headCenterYScale: 0.22, neckYScale: 0.11, neckWidthScale: 0.92, bottomArcDropScale: 0.27, topControlScale: 0.58, topArcLiftScale: 0.2, midWidenScale: 0.58, midWidenYScale: 0.28, bottomShoulderYScale: 0.76 },
    { coreRxTopScale: 0.22, coreRxBottomScale: 0.52, coreRyTopScale: 0.28, coreRyBottomScale: 0.63, falloffFeatherScale: 1.16, falloffSizeScale: 0.125, outerRxTopScale: 0.54, outerRxBottomScale: 1.34, outerRyTopScale: 0.68, outerRyBottomScale: 1.52, headCenterYScale: 0.23, neckYScale: 0.12, neckWidthScale: 0.93, bottomArcDropScale: 0.28, topControlScale: 0.59, topArcLiftScale: 0.2, midWidenScale: 0.59, midWidenYScale: 0.29, bottomShoulderYScale: 0.78 },
    { coreRxTopScale: 0.23, coreRxBottomScale: 0.54, coreRyTopScale: 0.29, coreRyBottomScale: 0.65, falloffFeatherScale: 1.18, falloffSizeScale: 0.13, outerRxTopScale: 0.56, outerRxBottomScale: 1.38, outerRyTopScale: 0.7, outerRyBottomScale: 1.56, headCenterYScale: 0.235, neckYScale: 0.12, neckWidthScale: 0.95, bottomArcDropScale: 0.29, topControlScale: 0.6, topArcLiftScale: 0.21, midWidenScale: 0.6, midWidenYScale: 0.3, bottomShoulderYScale: 0.79 },
    { coreRxTopScale: 0.24, coreRxBottomScale: 0.56, coreRyTopScale: 0.3, coreRyBottomScale: 0.67, falloffFeatherScale: 1.2, falloffSizeScale: 0.135, outerRxTopScale: 0.58, outerRxBottomScale: 1.41, outerRyTopScale: 0.72, outerRyBottomScale: 1.59, headCenterYScale: 0.24, neckYScale: 0.13, neckWidthScale: 0.96, bottomArcDropScale: 0.3, topControlScale: 0.61, topArcLiftScale: 0.22, midWidenScale: 0.61, midWidenYScale: 0.31, bottomShoulderYScale: 0.8 },
    { coreRxTopScale: 0.25, coreRxBottomScale: 0.58, coreRyTopScale: 0.31, coreRyBottomScale: 0.69, falloffFeatherScale: 1.22, falloffSizeScale: 0.14, outerRxTopScale: 0.6, outerRxBottomScale: 1.44, outerRyTopScale: 0.74, outerRyBottomScale: 1.62, headCenterYScale: 0.245, neckYScale: 0.14, neckWidthScale: 0.97, bottomArcDropScale: 0.31, topControlScale: 0.62, topArcLiftScale: 0.23, midWidenScale: 0.62, midWidenYScale: 0.32, bottomShoulderYScale: 0.82 },
    { coreRxTopScale: 0.26, coreRxBottomScale: 0.6, coreRyTopScale: 0.32, coreRyBottomScale: 0.71, falloffFeatherScale: 1.24, falloffSizeScale: 0.145, outerRxTopScale: 0.62, outerRxBottomScale: 1.48, outerRyTopScale: 0.76, outerRyBottomScale: 1.66, headCenterYScale: 0.25, neckYScale: 0.14, neckWidthScale: 0.98, bottomArcDropScale: 0.32, topControlScale: 0.63, topArcLiftScale: 0.24, midWidenScale: 0.63, midWidenYScale: 0.33, bottomShoulderYScale: 0.84 }
  ];
  const idx = Math.max(1, Math.min(10, Math.round(toNumber(value, 1)))) - 1;
  return presets[idx] ?? presets[0];
}
function drawRadialFallbackBlur(ctx, layer, video, canvasWidth, canvasHeight, blurPx, maskVariant, transitionGain) {
  const variant = Math.max(1, Math.min(10, Math.round(toNumber(maskVariant, 1))));
  const t = (variant - 1) / 9;
  const transition = resolveBlurTransitionProfile(transitionGain);
  const centerBlurAlpha = transition.fallbackCenterBlurAlpha;
  const cx = canvasWidth * 0.5;
  const cy = canvasHeight * (0.45 - t * 0.02);
  const inner = Math.max(34, Math.min(canvasWidth, canvasHeight) * (0.145 + t * 0.025));
  const outer = Math.max(inner + 56, Math.min(canvasWidth, canvasHeight) * (0.43 + t * 0.07));
  layer.save();
  layer.globalCompositeOperation = "copy";
  layer.filter = `blur(${blurPx}px)`;
  layer.drawImage(video, 0, 0, canvasWidth, canvasHeight);
  layer.restore();
  const alphaMask = layer.createRadialGradient(cx, cy, inner, cx, cy, outer);
  alphaMask.addColorStop(0, `rgba(0, 0, 0, ${centerBlurAlpha})`);
  alphaMask.addColorStop(0.2, `rgba(0, 0, 0, ${lerp(centerBlurAlpha, 0.35, 0.4)})`);
  alphaMask.addColorStop(0.4, `rgba(0, 0, 0, ${lerp(centerBlurAlpha, 0.58, 0.58)})`);
  alphaMask.addColorStop(0.62, `rgba(0, 0, 0, ${lerp(centerBlurAlpha, 0.78, 0.75)})`);
  alphaMask.addColorStop(0.8, `rgba(0, 0, 0, ${lerp(centerBlurAlpha, 0.92, 0.9)})`);
  alphaMask.addColorStop(1, "rgba(0, 0, 0, 1)");
  layer.save();
  layer.globalCompositeOperation = "destination-in";
  layer.fillStyle = alphaMask;
  layer.fillRect(0, 0, canvasWidth, canvasHeight);
  layer.restore();
  ctx.drawImage(video, 0, 0, canvasWidth, canvasHeight);
  ctx.drawImage(layer.canvas, 0, 0, canvasWidth, canvasHeight);
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
  const selection = selectBackgroundFilterBackend();
  if (!selection.supported) {
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
  const blurPx = Math.max(4, Math.min(28, Math.round(toNumber(options.blurPx, 6))));
  const maskVariant = Math.max(1, Math.min(10, Math.round(toNumber(options.maskVariant, 1))));
  const transitionGain = Math.max(1, Math.min(10, Math.round(toNumber(options.transitionGain, 10))));
  const backgroundColor = String(options.backgroundColor ?? "").trim();
  const backgroundImageUrl = String(options.backgroundImageUrl ?? "").trim();
  const facePaddingPx = Math.max(4, Math.min(64, Math.round(toNumber(options.facePaddingPx, 14))));
  const edgeFeatherPx = Math.max(0, Math.min(48, Math.round(toNumber(options.edgeFeatherPx, 16))));
  const temporalSmoothingAlpha = Math.max(0, Math.min(0.95, toNumber(options.temporalSmoothingAlpha, 0.55)));
  const detectIntervalMs = Math.max(100, Math.min(1200, Math.round(toNumber(options.detectIntervalMs, 220))));
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
  let segmentationBackend;
  try {
    if (selection.backend === "face_detector") {
      try {
        segmentationBackend = createBackgroundSegmentationBackend("face_detector", { detectIntervalMs, facePaddingPx });
      } catch {
        segmentationBackend = createBackgroundSegmentationBackend("center_mask_fallback", { detectIntervalMs, facePaddingPx });
      }
    } else {
      let mpBackend = null;
      let tfBackend = null;
      if (MEDIAPIPE_SEGMENTATION_ENABLED) {
        try {
          mpBackend = await createMediaPipeSegmentationBackend({ detectIntervalMs });
        } catch {
          mpBackend = null;
        }
      }
      if (!mpBackend && TFJS_SEGMENTATION_ENABLED) {
        try {
          tfBackend = await createTfjsSegmentationBackend({ detectIntervalMs, facePaddingPx });
        } catch {
          tfBackend = null;
        }
      }
      segmentationBackend = mpBackend ?? tfBackend ?? createBackgroundSegmentationBackend("center_mask_fallback", { detectIntervalMs, facePaddingPx });
    }
  } catch {
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
  let frameCount = 0;
  let detectCount = 0;
  let detectDurationSum = 0;
  let processDurationSum = 0;
  let statsWindowStartAt = performance.now();
  const frameBudgetMs = Math.max(1e3 / Math.max(1, fps), 1e3 / 24);
  let nextFrameDueAt = performance.now();
  let overloadCooldownUntil = 0;
  let overloadStreak = 0;
  let overloadDisabled = false;
  let lastBackgroundRefreshAt = 0;
  const backgroundRefreshIntervalMs = 125;
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
      nextFrameDueAt = frameStartedAt + frameBudgetMs;
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
    const segmentation = canRunSegmentation ? segmentationBackend.nextFaces(video, vw, vh, now) : { faces, detectSampleMs: null, matteMask: null };
    faces = segmentation.faces;
    smoothedFaces = smoothFaceBoxes(smoothedFaces, faces, temporalSmoothingAlpha);
    if (typeof segmentation.detectSampleMs === "number" && Number.isFinite(segmentation.detectSampleMs)) {
      detectCount += 1;
      detectDurationSum += Math.max(0, segmentation.detectSampleMs);
    }
    const shouldRefreshMask = Boolean(segmentation.matteMask) && (segmentation.detectSampleMs !== null || !hasMatteMask);
    if (shouldRefreshMask && segmentation.matteMask) {
      if (!previousMaskAlpha || previousMaskAlpha.length !== canvas.width * canvas.height) {
        previousMaskAlpha = new Uint8ClampedArray(canvas.width * canvas.height);
      }
      hasMatteMask = buildInnerFeatherMask(
        maskLayer,
        segmentation.matteMask,
        videoSampleLayer,
        video,
        canvas.width,
        canvas.height,
        smoothedFaces,
        vw,
        vh,
        previousMaskAlpha,
        preferFastMatte
      );
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
        backgroundLayer.filter = `blur(${Math.max(2, Math.round(blurPx * 0.75))}px)`;
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
    } else if (smoothedFaces.length > 0) {
      for (const face of smoothedFaces) {
        drawQuickFacePatch(ctx, video, canvas.width, canvas.height, vw, vh, face);
      }
    } else {
      ctx.save();
      ctx.filter = "none";
      ctx.drawImage(video, 0, 0, canvas.width, canvas.height);
      ctx.restore();
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
            thresholdMs: overloadFrameMs
          });
        } catch {
        }
      }
    }
    if (frameProcessMs > frameBudgetMs * 1.5) {
      overloadCooldownUntil = now + Math.max(frameBudgetMs * 3, 180);
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
          targetFps: fps,
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
    reason: segmentationBackend.kind === "face_detector" ? "ok" : "ok_fallback",
    backend: segmentationBackend.kind,
    dispose
  };
}
export {
  createBackgroundFilterStream,
  resolveProcessingSpec
};
