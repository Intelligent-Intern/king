function clampByte(value) {
  return Math.max(0, Math.min(255, Math.round(value)));
}

function clamp01(value) {
  return Math.max(0, Math.min(1, Number(value) || 0));
}

function smoothstep(edge0, edge1, value) {
  if (value <= edge0) return 0;
  if (value >= edge1) return 1;
  const t = (value - edge0) / Math.max(1e-6, edge1 - edge0);
  return t * t * (3 - 2 * t);
}

export const DEFAULT_INNER_CONTRACT_PX = 16;
export const DEFAULT_INNER_FEATHER_PX = 24;

const INNER_FEATHER_RAMP = [
  { progress: 0.0, alpha: 0.05 },
  { progress: 0.2, alpha: 0.15 },
  { progress: 0.4, alpha: 0.4 },
  { progress: 0.6, alpha: 0.7 },
  { progress: 0.8, alpha: 0.9 },
  { progress: 1.0, alpha: 1.0 },
];

export function sampleInnerFeatherRamp(progress) {
  const t = clamp01(progress);
  let previous = INNER_FEATHER_RAMP[0];
  for (let index = 1; index < INNER_FEATHER_RAMP.length; index += 1) {
    const next = INNER_FEATHER_RAMP[index];
    if (t > next.progress) {
      previous = next;
      continue;
    }
    const span = Math.max(1e-6, next.progress - previous.progress);
    const localT = (t - previous.progress) / span;
    return previous.alpha + (next.alpha - previous.alpha) * localT;
  }
  return INNER_FEATHER_RAMP[INNER_FEATHER_RAMP.length - 1].alpha;
}

export function buildInnerDistanceFeatherAlpha(base, width, height, threshold = 110) {
  const sourceWidth = Math.max(1, Math.round(Number(width) || 1));
  const sourceHeight = Math.max(1, Math.round(Number(height) || 1));
  const pixelCount = sourceWidth * sourceHeight;
  if (!base || base.length < pixelCount) return new Uint8ClampedArray(pixelCount);

  const maxInset = Math.max(1, Math.floor(Math.min(sourceWidth, sourceHeight) / 3));
  const contractPx = Math.min(DEFAULT_INNER_CONTRACT_PX, maxInset);
  const featherPx = Math.max(1, Math.min(DEFAULT_INNER_FEATHER_PX, maxInset));
  const cutoff = Math.max(0, Math.min(255, Math.round(Number(threshold) || 0)));
  const dist = new Float32Array(pixelCount);
  const inf = sourceWidth + sourceHeight + 1;
  const diagonal = Math.SQRT2;

  for (let y = 0; y < sourceHeight; y += 1) {
    for (let x = 0; x < sourceWidth; x += 1) {
      const index = y * sourceWidth + x;
      const value = Number(base[index]) || 0;
      const isImageEdge = x === 0 || y === 0 || x === sourceWidth - 1 || y === sourceHeight - 1;
      dist[index] = value >= cutoff && !isImageEdge ? inf : 0;
    }
  }

  for (let y = 0; y < sourceHeight; y += 1) {
    for (let x = 0; x < sourceWidth; x += 1) {
      const index = y * sourceWidth + x;
      let best = dist[index];
      if (x > 0) best = Math.min(best, dist[index - 1] + 1);
      if (y > 0) best = Math.min(best, dist[index - sourceWidth] + 1);
      if (x > 0 && y > 0) best = Math.min(best, dist[index - sourceWidth - 1] + diagonal);
      if (x + 1 < sourceWidth && y > 0) best = Math.min(best, dist[index - sourceWidth + 1] + diagonal);
      dist[index] = best;
    }
  }

  for (let y = sourceHeight - 1; y >= 0; y -= 1) {
    for (let x = sourceWidth - 1; x >= 0; x -= 1) {
      const index = y * sourceWidth + x;
      let best = dist[index];
      if (x + 1 < sourceWidth) best = Math.min(best, dist[index + 1] + 1);
      if (y + 1 < sourceHeight) best = Math.min(best, dist[index + sourceWidth] + 1);
      if (x + 1 < sourceWidth && y + 1 < sourceHeight) best = Math.min(best, dist[index + sourceWidth + 1] + diagonal);
      if (x > 0 && y + 1 < sourceHeight) best = Math.min(best, dist[index + sourceWidth - 1] + diagonal);
      dist[index] = best;
    }
  }

  const out = new Uint8ClampedArray(pixelCount);
  for (let index = 0; index < pixelCount; index += 1) {
    const distance = dist[index];
    if (distance <= contractPx) {
      out[index] = 0;
      continue;
    }
    const t = Math.min(1, Math.max(0, (distance - contractPx) / featherPx));
    const inside = sampleInnerFeatherRamp(t);
    out[index] = clampByte(inside * 255);
  }

  return out;
}

export function buildInnerDistanceFeatherMaskValues(mask, width, height, threshold = 0.43) {
  const sourceWidth = Math.max(1, Math.round(Number(width) || 1));
  const sourceHeight = Math.max(1, Math.round(Number(height) || 1));
  const pixelCount = sourceWidth * sourceHeight;
  if (!(mask instanceof Float32Array) || mask.length < pixelCount) return mask;

  const base = new Uint8ClampedArray(pixelCount);
  for (let index = 0; index < pixelCount; index += 1) {
    base[index] = clampByte(clamp01(mask[index]) * 255);
  }

  const alpha = buildInnerDistanceFeatherAlpha(base, sourceWidth, sourceHeight, threshold * 255);
  const out = new Float32Array(pixelCount);
  for (let index = 0; index < pixelCount; index += 1) {
    out[index] = (alpha[index] ?? 0) / 255;
  }
  return out;
}

function gaussianAverageAlpha(alpha, width, height, radius) {
  if (radius <= 0) return alpha;

  const cappedRadius = Math.max(1, Math.min(12, radius));
  const sigma = Math.max(1.25, cappedRadius / 2.2);
  const kernel = new Float32Array(cappedRadius * 2 + 1);
  let kernelSum = 0;

  for (let k = -cappedRadius; k <= cappedRadius; k += 1) {
    const weight = Math.exp(-(k * k) / (2 * sigma * sigma));
    kernel[k + cappedRadius] = weight;
    kernelSum += weight;
  }

  const tmp = new Float32Array(alpha.length);
  const out = new Uint8ClampedArray(alpha.length);

  for (let y = 0; y < height; y += 1) {
    for (let x = 0; x < width; x += 1) {
      let sum = 0;
      let weightSum = 0;
      for (let k = -cappedRadius; k <= cappedRadius; k += 1) {
        const xx = Math.max(0, Math.min(width - 1, x + k));
        const weight = kernel[k + cappedRadius] ?? 0;
        sum += (alpha[y * width + xx] ?? 0) * weight;
        weightSum += weight;
      }
      tmp[y * width + x] = sum / Math.max(1e-6, weightSum || kernelSum);
    }
  }

  for (let y = 0; y < height; y += 1) {
    for (let x = 0; x < width; x += 1) {
      let sum = 0;
      let weightSum = 0;
      for (let k = -cappedRadius; k <= cappedRadius; k += 1) {
        const yy = Math.max(0, Math.min(height - 1, y + k));
        const weight = kernel[k + cappedRadius] ?? 0;
        sum += (tmp[yy * width + x] ?? 0) * weight;
        weightSum += weight;
      }
      out[y * width + x] = clampByte(sum / Math.max(1e-6, weightSum || kernelSum));
    }
  }

  return out;
}

export function shapeForegroundAlpha(alpha, width, height, controls, previousAlpha = null) {
  const gamma = Math.max(0.4, Math.min(2.5, Number(controls?.gamma) || 0.8));
  const contrast = Math.max(0.25, Math.min(4, Number(controls?.contrast ?? 0.75)));
  const averageRadius = Math.max(0, Math.min(12, Math.round(Number(controls?.averageRadius ?? 6))));
  const contourHalfWidth = Math.max(0.06, Math.min(0.44, 0.26 / contrast));
  const edgeLow = 0.5 - contourHalfWidth;
  const edgeHigh = 0.5 + contourHalfWidth;
  const averagedInput = gaussianAverageAlpha(alpha, width, height, averageRadius);
  const base = new Uint8ClampedArray(alpha.length);

  for (let i = 0; i < alpha.length; i += 1) {
    const raw = (averagedInput[i] ?? 0) / 255;
    const contour = smoothstep(edgeLow, edgeHigh, raw);
    base[i] = clampByte(Math.pow(contour, gamma) * 255);
  }

  const out = buildInnerDistanceFeatherAlpha(base, width, height);
  if (!previousAlpha || previousAlpha.length !== out.length) return out;

  let maxPrevious = 0;
  let maxTarget = 0;
  for (let i = 0; i < out.length; i += 1) {
    maxPrevious = Math.max(maxPrevious, previousAlpha[i] ?? 0);
    maxTarget = Math.max(maxTarget, out[i] ?? 0);
  }
  if (maxPrevious <= 0 && maxTarget > 0) {
    previousAlpha.set(out);
    return out;
  }

  const rise = clamp01(controls?.temporalRise ?? 0.7);
  const fall = clamp01(controls?.temporalFall ?? 0.6);
  for (let i = 0; i < out.length; i += 1) {
    const target = out[i] ?? 0;
    const prev = previousAlpha[i] ?? 0;
    const rate = target >= prev ? rise : fall;
    out[i] = clampByte(prev + (target - prev) * rate);
  }
  previousAlpha.set(out);

  return out;
}
