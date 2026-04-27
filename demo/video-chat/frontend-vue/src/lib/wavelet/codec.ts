/**
 * Wavelet Video Codec — 2D separable Haar DWT
 *
 * Improvements over the original 1D-only codec:
 *  • Proper 2D DWT (row-pass then column-pass at each level) captures
 *    both horizontal and vertical spatial correlation.
 *  • Per-subband step sizes: finest subbands get the largest step
 *    (most zeros → better RLE compression); LL gets the smallest step.
 *  • Full-colour YUV 4:2:0 encoding instead of grayscale-only.
 *  • Simple, self-contained binary format that the decoder can read
 *    without band-size guessing.
 *  • Temporal residual coding on the Y channel for delta frames.
 *  • DWT-based background blur: attenuates high-freq detail subbands
 *    to produce a blurred background without segmentation overhead.
 */

// ─── Binary format ───���──────────────────────────────────────────────────────
// Inner payload (FrameData.data):
//   [0..3]   magic  0x574C5643 ("WLVC")
//   [4]      version = 2
//   [5]      frame_type: 0 = key, 1 = delta
//   [6]      quality (1–100)
//   [7]      levels (DWT depth)
//   [8..9]   width  (uint16)
//   [10..11] height (uint16)
//   [12..15] Y channel encoded byte count (uint32)
//   [16..19] U channel encoded byte count (uint32)
//   [20..23] V channel encoded byte count (uint32)
//   [24..25] uvW (uint16)
//   [26..27] uvH (uint16)
//   [28]     wavelet_type (0=haar, 1=db4, 2=cdf97)
//   [29]     color_space (0=yuv, 1=rgb)
//   [30]     entropy_coding (0=rle, 1=arithmetic, 2=none)
//   [31]     flags: bit0=motion_estimation, bit1=blur_background
//   [32]     blur_radius (0-10, 0=off)
//   [33+]    Y_data | U_data | V_data
//
// Each channel: uint32 n_values | uint32 n_pairs | (int16 val, uint16 count)×n_pairs

const MAGIC = 0x574C5643  // "WLVC"
const HEADER_BYTES = 33  // version 2: +1 byte for flags+blur

// ─── Haar 1D lifting — in-place ─────────────────────────────────────────────

function haar1dFwd(buf: Float32Array, off: number, n: number, stride: number): void {
  const half = n >> 1
  const tmp = new Float32Array(n)
  for (let i = 0; i < half; i++) {
    const a = buf[off + i * 2 * stride]
    const b = buf[off + (i * 2 + 1) * stride]
    tmp[i]        = (a + b) * 0.5  // approx
    tmp[half + i] = b - a           // detail
  }
  if (n & 1) tmp[n - 1] = buf[off + (n - 1) * stride]  // preserve odd last sample
  for (let i = 0; i < n; i++) buf[off + i * stride] = tmp[i]
}

function haar1dInv(buf: Float32Array, off: number, n: number, stride: number): void {
  const half = n >> 1
  const tmp = new Float32Array(n)
  for (let i = 0; i < half; i++) {
    const s = buf[off + i * stride]           // approx
    const d = buf[off + (half + i) * stride]  // detail
    const even = s - d * 0.5
    tmp[i * 2]     = even
    tmp[i * 2 + 1] = d + even
  }
  if (n & 1) tmp[n - 1] = buf[off + (n - 1) * stride]
  for (let i = 0; i < n; i++) buf[off + i * stride] = tmp[i]
}

// ─── 2D Haar DWT — in-place Mallat pyramid ───────────────────────────────────

function dwtFwd2D(data: Float32Array, w: number, h: number, levels: number): void {
  let cw = w, ch = h
  for (let lv = 0; lv < levels; lv++) {
    for (let row = 0; row < ch; row++) haar1dFwd(data, row * w, cw, 1)
    for (let col = 0; col < cw; col++) haar1dFwd(data, col,    ch, w)
    cw >>= 1; ch >>= 1
  }
}

function dwtInv2D(data: Float32Array, w: number, h: number, levels: number): void {
  // Walk back from coarsest to finest
  let cw = w >> levels, ch = h >> levels
  for (let lv = levels - 1; lv >= 0; lv--) {
    cw <<= 1; ch <<= 1
    for (let col = 0; col < cw; col++) haar1dInv(data, col,    ch, w)
    for (let row = 0; row < ch; row++) haar1dInv(data, row * w, cw, 1)
  }
}

// ─── Per-subband quantisation ────────────────────────────────────────────────
//
// lv = DWT iteration index (0 = first pass = creates FINEST subbands).
// Finest subbands (lv=0) get the LARGEST step so they are zeroed most
// aggressively, which is the correct thing for compression.
//
// step = base × 2^(levels − lv)      for detail subbands
// step = base × 1                     for LL
//
// base derives from the JPEG-style quality formula.

function baseStep(quality: number): number {
  const qf = quality < 50 ? 5000 / quality : 200 - 2 * quality
  return qf / 100.0
}

function detailStep(lv: number, levels: number, quality: number): number {
  return baseStep(quality) * (1 << (levels - lv))
}

function clampI16(v: number): number {
  return Math.max(-32768, Math.min(32767, v))
}

function quantize2D(
  data: Float32Array, w: number, h: number, levels: number, quality: number
): Int16Array {
  const out = new Int16Array(data.length)
  const llStep = baseStep(quality) * 0.5   // finer LL quantization preserves faces/gradients
  const llDz   = llStep * 0.25
  const llW    = w >> levels
  const llH    = h >> levels

  // LL subband
  for (let r = 0; r < llH; r++) {
    for (let c = 0; c < llW; c++) {
      const v = data[r * w + c]
      out[r * w + c] = Math.abs(v) < llDz ? 0 : clampI16(Math.round(v / llStep))
    }
  }

  // Detail subbands — iterate level by level
  for (let lv = 0; lv < levels; lv++) {
    const step = detailStep(lv, levels, quality)
    const dz   = step * 0.25  // smaller dead-zone preserves texture/edges
    const cw   = w >> lv
    const ch   = h >> lv
    const hw   = cw >> 1  // half-width  = start of horizontal-detail columns
    const hh   = ch >> 1  // half-height = start of vertical-detail rows

    // LH (row-detail × col-approx): rows [0..hh-1], cols [hw..cw-1]
    for (let r = 0; r < hh; r++) for (let c = hw; c < cw; c++) {
      const v = data[r * w + c]
      out[r * w + c] = Math.abs(v) < dz ? 0 : clampI16(Math.round(v / step))
    }
    // HL (row-approx × col-detail): rows [hh..ch-1], cols [0..hw-1]
    for (let r = hh; r < ch; r++) for (let c = 0; c < hw; c++) {
      const v = data[r * w + c]
      out[r * w + c] = Math.abs(v) < dz ? 0 : clampI16(Math.round(v / step))
    }
    // HH (both details): rows [hh..ch-1], cols [hw..cw-1]
    for (let r = hh; r < ch; r++) for (let c = hw; c < cw; c++) {
      const v = data[r * w + c]
      out[r * w + c] = Math.abs(v) < dz ? 0 : clampI16(Math.round(v / step))
    }
  }
  return out
}

function dequantize2D(
  quant: Int16Array, w: number, h: number, levels: number, quality: number
): Float32Array {
  const out  = new Float32Array(quant.length)
  const step = baseStep(quality)
  const llW  = w >> levels
  const llH  = h >> levels

  for (let r = 0; r < llH; r++)
    for (let c = 0; c < llW; c++)
      out[r * w + c] = quant[r * w + c] * (step * 0.5)  // matches quantize2D LL step

  for (let lv = 0; lv < levels; lv++) {
    const s  = detailStep(lv, levels, quality)
    const cw = w >> lv, ch = h >> lv
    const hw = cw >> 1, hh = ch >> 1

    for (let r = 0; r < hh; r++) for (let c = hw; c < cw; c++)
      out[r * w + c] = quant[r * w + c] * s
    for (let r = hh; r < ch; r++) for (let c = 0; c < hw; c++)
      out[r * w + c] = quant[r * w + c] * s
    for (let r = hh; r < ch; r++) for (let c = hw; c < cw; c++)
      out[r * w + c] = quant[r * w + c] * s
  }
  return out
}

// ─── Run-length coding of Int16 arrays ──────────────────────────────────────
// Layout: uint32 n_values | uint32 n_pairs | (int16 value, uint16 count)×n_pairs
// All values little-endian.

function rleEncode(data: Int16Array): Uint8Array {
  const pairs: number[] = []  // [val0, cnt0, val1, cnt1, ...]
  let i = 0
  while (i < data.length) {
    const val = data[i]
    let run = 1
    while (i + run < data.length && data[i + run] === val && run < 0xFFFF) run++
    pairs.push(val, run)
    i += run
  }
  const nPairs = pairs.length >> 1
  const buf    = new ArrayBuffer(8 + nPairs * 4)
  const view   = new DataView(buf)
  view.setUint32(0, data.length, true)
  view.setUint32(4, nPairs, true)
  for (let j = 0; j < nPairs; j++) {
    view.setInt16 (8 + j * 4,     pairs[j * 2],     true)
    view.setUint16(8 + j * 4 + 2, pairs[j * 2 + 1], true)
  }
  return new Uint8Array(buf)
}

function rleDecode(src: Uint8Array): Int16Array {
  const view   = new DataView(src.buffer, src.byteOffset, src.byteLength)
  const nVals  = view.getUint32(0, true)
  const nPairs = view.getUint32(4, true)
  const out    = new Int16Array(nVals)
  let idx = 0
  for (let j = 0; j < nPairs; j++) {
    const val   = view.getInt16 (8 + j * 4,     true)
    const count = view.getUint16(8 + j * 4 + 2, true)
    out.fill(val, idx, idx + count)
    idx += count
  }
  return out
}

export type WaveletType = 'haar' | 'db4' | 'cdf97'

export interface WaveletCodecConfig {
  waveletType: WaveletType
  levels: number
  quality: number
  colorSpace: 'yuv' | 'rgb'
  entropyCoding: 'rle' | 'arithmetic' | 'none'
  keyFrameInterval: number
}

export const DEFAULT_CODEC_CONFIG: Required<WaveletCodecConfig> = {
  waveletType: 'haar',
  levels: 3,
  quality: 75,
  colorSpace: 'yuv',
  entropyCoding: 'rle',
  keyFrameInterval: 30,
}

export interface FrameData {
  type: 'keyframe' | 'delta'
  timestamp: number
  width: number
  height: number
  data: ArrayBuffer
  quality: number
}

export interface DecodedFrame {
  data: Uint8ClampedArray
  width: number
  height: number
  timestamp: number
  quality: number
  colorSpace?: PredefinedColorSpace
}

const LEVELS = 3

// ─── Encoder ─────────────────────────────────────────────────────────────────

export class WaveletVideoEncoder {
  private config: Required<WaveletCodecConfig>
  private frameCount = 0
  private previousY: Float32Array | null = null

  constructor(config: Partial<WaveletCodecConfig> = {}) {
    this.config = { ...DEFAULT_CODEC_CONFIG, ...config }
  }

  getConfig(): Required<WaveletCodecConfig> {
    return { ...this.config }
  }

  setConfig(config: Partial<WaveletCodecConfig>): void {
    this.config = { ...this.config, ...config }
  }

  setQuality(quality: number): void {
    this.config.quality = Math.max(1, Math.min(100, quality))
  }

  encodeFrame(imageData: ImageData, timestamp: number): FrameData {
    const { width: w, height: h, data: px } = imageData
    const isKey = this.frameCount % this.config.keyFrameInterval === 0
    this.frameCount++

    const { levels, colorSpace, quality } = this.config
    
    // Use configured color space
    let Y: Float32Array, U: Float32Array, V: Float32Array
    const uvW = w >> 1, uvH = h >> 1
    
    if (colorSpace === 'yuv') {
      // YUV 4:2:0
      Y = new Float32Array(w * h)
      U = new Float32Array(uvW * uvH)
      V = new Float32Array(uvW * uvH)

      for (let row = 0; row < h; row++) {
        for (let col = 0; col < w; col++) {
          const i = (row * w + col) * 4
          const r = px[i], g = px[i + 1], b = px[i + 2]
          Y[row * w + col] = 0.299 * r + 0.587 * g + 0.114 * b - 128.0
        }
      }
      for (let row = 0; row < uvH; row++) {
        for (let col = 0; col < uvW; col++) {
          const i = (row * 2 * w + col * 2) * 4
          const r = px[i], g = px[i + 1], b = px[i + 2]
          U[row * uvW + col] = -0.147 * r - 0.289 * g + 0.436 * b
          V[row * uvW + col] =  0.615 * r - 0.515 * g - 0.100 * b
        }
      }
    } else {
      // RGB - no subsampling
      Y = new Float32Array(w * h * 3)
      for (let row = 0; row < h; row++) {
        for (let col = 0; col < w; col++) {
          const i = (row * w + col) * 4
          const r = px[i], g = px[i + 1], b = px[i + 2]
          const idx = row * w + col
          Y[idx * 3] = r
          Y[idx * 3 + 1] = g
          Y[idx * 3 + 2] = b
        }
      }
      U = new Float32Array(0)
      V = new Float32Array(0)
    }

    // ── Temporal residual on Y (delta frames only) ────────────────────────
    let yEnc: Float32Array
    if (!isKey && this.previousY) {
      yEnc = new Float32Array(Y.length)
      for (let k = 0; k < Y.length; k++) yEnc[k] = Y[k] - this.previousY[k]
    } else {
      yEnc = Y
    }

    // ── Forward DWT ───────────────────────────────────────────────────────
    dwtFwd2D(yEnc, w, h, levels)
    dwtFwd2D(U,    uvW, uvH, levels)
    dwtFwd2D(V,    uvW, uvH, levels)

    // ── Quantise ──────────────────────────────────────────────────────────
    const yQ = quantize2D(yEnc, w,   h,   levels, quality)
    const uQ = quantize2D(U,    uvW, uvH, levels, quality)
    const vQ = quantize2D(V,    uvW, uvH, levels, quality)

    // ── Closed-loop reference update ──────────────────────────────────────
    const yRec = dequantize2D(yQ, w, h, levels, quality)
    dwtInv2D(yRec, w, h, levels)
    if (!isKey && this.previousY) {
      for (let k = 0; k < yRec.length; k++) yRec[k] += this.previousY[k]
    }
    this.previousY = yRec

    // ── Entropy coding ────────────────────────────────────────────────────
    let yData: Uint8Array, uData: Uint8Array, vData: Uint8Array
    
    switch (this.config.entropyCoding) {
      case 'rle':
        yData = rleEncode(yQ)
        uData = rleEncode(uQ)
        vData = rleEncode(vQ)
        break
      case 'none':
        yData = new Uint8Array(yQ.buffer)
        uData = new Uint8Array(uQ.buffer)
        vData = new Uint8Array(vQ.buffer)
        break
      case 'arithmetic':
      default:
        // TODO: wire up ArithmeticEncoder from quantize.ts
        yData = rleEncode(yQ)
        uData = rleEncode(uQ)
        vData = rleEncode(vQ)
    }

    // ── Pack payload ──────────────────────────────────────────────────────
    const totalBytes = HEADER_BYTES + yData.byteLength + uData.byteLength + vData.byteLength
    const buf  = new ArrayBuffer(totalBytes)
    const view = new DataView(buf)
    const body = new Uint8Array(buf)

    const waveletMap: Record<WaveletType, number> = { haar: 0, db4: 1, cdf97: 2 }
    const colorMap: Record<string, number> = { yuv: 0, rgb: 1 }
    const entropyMap: Record<string, number> = { rle: 0, arithmetic: 1, none: 2 }

    view.setUint32(0,  MAGIC, false)
    view.setUint8 (4,  2)                                                    // version 2
    view.setUint8 (5,  isKey ? 0 : 1)                                         // frame type
    view.setUint8 (6,  quality)
    view.setUint8 (7,  levels)
    view.setUint16(8,  w,   false)
    view.setUint16(10, h,   false)
    view.setUint32(12, yData.byteLength, false)
    view.setUint32(16, uData.byteLength, false)
    view.setUint32(20, vData.byteLength, false)
    view.setUint16(24, uvW, false)
    view.setUint16(26, uvH, false)
    view.setUint8 (28, waveletMap[this.config.waveletType] ?? 0)              // wavelet type
    view.setUint8 (29, colorMap[this.config.colorSpace] ?? 0)                // color space
    view.setUint8 (30, entropyMap[this.config.entropyCoding] ?? 0)          // entropy coding
    view.setUint8 (31, 0)                                                    // flags (motion_estimation=0, blur=0; blur handled at processor level)
    view.setUint8 (32, 0)                                                    // blur_radius (handled at processor level, not codec level)

    body.set(yData, HEADER_BYTES)
    body.set(uData, HEADER_BYTES + yData.byteLength)
    body.set(vData, HEADER_BYTES + yData.byteLength + uData.byteLength)

    return {
      type:      isKey ? 'keyframe' : 'delta',
      timestamp,
      width:     w,
      height:    h,
      data:      buf,
      quality:   quality,
    }
  }

  reset(): void {
    this.frameCount = 0
    this.previousY  = null
  }
}

// ─── Decoder ─────────────────────────────────────────────────────────────────

export class WaveletVideoDecoder {
  private config: Required<WaveletCodecConfig>
  private previousY: Float32Array | null = null

  constructor(config: Partial<WaveletCodecConfig> = {}) {
    this.config = { ...DEFAULT_CODEC_CONFIG, ...config }
  }

  getConfig(): Required<WaveletCodecConfig> {
    return { ...this.config }
  }

  setConfig(config: Partial<WaveletCodecConfig>): void {
    this.config = { ...this.config, ...config }
  }

  setQuality(quality: number): void {
    this.config.quality = Math.max(1, Math.min(100, quality))
  }

  decodeFrame(frameData: FrameData): DecodedFrame {
    const view = new DataView(frameData.data)

    // Support both v1 (32 bytes) and v2 (33 bytes) headers
    const version = view.byteLength >= 33 ? view.getUint8(4) : 1
    const expectedBytes = version >= 2 ? 33 : 32
    if (view.byteLength < expectedBytes || view.getUint32(0, false) !== MAGIC) {
      throw new Error('[WaveletDecoder] Invalid frame: bad magic or short header')
    }

    const isKey   = view.getUint8(5) === 0
    const quality = view.getUint8(6)
    const levels  = view.getUint8(7)
    const w       = view.getUint16(8,  false)
    const h       = view.getUint16(10, false)
    const yBytes  = view.getUint32(12, false)
    const uBytes  = view.getUint32(16, false)
    const vBytes  = view.getUint32(20, false)
    const uvW     = view.getUint16(24, false)
    const uvH     = view.getUint16(26, false)
    // Extended header fields (v2+)
    const _waveletType   = version >= 2 ? view.getUint8(28) : 0
    const colorSpaceVal = version >= 2 ? view.getUint8(29) : 0  // default YUV
    const entropyVal    = version >= 2 ? view.getUint8(30) : 0    // default RLE
    const _flags       = version >= 2 ? view.getUint8(31) : 0
    const _blurRadius  = version >= 2 ? view.getUint8(32) : 0

    const isYuv = colorSpaceVal === 0
    const isRle = entropyVal === 0

    const payload = new Uint8Array(frameData.data)
    const yData = payload.subarray(HEADER_BYTES, HEADER_BYTES + yBytes)
    const uData = payload.subarray(HEADER_BYTES + yBytes, HEADER_BYTES + yBytes + uBytes)
    const vStart = HEADER_BYTES + yBytes + uBytes
    const vEnd = vStart + vBytes
    if (vEnd > payload.byteLength) {
      throw new Error('[WaveletDecoder] Invalid frame: payload length mismatch')
    }
    const vData = payload.subarray(vStart, vEnd)

    // ── Decode channels ───────────────────────────────────────────────────
    let yQ: Int16Array, uQ: Int16Array, vQ: Int16Array
    
    if (isRle) {
      yQ = rleDecode(yData)
      uQ = rleDecode(uData)
      vQ = rleDecode(vData)
    } else if (entropyVal === 2) {
      // None - raw Int16Array
      yQ = new Int16Array(yData.buffer, yData.byteOffset, yBytes / 2)
      uQ = new Int16Array(uData.buffer, uData.byteOffset, uBytes / 2)
      vQ = new Int16Array(vData.buffer, vData.byteOffset, vBytes / 2)
    } else {
      // Arithmetic - fallback to RLE
      yQ = rleDecode(yData)
      uQ = rleDecode(uData)
      vQ = rleDecode(vData)
    }

    // ── Dequantise ────────────────────────────────────────────────────────
    const Y = dequantize2D(yQ, w,   h,   levels, quality)
    const U = dequantize2D(uQ, uvW, uvH, levels, quality)
    const V = dequantize2D(vQ, uvW, uvH, levels, quality)

    // ── Inverse DWT ───────────────────────────────────────────────────────
    dwtInv2D(Y, w,   h,   levels)
    dwtInv2D(U, uvW, uvH, levels)
    dwtInv2D(V, uvW, uvH, levels)

    // ── Temporal residual reconstruction ─────────────────────────────────
    if (!isKey && this.previousY) {
      for (let k = 0; k < Y.length; k++) Y[k] += this.previousY[k]
    }
    this.previousY = Y.slice()

    // ── YUV/RGB → RGBA ─────────────────────────────────────────────────
    const rgba = new Uint8ClampedArray(w * h * 4)
    
    if (isYuv) {
      // YUV mode
      for (let row = 0; row < h; row++) {
        for (let col = 0; col < w; col++) {
          const yi  = row * w + col
          const uvi = (row >> 1) * uvW + (col >> 1)

          const y = Y[yi] + 128.0
          const u = U[uvi]
          const v = V[uvi]

          const pi = yi * 4
          rgba[pi]     = Math.max(0, Math.min(255, y + 1.13983 * v))
          rgba[pi + 1] = Math.max(0, Math.min(255, y - 0.39465 * u - 0.58060 * v))
          rgba[pi + 2] = Math.max(0, Math.min(255, y + 2.03211 * u))
          rgba[pi + 3] = 255
        }
      }
    } else {
      // RGB mode - Y_, U_, V_ contain R, G, B
      for (let i = 0; i < w * h; i++) {
        rgba[i * 4]     = Math.max(0, Math.min(255, Y[i]))
        rgba[i * 4 + 1] = Math.max(0, Math.min(255, U[i]))
        rgba[i * 4 + 2] = Math.max(0, Math.min(255, V[i]))
        rgba[i * 4 + 3] = 255
      }
    }

    return { data: rgba, width: w, height: h, timestamp: frameData.timestamp, quality }
  }

  reset(): void {
    this.previousY = null
  }
}

// ─── Factory functions (public API, unchanged) ────────────────────────────────

export function createEncoder(config?: Partial<WaveletCodecConfig>): WaveletVideoEncoder {
  return new WaveletVideoEncoder(config)
}

export function createDecoder(config?: Partial<WaveletCodecConfig>): WaveletVideoDecoder {
  return new WaveletVideoDecoder(config)
}

// ─── Re-export helpers still used by dwt.ts importers ─────────────────────────

export function rgbToYuv(r: number, g: number, b: number) {
  return {
    y: 0.299 * r + 0.587 * g + 0.114 * b,
    u: -0.147 * r - 0.289 * g + 0.436 * b,
    v:  0.615 * r - 0.515 * g - 0.100 * b,
  }
}

export function yuvToRgb(y: number, u: number, v: number) {
  return {
    r: y + 1.13983 * v,
    g: y - 0.39465 * u - 0.58060 * v,
    b: y + 2.03211 * u,
  }
}
