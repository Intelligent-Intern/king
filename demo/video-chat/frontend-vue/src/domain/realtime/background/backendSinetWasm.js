import { shapeForegroundAlpha } from './maskPostprocess';

const VIDEOCHAT_CDN_ORIGIN = String(import.meta.env.VITE_VIDEOCHAT_CDN_ORIGIN || '').replace(/\/+$/, '');
const SINET_MODEL_WIDTH = 256;
const SINET_MODEL_HEIGHT = 256;
const SINET_ASSET_BASE_PATH = '/cdn/vendor/sinet/';
const SINET_GRAPH_URL = `${VIDEOCHAT_CDN_ORIGIN}${SINET_ASSET_BASE_PATH}sinet-float.onnx`;
const SINET_EXTERNAL_WEIGHTS_URL = `${VIDEOCHAT_CDN_ORIGIN}${SINET_ASSET_BASE_PATH}sinet.data`;
const SINET_EXTERNAL_WEIGHTS_PATH = 'sinet.data';

let sinetModelFiles = null;
let sinetSession = null;
let ortModule = null;
let sinetRunChain = Promise.resolve();

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

function readAlphaControls(opts) {
  return {
    gamma: Math.max(0.4, Math.min(2.5, Number(opts.alphaGamma ?? 0.8))),
    contrast: Math.max(0.25, Math.min(4, Number(opts.maskContrast ?? 0.75))),
    averageRadius: Math.max(0, Math.min(12, Math.round(Number(opts.averageRadius ?? 6)))),
    temporalRise: Math.max(0, Math.min(1, Number(opts.temporalRise ?? 0.7))),
    temporalFall: Math.max(0, Math.min(1, Number(opts.temporalFall ?? 0.6))),
  };
}

function isVideoFrameReady(video) {
  return video instanceof HTMLVideoElement
    && video.readyState >= 2
    && !video.ended
    && Math.max(0, Number(video.videoWidth) || 0) > 1
    && Math.max(0, Number(video.videoHeight) || 0) > 1;
}

async function fetchBinaryAsset(path) {
  const response = await fetch(path, { cache: 'force-cache' });
  if (!response.ok) throw new Error(`Failed to load ${path}: HTTP ${response.status}`);
  return new Uint8Array(await response.arrayBuffer());
}

function configureOrtWasmRuntime(ort) {
  const wasm = ort?.env?.wasm;
  if (!wasm || wasm.__kingSinetConfigured === true) return;
  wasm.proxy = false;
  wasm.numThreads = 1;
  wasm.__kingSinetConfigured = true;
}

function getOrtModule() {
  if (!ortModule) {
    ortModule = import('onnxruntime-web/wasm')
      .then((ort) => {
        configureOrtWasmRuntime(ort);
        return ort;
      })
      .catch((error) => {
        ortModule = null;
        throw error;
      });
  }
  return ortModule;
}

function getSinetModelFiles() {
  if (!sinetModelFiles) {
    sinetModelFiles = Promise.all([
      fetchBinaryAsset(SINET_GRAPH_URL),
      fetchBinaryAsset(SINET_EXTERNAL_WEIGHTS_URL),
    ])
      .then(([model, weights]) => ({ model, weights }))
      .catch((error) => {
        sinetModelFiles = null;
        throw error;
      });
  }
  return sinetModelFiles;
}

function getSinetSession() {
  if (!sinetSession) {
    sinetSession = Promise.all([getSinetModelFiles(), getOrtModule()])
      .then(([{ model, weights }, ort]) => ort.InferenceSession.create(model, {
        executionProviders: ['wasm'],
        graphOptimizationLevel: 'all',
        externalData: [{ path: SINET_EXTERNAL_WEIGHTS_PATH, data: weights }],
      }))
      .catch((error) => {
        sinetSession = null;
        throw error;
      });
  }
  return sinetSession;
}

function enqueueSinetRun(work) {
  const run = sinetRunChain.then(work, work);
  sinetRunChain = run.catch(() => {});
  return run;
}

function imageDataToSinetTensor(image, ort) {
  const width = image.width;
  const height = image.height;
  const pixels = width * height;
  const out = new Float32Array(3 * pixels);
  const data = image.data;

  for (let i = 0; i < pixels; i += 1) {
    const p = i * 4;
    out[i] = (data[p] ?? 0) / 255;
    out[pixels + i] = (data[p + 1] ?? 0) / 255;
    out[pixels * 2 + i] = (data[p + 2] ?? 0) / 255;
  }

  return new ort.Tensor('float32', out, [1, 3, height, width]);
}

function binaryForegroundAlpha(value, threshold = 0) {
  return Number(value) > Number(threshold) ? 255 : 0;
}

function singleChannelAlpha(output, pixels) {
  const alpha = new Uint8ClampedArray(pixels);
  let probabilityLike = true;
  const sampleCount = Math.min(pixels, 1024);

  for (let i = 0; i < sampleCount; i += 1) {
    const value = output[i] ?? 0;
    if (value < -0.01 || value > 1.01) {
      probabilityLike = false;
      break;
    }
  }

  const threshold = probabilityLike ? 0.5 : 0;
  for (let i = 0; i < pixels; i += 1) {
    const value = output[i] ?? 0;
    alpha[i] = binaryForegroundAlpha(value, threshold);
  }

  return alpha;
}

function twoChannelForegroundAlpha(output, pixels) {
  const alpha = new Uint8ClampedArray(pixels);

  for (let i = 0; i < pixels; i += 1) {
    const bg = output[i] ?? 0;
    const fg = output[pixels + i] ?? 0;
    alpha[i] = binaryForegroundAlpha(fg, bg);
  }

  return alpha;
}

function sinetForegroundAlpha(output, width, height) {
  const pixels = width * height;
  if (!(output instanceof Float32Array) || output.length < pixels) {
    return new Uint8ClampedArray(pixels);
  }
  if (output.length >= pixels * 2) {
    return twoChannelForegroundAlpha(output, pixels);
  }
  return singleChannelAlpha(output, pixels);
}

function alphaToFloatMask(alpha) {
  const out = new Float32Array(alpha.length);
  for (let i = 0; i < alpha.length; i += 1) {
    out[i] = (alpha[i] ?? 0) / 255;
  }
  return out;
}

export async function createSinetWasmSegmentationBackend(opts = {}) {
  if (typeof document === 'undefined') return null;

  const detectIntervalMs = Math.max(66, Math.min(1200, Math.round(Number(opts.detectIntervalMs || 140))));
  const alphaControls = readAlphaControls(opts);
  const sampleCanvas = document.createElement('canvas');
  sampleCanvas.width = SINET_MODEL_WIDTH;
  sampleCanvas.height = SINET_MODEL_HEIGHT;
  const sampleCtx = sampleCanvas.getContext('2d', { alpha: false, desynchronized: true, willReadFrequently: true });
  const resultFrameCanvas = document.createElement('canvas');
  resultFrameCanvas.width = 1;
  resultFrameCanvas.height = 1;
  const resultFrameCtx = resultFrameCanvas.getContext('2d', { alpha: false, desynchronized: true });
  if (!sampleCtx || !resultFrameCtx) return null;

  await getSinetSession();

  let faces = [];
  let pendingMaskValues = null;
  let pendingSampleMs = null;
  let pendingResultFrame = false;
  let lastDetectAt = -Infinity;
  let detectPending = false;
  let previousAlpha = null;
  let disposed = false;
  let runtimeErrorWarned = false;

  function resetState() {
    faces = [];
    pendingMaskValues = null;
    pendingSampleMs = null;
    pendingResultFrame = false;
    lastDetectAt = -Infinity;
    detectPending = false;
    previousAlpha = null;
  }

  function warnRuntimeError(error) {
    if (runtimeErrorWarned) return;
    runtimeErrorWarned = true;
    console.warn('[BackgroundFilter] SINet WASM segmentation failed', {
      message: error?.message || 'segmentation_failed',
    });
  }

  const runSegmentation = async (video, sourceWidth, sourceHeight, targetWidth, targetHeight) => {
    const detectStartedAt = performance.now();
    try {
      if (disposed || !isVideoFrameReady(video)) return;

      if (resultFrameCanvas.width !== targetWidth || resultFrameCanvas.height !== targetHeight) {
        resultFrameCanvas.width = targetWidth;
        resultFrameCanvas.height = targetHeight;
      }
      resultFrameCtx.drawImage(video, 0, 0, sourceWidth, sourceHeight, 0, 0, targetWidth, targetHeight);
      sampleCtx.drawImage(video, 0, 0, sourceWidth, sourceHeight, 0, 0, SINET_MODEL_WIDTH, SINET_MODEL_HEIGHT);

      const frame = sampleCtx.getImageData(0, 0, SINET_MODEL_WIDTH, SINET_MODEL_HEIGHT);
      const [session, ort] = await Promise.all([getSinetSession(), getOrtModule()]);
      const inputName = session.inputNames[0] || 'image';
      const outputName = session.outputNames[0] || 'mask';
      const inputTensor = imageDataToSinetTensor(frame, ort);
      const output = await enqueueSinetRun(() => session.run({ [inputName]: inputTensor }));
      const tensor = output[outputName] || output[session.outputNames[0]];
      if (!tensor || !(tensor.data instanceof Float32Array)) return;

      if (!previousAlpha || previousAlpha.length !== SINET_MODEL_WIDTH * SINET_MODEL_HEIGHT) {
        previousAlpha = new Uint8ClampedArray(SINET_MODEL_WIDTH * SINET_MODEL_HEIGHT);
      }

      const alpha = shapeForegroundAlpha(
        sinetForegroundAlpha(tensor.data, SINET_MODEL_WIDTH, SINET_MODEL_HEIGHT),
        SINET_MODEL_WIDTH,
        SINET_MODEL_HEIGHT,
        alphaControls,
        previousAlpha,
      );
      pendingMaskValues = alphaToFloatMask(alpha);
      pendingResultFrame = true;

      const box = estimatePersonBoxFromAlpha(alpha, SINET_MODEL_WIDTH, SINET_MODEL_HEIGHT);
      if (box) {
        const scaleX = targetWidth / SINET_MODEL_WIDTH;
        const scaleY = targetHeight / SINET_MODEL_HEIGHT;
        faces = [clampBox({
          x: box.x * scaleX,
          y: box.y * scaleY,
          width: box.width * scaleX,
          height: box.height * scaleY,
        }, targetWidth, targetHeight)];
      } else {
        faces = [];
      }
    } catch (error) {
      warnRuntimeError(error);
    } finally {
      pendingSampleMs = Math.max(0, performance.now() - detectStartedAt);
      detectPending = false;
    }
  };

  return {
    kind: 'sinet_wasm',

    resetSession() {
      resetState();
      return Promise.resolve();
    },

    nextFaces(video, vw, vh, nowMs) {
      if (disposed || !isVideoFrameReady(video)) {
        return { faces: [], detectSampleMs: null, matteMaskBitmap: null, matteMaskValues: null };
      }

      const sourceWidth = Math.max(1, Math.round(Number(video.videoWidth) || Number(vw) || 1));
      const sourceHeight = Math.max(1, Math.round(Number(video.videoHeight) || Number(vh) || 1));
      const targetWidth = Math.max(1, Math.round(Number(vw) || sourceWidth));
      const targetHeight = Math.max(1, Math.round(Number(vh) || sourceHeight));

      if (!detectPending && nowMs - lastDetectAt >= detectIntervalMs) {
        detectPending = true;
        lastDetectAt = nowMs;
        void runSegmentation(video, sourceWidth, sourceHeight, targetWidth, targetHeight);
      }

      const sample = pendingSampleMs;
      pendingSampleMs = null;
      const maskValues = pendingMaskValues;
      pendingMaskValues = null;
      const sourceFrame = pendingResultFrame ? resultFrameCanvas : null;
      pendingResultFrame = false;

      return {
        faces,
        detectSampleMs: sample,
        matteMaskBitmap: null,
        matteMaskValues: maskValues,
        matteMaskWidth: SINET_MODEL_WIDTH,
        matteMaskHeight: SINET_MODEL_HEIGHT,
        sourceFrame,
      };
    },

    dispose() {
      if (disposed) return;
      disposed = true;
      resetState();
      resultFrameCanvas.width = 1;
      resultFrameCanvas.height = 1;
      sampleCanvas.width = 1;
      sampleCanvas.height = 1;
    },
  };
}
