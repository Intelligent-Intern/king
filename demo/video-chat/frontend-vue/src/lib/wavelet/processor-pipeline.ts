/**
 * Wavelet Video Processor
 * Processes camera stream through wavelet codec and outputs a new stream
 */

import { createWaveletCodec } from './webrtc-shim.js'
import { debugWarn } from '../../support/debugLogs.js'

export interface WaveletProcessorConfig {
  quality: number
  enableKalman: boolean
  keyFrameInterval: number
  width: number
  height: number
  frameRate: number
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

  constructor(config: Partial<WaveletProcessorConfig> = {}) {
    this.config = {
      quality: config.quality ?? 40,
      enableKalman: config.enableKalman ?? false, // Disabled: stub for future use
      keyFrameInterval: config.keyFrameInterval ?? 30,
      width: config.width ?? 640,
      height: config.height ?? 480,
      frameRate: config.frameRate ?? 30,
    }
    
    this.codec = createWaveletCodec({
      quality: this.config.quality,
      enableKalman: this.config.enableKalman,
      keyFrameInterval: this.config.keyFrameInterval,
      width: this.config.width,
      height: this.config.height,
    })
    
    this.canvas = new OffscreenCanvas(this.config.width, this.config.height)
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
    this.sourceVideo.muted = true  // prevent mic audio loopback through speakers

    await this.sourceVideo.play()
    const sourceVideo = this.sourceVideo

    const outputCanvas = document.createElement('canvas')
    outputCanvas.width = this.config.width
    outputCanvas.height = this.config.height
    const outputCtx = outputCanvas.getContext('2d')!

    this.processedStream = outputCanvas.captureStream(this.config.frameRate)

    const processFrame = (timestamp: number) => {
      if (timestamp - this.lastFrameTime < 1000 / this.config.frameRate) {
        this.animationId = requestAnimationFrame(processFrame)
        return
      }
      this.lastFrameTime = timestamp

      try {
        outputCtx.drawImage(sourceVideo, 0, 0, this.config.width, this.config.height)
        
        const frame = new VideoFrame(outputCanvas, { timestamp })
        
        const encodeStart = performance.now()
        const encoded = this.codec.encodeFrame(frame)
        const encodeTime = performance.now() - encodeStart
        frame.close()

        if (encoded && encoded.byteLength > 0) {
          const decodeStart = performance.now()
          const decoded = this.codec.decodeFrame(encoded, timestamp)
          const decodeTime = performance.now() - decodeStart
          
          if (decoded) {
            this.ctx.drawImage(decoded, 0, 0)
            outputCtx.drawImage(this.canvas, 0, 0)
            decoded.close()
            
            // Track local stats
            this.localStats.framesProcessed++
            this.localStats.totalBytes += encoded.byteLength
            this.localStats.compressionRatio = (this.config.width * this.config.height * 4) / Math.max(encoded.byteLength, 1)
            this.localStats.avgEncodeTimeMs = (this.localStats.avgEncodeTimeMs * (this.localStats.framesProcessed - 1) + encodeTime) / this.localStats.framesProcessed
            this.localStats.avgDecodeTimeMs = (this.localStats.avgDecodeTimeMs * (this.localStats.framesProcessed - 1) + decodeTime) / this.localStats.framesProcessed
          } else {
            // Decode failed - show original
            outputCtx.drawImage(sourceVideo, 0, 0, this.config.width, this.config.height)
          }
        } else {
          // Encode failed - show original
          outputCtx.drawImage(sourceVideo, 0, 0, this.config.width, this.config.height)
        }
      } catch (e) {
        debugWarn('[Wavelet] Frame error:', e)
        outputCtx.drawImage(sourceVideo, 0, 0, this.config.width, this.config.height)
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
    if (this.sourceVideo) {
      this.sourceVideo.srcObject = null   // release camera hardware reference
      this.sourceVideo = null
    }
    if (this.processedStream) {
      // Only stop the canvas-generated video track — audio tracks were added
      // externally by the caller and are their responsibility to stop.
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
