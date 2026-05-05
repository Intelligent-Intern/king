/**
 * Web Worker: MediaPipe Tasks-Vision ImageSegmenter
 *
 * Uses the vendored selfie segmentation model with CATEGORY_MASK output to produce
 * a foreground alpha mask. All non-background categories become alpha 1.
 *
 * Protocol:
 *   IN  { type: 'INIT', modelAssetPath?, delegate?, wasmPath? }
 *   OUT { type: 'INIT_DONE', labels: string[] }
 *   OUT { type: 'INIT_ERROR', error: string }
 *
 *   IN  { type: 'SEGMENT_VIDEO', bitmap: ImageBitmap, timestampMs: number }
 *       (bitmap is transferred - caller must not reuse it)
 *   OUT { type: 'SEGMENT_RESULT', maskValues: Float32Array|null, width, height, inferenceMs }
 *       (maskValues.buffer is transferred)
 *   OUT { type: 'SEGMENT_ERROR', error: string }
 *
 *   IN  { type: 'CLEANUP' }
 *   OUT { type: 'CLEANUP_DONE' }
 */

const TASKS_VISION_MODULE_PATH = '/cdn/vendor/mediapipe/tasks-vision/vision_bundle.mjs';
const { ImageSegmenter, FilesetResolver } = await import(
  /* @vite-ignore */
  TASKS_VISION_MODULE_PATH
);
const DEFAULT_WASM_PATH = '/wasm';
const DEFAULT_MODEL_PATH = '/cdn/vendor/mediapipe/selfie_segmentation/selfie_segmentation.tflite';

let segmenter = null;
let segmenterLabels = [];
let lastTimestampMs = -1;
let isInitializing = false;
let renderCanvas = null;

function trimTrailingSlash(value) {
  return String(value || '').replace(/\/+$/, '');
}

function buildWasmCandidates(inputPath) {
  const configured = trimTrailingSlash(inputPath || DEFAULT_WASM_PATH);
  const sameOrigin = trimTrailingSlash(self.location.origin);
  const candidates = [
    configured,
    `${sameOrigin}/wasm`,
    `${sameOrigin}/cdn/vendor/mediapipe/wasm`,
    '/wasm',
    '/cdn/vendor/mediapipe/wasm',
  ];
  return Array.from(new Set(candidates.filter(Boolean)));
}

function buildModelCandidates(inputPath) {
  const configured = String(inputPath || DEFAULT_MODEL_PATH);
  if (/^https?:\/\//i.test(configured) || configured.startsWith('/')) {
    return Array.from(new Set([
      configured,
      DEFAULT_MODEL_PATH,
      '/cdn/vendor/mediapipe/selfie_segmentation/selfie_segmentation_landscape.tflite',
    ]));
  }
  return [
    configured,
    `/cdn/vendor/mediapipe/selfie_segmentation/${configured.replace(/^\/+/, '')}`,
    `/cdn/vendor/mediapipe/models/${configured.replace(/^\/+/, '')}`,
  ];
}

async function isFetchableBinary(url) {
  try {
    const res = await fetch(`${url}?cb=${Date.now()}`, { method: 'GET', cache: 'no-store' });
    if (!res.ok) return false;
    const contentType = String(res.headers.get('content-type') || '').toLowerCase();
    if (contentType.includes('text/html')) return false;
    return true;
  } catch {
    return false;
  }
}

async function resolveWasmPath(inputPath) {
  const candidates = buildWasmCandidates(inputPath);
  for (const base of candidates) {
    const probe = `${base}/vision_wasm_internal.js`;
    if (await isFetchableBinary(probe)) return base;
  }
  throw new Error(`No valid Tasks-Vision wasm path found. Tried: ${candidates.join(', ')}`);
}

async function resolveModelPath(inputPath) {
  const candidates = buildModelCandidates(inputPath);
  for (const modelPath of candidates) {
    if (await isFetchableBinary(modelPath)) return modelPath;
  }
  throw new Error(`No valid segmentation model path found. Tried: ${candidates.join(', ')}`);
}

function stripImportQuery(value) {
  if (typeof value !== 'string' || value === '') return value;
  const cleaned = value
    .replace(/[?&]import(?=(&|$))/g, '')
    .replace(/[?&]$/, '');
  return cleaned;
}

function sanitizeFilesetPaths(fileset) {
  if (!fileset || typeof fileset !== 'object') return fileset;
  const keys = Object.keys(fileset);
  for (const key of keys) {
    if (!/Path$/i.test(key)) continue;
    const current = fileset[key];
    if (typeof current !== 'string') continue;
    fileset[key] = stripImportQuery(current);
  }
  return fileset;
}

function clamp01(value) {
  return Math.max(0, Math.min(1, Number(value) || 0));
}

function confidenceMaskValues(confidenceMasks) {
  const masks = Array.isArray(confidenceMasks) ? confidenceMasks : [];
  const firstMask = masks[0] || null;
  const width = Math.max(1, Math.round(Number(firstMask?.width) || 0));
  const height = Math.max(1, Math.round(Number(firstMask?.height) || 0));
  if (!firstMask || width <= 1 || height <= 1) return null;

  const pixelCount = width * height;
  const confidenceArrays = [];
  for (let index = 0; index < masks.length; index += 1) {
    const label = String(segmenterLabels[index] || '').trim().toLowerCase();
    if (segmenterLabels.length === 0 && masks.length > 1 && index === 0) continue;
    if (label === 'background') continue;
    try {
      const values = masks[index]?.getAsFloat32Array?.();
      if (values && values.length >= pixelCount) confidenceArrays.push(values);
    } catch {
      // Ignore a failed class mask and keep combining the rest.
    }
  }

  if (confidenceArrays.length === 0) return null;

  const values = new Float32Array(pixelCount);
  let maxAlpha = 0;
  for (let pixel = 0; pixel < pixelCount; pixel += 1) {
    let alpha = 0;
    for (const classValues of confidenceArrays) {
      alpha = Math.max(alpha, clamp01(classValues[pixel] || 0));
    }
    maxAlpha = Math.max(maxAlpha, alpha);
    values[pixel] = alpha;
  }
  if (maxAlpha <= 0) return null;

  return {
    values,
    width,
    height,
  };
}

function categoryMaskValues(categoryMask) {
  const width = Math.max(1, Math.round(Number(categoryMask?.width) || 0));
  const height = Math.max(1, Math.round(Number(categoryMask?.height) || 0));
  if (!categoryMask || width <= 1 || height <= 1) return null;

  let categoryValues = null;
  try {
    categoryValues = categoryMask.getAsUint8Array();
  } catch {
    return null;
  }
  if (!categoryValues || categoryValues.length < width * height) return null;

  const values = new Float32Array(width * height);
  for (let pixel = 0; pixel < width * height; pixel += 1) {
    values[pixel] = categoryValues[pixel] > 0 ? 1 : 0;
  }

  return {
    values,
    width,
    height,
  };
}

// vision_wasm_internal.js is a classic UMD script that sets self.ModuleFactory.
// In a type:module worker, the Tasks-Vision browser module skips this side
// effect. We must manually fetch+eval it in global scope before
// calling FilesetResolver.forVisionTasks(), otherwise MediaPipe throws
// "ModuleFactory not set".
// DO NOT EVER REMOVE THE FOLLOWING FUNCTION OR THE CALL TO IT, or the worker will fail to initialize with a very confusing error.
async function loadModuleFactory(resolvedWasmPath) {
  const url = `${resolvedWasmPath}/vision_wasm_internal.js`;
  const res = await fetch(`${url}?cb=${Date.now()}`, { cache: 'no-store' });
  if (!res.ok) throw new Error(`Failed to fetch wasm loader: ${res.status} ${url}`);
  const src = await res.text();
  (0, eval)(src);
  if (typeof self.ModuleFactory !== 'function') {
    throw new Error(`ModuleFactory not set after eval of ${url}`);
  }
  console.log('[Worker] ModuleFactory loaded from:', url);
}

async function initialize({ modelAssetPath, delegate, wasmPath }) {
  if (isInitializing) return;
  isInitializing = true;
  try {
    const resolvedWasm = await resolveWasmPath(wasmPath || DEFAULT_WASM_PATH);
    const resolvedModel = await resolveModelPath(modelAssetPath || DEFAULT_MODEL_PATH);

    await loadModuleFactory(resolvedWasm);

    console.log('ModuleFactory before fileset:', typeof self.ModuleFactory);
    const fileset = sanitizeFilesetPaths(await FilesetResolver.forVisionTasks(resolvedWasm));

    const response = await fetch(resolvedModel);
    if (!response.ok) {
      throw new Error(`Failed to fetch model (${response.status}): ${resolvedModel}`);
    }
    const modelBuffer = await response.arrayBuffer();

    if (segmenter) {
      try { segmenter.close(); } catch { /* ignore */ }
      segmenter = null;
    }
    if (!renderCanvas) {
      renderCanvas = new OffscreenCanvas(1, 1);
    }

    segmenter = await ImageSegmenter.createFromOptions(fileset, {
      baseOptions: {
        modelAssetBuffer: new Uint8Array(modelBuffer),
        delegate: delegate === 'GPU' ? 'GPU' : 'CPU',
      },
      canvas: renderCanvas,
      runningMode: 'VIDEO',
      outputCategoryMask: true,
      outputConfidenceMasks: true,
    });

    segmenterLabels = segmenter.getLabels();
    console.log('Segmenter initialized with labels:', segmenterLabels);
    self.postMessage({ type: 'INIT_DONE', labels: segmenterLabels });
  } catch (error) {
    self.postMessage({ type: 'INIT_ERROR', error: error?.message || String(error) });
  } finally {
    isInitializing = false;
  }
}

self.onmessage = async (event) => {
  const { type } = event.data;

  if (type === 'INIT') {
    console.log('Worker received INIT message with config', event.data);
    await initialize(event.data);

  } else if (type === 'SEGMENT_VIDEO' || type === 'SEGMENT_IMAGE') {
    if (!segmenter) {
      event.data.bitmap?.close();
      self.postMessage({ type: 'SEGMENT_ERROR', error: 'Segmenter not initialized' });
      return;
    }
    
    const { bitmap, timestampMs } = event.data;
    const ts = timestampMs > lastTimestampMs ? timestampMs : lastTimestampMs + 1;
    lastTimestampMs = ts;

    const startMs = performance.now();
    try {
      segmenter.segmentForVideo(bitmap, ts, (result) => {
        bitmap.close();
        const inferenceTime = performance.now() - startMs;

        let maskValues = null;
        let width = 0;
        let height = 0;

        const categoryResult = categoryMaskValues(result.categoryMask);
        const fallbackResult = categoryResult || confidenceMaskValues(result.confidenceMasks);
        if (fallbackResult) {
          maskValues = fallbackResult.values;
          width = fallbackResult.width;
          height = fallbackResult.height;
        }

        result.close?.();

        self.postMessage(
          { type: 'SEGMENT_RESULT', mode: 'VIDEO', maskValues, width, height, inferenceTime },
          maskValues ? [maskValues.buffer] : [],
        );
      });
    } catch (e) {
      try { bitmap?.close(); } catch { /* ignore */ }
      self.postMessage({ type: 'SEGMENT_ERROR', error: e?.message || String(e) });
    }

  } else if (type === 'CLEANUP') {
    try { segmenter?.close(); } catch { /* ignore */ }
    segmenter = null;
    segmenterLabels = [];
    renderCanvas = null;
    lastTimestampMs = -1;
    self.postMessage({ type: 'CLEANUP_DONE' });
  }
};
self.postMessage({ type: 'READY' });
