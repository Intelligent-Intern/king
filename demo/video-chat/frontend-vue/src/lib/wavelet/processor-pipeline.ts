/**
 * Wavelet Video Processor
 * Processes camera stream through wavelet codec and outputs a new stream.
 * Supports pre-encode background blur: segmentation + sharp foreground
 * composite → blurred background → encode. The blur is baked into the
 * bitstream so it requires no segmentation metadata to be transmitted.
 *
 * Blur modes:
 *   - 'fast': CSS Gaussian blur, no segmentation, quick but blurs face too
 *   - 'quality': MediaPipe/TF.js segmentation, sharp face, higher CPU
 */

import { createWaveletCodec, WaveletCodecConfig } from './webrtc-shim.js'
import { SFUClient, SFUEncodedFrame } from '../sfu/sfuClient.js'
import { debugWarn } from '../../support/debugLogs.js'
import { BackgroundBlurProcessor } from './blur-processor.js'

export type { WaveletType, EntropyMode, ColorSpace } from '../wasm/wasm-codec.js'

export interface WaveletProcessorConfig {
  waveletType?: 'haar' | 'db4' | 'cdf97'
  entropyCoding?: 'rle' | 'arithmetic' | 'none'
  dwtLevels?: number
  colorSpace?: 'yuv' | 'rgb'
  quality?: number
  enableKalman?: boolean
  keyFrameInterval?: number
  width?: number
  height?: number
  frameRate?: number
  motionEstimation?: boolean
  // Pre-encode background blur (baked into encoded frames)
  blurRadius?: number        // 0=off, 1-10 strength
  blurMode?: 'fast' | 'quality'  // fast=CSS blur only, quality=segmentation
  blurOnStats?: (s: BlurStats) => void
  // SFU integration
  sfuClient?: SFUClient
  publisherId?: string
  trackId?: string
  sendToSFU?: boolean
}

export interface BlurStats {
  fps: number
  avgBlurMs: number
  active: boolean
  mode: 'fast' | 'quality'
}

export interface ProcessorStats {
  framesProcessed: number
  keyFrames: number
  deltaFrames: number
  totalBytes: number
  compressionRatio: number
  avgEncodeTimeMs: number
  avgDecodeTimeMs: number
}

export class WaveletVideoProcessor {
  private config: WaveletProcessorConfig
  private codec: ReturnType<typeof createWaveletCodec>
  private canvas: OffscreenCanvas
  private ctx: OffscreenCanvasRenderingContext2D
  private processedStream: MediaStream | null = null
  private sourceVideo: HTMLVideoElement | null = null
  private animationId: number | null = null
  private lastFrameTime: number = 0
  private localStats: ProcessorStats = {
    framesProcessed: 0,
    keyFrames: 0,
    deltaFrames: 0,
    totalBytes: 0,
    compressionRatio: 0,
    avgEncodeTimeMs: 0,
    avgDecodeTimeMs: 0,
  }
  private sfuClient?: SFUClient
  private publisherId?: string
  private trackId?: string
  private blurProcessor: BackgroundBlurProcessor | null = null

  constructor(config: Partial<WaveletProcessorConfig> = {}) {
    this.config = {
      quality: config.quality ?? 40,
      enableKalman: config.enableKalman ?? false,
      keyFrameInterval: config.keyFrameInterval ?? 30,
      width: config.width ?? 640,
      height: config.height ?? 480,
      frameRate: config.frameRate ?? 30,
      waveletType: config.waveletType ?? 'haar',
      entropyCoding: config.entropyCoding ?? 'rle',
      dwtLevels: config.dwtLevels ?? 4,
      colorSpace: config.colorSpace ?? 'yuv',
      motionEstimation: config.motionEstimation ?? true,
      blurRadius: config.blurRadius ?? 0,
      blurMode: config.blurMode ?? 'quality',
      blurOnStats: config.blurOnStats,
      sfuClient: config.sfuClient,
      publisherId: config.publisherId,
      trackId: config.trackId,
      sendToSFU: config.sendToSFU ?? false,
    }
    
    this.sfuClient = this.config.sfuClient
    this.publisherId = this.config.publisherId
    this.trackId = this.config.trackId
    
    this.codec = createWaveletCodec({
      waveletType: this.config.waveletType,
      entropyCoding: this.config.entropyCoding,
      dwtLevels: this.config.dwtLevels,
      colorSpace: this.config.colorSpace,
      quality: this.config.quality,
      enableKalman: this.config.enableKalman,
      keyFrameInterval: this.config.keyFrameInterval,
      width: this.config.width,
      height: this.config.height,
      motionEstimation: this.config.motionEstimation,
    })
    
    this.canvas = new OffscreenCanvas(this.config.width!, this.config.height!)
    this.ctx = this.canvas.getContext('2d')!
  }

  async processStream(inputStream: MediaStream): Promise<MediaStream> {
    const videoTrack = inputStream.getVideoTracks()[0]
    if (!videoTrack) {
      throw new Error('No video track in input stream')
    }

    const settings = videoTrack.getSettings()
    this.config.width = settings.width || 640
    this.config.height = settings.height || 480
    
    // Recreate codec with correct dimensions
    this.codec = createWaveletCodec({
      quality: this.config.quality,
      enableKalman: this.config.enableKalman,
      keyFrameInterval: this.config.keyFrameInterval,
      width: this.config.width,
      height: this.config.height,
    })
    
    this.canvas.width = this.config.width
    this.canvas.height = this.config.height
    
    this.sourceVideo = document.createElement('video')
    this.sourceVideo.srcObject = inputStream
    this.sourceVideo.autoplay = true
    this.sourceVideo.playsInline = true
    this.sourceVideo.muted = true

    await this.sourceVideo.play()
    const sourceVideo = this.sourceVideo

    // ── Init background blur processor ───────────────────────────────────────
    if (this.config.blurRadius! > 0) {
      this.blurProcessor = new BackgroundBlurProcessor()
      this.blurProcessor.init(sourceVideo, {
        blurRadius: this.config.blurRadius!,
        blurMode: this.config.blurMode!,
        onStats: this.config.blurOnStats,
      })
    }

    const outputCanvas = document.createElement('canvas')
    outputCanvas.width = this.config.width
    outputCanvas.height = this.config.height
    const outputCtx = outputCanvas.getContext('2d')!

    this.processedStream = outputCanvas.captureStream(this.config.frameRate)

    const processFrame = (timestamp: number) => {
      if (timestamp - this.lastFrameTime < 1000 / this.config.frameRate!) {
        this.animationId = requestAnimationFrame(processFrame)
        return
      }
      this.lastFrameTime = timestamp

      try {
        let frameToEncode: VideoFrame | ImageData | null = null

        if (this.blurProcessor && this.config.blurRadius! > 0) {
          const blurred = this.blurProcessor.process(this.ctx, this.config.width!, this.config.height!)
          if (blurred) {
            this.ctx.putImageData(blurred, 0, 0)
            outputCtx.drawImage(this.canvas, 0, 0)
            frameToEncode = new VideoFrame(outputCanvas, { timestamp })
          }
        }

        if (!frameToEncode) {
          outputCtx.drawImage(sourceVideo, 0, 0, this.config.width!, this.config.height!)
          frameToEncode = new VideoFrame(outputCanvas, { timestamp })
        }

        const encodeStart = performance.now()
        const encoded = this.codec.encodeFrame(frameToEncode)
        const encodeTime = performance.now() - encodeStart
        frameToEncode.close()

        if (encoded && encoded.byteLength > 0) {
          if (this.config.sendToSFU && this.sfuClient && this.publisherId && this.trackId) {
            const frameType = this.codec.getStats().keyFrames > this.localStats.keyFrames ? 'keyframe' : 'delta'
            const sfuFrame: SFUEncodedFrame = {
              publisherId: this.publisherId,
              trackId: this.trackId,
              timestamp: timestamp,
              data: encoded,
              type: frameType,
            }
            try {
              this.sfuClient.sendEncodedFrame(sfuFrame)
            } catch (e) {
              debugWarn('[Wavelet] SFU send error:', e)
            }
          }

          const decodeStart = performance.now()
          const decoded = this.codec.decodeFrame(encoded, timestamp)
          const decodeTime = performance.now() - decodeStart

          if (decoded) {
            this.ctx.drawImage(decoded, 0, 0)
            outputCtx.drawImage(this.canvas, 0, 0)
            decoded.close()

            this.localStats.framesProcessed++
            this.localStats.totalBytes += encoded.byteLength
            this.localStats.compressionRatio = (this.config.width! * this.config.height! * 4) / Math.max(encoded.byteLength, 1)
            this.localStats.avgEncodeTimeMs = (this.localStats.avgEncodeTimeMs * (this.localStats.framesProcessed - 1) + encodeTime) / this.localStats.framesProcessed
            this.localStats.avgDecodeTimeMs = (this.localStats.avgDecodeTimeMs * (this.localStats.framesProcessed - 1) + decodeTime) / this.localStats.framesProcessed
          } else {
            outputCtx.drawImage(sourceVideo, 0, 0, this.config.width!, this.config.height!)
          }
        } else {
          outputCtx.drawImage(sourceVideo, 0, 0, this.config.width!, this.config.height!)
        }
      } catch (e) {
        debugWarn('[Wavelet] Frame error:', e)
        outputCtx.drawImage(sourceVideo, 0, 0, this.config.width!, this.config.height!)
      }

      this.animationId = requestAnimationFrame(processFrame)
    }

    this.animationId = requestAnimationFrame(processFrame)

    return this.processedStream
  }

  stop(): void {
    if (this.animationId) {
      cancelAnimationFrame(this.animationId)
      this.animationId = null
    }
    if (this.blurProcessor) {
      this.blurProcessor.dispose()
      this.blurProcessor = null
    }
    if (this.sourceVideo) {
      this.sourceVideo.srcObject = null
      this.sourceVideo = null
    }
    if (this.processedStream) {
      this.processedStream.getVideoTracks().forEach(t => t.stop())
      this.processedStream = null
    }
    this.codec.reset()
    this.localStats = {
      framesProcessed: 0,
      keyFrames: 0,
      deltaFrames: 0,
      totalBytes: 0,
      compressionRatio: 0,
      avgEncodeTimeMs: 0,
      avgDecodeTimeMs: 0,
    }
  }

  setBlurRadius(radius: number): void {
    this.config.blurRadius = Math.max(0, Math.min(10, Math.round(radius)))
    if (this.blurProcessor) {
      this.blurProcessor.setBlurRadius(this.config.blurRadius)
    }
  }

  setBlurMode(mode: 'fast' | 'quality'): void {
    this.config.blurMode = mode
    if (this.blurProcessor) {
      this.blurProcessor.setBlurMode(mode)
    }
  }

  getBlurStats(): BlurStats | null {
    return this.blurProcessor?.getStats() ?? null
  }

  getStats(): ProcessorStats {
    const s = this.codec.getStats()
    return {
      framesProcessed: s.framesProcessed,
      keyFrames: s.keyFrames,
      deltaFrames: s.deltaFrames,
      totalBytes: s.totalBytes,
      compressionRatio: s.compressionRatio,
      avgEncodeTimeMs: s.avgEncodeTimeMs,
      avgDecodeTimeMs: s.avgDecodeTimeMs,
    }
  }

  isProcessing(): boolean {
    return this.animationId !== null
  }
}

export function createWaveletProcessor(config?: Partial<WaveletProcessorConfig>): WaveletVideoProcessor {
  return new WaveletVideoProcessor(config)
}
