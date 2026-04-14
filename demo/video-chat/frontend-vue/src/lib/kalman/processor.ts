/**
 * WebRTC Video Processor
 * Integrates wavelet compression and Kalman filtering with WebRTC
 */

import { createVideoEnhancer, VideoEnhancer } from './video.js'
import type { EnhancedFrame, QualityMetrics } from './video.js'

export interface WebRTCVideoProcessorConfig {
  enabled: boolean
  quality: number
  adaptiveQuality: boolean
  targetBitrate: number
  enableKalman: boolean
  kalmanStrength: number
  turnServers: RTCIceServer[]
}

const DEFAULT_PROCESSOR_CONFIG: WebRTCVideoProcessorConfig = {
  enabled: true,
  quality: 40,
  adaptiveQuality: true,
  targetBitrate: 500000,
  enableKalman: false, // Disabled: stub for future use
  kalmanStrength: 0.5,
  turnServers: [
    { urls: 'stun:stun.l.google.com:19302' },
    { urls: 'stun:stun1.l.google.com:19302' },
  ],
}

export class WebRTCVideoProcessor {
  private config: WebRTCVideoProcessorConfig
  private enhancer: VideoEnhancer
  private canvas: OffscreenCanvas
  private ctx: OffscreenCanvasRenderingContext2D
  private videoTrack: MediaStreamTrack | null = null
  private processor: ScriptProcessorNode | AudioWorkletNode | null = null
  private frameRate: number = 30
  private lastFrameTime: number = 0
  private animationFrameId: number | null = null
  private stream: MediaStream | null = null
  private statsInterval: number | null = null
  private metricsCallback: ((metrics: QualityMetrics) => void) | null = null

  constructor(config: Partial<WebRTCVideoProcessorConfig> = {}) {
    this.config = { ...DEFAULT_PROCESSOR_CONFIG, ...config }
    this.enhancer = createVideoEnhancer({
      waveletQuality: this.config.quality,
      adaptiveQuality: this.config.adaptiveQuality,
      targetBitrate: this.config.targetBitrate,
      enableEnhancement: this.config.enableKalman,
      enhancementStrength: this.config.kalmanStrength,
    })
    this.canvas = new OffscreenCanvas(640, 480)
    this.ctx = this.canvas.getContext('2d', { alpha: false })!
  }

  async initialize(stream: MediaStream): Promise<MediaStream> {
    this.stream = stream
    const videoTrack = stream.getVideoTracks()[0]
    if (!videoTrack) {
      throw new Error('No video track in stream')
    }

    this.videoTrack = videoTrack

    const settings = videoTrack.getSettings()
    this.canvas.width = settings.width || 640
    this.canvas.height = settings.height || 480

    return stream
  }

  startProcessing(
    videoElement: HTMLVideoElement,
    onFrame?: (enhanced: EnhancedFrame) => void,
    onMetrics?: (metrics: QualityMetrics) => void
  ): void {
    if (!this.config.enabled) return

    this.metricsCallback = onMetrics || null
    let lastEncodedFrame: EnhancedFrame | null = null

    const processFrame = async (timestamp: number) => {
      if (timestamp - this.lastFrameTime < 1000 / this.frameRate) {
        this.animationFrameId = requestAnimationFrame(processFrame)
        return
      }

      this.ctx.drawImage(videoElement, 0, 0, this.canvas.width, this.canvas.height)
      const imageData = this.ctx.getImageData(0, 0, this.canvas.width, this.canvas.height)

      const enhanced = this.enhancer.encodeFrame(imageData, timestamp)
      lastEncodedFrame = enhanced

      if (onFrame) {
        onFrame(enhanced)
      }

      if (this.metricsCallback) {
        const metrics = this.enhancer.getMetrics().average
        if (metrics.latency > 0) {
          this.metricsCallback(metrics)
        }
      }

      this.lastFrameTime = timestamp
      this.animationFrameId = requestAnimationFrame(processFrame)
    }

    this.animationFrameId = requestAnimationFrame(processFrame)

    this.statsInterval = window.setInterval(() => {
      if (this.metricsCallback && this.videoTrack) {
        const stats = this.videoTrack.getSettings()
        console.debug('[VideoProcessor]', {
          width: stats.width,
          height: stats.height,
          frameRate: stats.frameRate,
          enhanced: this.enhancer.getMetrics().average,
        })
      }
    }, 5000)
  }

  stopProcessing(): void {
    if (this.animationFrameId !== null) {
      cancelAnimationFrame(this.animationFrameId)
      this.animationFrameId = null
    }

    if (this.statsInterval !== null) {
      clearInterval(this.statsInterval)
      this.statsInterval = null
    }

    this.enhancer.reset()
  }

  decodeFrame(frameData: EnhancedFrame): ImageData | null {
    try {
      const decoded = this.enhancer.decodeFrame(frameData.frameData)
      return new ImageData(new Uint8ClampedArray(decoded.data), decoded.width, decoded.height)
    } catch (error) {
      console.error('Failed to decode frame:', error)
      return null
    }
  }

  setQuality(quality: number): void {
    this.config.quality = Math.max(1, Math.min(100, quality))
    this.enhancer.setQuality(this.config.quality)
  }

  setFrameRate(fps: number): void {
    this.frameRate = Math.max(1, Math.min(60, fps))
  }

  setKalmanEnhancement(enabled: boolean, strength?: number): void {
    this.config.enableKalman = enabled
    if (strength !== undefined) {
      this.config.kalmanStrength = strength
    }
    this.enhancer.setEnhancement(enabled, strength)
  }

  getMetrics(): { average: QualityMetrics; recent: QualityMetrics[] } {
    return this.enhancer.getMetrics()
  }

  getConfiguration(): RTCConfiguration {
    return {
      iceServers: this.config.turnServers,
      iceCandidatePoolSize: 10,
    }
  }

  setTURNServer(url: string, username?: string, credential?: string): void {
    const turnServer: RTCIceServer = { urls: url }
    if (username && credential) {
      turnServer.username = username
      turnServer.credential = credential
    }

    const existingIndex = this.config.turnServers.findIndex(s => 
      typeof s.urls === 'string' && s.urls.startsWith('turn:')
    )

    if (existingIndex >= 0) {
      this.config.turnServers[existingIndex] = turnServer
    } else {
      this.config.turnServers.push(turnServer)
    }
  }

  destroy(): void {
    this.stopProcessing()
    this.stream = null
    this.videoTrack = null
    this.enhancer.reset()
  }
}

export function createVideoProcessor(config?: Partial<WebRTCVideoProcessorConfig>): WebRTCVideoProcessor {
  return new WebRTCVideoProcessor(config)
}

export function estimateBandwidth(metrics: QualityMetrics[]): number {
  if (metrics.length < 5) return 500000

  const recent = metrics.slice(-10)
  const avgBitrate = recent.reduce((sum, m) => sum + m.bitrate, 0) / recent.length
  const variance = recent.reduce((sum, m) => sum + Math.pow(m.bitrate - avgBitrate, 2), 0) / recent.length
  const stdDev = Math.sqrt(variance)

  return Math.max(100000, avgBitrate - 2 * stdDev)
}

export function selectOptimalQuality(
  targetBitrate: number,
  currentMetrics: QualityMetrics[]
): number {
  if (currentMetrics.length === 0) return 75

  const avgBitrate = currentMetrics.reduce((sum, m) => sum + m.bitrate, 0) / currentMetrics.length

  if (avgBitrate > targetBitrate * 1.2) {
    return Math.max(20, Math.min(75, 75 - (avgBitrate - targetBitrate) / 10000))
  } else if (avgBitrate < targetBitrate * 0.8) {
    return Math.min(90, Math.max(75, 75 + (targetBitrate - avgBitrate) / 10000))
  }

  return 75
}
