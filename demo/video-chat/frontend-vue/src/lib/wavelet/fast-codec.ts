/**
 * Optimized Wavelet Codec - Pure JavaScript with TypedArrays
 * Uses efficient algorithms and avoids memory allocations
 */

export interface WaveletStats {
  framesProcessed: number
  keyFrames: number
  deltaFrames: number
  totalBytes: number
  compressionRatio: number
  avgEncodeTimeMs: number
  avgDecodeTimeMs: number
}

const HAAR_SCALE = 0.70710678118

export class FastWaveletCodec {
  private quality: number
  private width: number
  private height: number
  private frameCount: number
  private previousY: Float32Array | null
  private tempBuffer: Float32Array
  private quantBuffer: Int16Array
  private stats: WaveletStats

  constructor(quality = 75, width = 640, height = 480) {
    this.quality = quality
    this.width = width
    this.height = height
    this.frameCount = 0
    this.previousY = null
    this.tempBuffer = new Float32Array(width * height)
    this.quantBuffer = new Int16Array(width * height)
    this.stats = {
      framesProcessed: 0,
      keyFrames: 0,
      deltaFrames: 0,
      totalBytes: 0,
      compressionRatio: 0,
      avgEncodeTimeMs: 0,
      avgDecodeTimeMs: 0,
    }
  }

  setQuality(q: number) {
    this.quality = Math.max(1, Math.min(100, q))
  }

  reset() {
    this.frameCount = 0
    this.previousY = null
  }

  encode(rgba: Uint8ClampedArray): ArrayBuffer {
    const start = performance.now()
    const { width, height, quality } = this
    const isKeyFrame = this.frameCount % 30 === 0
    this.frameCount++

    // Extract Y plane and subsample U/V
    const yLen = width * height
    const uvLen = (width >> 1) * (height >> 1)
    
    const yPlane = this.tempBuffer
    const uPlane = new Float32Array(uvLen)
    const vPlane = new Float32Array(uvLen)

    let yIdx = 0
    let uvIdx = 0
    
    for (let y = 0; y < height; y++) {
      for (let x = 0; x < width; x++) {
        const rgbaIdx = (y * width + x) * 4
        const r = rgba[rgbaIdx]
        const g = rgba[rgbaIdx + 1]
        const b = rgba[rgbaIdx + 2]
        
        // Fast RGB to Y
        yPlane[yIdx] = 0.299 * r + 0.587 * g + 0.114 * b
        
        if ((x & 1) === 0 && (y & 1) === 0) {
          // U = -0.147R - 0.289G + 0.436B
          // V = 0.615R - 0.515G - 0.100B
          const u = -0.147 * r - 0.289 * g + 0.436 * b
          const v = 0.615 * r - 0.515 * g - 0.100 * b
          uPlane[uvIdx] = u
          vPlane[uvIdx] = v
          uvIdx++
        }
        yIdx++
      }
    }

    // Compute residual for delta frames
    if (!isKeyFrame && this.previousY) {
      for (let i = 0; i < yLen; i++) {
        yPlane[i] -= this.previousY[i]
      }
    }

    // 1-level 2D Haar transform
    this.haar2d(yPlane, width, height)

    // Quantize
    const qFactor = quality < 50 ? 5000 / quality : 200 - 2 * quality
    const qY = this.quantBuffer
    
    let nzCount = 0
    for (let i = 0; i < yLen; i++) {
      const v = Math.abs(yPlane[i])
      if (v > 0.5) {
        qY[nzCount++] = Math.round(yPlane[i] / qFactor)
      }
    }

    // Pack output
    const headerSize = 24
    const outputSize = headerSize + nzCount * 2 + uvLen * 4
    const output = new Uint8Array(outputSize)
    const view = new DataView(output.buffer)

    view.setUint32(0, 0x57415645, true)  // MAGIC
    view.setUint16(4, width, true)
    view.setUint16(6, height, true)
    view.setUint8(8, quality)
    view.setUint8(9, isKeyFrame ? 0 : 1)
    view.setUint32(10, nzCount, true)
    view.setUint32(14, uvLen, true)
    view.setUint32(18, outputSize, true)
    view.setUint32(22, Math.floor(start), true)

    // Pack Y
    let offset = headerSize
    for (let i = 0; i < nzCount; i++) {
      view.setInt16(offset, qY[i], true)
      offset += 2
    }

    // Pack U/V (quantized, no transform for speed)
    for (let i = 0; i < uvLen; i++) {
      view.setInt16(offset, Math.round(uPlane[i] / qFactor), true)
      offset += 2
      view.setInt16(offset, Math.round(vPlane[i] / qFactor), true)
      offset += 2
    }

    // Store for delta
    if (isKeyFrame) {
      this.previousY = new Float32Array(yLen)
      // Inverse transform for storage
      this.ihaar2d(yPlane, width, height)
      this.previousY.set(yPlane.subarray(0, yLen))
    }

    const encodeTime = performance.now() - start
    this.stats.framesProcessed++
    this.stats.totalBytes += outputSize
    this.stats.compressionRatio = (yLen * 4) / outputSize
    this.stats.avgEncodeTimeMs = (this.stats.avgEncodeTimeMs * (this.stats.framesProcessed - 1) + encodeTime) / this.stats.framesProcessed
    
    if (isKeyFrame) {
      this.stats.keyFrames++
    } else {
      this.stats.deltaFrames++
    }

    return output.buffer
  }

  decode(data: ArrayBuffer): Uint8ClampedArray | null {
    const start = performance.now()
    const view = new DataView(data)
    
    if (view.getUint32(0, true) !== 0x57415645) return null
    
    const width = view.getUint16(4, true)
    const height = view.getUint16(6, true)
    const quality = view.getUint8(8)
    const isKeyFrame = view.getUint8(9) === 0
    const nzCount = view.getUint32(10, true)
    const uvLen = view.getUint32(14, true)

    const qFactor = quality < 50 ? 5000 / quality : 200 - 2 * quality
    const yLen = width * height

    // Allocate output
    const rgba = new Uint8ClampedArray(yLen * 4)

    // Read Y coefficients
    let offset = 24
    for (let i = 0; i < nzCount; i++) {
      this.tempBuffer[i] = view.getInt16(offset, true) * qFactor
      offset += 2
    }

    // Read U/V
    const uPlane = new Float32Array(uvLen)
    const vPlane = new Float32Array(uvLen)
    for (let i = 0; i < uvLen; i++) {
      uPlane[i] = view.getInt16(offset, true) * qFactor
      offset += 2
      vPlane[i] = view.getInt16(offset, true) * qFactor
      offset += 2
    }

    // Inverse transform
    this.ihaar2d(this.tempBuffer, width, height)

    // Add previous frame for delta
    if (!isKeyFrame && this.previousY) {
      for (let i = 0; i < yLen; i++) {
        this.tempBuffer[i] += this.previousY[i]
      }
    }

    // YUV to RGB
    let rgbaIdx = 0
    let uvIdx = 0
    for (let y = 0; y < height; y++) {
      for (let x = 0; x < width; x++) {
        const Y = this.tempBuffer[y * width + x]
        const U = uPlane[uvIdx]
        const V = vPlane[uvIdx]
        
        // Fast YUV to RGB
        const r = Math.max(0, Math.min(255, Y + 1.402 * V))
        const g = Math.max(0, Math.min(255, Y - 0.344 * U - 0.714 * V))
        const b = Math.max(0, Math.min(255, Y + 1.772 * U))
        
        rgba[rgbaIdx++] = r
        rgba[rgbaIdx++] = g
        rgba[rgbaIdx++] = b
        rgba[rgbaIdx++] = 255

        if ((x & 1) === 1) uvIdx++
      }
      if ((width & 1) === 1) uvIdx++
    }

    // Store for delta
    if (isKeyFrame) {
      this.previousY = new Float32Array(yLen)
      this.previousY.set(this.tempBuffer.subarray(0, yLen))
    }

    const decodeTime = performance.now() - start
    this.stats.avgDecodeTimeMs = (this.stats.avgDecodeTimeMs * (this.stats.framesProcessed - 1) + decodeTime) / this.stats.framesProcessed

    return rgba
  }

  // Fast in-place 2D Haar wavelet transform
  private haar2d(data: Float32Array, width: number, height: number) {
    const size = Math.min(width, height)
    const half = size >> 1
    
    // Horizontal pass
    for (let y = 0; y < size; y++) {
      const row = y * width
      for (let x = 0; x < half; x++) {
        const even = data[row + x * 2]
        const odd = data[row + x * 2 + 1]
        data[row + x] = (even + odd) * HAAR_SCALE
        data[row + half + x] = (even - odd) * HAAR_SCALE
      }
    }
    
    // Vertical pass
    for (let x = 0; x < size; x++) {
      for (let y = 0; y < half; y++) {
        const even = data[y * 2 * width + x]
        const odd = data[(y * 2 + 1) * width + x]
        data[y * width + x] = (even + odd) * HAAR_SCALE
        data[(half + y) * width + x] = (even - odd) * HAAR_SCALE
      }
    }
  }

  // Fast in-place 2D inverse Haar wavelet transform
  private ihaar2d(data: Float32Array, width: number, height: number) {
    const size = Math.min(width, height)
    const half = size >> 1
    
    // Vertical pass (inverse)
    for (let x = 0; x < size; x++) {
      for (let y = 0; y < half; y++) {
        const approx = data[y * width + x]
        const detail = data[(half + y) * width + x]
        data[y * 2 * width + x] = (approx + detail) * HAAR_SCALE
        data[(y * 2 + 1) * width + x] = (approx - detail) * HAAR_SCALE
      }
    }
    
    // Horizontal pass (inverse)
    for (let y = 0; y < size; y++) {
      const row = y * width
      for (let x = 0; x < half; x++) {
        const approx = data[row + x]
        const detail = data[row + half + x]
        data[row + x * 2] = (approx + detail) * HAAR_SCALE
        data[row + x * 2 + 1] = (approx - detail) * HAAR_SCALE
      }
    }
  }

  getStats() {
    return { ...this.stats }
  }
}

export function createFastWaveletCodec(quality?: number, width?: number, height?: number): FastWaveletCodec {
  return new FastWaveletCodec(quality, width, height)
}
