import { shapeForegroundAlpha } from './maskPostprocess';

const SINET_MODEL_WIDTH = 256;
const SINET_MODEL_HEIGHT = 256;
const SINET_GRAPH_URL = '/cdn/vendor/sinet/sinet-float.onnx';
const SINET_EXTERNAL_WEIGHTS_URL = '/cdn/vendor/sinet/sinet.data';
const SINET_EXTERNAL_WEIGHTS_PATH = 'sinet.data';

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

function readAlphaControls(opts) {
  toMattePreset(opts.mattePreset);
  return {
    gamma: Math.max(0.4, Math.min(2.5, Number(opts.alphaGamma ?? 0.8))),
    contrast: Math.max(0.25, Math.min(4, Number(opts.maskContrast ?? 0.75))),
    fillRadius: Math.max(0, Math.min(4, Math.round(Number(opts.holeFillRadius ?? 0)))),
    averageRadius: Math.max(0, Math.min(12, Math.round(Number(opts.averageRadius ?? 6)))),
    temporalRise: Math.max(0, Math.min(1, Number(opts.temporalRise ?? 0.7))),
    temporalFall: Math.max(0, Math.min(1, Number(opts.temporalFall ?? 0.6))),
  };
}

async function fetchBinaryAsset(path) {
  const response = await fetch(path, { cache: 'force-cache' });
  if (!response.ok) throw new Error(`Failed to load ${path}: HTTP ${response.status}`);
  return new Uint8Array(await response.arrayBuffer());
}

let sinetModelFiles = null;
let ortModule = null;

function getOrtModule() {
  if (!ortModule) ortModule = import('onnxruntime-web');
  return ortModule;
}

function getSinetModelFiles() {
  if (!sinetModelFiles) {
    sinetModelFiles = Promise.all([
      fetchBinaryAsset(SINET_GRAPH_URL),
      fetchBinaryAsset(SINET_EXTERNAL_WEIGHTS_URL),
    ]).then(([model, weights]) => ({ model, weights }));
  }
  return sinetModelFiles;
}

let sinetSession = null;

function getSinetSession() {
  if (!sinetSession) {
    sinetSession = Promise.all([getSinetModelFiles(), getOrtModule()]).then(([{ model, weights }, ort]) => {
      return ort.InferenceSession.create(model, {
        executionProviders: ['wasm'],
        graphOptimizationLevel: 'all',
        externalData: [{ path: SINET_EXTERNAL_WEIGHTS_PATH, data: weights }],
      });
    });
  }
  return sinetSession;
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

function sinetForegroundAlpha(output, width, height) {
  const pixels = width * height;
  const alpha = new Uint8ClampedArray(pixels);
  let probabilityLike = true;
  const sampleCount = Math.min(pixels, 1024);
  for (let i = 0; i < sampleCount; i += 1) {
    const bg = output[i] ?? 0;
    const fg = output[pixels + i] ?? 0;
    if (bg < -0.01 || bg > 1.01 || fg < -0.01 || fg > 1.01) {
      probabilityLike = false;
      break;
    }
  }
  for (let i = 0; i < pixels; i += 1) {
    const bg = output[i] ?? 0;
    const fg = output[pixels + i] ?? 0;
    if (probabilityLike) {
      alpha[i] = Math.max(0, Math.min(255, Math.round(fg * 255)));
      continue;
    }
    const max = Math.max(bg, fg);
    const bgExp = Math.exp(bg - max);
    const fgExp = Math.exp(fg - max);
    alpha[i] = Math.max(0, Math.min(255, Math.round((fgExp / Math.max(1e-6, bgExp + fgExp)) * 255)));
  }
  return alpha;
}

export async function createSinetWasmSegmentationBackend(opts = {}) {
  if (typeof document === 'undefined') return null;

  const detectIntervalMs = Math.max(66, Math.min(1200, Math.round(Number(opts.detectIntervalMs || 140))));
  const alphaControls = readAlphaControls(opts);
  const sampleCanvas = document.createElement('canvas');
  sampleCanvas.width = SINET_MODEL_WIDTH;
  sampleCanvas.height = SINET_MODEL_HEIGHT;
  const sampleCtx = sampleCanvas.getContext('2d', { alpha: false, desynchronized: true, willReadFrequently: true });
  const maskCanvas = document.createElement('canvas');
  maskCanvas.width = SINET_MODEL_WIDTH;
  maskCanvas.height = SINET_MODEL_HEIGHT;
  const maskCtx = maskCanvas.getContext('2d', { alpha: true, desynchronized: true });
  if (!sampleCtx || !maskCtx) return null;

  let faces = [];
  let matteMask = null;
  let lastDetectAt = 0;
  let detectPending = false;
  let pendingSampleMs = null;
  let previousAlpha = null;
  let disposed = false;

  const runSegmentation = async (video, vw, vh) => {
    const detectStartedAt = performance.now();
    try {
      sampleCtx.drawImage(video, 0, 0, SINET_MODEL_WIDTH, SINET_MODEL_HEIGHT);
      const frame = sampleCtx.getImageData(0, 0, SINET_MODEL_WIDTH, SINET_MODEL_HEIGHT);
      const [session, ort] = await Promise.all([getSinetSession(), getOrtModule()]);
      const inputName = session.inputNames[0] || 'image';
      const outputName = session.outputNames[0] || 'mask';
      const output = await session.run({ [inputName]: imageDataToSinetTensor(frame, ort) });
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
        previousAlpha
      );
      writeAlphaMaskToCanvas(maskCtx, alpha, SINET_MODEL_WIDTH, SINET_MODEL_HEIGHT);
      matteMask = maskCanvas;
      const box = estimatePersonBoxFromAlpha(alpha, SINET_MODEL_WIDTH, SINET_MODEL_HEIGHT);
      if (box) {
        const scaleX = vw / SINET_MODEL_WIDTH;
        const scaleY = vh / SINET_MODEL_HEIGHT;
        faces = [clampBox({
          x: box.x * scaleX,
          y: box.y * scaleY,
          width: box.width * scaleX,
          height: box.height * scaleY,
        }, vw, vh)];
      }
    } catch {
      // Keep the last usable matte and face box.
    } finally {
      pendingSampleMs = Math.max(0, performance.now() - detectStartedAt);
      detectPending = false;
    }
  };

  return {
    kind: 'sinet_wasm',
    nextFaces(video, vw, vh, nowMs) {
      if (disposed) return { faces: [], detectSampleMs: null, matteMask: null };
      if (!detectPending && nowMs - lastDetectAt >= detectIntervalMs) {
        detectPending = true;
        lastDetectAt = nowMs;
        void runSegmentation(video, vw, vh);
      }
      const sample = pendingSampleMs;
      pendingSampleMs = null;
      return { faces, detectSampleMs: sample, matteMask };
    },
    dispose() {
      disposed = true;
      faces = [];
      matteMask = null;
      detectPending = false;
      pendingSampleMs = null;
      previousAlpha = null;
    },
  };
}
