import {
  createKingBackgroundMatteRefiner,
  type KingBackgroundMattePreset,
  type KingBackgroundMatteRefiner,
} from '../../src/lib/wasm/wasm-codec';
import { shapeForegroundAlpha } from '../../src/domain/realtime/background/maskPostprocess';
import * as ort from 'onnxruntime-web';

const startButton = document.getElementById('startButton') as HTMLButtonElement;
const modelSelect = document.getElementById('modelSelect') as HTMLSelectElement;
const deviceSelect = document.getElementById('deviceSelect') as HTMLSelectElement;
const dtypeSelect = document.getElementById('dtypeSelect') as HTMLSelectElement;
const presetSelect = document.getElementById('presetSelect') as HTMLSelectElement;
const modelWidthInput = document.getElementById('modelWidthInput') as HTMLInputElement;
const modelHeightInput = document.getElementById('modelHeightInput') as HTMLInputElement;
const alphaGammaInput = document.getElementById('alphaGammaInput') as HTMLInputElement;
const maskContrastInput = document.getElementById('maskContrastInput') as HTMLInputElement;
const averageRadiusInput = document.getElementById('averageRadiusInput') as HTMLInputElement;
const temporalRiseInput = document.getElementById('temporalRiseInput') as HTMLInputElement;
const temporalFallInput = document.getElementById('temporalFallInput') as HTMLInputElement;
const intervalInput = document.getElementById('intervalInput') as HTMLInputElement;
const statusEl = document.getElementById('status') as HTMLSpanElement;
const sourceVideo = document.getElementById('sourceVideo') as HTMLVideoElement;
const sampleCanvas = document.getElementById('sampleCanvas') as HTMLCanvasElement;
const maskCanvas = document.getElementById('maskCanvas') as HTMLCanvasElement;
const compositeCanvas = document.getElementById('compositeCanvas') as HTMLCanvasElement;

const sampleCtx = sampleCanvas.getContext('2d', { alpha: false, willReadFrequently: true });
const maskCtx = maskCanvas.getContext('2d', { alpha: false, desynchronized: true });
const compositeCtx = compositeCanvas.getContext('2d', { alpha: false, desynchronized: true });
const EXCLUSION_BACKGROUND = '#061a4a';

let stream: MediaStream | null = null;
let rafId = 0;
let refiner: KingBackgroundMatteRefiner | null = null;
let refinerKey = '';
let lastFrameAt = performance.now();
let frameCount = 0;
const sinetSessions = new Map<string, Promise<ort.InferenceSession>>();
let sinetModelFiles: Promise<{ model: Uint8Array; weights: Uint8Array }> | null = null;
let modelBusy = false;
let lastSegmentStartedAt = 0;
let lastSegmentElapsed = 0;
let lastAlpha: Uint8ClampedArray | null = null;
let lastAlphaWidth = 0;
let lastAlphaHeight = 0;
let previousSinetAlpha: Uint8ClampedArray | null = null;

function setStatus(message: string): void {
  statusEl.textContent = message;
}

function readModelSize(): { width: number; height: number } {
  const width = Math.max(96, Math.min(960, Math.round(Number(modelWidthInput.value || 256))));
  const height = Math.max(96, Math.min(540, Math.round(Number(modelHeightInput.value || 144))));
  return { width, height };
}

function readPreset(): KingBackgroundMattePreset | 'exclusion' {
  const value = String(presetSelect.value || '').trim();
  if (value === 'hard_blur' || value === 'exclusion') return value;
  return 'weak_blur';
}

function readModelMode(): 'sinet' | 'king_wasm' {
  return modelSelect.value === 'king_wasm' ? 'king_wasm' : 'sinet';
}

function readDevice(): 'wasm' | 'webgpu' {
  return deviceSelect.value === 'webgpu' ? 'webgpu' : 'wasm';
}

function readDtype(): 'float' {
  return 'float';
}

function readIntervalMs(): number {
  return Math.max(0, Math.min(1000, Math.round(Number(intervalInput.value || 125))));
}

function readAlphaControls(): {
  gamma: number;
  contrast: number;
  averageRadius: number;
  temporalRise: number;
  temporalFall: number;
} {
  return {
    gamma: Math.max(0.4, Math.min(2.5, Number(alphaGammaInput.value || 0.8))),
    contrast: Math.max(0.25, Math.min(4, Number(maskContrastInput.value || 0.75))),
    averageRadius: Math.max(0, Math.min(12, Math.round(Number(averageRadiusInput.value || 6)))),
    temporalRise: Math.max(0, Math.min(1, Number(temporalRiseInput.value || 0.7))),
    temporalFall: Math.max(0, Math.min(1, Number(temporalFallInput.value || 0.6))),
  };
}

async function fetchBinaryAsset(path: string): Promise<Uint8Array> {
  const response = await fetch(path, { cache: 'force-cache' });
  if (!response.ok) throw new Error(`Failed to load ${path}: HTTP ${response.status}`);
  return new Uint8Array(await response.arrayBuffer());
}

function getSinetModelFiles(): Promise<{ model: Uint8Array; weights: Uint8Array }> {
  if (!sinetModelFiles) {
    sinetModelFiles = Promise.all([
      fetchBinaryAsset('/cdn/vendor/sinet/sinet-float.onnx'),
      fetchBinaryAsset('/cdn/vendor/sinet/sinet.data'),
    ]).then(([model, weights]) => ({ model, weights }));
  }
  return sinetModelFiles;
}

function getSinetSession(): Promise<ort.InferenceSession> {
  const device = readDevice();
  const key = device === 'webgpu' ? 'webgpu' : 'wasm';
  let promise = sinetSessions.get(key);
  if (!promise) {
    setStatus(`Loading SINet ${key}`);
    const executionProviders = key === 'webgpu' ? ['webgpu', 'wasm'] : ['wasm'];
    promise = getSinetModelFiles().then(({ model, weights }) => {
      return ort.InferenceSession.create(model, {
        executionProviders,
        graphOptimizationLevel: 'all',
        externalData: [{ path: 'sinet.data', data: weights }],
      });
    });
    sinetSessions.set(key, promise);
  }
  return promise;
}

async function getRefiner(): Promise<KingBackgroundMatteRefiner | null> {
  const { width, height } = readModelSize();
  const preset = readPreset();
  const nextKey = `${width}x${height}:${preset}`;
  if (refiner && refinerKey === nextKey) return refiner;
  refiner?.destroy();
  refiner = await createKingBackgroundMatteRefiner({
    width,
    height,
    preset: preset === 'exclusion' ? 'replace' : preset,
  });
  refinerKey = refiner ? nextKey : '';
  sampleCanvas.width = width;
  sampleCanvas.height = height;
  return refiner;
}

function resizeOutputCanvases(): void {
  const width = Math.max(1, sourceVideo.videoWidth || 1280);
  const height = Math.max(1, sourceVideo.videoHeight || 720);
  if (maskCanvas.width !== width || maskCanvas.height !== height) {
    maskCanvas.width = width;
    maskCanvas.height = height;
    compositeCanvas.width = width;
    compositeCanvas.height = height;
  }
}

function drawMask(alpha: Uint8ClampedArray, modelWidth: number, modelHeight: number): void {
  if (!maskCtx) return;
  const maskImage = new ImageData(modelWidth, modelHeight);
  for (let i = 0; i < alpha.length; i += 1) {
    const p = i * 4;
    const value = alpha[i] ?? 0;
    maskImage.data[p] = value;
    maskImage.data[p + 1] = value;
    maskImage.data[p + 2] = value;
    maskImage.data[p + 3] = 255;
  }

  const tmp = new OffscreenCanvas(modelWidth, modelHeight);
  const tmpCtx = tmp.getContext('2d');
  if (!tmpCtx) return;
  tmpCtx.putImageData(maskImage, 0, 0);
  maskCtx.imageSmoothingEnabled = false;
  maskCtx.drawImage(tmp, 0, 0, maskCanvas.width, maskCanvas.height);
}

function compositeAlpha(value: number, preset: KingBackgroundMattePreset | 'exclusion'): number {
  const normalized = Math.max(0, Math.min(1, value / 255));
  const shaped = preset === 'exclusion'
    ? Math.pow(normalized, 3.2)
    : Math.pow(normalized, 2.2);
  return Math.max(0, Math.min(255, Math.round(shaped * 255)));
}

function drawComposite(alpha: Uint8ClampedArray, modelWidth: number, modelHeight: number): void {
  if (!compositeCtx) return;
  const preset = readPreset();
  const maskCanvasSmall = new OffscreenCanvas(modelWidth, modelHeight);
  const maskCanvasSmallCtx = maskCanvasSmall.getContext('2d');
  if (!maskCanvasSmallCtx) return;

  const maskImage = new ImageData(modelWidth, modelHeight);
  for (let i = 0; i < alpha.length; i += 1) {
    const p = i * 4;
    maskImage.data[p] = 255;
    maskImage.data[p + 1] = 255;
    maskImage.data[p + 2] = 255;
    maskImage.data[p + 3] = compositeAlpha(alpha[i] ?? 0, preset);
  }
  maskCanvasSmallCtx.putImageData(maskImage, 0, 0);

  compositeCtx.globalCompositeOperation = 'source-over';
  if (preset === 'exclusion') {
    compositeCtx.save();
    compositeCtx.globalCompositeOperation = 'copy';
    compositeCtx.fillStyle = EXCLUSION_BACKGROUND;
    compositeCtx.fillRect(0, 0, compositeCanvas.width, compositeCanvas.height);
    compositeCtx.restore();
  } else {
    compositeCtx.save();
    compositeCtx.filter = preset === 'weak_blur' ? 'blur(14px)' : 'blur(32px)';
    compositeCtx.drawImage(sourceVideo, 0, 0, compositeCanvas.width, compositeCanvas.height);
    compositeCtx.restore();
  }

  const personLayer = new OffscreenCanvas(compositeCanvas.width, compositeCanvas.height);
  const personLayerCtx = personLayer.getContext('2d');
  if (!personLayerCtx) return;
  personLayerCtx.save();
  personLayerCtx.globalCompositeOperation = 'copy';
  personLayerCtx.drawImage(sourceVideo, 0, 0, compositeCanvas.width, compositeCanvas.height);
  personLayerCtx.restore();
  personLayerCtx.save();
  personLayerCtx.globalCompositeOperation = 'destination-in';
  personLayerCtx.imageSmoothingEnabled = true;
  personLayerCtx.drawImage(maskCanvasSmall, 0, 0, compositeCanvas.width, compositeCanvas.height);
  personLayerCtx.restore();

  compositeCtx.save();
  compositeCtx.filter = 'none';
  compositeCtx.globalCompositeOperation = 'source-over';
  compositeCtx.drawImage(personLayer, 0, 0);
  compositeCtx.restore();
}

function alphaFromRawImage(raw: any): { alpha: Uint8ClampedArray; width: number; height: number } | null {
  const width = Math.max(1, Math.round(Number(raw?.width || 0)));
  const height = Math.max(1, Math.round(Number(raw?.height || 0)));
  const channels = Math.max(1, Math.round(Number(raw?.channels || 4)));
  const data = raw?.data;
  if (!width || !height || !(data instanceof Uint8ClampedArray || data instanceof Uint8Array)) return null;
  const alpha = new Uint8ClampedArray(width * height);
  if (channels >= 4) {
    for (let i = 0; i < alpha.length; i += 1) {
      alpha[i] = data[i * channels + 3] ?? 0;
    }
  } else {
    for (let i = 0; i < alpha.length; i += 1) {
      alpha[i] = data[i * channels] ?? 0;
    }
  }
  return { alpha, width, height };
}

function sinetForegroundAlpha(output: Float32Array, width: number, height: number): Uint8ClampedArray {
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

function imageDataToSinetTensor(image: ImageData): ort.Tensor {
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

function shapeAlpha(alpha: Uint8ClampedArray, width: number, height: number): Uint8ClampedArray {
  const { gamma, contrast, averageRadius, temporalRise, temporalFall } = readAlphaControls();
  if (readModelMode() !== 'sinet') {
    return shapeForegroundAlpha(alpha, width, height, { gamma, contrast, fillRadius: 0, averageRadius, temporalRise: 1, temporalFall: 1 }, null);
  }
  if (!previousSinetAlpha || previousSinetAlpha.length !== alpha.length) {
    previousSinetAlpha = new Uint8ClampedArray(alpha.length);
  }
  return shapeForegroundAlpha(alpha, width, height, { gamma, contrast, fillRadius: 0, averageRadius, temporalRise, temporalFall }, previousSinetAlpha);
}

async function segmentCurrentFrame(model: KingBackgroundMatteRefiner): Promise<{
  alpha: Uint8ClampedArray;
  width: number;
  height: number;
  elapsed: number;
} | null> {
  const { width, height } = readModelSize();
  sampleCtx?.drawImage(sourceVideo, 0, 0, width, height);
  const startedAt = performance.now();
  const mode = readModelMode();
  if (mode === 'king_wasm') {
    if (!sampleCtx) return null;
    const image = sampleCtx.getImageData(0, 0, width, height);
    const alpha = model.segment(image.data);
    return alpha ? { alpha: shapeAlpha(alpha, width, height), width, height, elapsed: performance.now() - startedAt } : null;
  }
  if (mode === 'sinet') {
    if (!sampleCtx) return null;
    const image = sampleCtx.getImageData(0, 0, width, height);
    const session = await getSinetSession();
    const inputName = session.inputNames[0] || 'image';
    const outputName = session.outputNames[0] || 'mask';
    const output = await session.run({ [inputName]: imageDataToSinetTensor(image) });
    const tensor = output[outputName] || output[session.outputNames[0]];
    if (!tensor || !(tensor.data instanceof Float32Array)) return null;
    const alpha = sinetForegroundAlpha(tensor.data, width, height);
    return { alpha: shapeAlpha(alpha, width, height), width, height, elapsed: performance.now() - startedAt };
  }

  return null;
}

async function frame(): Promise<void> {
  if (!stream || !sampleCtx || sourceVideo.readyState < 2) {
    rafId = requestAnimationFrame(() => void frame());
    return;
  }

  const model = await getRefiner();
  if (!model) {
    setStatus('King WASM BackgroundMatteRefiner unavailable');
    return;
  }

  resizeOutputCanvases();
  let elapsed = 0;
  const now = performance.now();
  if (!modelBusy && now - lastSegmentStartedAt >= readIntervalMs()) {
    modelBusy = true;
    lastSegmentStartedAt = now;
    try {
      const result = await segmentCurrentFrame(model);
      if (result) {
        lastAlpha = result.alpha;
        lastAlphaWidth = result.width;
        lastAlphaHeight = result.height;
        lastSegmentElapsed = result.elapsed;
        elapsed = result.elapsed;
      }
    } catch (error) {
      setStatus(error instanceof Error ? error.message : String(error));
    } finally {
      modelBusy = false;
    }
  }
  if (lastAlpha) {
    drawMask(lastAlpha, lastAlphaWidth, lastAlphaHeight);
    drawComposite(lastAlpha, lastAlphaWidth, lastAlphaHeight);
  }

  frameCount += 1;
  if (now - lastFrameAt >= 1000) {
    const { width, height } = readModelSize();
    const { gamma, contrast, averageRadius, temporalRise, temporalFall } = readAlphaControls();
    setStatus(`${readModelMode()} ${readDevice()}/${readDtype()} ${width}x${height} g=${gamma.toFixed(2)} c=${contrast.toFixed(2)} avg=${averageRadius} rise=${temporalRise.toFixed(2)} fall=${temporalFall.toFixed(2)} | ${frameCount} fps | ${lastSegmentElapsed.toFixed(2)} ms segment`);
    frameCount = 0;
    lastFrameAt = now;
  }
  rafId = requestAnimationFrame(() => void frame());
}

async function startCamera(): Promise<void> {
  if (stream) {
    for (const track of stream.getTracks()) track.stop();
    stream = null;
  }
  cancelAnimationFrame(rafId);
  setStatus('Requesting camera');
  stream = await navigator.mediaDevices.getUserMedia({
    video: {
      width: { ideal: 1280 },
      height: { ideal: 720 },
      frameRate: { ideal: 30, max: 30 },
    },
    audio: false,
  });
  sourceVideo.srcObject = stream;
  await sourceVideo.play();
  setStatus('Running');
  void frame();
}

startButton.addEventListener('click', () => {
  void startCamera().catch((error) => {
    setStatus(error instanceof Error ? error.message : String(error));
  });
});

for (const control of [
  modelSelect,
  deviceSelect,
  dtypeSelect,
  presetSelect,
  modelWidthInput,
  modelHeightInput,
  alphaGammaInput,
  maskContrastInput,
  averageRadiusInput,
  temporalRiseInput,
  temporalFallInput,
  intervalInput,
]) {
  control.addEventListener('change', () => {
    refiner?.destroy();
    refiner = null;
    refinerKey = '';
    lastAlpha = null;
    lastAlphaWidth = 0;
    lastAlphaHeight = 0;
    previousSinetAlpha = null;
  });
}
