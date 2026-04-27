/**
 * WebRTC Wavelet Codec Shim
 * Integrates wavelet compression into WebRTC/SFU pipeline
 * 
 * Uses the WebRTC Encoded Transform API (Chrome 94+, Firefox 118+)
 * 
 * Configuration:
 *   - quality: 40 (best balance of quality vs compression)
 *   - keyFrameInterval: 2 (every 2nd frame is keyframe to prevent drift)
 *   - WASM is used if available (20x compression), falls back to JS wavelet (5x)
 * 
 * Performance (320x240 webcam):
 *   - WASM: ~20x compression, psnr ~35dB
 *   - JS wavelet: ~5x compression
 */

import { createEncoder, createDecoder } from '../wavelet/codec.js'
import { createKalmanFilter } from '../kalman/filter.js'
import { 
  WasmWaveletVideoEncoder, 
  WasmWaveletVideoDecoder,
  WasmCodecConfig,
  WaveletType,
  EntropyMode,
  ColorSpace
} from '../wasm/wasm-codec.js'
import { debugLog, debugWarn } from '../../support/debugLogs.js'

export type { WaveletType, EntropyMode, ColorSpace }

export interface WaveletCodecConfig {
  waveletType?: WaveletType
  entropyCoding?: EntropyMode
  dwtLevels?: number
  colorSpace?: ColorSpace
  quality?: number
  enableKalman?: boolean
  keyFrameInterval?: number
  width?: number
  height?: number
  motionEstimation?: boolean
}

export interface CodecStats {
  framesProcessed: number
  framesDecoded: number
  keyFrames: number
  deltaFrames: number
  totalBytes: number
  compressionRatio: number
  avgEncodeTimeMs: number
  avgDecodeTimeMs: number
}

const DEFAULT_CONFIG: Required<WaveletCodecConfig> = {
  waveletType: 'haar',
  entropyCoding: 'rle',
  dwtLevels: 4,
  colorSpace: 'yuv',
  quality: 40,
  enableKalman: false,
  keyFrameInterval: 30,
  width: 640,
  height: 480,
  motionEstimation: true,
}

export class WaveletCodec {
  private config: Required<WaveletCodecConfig>
  private encoder: ReturnType<typeof createEncoder>
  private decoder: ReturnType<typeof createDecoder>
  private kalman: ReturnType<typeof createKalmanFilter>
  private frameCount: number
  private stats: CodecStats
  private encodedChunkId: number = 0
  private usingWasm: boolean = false

  constructor(config: Partial<WaveletCodecConfig> = {}) {
    this.config = { ...DEFAULT_CONFIG, ...config }
    this.encoder = createEncoder({
      waveletType: this.config.waveletType,
      levels: this.config.dwtLevels,
      quality: this.config.quality,
      colorSpace: this.config.colorSpace,
      entropyCoding: this.config.entropyCoding,
      keyFrameInterval: this.config.keyFrameInterval,
    })
    this.decoder = createDecoder({
      waveletType: this.config.waveletType,
      levels: this.config.dwtLevels,
      quality: this.config.quality,
      colorSpace: this.config.colorSpace,
      entropyCoding: this.config.entropyCoding,
      keyFrameInterval: this.config.keyFrameInterval,
    })
    this.kalman = createKalmanFilter({
      processNoise: 0.001,
      measurementNoise: 0.1,
    })
    this.frameCount = 0
    this.stats = {
      framesProcessed: 0,
      framesDecoded: 0,
      keyFrames: 0,
      deltaFrames: 0,
      totalBytes: 0,
      compressionRatio: 0,
      avgEncodeTimeMs: 0,
      avgDecodeTimeMs: 0,
    }

    // Kick off WASM init in background; swap in once ready
    this.initWasm()
  }

  getConfig(): Required<WaveletCodecConfig> {
    return { ...this.config }
  }

  setConfig(config: Partial<WaveletCodecConfig>): void {
    this.config = { ...this.config, ...config }
  }

  private async initWasm(): Promise<void> {
    debugLog('[WaveletCodec] Starting WASM init...')
    try {
      const w = this.config.width ?? 640
      const h = this.config.height ?? 480
      debugLog(`[WaveletCodec] WASM dimensions: ${w}x${h} quality=${this.config.quality}`)
      const wasmEnc = new WasmWaveletVideoEncoder({
        width: w,
        height: h,
        quality: this.config.quality,
        keyFrameInterval: this.config.keyFrameInterval,
        waveletType: this.config.waveletType,
        dwtLevels: this.config.dwtLevels,
        colorSpace: this.config.colorSpace,
        entropyCoding: this.config.entropyCoding,
        motionEstimation: this.config.motionEstimation,
      })
      const wasmDec = new WasmWaveletVideoDecoder({
        width: w,
        height: h,
        quality: this.config.quality,
        waveletType: this.config.waveletType,
        dwtLevels: this.config.dwtLevels,
        colorSpace: this.config.colorSpace,
        entropyCoding: this.config.entropyCoding,
      })
      debugLog('[WaveletCodec] WASM objects created, calling init()...')
      const [encOk, decOk] = await Promise.all([wasmEnc.init(), wasmDec.init()])
      debugLog(`[WaveletCodec] WASM init results: enc=${encOk} dec=${decOk}`)
      if (encOk && decOk) {
        this.encoder.reset()
        this.decoder.reset()
        this.encoder = wasmEnc as unknown as ReturnType<typeof createEncoder>
        this.decoder = wasmDec as unknown as ReturnType<typeof createDecoder>
        this.usingWasm = true
        debugLog('[WaveletCodec] Switched to WASM codec')
      } else {
        debugWarn(`[WaveletCodec] WASM init returned false: enc=${encOk} dec=${decOk}`)
      }
    } catch (e) {
      debugWarn('[WaveletCodec] WASM init exception:', e)
    }
  }

  encodeFrame(videoFrame: VideoFrame): ArrayBuffer | null {
    const startTime = performance.now()

    try {
      const width = videoFrame.displayWidth || this.config.width
      const height = videoFrame.displayHeight || this.config.height

      const frameWidth = videoFrame.codedWidth ?? width
      const frameHeight = videoFrame.codedHeight ?? height
      
      if (frameWidth !== this.config.width || frameHeight !== this.config.height) {
        this.config.width = frameWidth
        this.config.height = frameHeight
        this.kalman.setFrameSize(frameWidth, frameHeight)
      }

      const canvas = new OffscreenCanvas(width, height)
      const ctx = canvas.getContext('2d')
      if (!ctx) return null

      ctx.drawImage(videoFrame, 0, 0)
      const imageData = ctx.getImageData(0, 0, width, height)

      // Pass full-colour RGBA directly; codec handles YUV conversion internally.
      const frameData = this.encoder.encodeFrame(imageData, videoFrame.timestamp)

      const encodedData = this.packageFrame(frameData, width, height)

      const encodeTime = performance.now() - startTime
      this.stats.framesProcessed++
      this.stats.totalBytes += encodedData.byteLength
      this.stats.avgEncodeTimeMs = 
        (this.stats.avgEncodeTimeMs * (this.stats.framesProcessed - 1) + encodeTime) / this.stats.framesProcessed
      this.stats.compressionRatio = (width * height * 4) / Math.max(encodedData.byteLength, 1)

      // Read actual frame type from inner payload byte 5 (0=key, 1=delta).
      // The WASM encoder wrapper always reports 'keyframe' so we can't trust frameData.type.
      const innerByte5 = frameData.data.byteLength > 5 ? new Uint8Array(frameData.data)[5] : 0
      if (innerByte5 === 0) {
        this.stats.keyFrames++
      } else {
        this.stats.deltaFrames++
      }

      return encodedData
    } catch (error) {
      debugWarn('[WaveletCodec] Encode error:', error)
      return null
    }
  }

  decodeFrame(encodedData: ArrayBuffer, timestamp: number): VideoFrame | null {
    const startTime = performance.now()

    try {
      const unpackaged = this.unpackageFrame(encodedData)
      if (!unpackaged || !unpackaged.data) {
        this.recordDecodeMetric(performance.now() - startTime)
        return null
      }

      const { data, width, height, isKeyFrame } = unpackaged

      const frameData = {
        type: isKeyFrame ? 'keyframe' as const : 'delta' as const,
        timestamp,
        width,
        height,
        data,
        quality: this.config.quality,
      }

      const decoded = this.decoder.decodeFrame(frameData)
      if (!decoded) {
        this.recordDecodeMetric(performance.now() - startTime)
        return null
      }

      const canvas = new OffscreenCanvas(width, height)
      const ctx = canvas.getContext('2d')
      if (!ctx) {
        this.recordDecodeMetric(performance.now() - startTime)
        return null
      }

      const imageData = new ImageData(new Uint8ClampedArray(decoded.data), width, height)
      ctx.putImageData(imageData, 0, 0)

      this.recordDecodeMetric(performance.now() - startTime)
      return new VideoFrame(canvas, { timestamp })
    } catch (error) {
      debugWarn('[WaveletCodec] Decode error:', error)
      this.recordDecodeMetric(performance.now() - startTime)
      return null
    }
  }

  private recordDecodeMetric(decodeTime: number): void {
    this.stats.framesDecoded++
    this.stats.avgDecodeTimeMs =
      (this.stats.avgDecodeTimeMs * (this.stats.framesDecoded - 1) + decodeTime) / this.stats.framesDecoded
  }

  private packageFrame(frameData: { type: string; timestamp: number; data: ArrayBuffer; width: number; height: number; quality: number }, width: number, height: number): ArrayBuffer {
    const header = new Uint32Array([
      0x574C5643,
      width,
      height,
      frameData.type === 'keyframe' ? 1 : 0,
      frameData.data.byteLength,
      this.encodedChunkId++,
    ])

    const buffer = new Uint8Array(header.byteLength + frameData.data.byteLength)
    buffer.set(new Uint8Array(header.buffer), 0)
    buffer.set(new Uint8Array(frameData.data), header.byteLength)

    return buffer.buffer
  }

  private unpackageFrame(data: ArrayBuffer): { data: ArrayBuffer; width: number; height: number; isKeyFrame: boolean } | null {
    const view = new DataView(data)
    const magic = view.getUint32(0, true)  // Uint32Array writes LE, so read LE

    if (magic !== 0x574C5643) {
      return null
    }

    const width = view.getUint32(4, true)
    const height = view.getUint32(8, true)
    const isKeyFrame = view.getUint32(12, true) === 1
    const byteLength = view.getUint32(16, true)

    const frameData = data.slice(24, 24 + byteLength)

    return { data: frameData, width, height, isKeyFrame }
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

  getStats(): CodecStats {
    return { ...this.stats }
  }

  reset(): void {
    this.frameCount = 0
    this.encoder.reset()
    this.decoder.reset()
    this.kalman.reset()
    this.stats = {
      framesProcessed: 0,
      framesDecoded: 0,
      keyFrames: 0,
      deltaFrames: 0,
      totalBytes: 0,
      compressionRatio: 0,
      avgEncodeTimeMs: 0,
      avgDecodeTimeMs: 0,
    }
  }
}

export function createWaveletCodec(config?: Partial<WaveletCodecConfig>): WaveletCodec {
  return new WaveletCodec(config)
}
