const MEDIAPIPE_SELFIE_SEGMENTATION_URL =
  'https://cdn.jsdelivr.net/npm/@mediapipe/selfie_segmentation/selfie_segmentation.js';
const MEDIAPIPE_SELFIE_SEGMENTATION_BASE_URL =
  'https://cdn.jsdelivr.net/npm/@mediapipe/selfie_segmentation/';

let runtimeLoadPromise = null;

function clampBox(box, maxW, maxH) {
  const x = Math.max(0, Math.min(maxW, box.x));
  const y = Math.max(0, Math.min(maxH, box.y));
  const width = Math.max(0, Math.min(maxW - x, box.width));
  const height = Math.max(0, Math.min(maxH - y, box.height));
  return { x, y, width, height };
}

function getCtorFromWindow() {
  const ctorMaybe = window.SelfieSegmentation;
  return typeof ctorMaybe === 'function' ? ctorMaybe : null;
}

function hasRuntime() {
  return typeof window !== 'undefined' && Boolean(getCtorFromWindow());
}

async function loadRuntime(timeoutMs = 5000) {
  if (typeof window === 'undefined' || typeof document === 'undefined') return false;
  if (hasRuntime()) return true;
  if (runtimeLoadPromise) return runtimeLoadPromise;

  runtimeLoadPromise = new Promise((resolve) => {
    const existing = document.querySelector('script[data-vc-mediapipe-selfie-segmentation="1"]');
    if (existing) {
      const done = () => resolve(hasRuntime());
      existing.addEventListener('load', done, { once: true });
      existing.addEventListener('error', () => resolve(false), { once: true });
      setTimeout(() => resolve(hasRuntime()), timeoutMs);
      return;
    }

    const script = document.createElement('script');
    script.src = MEDIAPIPE_SELFIE_SEGMENTATION_URL;
    script.async = true;
    script.defer = true;
    script.dataset.vcMediapipeSelfieSegmentation = '1';
    script.addEventListener('load', () => resolve(hasRuntime()), { once: true });
    script.addEventListener('error', () => resolve(false), { once: true });
    document.head.appendChild(script);
    setTimeout(() => resolve(hasRuntime()), timeoutMs);
  }).finally(() => {
    runtimeLoadPromise = null;
  });

  return runtimeLoadPromise;
}

function estimatePersonBox(maskSource, scratchCanvas, vw, vh) {
  if (!(scratchCanvas instanceof HTMLCanvasElement)) return null;
  const ctx = scratchCanvas.getContext('2d', { willReadFrequently: true });
  if (!ctx) return null;
  scratchCanvas.width = Math.max(1, vw);
  scratchCanvas.height = Math.max(1, vh);
  ctx.clearRect(0, 0, vw, vh);
  try {
    ctx.drawImage(maskSource, 0, 0, vw, vh);
  } catch {
    return null;
  }
  let data;
  try {
    data = ctx.getImageData(0, 0, vw, vh);
  } catch {
    return null;
  }

  const step = 8;
  let minX = vw;
  let minY = vh;
  let maxX = 0;
  let maxY = 0;
  let hits = 0;
  for (let y = 0; y < vh; y += step) {
    for (let x = 0; x < vw; x += step) {
      const i = (y * vw + x) * 4;
      const alpha = data.data[i + 3] || 0;
      if (alpha < 16) continue;
      hits += 1;
      if (x < minX) minX = x;
      if (y < minY) minY = y;
      if (x > maxX) maxX = x;
      if (y > maxY) maxY = y;
    }
  }
  if (hits <= 0 || maxX <= minX || maxY <= minY) return null;
  const pad = 12;
  return clampBox(
    {
      x: minX - pad,
      y: minY - pad,
      width: maxX - minX + pad * 2,
      height: maxY - minY + pad * 2,
    },
    vw,
    vh,
  );
}

export async function createMediaPipeSegmentationBackend(opts = {}) {
  if (!(await loadRuntime())) return null;

  const Ctor = getCtorFromWindow();
  if (!Ctor) return null;

  let segmenter;
  try {
    segmenter = new Ctor({
      locateFile: (file) => `${MEDIAPIPE_SELFIE_SEGMENTATION_BASE_URL}${file}`,
    });
    segmenter.setOptions({ modelSelection: 1 });
  } catch {
    return null;
  }

  const detectIntervalMs = Math.max(100, Math.min(1200, Math.round(Number(opts.detectIntervalMs || 220))));
  const scratchCanvas = document.createElement('canvas');

  let faces = [];
  let matteMask = null;
  let detectPending = false;
  let lastDetectAt = 0;
  let pendingSampleMs = null;
  let detectStartedAt = 0;
  let disposed = false;

  try {
    segmenter.onResults((results) => {
      if (disposed) return;
      matteMask = results?.segmentationMask || null;
      pendingSampleMs = Math.max(0, performance.now() - detectStartedAt);
      detectPending = false;
    });
  } catch {
    return null;
  }

  return {
    kind: 'mediapipe',
    nextFaces(video, vw, vh, nowMs) {
      if (disposed) return { faces: [], detectSampleMs: null, matteMask: null };

      if (!detectPending && nowMs - lastDetectAt >= detectIntervalMs) {
        detectPending = true;
        lastDetectAt = nowMs;
        detectStartedAt = performance.now();
        void segmenter.send({ image: video }).catch(() => {
          detectPending = false;
        });
      }

      if (matteMask) {
        const estimate = estimatePersonBox(matteMask, scratchCanvas, vw, vh);
        faces = estimate ? [estimate] : faces;
      }

      const sample = pendingSampleMs;
      pendingSampleMs = null;
      return {
        faces,
        detectSampleMs: sample,
        matteMask,
      };
    },
    dispose() {
      disposed = true;
      try {
        if (typeof segmenter.reset === 'function') segmenter.reset();
      } catch {
        // ignore
      }
      try {
        if (typeof segmenter.close === 'function') segmenter.close();
      } catch {
        // ignore
      }
      faces = [];
      matteMask = null;
      detectPending = false;
      pendingSampleMs = null;
    },
  };
}
