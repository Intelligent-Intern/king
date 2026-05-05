/**
 * Video Enhancement Pipeline
 * Combines wavelet compression with Kalman filtering for improved video quality
 */

import { createEncoder, createDecoder, WaveletVideoEncoder, WaveletVideoDecoder } from '../wavelet/codec.ts'
import type { FrameData, DecodedFrame } from '../wavelet/codec.ts'
import { createKalmanFilter, VideoKalmanFilter } from './filter.ts'

export interface VideoEnhancerConfig {
  waveletQuality: number
  kalmanProcessNoise: number
  kalmanMeasurementNoise: number
  enableEnhancement: boolean
  enhancementStrength: number
  adaptiveQuality: boolean
  targetBitrate: number
}

export interface EnhancedFrame {
  frameData: FrameData
  motionVectors: Map<string, { dx: number; dy: number; confidence: number }>
  compressionRatio: number
  timestamp: number
}

export interface QualityMetrics {
  psnr: number
  ssim: number
  bitrate: number
  compressionRatio: number
  latency: number
}

const DEFAULT_ENHANCER_CONFIG: VideoEnhancerConfig = {
  waveletQuality: 40,
  kalmanProcessNoise: 0.001,
  kalmanMeasurementNoise: 0.1,
  enableEnhancement: false, // Disabled: adds noise before wavelet, hurts quality
  enhancementStrength: 0.3,
  adaptiveQuality: true,
  targetBitrate: 500000,
}

export class VideoEnhancer {
  private config: VideoEnhancerConfig
  private encoder: WaveletVideoEncoder
  private decoder: WaveletVideoDecoder
  private kalman: VideoKalmanFilter
  private frameCount: number
  private lastQualityAdjustment: number
  private metrics: QualityMetrics[]

  constructor(config: Partial<VideoEnhancerConfig> = {}) {
    this.config = { ...DEFAULT_ENHANCER_CONFIG, ...config }
    this.encoder = createEncoder({ quality: this.config.waveletQuality })
    this.decoder = createDecoder({ quality: this.config.waveletQuality })
    this.kalman = createKalmanFilter({
      processNoise: this.config.kalmanProcessNoise,
      measurementNoise: this.config.kalmanMeasurementNoise,
    })
    this.frameCount = 0
    this.lastQualityAdjustment = Date.now()
    this.metrics = []
  }

  setQuality(quality: number): void {
    this.config.waveletQuality = Math.max(1, Math.min(100, quality))
    this.encoder.setQuality(this.config.waveletQuality)
    this.decoder.setQuality(this.config.waveletQuality)
  }

  setEnhancement(enabled: boolean, strength?: number): void {
    this.config.enableEnhancement = enabled
    if (strength !== undefined) {
      this.config.enhancementStrength = Math.max(0, Math.min(1, strength))
    }
  }

  encodeFrame(imageData: ImageData, timestamp: number): EnhancedFrame {
    const startTime = performance.now()

    let motionVectors = new Map<string, { dx: number; dy: number; confidence: number }>()

    if (this.config.enableEnhancement) {
      this.kalman.setFrameSize(imageData.width, imageData.height)
      const pixels = new Float32Array(imageData.width * imageData.height)
      for (let i = 0; i < imageData.data.length; i += 4) {
        const gray = 0.299 * imageData.data[i] + 0.587 * imageData.data[i + 1] + 0.114 * imageData.data[i + 2]
        pixels[Math.floor(i / 4)] = gray
      }
      const result = this.kalman.updateWithFrame(pixels)
      if (result) {
        motionVectors = result.motionVectors
      }
    }

    const frameData = this.encoder.encodeFrame(imageData, timestamp)

    const originalSize = imageData.width * imageData.height * 4
    const compressedSize = frameData.data.byteLength
    const compressionRatio = originalSize / compressedSize

    const endTime = performance.now()

    const metric: QualityMetrics = {
      psnr: this.estimatePSNR(compressionRatio),
      ssim: this.estimateSSIM(compressionRatio),
      bitrate: compressedSize * 30,
      compressionRatio,
      latency: endTime - startTime,
    }
    this.metrics.push(metric)
    if (this.metrics.length > 300) this.metrics.shift()

    if (this.config.adaptiveQuality && Date.now() - this.lastQualityAdjustment > 5000) {
      this.adjustQuality()
      this.lastQualityAdjustment = Date.now()
    }

    this.frameCount++

    return {
      frameData,
      motionVectors,
      compressionRatio,
      timestamp,
    }
  }

  decodeFrame(frameData: FrameData): DecodedFrame {
    return this.decoder.decodeFrame(frameData)
  }

  private estimatePSNR(compressionRatio: number): number {
    const basePSNR = 30
    const maxPSNR = 50
    const ratio = Math.min(compressionRatio / 50, 1)
    return basePSNR + (maxPSNR - basePSNR) * ratio
  }

  private estimateSSIM(compressionRatio: number): number {
    const baseSSIM = 0.7
    const maxSSIM = 0.99
    const ratio = Math.min(compressionRatio / 50, 1)
    return baseSSIM + (maxSSIM - baseSSIM) * ratio
  }

  private adjustQuality(): void {
    if (this.metrics.length < 30) return

    const recentMetrics = this.metrics.slice(-30)
    const avgLatency = recentMetrics.reduce((sum, m) => sum + m.latency, 0) / recentMetrics.length
    const avgBitrate = recentMetrics.reduce((sum, m) => sum + m.bitrate, 0) / recentMetrics.length

    if (avgLatency > 50) {
      this.setQuality(this.config.waveletQuality - 5)
    } else if (avgLatency < 20 && avgBitrate < this.config.targetBitrate * 0.8) {
      this.setQuality(this.config.waveletQuality + 2)
    }
  }

  getMetrics(): { average: QualityMetrics; recent: QualityMetrics[] } {
    if (this.metrics.length === 0) {
      return {
        average: { psnr: 0, ssim: 0, bitrate: 0, compressionRatio: 0, latency: 0 },
        recent: [],
      }
    }

    const avg = this.metrics.reduce(
      (acc, m) => ({
        psnr: acc.psnr + m.psnr,
        ssim: acc.ssim + m.ssim,
        bitrate: acc.bitrate + m.bitrate,
        compressionRatio: acc.compressionRatio + m.compressionRatio,
        latency: acc.latency + m.latency,
      }),
      { psnr: 0, ssim: 0, bitrate: 0, compressionRatio: 0, latency: 0 }
    )

    const count = this.metrics.length
    return {
      average: {
        psnr: avg.psnr / count,
        ssim: avg.ssim / count,
        bitrate: avg.bitrate / count,
        compressionRatio: avg.compressionRatio / count,
        latency: avg.latency / count,
      },
      recent: this.metrics.slice(-30),
    }
  }

  reset(): void {
    this.encoder.reset()
    this.decoder.reset()
    this.kalman.reset()
    this.frameCount = 0
    this.metrics = []
  }
}

export function createVideoEnhancer(config?: Partial<VideoEnhancerConfig>): VideoEnhancer {
  return new VideoEnhancer(config)
}
