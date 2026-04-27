/**
 * Fast background blur — dual path architecture
 *
 * WASM path (fast, no segmentation):
 *   - Gaussian blur applied via C++ at encode time
 *   - Quick but blurs the entire frame uniformly
 *   - Falls back when WASM unavailable
 *
 * TS path (quality, with segmentation):
 *   - Uses MediaPipe/TF.js to segment person
 *   - Blurs background, keeps face sharp
 *   - Higher CPU but better results
 *
 * The processor pipeline decides which to use based on config:
 *   blurMode: 'fast' | 'quality'
 */

import { PreEncodeBlurCompositor } from '../../domain/realtime/preEncodeBlurCompositor'

export interface BlurOptions {
  blurRadius: number        // 1-10 strength, 0=off
  blurMode: 'fast' | 'quality'
  onStats?: (s: BlurStats) => void
}

export interface BlurStats {
  fps: number
  avgBlurMs: number
  active: boolean
  mode: 'fast' | 'quality'
}

// Fast TS blur: simple box blur without segmentation
// Used when performance matters more than face clarity
export function applyFastBlur(
  ctx: CanvasRenderingContext2D,
  width: number,
  height: number,
  blurRadius: number
): void {
  const radius = Math.max(1, Math.min(10, Math.round(blurRadius)));
  ctx.filter = `blur(${radius}px)`;
}

export class BackgroundBlurProcessor {
  private options: BlurOptions = { blurRadius: 0, blurMode: 'quality' }
  private qualityCompositor: PreEncodeBlurCompositor | null = null
  private lastBlurFrame: ImageData | null = null
  private frameSkip = 0
  private frameInterval = 1  // process every N frames

  init(video: HTMLVideoElement, opts: BlurOptions): void {
    this.options = { ...this.options, ...opts }

    if (this.options.blurMode === 'quality' && this.options.blurRadius > 0) {
      this.qualityCompositor = new PreEncodeBlurCompositor()
      this.qualityCompositor.init(video, {
        blurRadius: this.options.blurRadius,
        autoDisableOnSlow: true,
        onStats: this.options.onStats,
      }).catch(() => {
        this.qualityCompositor = null
      })
    }

    // Adaptive frame skipping: if blur is strong, skip more frames
    this.frameInterval = this.options.blurRadius >= 7 ? 2 : 1
  }

  /**
   * Process one frame. Returns processed ImageData or null if no blur.
   * The caller should draw this to canvas before encoding.
   */
  process(
    ctx: CanvasRenderingContext2D,
    width: number,
    height: number
  ): ImageData | null {
    if (this.options.blurRadius <= 0) return null

    this.frameSkip++

    // Frame skipping for performance: every 2nd frame when blur is strong
    if (this.frameSkip < this.frameInterval) {
      return this.lastBlurFrame
    }
    this.frameSkip = 0

    if (this.options.blurMode === 'fast') {
      // Fast path: just apply CSS blur, accept face blur
      const tempCanvas = document.createElement('canvas')
      tempCanvas.width = width
      tempCanvas.height = height
      const tempCtx = tempCanvas.getContext('2d')!
      tempCtx.filter = `blur(${this.options.blurRadius}px)`
      tempCtx.drawImage(
        (ctx as any).canvas || (ctx as any)._,
        0, 0, width, height
      )
      const blurred = tempCtx.getImageData(0, 0, width, height)
      this.lastBlurFrame = blurred
      return blurred
    }

    // Quality path: segmentation + matte composite
    if (!this.qualityCompositor) return null

    const processed = this.qualityCompositor.process()
    if (processed) {
      this.lastBlurFrame = processed
      return processed
    }

    return this.lastBlurFrame
  }

  setBlurRadius(radius: number): void {
    this.options.blurRadius = Math.max(0, Math.min(10, Math.round(radius)));
    if (this.qualityCompositor) {
      this.qualityCompositor.setBlurRadius(this.options.blurRadius);
    }
    // Adjust frame interval based on blur strength
    this.frameInterval = this.options.blurRadius >= 7 ? 2 : 1;
  }

  setBlurMode(mode: 'fast' | 'quality'): void {
    this.options.blurMode = mode

    if (mode === 'fast' && this.qualityCompositor) {
      this.qualityCompositor.dispose()
      this.qualityCompositor = null
    }
  }

  getStats(): BlurStats {
    if (this.qualityCompositor) {
      const qs = this.qualityCompositor.getStats()
      return {
        fps: qs.fps,
        avgBlurMs: qs.avgBlurMs,
        active: qs.active,
        mode: 'quality',
      }
    }
    return {
      fps: 0,
      avgBlurMs: 0,
      active: this.options.blurRadius > 0,
      mode: this.options.blurMode,
    }
  }

  dispose(): void {
    if (this.qualityCompositor) {
      this.qualityCompositor.dispose()
      this.qualityCompositor = null
    }
    this.lastBlurFrame = null
  }
}