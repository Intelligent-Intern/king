/**
 * WebRTC Video Processor with Wavelet Compression and Kalman Filtering
 * Integrates custom codec into the WebRTC pipeline
 */

import { createEncoder, createDecoder, WaveletVideoEncoder, WaveletVideoDecoder } from '../wavelet/codec.js'
import { createKalmanFilter, VideoKalmanFilter } from '../kalman/filter.js'

export interface VideoProcessorConfig {
  enabled: boolean
  waveletQuality: number
  enableKalman: boolean
  keyFrameInterval: number
  maxBitrate: number
  targetFps: number
}

export interface ProcessedFrame {
  frame: ImageData
  compressed: ArrayBuffer
  isKeyFrame: boolean
  timestamp: number
  metrics: FrameMetrics
}

export interface FrameMetrics {
  originalSize: number
  compressedSize: number
  compressionRatio: number
  processingTimeMs: number
  psnr: number
}

const DEFAULT_CONFIG: VideoProcessorConfig = {
  enabled: true,
  waveletQuality: 40,
  enableKalman: false, // Disabled: replaces pixels with residuals, hurts quality
  keyFrameInterval: 30,
  maxBitrate: 1000000,
  targetFps: 30,
}

export class VideoFrameProcessor {
  private config: VideoProcessorConfig
  private encoder: WaveletVideoEncoder
  private decoder: WaveletVideoDecoder
  private kalman: VideoKalmanFilter
  private frameCount: number
  private lastFrameTime: number
  private metrics: FrameMetrics[]

  constructor(config: Partial<VideoProcessorConfig> = {}) {
    this.config = { ...DEFAULT_CONFIG, ...config }
    this.encoder = createEncoder({
      quality: this.config.waveletQuality,
      keyFrameInterval: this.config.keyFrameInterval,
    })
    this.decoder = createDecoder({
      quality: this.config.waveletQuality,
    })
    this.kalman = createKalmanFilter({
      processNoise: 0.001,
      measurementNoise: 0.1,
    })
    this.frameCount = 0
    this.lastFrameTime = performance.now()
    this.metrics = []
  }

  processFrame(imageData: ImageData): ProcessedFrame {
    const startTime = performance.now()
    const timestamp = Date.now()

    if (this.config.enableKalman) {
      this.kalman.setFrameSize(imageData.width, imageData.height)
      this.kalman.updateWithFrame(this.toGrayscale(imageData))
    }

    const frameData = this.encoder.encodeFrame(imageData, timestamp)

    const originalSize = imageData.width * imageData.height * 4
    const compressedSize = frameData.data.byteLength
    const processingTimeMs = performance.now() - startTime

    const frameMetrics: FrameMetrics = {
      originalSize,
      compressedSize,
      compressionRatio: originalSize / compressedSize,
      processingTimeMs,
      psnr: this.estimatePSNR(originalSize / compressedSize),
    }

    this.metrics.push(frameMetrics)
    if (this.metrics.length > 300) {
      this.metrics.shift()
    }

    this.frameCount++
    this.lastFrameTime = startTime

    return {
      frame: new ImageData(
        new Uint8ClampedArray(imageData.data),
        imageData.width,
        imageData.height
      ),
      compressed: frameData.data,
      isKeyFrame: frameData.type === 'keyframe',
      timestamp,
      metrics: frameMetrics,
    }
  }

  decodeFrame(compressed: ArrayBuffer, timestamp: number): ImageData | null {
    try {
      const frameData = {
        type: 'delta' as const,
        timestamp,
        width: 0,
        height: 0,
        data: compressed,
        quality: this.config.waveletQuality,
      }
      const decoded = this.decoder.decodeFrame(frameData)
      if (!decoded) return null
      return new ImageData(new Uint8ClampedArray(decoded.data), decoded.width, decoded.height)
    } catch {
      return null
    }
  }

  private toGrayscale(imageData: ImageData): Float32Array {
    const pixels = imageData.data
    const grayscale = new Float32Array(imageData.width * imageData.height)

    for (let i = 0; i < grayscale.length; i++) {
      const r = pixels[i * 4]
      const g = pixels[i * 4 + 1]
      const b = pixels[i * 4 + 2]
      grayscale[i] = 0.299 * r + 0.587 * g + 0.114 * b
    }

    return grayscale
  }

  private grayscaleToImageData(grayscale: Float32Array, width: number, height: number): ImageData {
    const data = new Uint8ClampedArray(width * height * 4)
    
    for (let i = 0; i < grayscale.length; i++) {
      const gray = Math.max(0, Math.min(255, Math.round(grayscale[i])))
      data[i * 4] = gray
      data[i * 4 + 1] = gray
      data[i * 4 + 2] = gray
      data[i * 4 + 3] = 255
    }

    return new ImageData(data, width, height)
  }

  private estimatePSNR(compressionRatio: number): number {
    const basePSNR = 25
    const maxPSNR = 45
    const ratio = Math.min(compressionRatio / 20, 1)
    return basePSNR + (maxPSNR - basePSNR) * ratio
  }

  getMetrics(): { average: FrameMetrics; recent: FrameMetrics[]; summary: MetricsSummary } {
    const recent = this.metrics.slice(-30)
    
    let avg: FrameMetrics = {
      originalSize: 0,
      compressedSize: 0,
      compressionRatio: 0,
      processingTimeMs: 0,
      psnr: 0,
    }

    if (this.metrics.length > 0) {
      const sum = this.metrics.reduce((acc, m) => ({
        originalSize: acc.originalSize + m.originalSize,
        compressedSize: acc.compressedSize + m.compressedSize,
        compressionRatio: acc.compressionRatio + m.compressionRatio,
        processingTimeMs: acc.processingTimeMs + m.processingTimeMs,
        psnr: acc.psnr + m.psnr,
      }), avg)
      
      const count = this.metrics.length
      avg = {
        originalSize: sum.originalSize / count,
        compressedSize: sum.compressedSize / count,
        compressionRatio: sum.compressionRatio / count,
        processingTimeMs: sum.processingTimeMs / count,
        psnr: sum.psnr / count,
      }
    }

    const summary: MetricsSummary = {
      totalFrames: this.frameCount,
      keyFrames: Math.floor(this.frameCount / this.config.keyFrameInterval),
      averageBitrate: avg.compressedSize * 8 * this.config.targetFps,
      estimatedBandwidth: avg.compressedSize * this.config.targetFps,
      codec: 'wavelet-kalman',
      quality: this.config.waveletQuality,
    }

    return { average: avg, recent, summary }
  }

  setQuality(quality: number): void {
    this.config.waveletQuality = quality
    this.encoder.setQuality(quality)
    this.decoder.setQuality(quality)
  }

  reset(): void {
    this.frameCount = 0
    this.metrics = []
    this.encoder.reset()
    this.decoder.reset()
    this.kalman.reset()
  }
}

export interface MetricsSummary {
  totalFrames: number
  keyFrames: number
  averageBitrate: number
  estimatedBandwidth: number
  codec: string
  quality: number
}

export function createVideoProcessor(config?: Partial<VideoProcessorConfig>): VideoFrameProcessor {
  return new VideoFrameProcessor(config)
}
