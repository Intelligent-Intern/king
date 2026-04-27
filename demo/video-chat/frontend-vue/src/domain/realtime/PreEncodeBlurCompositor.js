import { createMediaPipeSegmentationBackend } from './backgroundFilterBackendMediapipe';
import { createTfjsSegmentationBackend } from './backgroundFilterBackendTfjs';

const PROCESS_WIDTH  = 480;
const PROCESS_HEIGHT = 270;
const BLUR_STEP_PX   = [0, 1, 2, 3, 4, 5, 6, 7, 8, 9, 10];
const DETECT_INTERVAL_MS = 220;
const FACE_PADDING_PX = 14;

function clamp01(v) { return Math.max(0, Math.min(1, v)); }
function smoothstep(e0, e1, x) {
  const t = clamp01((x - e0) / Math.max(1e-6, e1 - e0));
  return t * t * (3 - 2 * t);
}

export class PreEncodeBlurCompositor {
  constructor(options) {
    this.options = options;
    this.processCanvas = document.createElement('canvas');
    this.processCanvas.width = PROCESS_WIDTH;
    this.processCanvas.height = PROCESS_HEIGHT;
    this.processCtx = this.processCanvas.getContext('2d', { willReadFrequently: true });

    this.blurCanvas = document.createElement('canvas');
    this.blurCanvas.width = PROCESS_WIDTH;
    this.blurCanvas.height = PROCESS_HEIGHT;
    this.blurCtx = this.blurCanvas.getContext('2d');

    this.matteCanvas = document.createElement('canvas');
    this.matteCanvas.width = PROCESS_WIDTH;
    this.matteCanvas.height = PROCESS_HEIGHT;
    this.matteCtx = this.matteCanvas.getContext('2d');

    this.sharpCanvas = document.createElement('canvas');
    this.sharpCanvas.width = PROCESS_WIDTH;
    this.sharpCanvas.height = PROCESS_HEIGHT;
    this.sharpCtx = this.sharpCanvas.getContext('2d');

    this.currentBlurRadius = options.blurRadius;
    this.disposed = false;
    this.statsFrameCount = 0;
    this.statsBlurMsSum = 0;
    this.statsWindowStart = 0;
  }

  async init(video) {
    this.video = video;
    try {
      this.backend = createMediaPipeSegmentationBackend({ detectIntervalMs: DETECT_INTERVAL_MS });
      await this.backend.waitForReady();
      return true;
    } catch (_e1) {
      try {
        this.backend = createTfjsSegmentationBackend({ detectIntervalMs: DETECT_INTERVAL_MS });
        await this.backend.waitForReady();
        return true;
      } catch (_e2) {
        return false;
      }
    }
  }

  process() {
    if (this.disposed || !this.video || !this.backend) return null;
    const now = performance.now();

    const { matteMask } = this.backend.nextFaces(this.video, PROCESS_WIDTH, PROCESS_HEIGHT, now);

    const blurPx = BLUR_STEP_PX[Math.min(this.currentBlurRadius, 10)] || 0;
    this.processCtx.drawImage(this.video, 0, 0, PROCESS_WIDTH, PROCESS_HEIGHT);

    if (blurPx <= 0) {
      this.statsFrameCount++;
      return this.processCtx.getImageData(0, 0, PROCESS_WIDTH, PROCESS_HEIGHT);
    }

    this.blurCtx.filter = `blur(${blurPx}px)`;
    this.blurCtx.drawImage(this.processCanvas, 0, 0);
    this.blurCtx.filter = 'none';

    if (matteMask) {
      this.matteCtx.drawImage(matteMask, 0, 0, PROCESS_WIDTH, PROCESS_HEIGHT);
      this.sharpCtx.clearRect(0, 0, PROCESS_WIDTH, PROCESS_HEIGHT);
      this.sharpCtx.drawImage(this.processCanvas, 0, 0);
      const blurredBg = this.blurCtx.getImageData(0, 0, PROCESS_WIDTH, PROCESS_HEIGHT);
      const sharpFg = this.sharpCtx.getImageData(0, 0, PROCESS_WIDTH, PROCESS_HEIGHT);
      const matte = this.matteCtx.getImageData(0, 0, PROCESS_WIDTH, PROCESS_HEIGHT);
      const out = this.processCtx.createImageData(PROCESS_WIDTH, PROCESS_HEIGHT);
      for (let i = 0; i < out.data.length; i += 4) {
        const a = matte.data[i + 3] / 255;
        out.data[i]     = blurredBg.data[i]     * (1 - a) + sharpFg.data[i]     * a;
        out.data[i + 1] = blurredBg.data[i + 1] * (1 - a) + sharpFg.data[i + 1] * a;
        out.data[i + 2] = blurredBg.data[i + 2] * (1 - a) + sharpFg.data[i + 2] * a;
        out.data[i + 3] = 255;
      }
      this.statsBlurMsSum += performance.now() - now;
      this.statsFrameCount++;
      this._tickStats();
      return out;
    }

    this.statsBlurMsSum += performance.now() - now;
    this.statsFrameCount++;
    this._tickStats();
    return this.blurCtx.getImageData(0, 0, PROCESS_WIDTH, PROCESS_HEIGHT);
  }

  setBlurRadius(radius) {
    this.currentBlurRadius = Math.max(0, Math.min(10, Math.round(radius)));
  }

  getStats() {
    const elapsed = this.statsWindowStart > 0 ? performance.now() - this.statsWindowStart : 0;
    const fps = elapsed > 0 ? (this.statsFrameCount / elapsed) * 1000 : 0;
    const avgBlurMs = this.statsFrameCount > 0 ? this.statsBlurMsSum / this.statsFrameCount : 0;
    return { fps, avgBlurMs, active: this.currentBlurRadius > 0 };
  }

  dispose() {
    this.disposed = true;
    if (this.backend) {
      this.backend.dispose();
      this.backend = null;
    }
  }

  _tickStats() {
    if (this.statsWindowStart === 0) this.statsWindowStart = performance.now();
    if (performance.now() - this.statsWindowStart >= 1000) {
      this.statsFrameCount = 0;
      this.statsBlurMsSum = 0;
      this.statsWindowStart = performance.now();
    }
  }
}