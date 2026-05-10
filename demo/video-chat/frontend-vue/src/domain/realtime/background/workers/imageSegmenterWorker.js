/**
 * Web Worker: MediaPipe Tasks-Vision ImageSegmenter
 *
 * Uses selfie_multiclass_256x256.tflite with CATEGORY_MASK output to produce
 * a MediaPipe-drawn foreground alpha mask.
 *
 * Protocol:
 *   IN  { type: 'INIT', modelAssetPath?, delegate?, wasmPath? }
 *   OUT { type: 'INIT_DONE', labels: string[] }
 *   OUT { type: 'INIT_ERROR', error: string }
 *
 *   IN  { type: 'SEGMENT_VIDEO', bitmap: ImageBitmap, timestampMs: number }
 *       (bitmap is transferred - caller must not reuse it)
 *   OUT { type: 'SEGMENT_RESULT', maskBitmap: ImageBitmap, width, height, inferenceMs }
 *       (maskBitmap is transferred)
 *   OUT { type: 'SEGMENT_ERROR', error: string }
 *
 *   IN  { type: 'CLEANUP' }
 *   OUT { type: 'CLEANUP_DONE' }
 */

const tasksVisionModulePath = typeof TASKS_VISION_MODULE_PATH === 'string'
  ? TASKS_VISION_MODULE_PATH
  : '/cdn/vendor/mediapipe/tasks-vision/vision_bundle.mjs';

async function importStaticModule(modulePath) {
  const moduleUrl = new URL(modulePath, self.location.origin).href;
  return import(
    /* @vite-ignore */
    moduleUrl
  );
}

const { DrawingUtils, ImageSegmenter, FilesetResolver } = await importStaticModule(tasksVisionModulePath);
const DEFAULT_WASM_PATH = '/wasm';
const DEFAULT_MODEL_PATH = '/cdn/vendor/mediapipe/models/selfie_multiclass_256x256.tflite';

let segmenter = null;
let segmenterLabels = [];
let lastTimestampMs = -1;
let isInitializing = false;
let renderCanvas = null;

function buildCategoryAlphaColors() {
  const colors = [];
  colors.push([0, 0, 0, 0]);
  for (let index = 1; index < 256; index += 1) {
    colors.push([255, 255, 255, 255]);
  }
  return colors;
}

const CATEGORY_ALPHA_COLORS = buildCategoryAlphaColors();

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
    ]));
  }
  return [
    configured,
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

function categoryMaskBitmap(categoryMask) {
  const width = Math.max(1, Math.round(Number(categoryMask?.width) || 0));
  const height = Math.max(1, Math.round(Number(categoryMask?.height) || 0));
  if (!categoryMask || width <= 1 || height <= 1) return null;

  try {
    if (!renderCanvas || renderCanvas.width !== width || renderCanvas.height !== height) {
      renderCanvas = new OffscreenCanvas(width, height);
    }
    renderCanvas.width = width;
    renderCanvas.height = height;

    const glCtx = renderCanvas.getContext('webgl2');
    if (!glCtx) return null;

    const drawingUtils = new DrawingUtils(glCtx);
    drawingUtils.drawCategoryMask(categoryMask, CATEGORY_ALPHA_COLORS, [0, 0, 0, 0]);
    const bitmap = renderCanvas.transferToImageBitmap();
    return {
      bitmap,
      width,
      height,
    };
  } catch {
    return null;
  }
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

  } else if (type === 'RESET') {
    // Keep lastTimestampMs monotonic while the ImageSegmenter stays alive.
    // MediaPipe VIDEO mode rejects lower timestamps even after our session reset.
    self.postMessage({ type: 'RESET_DONE', sessionId: Math.max(0, Math.round(Number(event.data.sessionId) || 0)) });

  } else if (type === 'SEGMENT_VIDEO' || type === 'SEGMENT_IMAGE') {
    const requestSessionId = Math.max(0, Math.round(Number(event.data.sessionId) || 0));
    if (!segmenter) {
      event.data.bitmap?.close();
      self.postMessage({ type: 'SEGMENT_ERROR', error: 'segmenter_not_initialized', sessionId: requestSessionId });
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

        let maskBitmap = null;
        let width = 0;
        let height = 0;

        const categoryResult = categoryMaskBitmap(result.categoryMask);
        if (!categoryResult) {
          result.close?.();
          self.postMessage({
            type: 'SEGMENT_ERROR',
            error: 'production_category_mask_unavailable',
            sessionId: requestSessionId,
          });
          return;
        }

        maskBitmap = categoryResult.bitmap;
        width = categoryResult.width;
        height = categoryResult.height;
        result.close?.();

        self.postMessage(
          { type: 'SEGMENT_RESULT', mode: 'VIDEO', maskBitmap, width, height, inferenceTime, sessionId: requestSessionId },
          [maskBitmap],
        );
      });
    } catch (e) {
      try { bitmap?.close(); } catch { /* ignore */ }
      self.postMessage({ type: 'SEGMENT_ERROR', error: e?.message || String(e), sessionId: requestSessionId });
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
