export interface BackgroundMaskShapeControls {
  threshold?: number;
  gamma: number;
  contrast?: number;
  fillRadius: number;
  averageRadius?: number;
  temporalRise?: number;
  temporalFall?: number;
}

function clampByte(value: number): number {
  return Math.max(0, Math.min(255, Math.round(value)));
}

function clamp01(value: number): number {
  return Math.max(0, Math.min(1, Number(value) || 0));
}

function gaussianAverageAlpha(alpha: Uint8ClampedArray, width: number, height: number, radius: number): Uint8ClampedArray {
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

export function shapeForegroundAlpha(
  alpha: Uint8ClampedArray,
  width: number,
  height: number,
  controls: BackgroundMaskShapeControls,
  previousAlpha?: Uint8ClampedArray | null
): Uint8ClampedArray {
  const gamma = Math.max(0.4, Math.min(2.5, Number(controls.gamma) || 0.8));
  const contrast = Math.max(0.25, Math.min(4, Number(controls.contrast ?? 0.75)));
  const fillRadius = Math.max(0, Math.min(4, Math.round(Number(controls.fillRadius) || 0)));
  const averageRadius = Math.max(0, Math.min(12, Math.round(Number(controls.averageRadius ?? 6))));
  const averagedInput = gaussianAverageAlpha(alpha, width, height, averageRadius);
  const out = new Uint8ClampedArray(alpha.length);
  for (let i = 0; i < alpha.length; i += 1) {
    const raw = (averagedInput[i] ?? 0) / 255;
    const contrasted = Math.max(0, Math.min(1, (raw - 0.5) * contrast + 0.5));
    const normalized = Math.max(0, contrasted);
    const value = clampByte(Math.pow(normalized, gamma) * 255);
    out[i] = value;
  }
  if (!previousAlpha || previousAlpha.length !== out.length) return out;

  const rise = clamp01(controls.temporalRise ?? 0.7);
  const fall = clamp01(controls.temporalFall ?? 0.6);
  for (let i = 0; i < out.length; i += 1) {
    const target = out[i] ?? 0;
    const prev = previousAlpha[i] ?? 0;
    const rate = target >= prev ? rise : fall;
    out[i] = clampByte(prev + (target - prev) * rate);
  }
  previousAlpha.set(out);
  return out;
}
