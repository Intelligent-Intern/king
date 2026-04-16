const MEDIAPIPE_SELFIE_SEGMENTATION_URL =
  'https://cdn.jsdelivr.net/npm/@mediapipe/selfie_segmentation/selfie_segmentation.js';
const MEDIAPIPE_SELFIE_SEGMENTATION_BASE_URL =
  'https://cdn.jsdelivr.net/npm/@mediapipe/selfie_segmentation/';

let runtimeLoadPromise = null;

function silenceMediaPipeWasmLogs() {
  if (typeof window === 'undefined') return;
  const existing = window.createMediapipeSolutionsWasm;
  const target = existing && typeof existing === 'object' ? existing : {};
  target.print = () => {};
  target.printErr = () => {};
  window.createMediapipeSolutionsWasm = target;
}

function installMediaPipeConsoleNoiseFilter() {
  if (typeof window === 'undefined' || typeof console === 'undefined') return;
  if (window.__vcMediaPipeConsoleFilterInstalled === true) return;

  const shouldDrop = (args) => {
    const first = typeof args?.[0] === 'string' ? args[0] : '';
    if (first === '') return false;
    return (
      /selfie_segmentation_solution/i.test(first)
      || /gl_context(?:_webgl)?\.cc/i.test(first)
      || /OpenGL error checking is disabled/i.test(first)
      || /Found unchecked GL error/i.test(first)
      || /Ignoring unchecked GL error/i.test(first)
      || /WebGL:\s*INVALID_VALUE:\s*texImage2D:\s*no video/i.test(first)
      || /GL_INVALID_FRAMEBUFFER_OPERATION/i.test(first)
    );
  };

  const wrap = (fn) => {
    if (typeof fn !== 'function') return fn;
    return (...args) => {
      if (shouldDrop(args)) return;
      fn(...args);
    };
  };

  console.log = wrap(console.log.bind(console));
  console.info = wrap(console.info.bind(console));
  console.warn = wrap(console.warn.bind(console));
  console.error = wrap(console.error.bind(console));
  window.__vcMediaPipeConsoleFilterInstalled = true;
}

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
  silenceMediaPipeWasmLogs();
  installMediaPipeConsoleNoiseFilter();

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
    segmenter.setOptions({ modelSelection: 0 });
  } catch {
    return null;
  }

  const detectIntervalMs = Math.max(100, Math.min(1200, Math.round(Number(opts.detectIntervalMs || 220))));
  const scratchCanvas = document.createElement('canvas');
  const frameCanvas = document.createElement('canvas');
  const frameCtx = frameCanvas.getContext('2d', { alpha: false, desynchronized: true });

  let faces = [];
  let matteMask = null;
  let detectPending = false;
  let lastDetectAt = 0;
  let pendingSampleMs = null;
  let detectStartedAt = 0;
  let disposed = false;
  let detectInputWidth = 0;
  let detectInputHeight = 0;
  let detectSourceWidth = 0;
  let detectSourceHeight = 0;

  try {
    segmenter.onResults((results) => {
      if (disposed) return;
      matteMask = results?.segmentationMask || null;
      if (
        matteMask
        && detectInputWidth > 1
        && detectInputHeight > 1
        && detectSourceWidth > 1
        && detectSourceHeight > 1
      ) {
        const estimate = estimatePersonBox(matteMask, scratchCanvas, detectInputWidth, detectInputHeight);
        if (estimate) {
          const scaleX = detectSourceWidth / Math.max(1, detectInputWidth);
          const scaleY = detectSourceHeight / Math.max(1, detectInputHeight);
          faces = [clampBox({
            x: estimate.x * scaleX,
            y: estimate.y * scaleY,
            width: estimate.width * scaleX,
            height: estimate.height * scaleY,
          }, detectSourceWidth, detectSourceHeight)];
        }
      }
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
      const hasFrame = video instanceof HTMLVideoElement
        && video.readyState >= 2
        && Number(video.videoWidth || vw) > 1
        && Number(video.videoHeight || vh) > 1
        && !video.ended;
      if (!hasFrame) {
        detectPending = false;
        return { faces, detectSampleMs: null, matteMask };
      }

      if (!detectPending && nowMs - lastDetectAt >= detectIntervalMs) {
        if (!frameCtx) {
          return { faces, detectSampleMs: null, matteMask };
        }
        detectPending = true;
        lastDetectAt = nowMs;
        detectStartedAt = performance.now();
        detectSourceWidth = Math.max(1, Math.round(vw));
        detectSourceHeight = Math.max(1, Math.round(vh));
        frameCanvas.width = Math.max(1, Math.round(vw));
        frameCanvas.height = Math.max(1, Math.round(vh));
        detectInputWidth = frameCanvas.width;
        detectInputHeight = frameCanvas.height;
        try {
          frameCtx.drawImage(video, 0, 0, frameCanvas.width, frameCanvas.height);
        } catch {
          detectPending = false;
          return { faces, detectSampleMs: null, matteMask };
        }
        void segmenter.send({ image: frameCanvas }).catch(() => {
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
      detectInputWidth = 0;
      detectInputHeight = 0;
      detectSourceWidth = 0;
      detectSourceHeight = 0;
    },
  };
}
