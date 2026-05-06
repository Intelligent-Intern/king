import { createKingBackgroundMatteRefiner } from '../../../lib/wasm/wasm-codec';

function clampBox(box, maxW, maxH) {
  const x = Math.max(0, Math.min(maxW, box.x));
  const y = Math.max(0, Math.min(maxH, box.y));
  const width = Math.max(0, Math.min(maxW - x, box.width));
  const height = Math.max(0, Math.min(maxH - y, box.height));
  return { x, y, width, height };
}

function estimatePersonBoxFromAlpha(alpha, width, height) {
  const step = 4;
  let minX = width;
  let minY = height;
  let maxX = 0;
  let maxY = 0;
  let hits = 0;
  for (let y = 0; y < height; y += step) {
    for (let x = 0; x < width; x += step) {
      const value = alpha[y * width + x] ?? 0;
      if (value < 40) continue;
      hits += 1;
      minX = Math.min(minX, x);
      minY = Math.min(minY, y);
      maxX = Math.max(maxX, x);
      maxY = Math.max(maxY, y);
    }
  }
  if (hits <= 0 || maxX <= minX || maxY <= minY) return null;
  const pad = 12;
  return clampBox({
    x: minX - pad,
    y: minY - pad,
    width: maxX - minX + pad * 2,
    height: maxY - minY + pad * 2,
  }, width, height);
}

function writeAlphaMaskToCanvas(ctx, alpha, width, height) {
  const image = ctx.createImageData(width, height);
  for (let i = 0; i < alpha.length; i += 1) {
    const p = i * 4;
    image.data[p] = 255;
    image.data[p + 1] = 255;
    image.data[p + 2] = 255;
    image.data[p + 3] = alpha[i] ?? 0;
  }
  ctx.putImageData(image, 0, 0);
}

function toMattePreset(value) {
  const normalized = String(value || '').trim().toLowerCase();
  if (normalized === 'hard_blur' || normalized === 'hard' || normalized === 'strong') return 'hard_blur';
  if (normalized === 'replace' || normalized === 'background') return 'replace';
  return 'weak_blur';
}

export async function createKingWasmSegmentationBackend(opts = {}) {
  if (typeof document === 'undefined') return null;

  const detectIntervalMs = Math.max(66, Math.min(1200, Math.round(Number(opts.detectIntervalMs || 140))));
  const width = Math.max(1, Math.round(Number(opts.width || 256)));
  const height = Math.max(1, Math.round(Number(opts.height || 144)));
  const refiner = await createKingBackgroundMatteRefiner({
    width,
    height,
    preset: toMattePreset(opts.mattePreset),
  });
  if (!refiner) return null;

  const sampleCanvas = document.createElement('canvas');
  sampleCanvas.width = width;
  sampleCanvas.height = height;
  const sampleCtx = sampleCanvas.getContext('2d', { alpha: false, desynchronized: true, willReadFrequently: true });
  const maskCanvas = document.createElement('canvas');
  maskCanvas.width = width;
  maskCanvas.height = height;
  const maskCtx = maskCanvas.getContext('2d', { alpha: true, desynchronized: true });
  if (!sampleCtx || !maskCtx) {
    refiner.destroy();
    return null;
  }

  let faces = [];
  let matteMask = null;
  let lastDetectAt = 0;
  let pendingSampleMs = null;
  let disposed = false;

  return {
    kind: 'king_wasm',
    nextFaces(video, vw, vh, nowMs) {
      if (disposed) return { faces: [], detectSampleMs: null, matteMask: null };
      if (nowMs - lastDetectAt < detectIntervalMs) {
        const sample = pendingSampleMs;
        pendingSampleMs = null;
        return { faces, detectSampleMs: sample, matteMask };
      }

      lastDetectAt = nowMs;
      const detectStartedAt = performance.now();
      try {
        sampleCtx.drawImage(video, 0, 0, width, height);
        const frame = sampleCtx.getImageData(0, 0, width, height);
        const alpha = refiner.segment(frame.data);
        if (!alpha || alpha.length !== width * height) {
          return { faces, detectSampleMs: null, matteMask };
        }
        writeAlphaMaskToCanvas(maskCtx, alpha, width, height);
        matteMask = maskCanvas;
        const box = estimatePersonBoxFromAlpha(alpha, width, height);
        if (box) {
          const scaleX = vw / width;
          const scaleY = vh / height;
          faces = [clampBox({
            x: box.x * scaleX,
            y: box.y * scaleY,
            width: box.width * scaleX,
            height: box.height * scaleY,
          }, vw, vh)];
        }
      } catch {
        // Keep the last usable matte and face box.
      }
      pendingSampleMs = Math.max(0, performance.now() - detectStartedAt);
      const sample = pendingSampleMs;
      pendingSampleMs = null;
      return { faces, detectSampleMs: sample, matteMask };
    },
    dispose() {
      disposed = true;
      faces = [];
      matteMask = null;
      refiner.destroy();
    },
  };
}
