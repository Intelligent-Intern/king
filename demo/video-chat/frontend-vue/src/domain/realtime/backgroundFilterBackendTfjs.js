const TFJS_CORE_URL = 'https://cdn.jsdelivr.net/npm/@tensorflow/tfjs-core/dist/tf-core.min.js';
const TFJS_CONVERTER_URL = 'https://cdn.jsdelivr.net/npm/@tensorflow/tfjs-converter/dist/tf-converter.min.js';
const TFJS_BACKEND_WEBGL_URL = 'https://cdn.jsdelivr.net/npm/@tensorflow/tfjs-backend-webgl/dist/tf-backend-webgl.min.js';
const TFJS_BODY_SEGMENTATION_URL =
  'https://cdn.jsdelivr.net/npm/@tensorflow-models/body-segmentation/dist/body-segmentation.min.js';

let runtimeLoadPromise = null;

function clampBox(box, maxW, maxH) {
  const x = Math.max(0, Math.min(maxW, box.x));
  const y = Math.max(0, Math.min(maxH, box.y));
  const width = Math.max(0, Math.min(maxW - x, box.width));
  const height = Math.max(0, Math.min(maxH - y, box.height));
  return { x, y, width, height };
}

async function ensureScript(url, dataKey, timeoutMs = 5000) {
  if (typeof document === 'undefined') return false;
  const attr = `data-vc-${dataKey}`;
  const existing = document.querySelector(`script[${attr}="1"]`);
  if (existing) return true;
  return await new Promise((resolve) => {
    const script = document.createElement('script');
    script.src = url;
    script.async = true;
    script.defer = true;
    script.setAttribute(attr, '1');
    script.addEventListener('load', () => resolve(true), { once: true });
    script.addEventListener('error', () => resolve(false), { once: true });
    document.head.appendChild(script);
    setTimeout(() => resolve(false), timeoutMs);
  });
}

function readBoxFromSegment(segment) {
  if (!segment || typeof segment !== 'object') return null;
  const box = segment.box || segment.boundingBox || null;
  if (!box || typeof box !== 'object') return null;

  const xMin = Number(box.xMin ?? box.x ?? box.left ?? NaN);
  const yMin = Number(box.yMin ?? box.y ?? box.top ?? NaN);
  const xMax = Number(box.xMax ?? NaN);
  const yMax = Number(box.yMax ?? NaN);
  const width = Number(box.width ?? (Number.isFinite(xMax) && Number.isFinite(xMin) ? xMax - xMin : NaN));
  const height = Number(box.height ?? (Number.isFinite(yMax) && Number.isFinite(yMin) ? yMax - yMin : NaN));

  if (!Number.isFinite(xMin) || !Number.isFinite(yMin) || !Number.isFinite(width) || !Number.isFinite(height)) return null;
  if (width <= 0 || height <= 0) return null;
  return { x: xMin, y: yMin, width, height };
}

function readMaskFromSegment(segment) {
  if (!segment || typeof segment !== 'object') return null;
  const candidates = [segment.mask, segment.segmentationMask];
  for (const candidate of candidates) {
    if (!candidate || typeof candidate !== 'object') continue;
    if (typeof candidate.toCanvasImageSource === 'function') {
      try {
        const output = candidate.toCanvasImageSource();
        if (output) return output;
      } catch {
        // ignore and continue
      }
    }
    if (candidate instanceof HTMLCanvasElement || candidate instanceof HTMLImageElement) {
      return candidate;
    }
  }
  return null;
}

async function loadRuntime() {
  if (typeof window === 'undefined' || typeof document === 'undefined') return false;
  if (runtimeLoadPromise) return runtimeLoadPromise;

  runtimeLoadPromise = (async () => {
    if (!(await ensureScript(TFJS_CORE_URL, 'tfjs-core'))) return false;
    if (!(await ensureScript(TFJS_CONVERTER_URL, 'tfjs-converter'))) return false;
    if (!(await ensureScript(TFJS_BACKEND_WEBGL_URL, 'tfjs-backend-webgl'))) return false;
    if (!(await ensureScript(TFJS_BODY_SEGMENTATION_URL, 'tfjs-body-segmentation'))) return false;
    return true;
  })().finally(() => {
    runtimeLoadPromise = null;
  });

  return runtimeLoadPromise;
}

export async function createTfjsSegmentationBackend(opts = {}) {
  if (!(await loadRuntime())) return null;

  const tf = window.tf;
  const bodySegmentation = window.bodySegmentation;
  if (!tf || !bodySegmentation || typeof bodySegmentation.createSegmenter !== 'function') return null;

  try {
    if (typeof tf.setBackend === 'function') {
      await tf.setBackend('webgl').catch(() => undefined);
    }
    if (typeof tf.ready === 'function') {
      await tf.ready().catch(() => undefined);
    }
  } catch {
    // continue with best effort.
  }

  const supportedModels = bodySegmentation.SupportedModels || {};
  const model =
    supportedModels.MediaPipeSelfieSegmentation
    || supportedModels.BodyPix
    || Object.values(supportedModels)[0];
  if (!model) return null;

  let segmenter = null;
  try {
    segmenter = await bodySegmentation.createSegmenter(model, {
      runtime: 'tfjs',
      modelType: 'general',
    });
  } catch {
    return null;
  }
  if (!segmenter || typeof segmenter.segmentPeople !== 'function') return null;

  const detectIntervalMs = Math.max(66, Math.min(1200, Math.round(Number(opts.detectIntervalMs || 140))));
  const facePaddingPx = Math.max(4, Math.min(64, Math.round(Number(opts.facePaddingPx || 14))));

  let faces = [];
  let matteMask = null;
  let detectPending = false;
  let lastDetectAt = 0;
  let pendingSampleMs = null;
  let disposed = false;

  return {
    kind: 'tfjs',
    nextFaces(video, vw, vh, nowMs) {
      if (disposed) return { faces: [], detectSampleMs: null, matteMask: null };

      if (!detectPending && nowMs - lastDetectAt >= detectIntervalMs) {
        detectPending = true;
        lastDetectAt = nowMs;
        const detectStartedAt = performance.now();
        void segmenter
          .segmentPeople(video, {
            multiSegmentation: false,
            segmentBodyParts: false,
          })
          .then((segments) => {
            const rows = Array.isArray(segments) ? segments : [];
            const first = rows[0] || null;
            const box = readBoxFromSegment(first);
            if (box) {
              faces = [clampBox({
                x: box.x - facePaddingPx,
                y: box.y - facePaddingPx,
                width: box.width + facePaddingPx * 2,
                height: box.height + facePaddingPx * 2,
              }, vw, vh)];
            }
            matteMask = readMaskFromSegment(first);
          })
          .catch(() => {
            // keep last good state.
          })
          .finally(() => {
            pendingSampleMs = Math.max(0, performance.now() - detectStartedAt);
            detectPending = false;
          });
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
        if (typeof segmenter.dispose === 'function') segmenter.dispose();
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
