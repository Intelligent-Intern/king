/**
 * Quantization for wavelet coefficients
 * Implements scalar quantization with deadzone
 */

export interface QuantizationConfig {
  quality: number
  stepSize: number
  deadzoneRatio: number
  adaptive: boolean
}

const DEFAULT_QUANT_CONFIG: QuantizationConfig = {
  quality: 75,
  stepSize: 1.0,
  deadzoneRatio: 0.5,
  adaptive: true,
}

export class Quantizer {
  private config: QuantizationConfig
  private stepSizes: Map<number, number>

  constructor(config: Partial<QuantizationConfig> = {}) {
    this.config = { ...DEFAULT_QUANT_CONFIG, ...config }
    this.stepSizes = new Map()
    this.updateQuality(this.config.quality)
  }

  setQuality(quality: number): void {
    this.config.quality = Math.max(1, Math.min(100, quality))
    this.updateQuality(this.config.quality)
  }

  private updateQuality(quality: number): void {
    const qFactor = quality < 50 
      ? 5000 / quality 
      : 200 - 2 * quality

    for (let level = 0; level < 8; level++) {
      const baseStep = this.config.stepSize * Math.pow(2, level)
      this.stepSizes.set(level, baseStep * qFactor / 100)
    }
  }

  getStepSize(level: number): number {
    return this.stepSizes.get(level) || this.config.stepSize
  }

  quantize(coefficient: number, level: number): number {
    const step = this.getStepSize(level)
    const deadzone = step * this.config.deadzoneRatio

    if (Math.abs(coefficient) < deadzone) {
      return 0
    }

    return Math.round(coefficient / step)
  }

  dequantize(quantized: number, level: number): number {
    if (quantized === 0) return 0
    const step = this.getStepSize(level)
    return quantized * step
  }

  quantizeArray(coefficients: Float32Array, level: number): Int32Array {
    const result = new Int32Array(coefficients.length)
    const step = this.getStepSize(level)
    const deadzone = step * this.config.deadzoneRatio

    for (let i = 0; i < coefficients.length; i++) {
      const coef = coefficients[i]
      if (Math.abs(coef) < deadzone) {
        result[i] = 0
      } else {
        result[i] = Math.round(coef / step)
      }
    }

    return result
  }

  dequantizeArray(quantized: Int32Array, level: number): Float32Array {
    const result = new Float32Array(quantized.length)
    const step = this.getStepSize(level)

    for (let i = 0; i < quantized.length; i++) {
      result[i] = quantized[i] * step
    }

    return result
  }
}

export interface EntropyCodingResult {
  data: Uint8Array
  symbols: number
  bitsPerSymbol: number
}

export class ArithmeticEncoder {
  private precision = 32
  private range: number
  private low: number
  private high: number
  private pendingBits: number
  private output: number[]

  constructor() {
    this.range = 1 << this.precision
    this.low = 0
    this.high = this.range - 1
    this.pendingBits = 0
    this.output = []
  }

  private update(frequency: number, total: number): void {
    const range = this.high - this.low + 1
    const count = Math.floor((frequency * range) / total)

    this.high = this.low + count - 1
    this.low = this.low + Math.floor((frequency * range) / total)
  }

  encode(symbol: number, frequency: number, total: number): void {
    this.update(frequency, total)

    while (true) {
      if ((this.low & (1 << (this.precision - 1))) === (this.high & (1 << (this.precision - 1)))) {
        const bit = (this.high >> (this.precision - 1)) & 1
        this.output.push(bit)
        while (this.pendingBits > 0) {
          this.output.push(bit ^ 1)
          this.pendingBits--
        }
      } else if ((this.low & (1 << (this.precision - 2))) !== 0 && (this.high & (1 << (this.precision - 2))) === 0) {
        this.pendingBits++
        this.low &= (1 << (this.precision - 2)) - 1
        this.high = ((1 << (this.precision - 1)) - 1) | (this.high & ((1 << (this.precision - 2)) - 1))
      } else {
        break
      }

      this.low = (this.low << 1) & ((1 << this.precision) - 1)
      this.high = ((this.high + 1) << 1) - 1 & ((1 << this.precision) - 1)
    }
  }

  flush(): Uint8Array {
    this.output.push(1)
    while (this.pendingBits > 0) {
      this.output.push(0)
      this.pendingBits--
    }

    const bytes: number[] = []
    for (let i = 0; i < this.output.length; i += 8) {
      let byte = 0
      for (let j = 0; j < 8 && i + j < this.output.length; j++) {
        byte = (byte << 1) | this.output[i + j]
      }
      bytes.push(byte)
    }

    return new Uint8Array(bytes)
  }
}

export function runLengthEncode(data: Int32Array): { values: Int32Array; counts: Uint32Array } {
  if (data.length === 0) {
    return { values: new Int32Array(0), counts: new Uint32Array(0) }
  }

  const values: number[] = []
  const counts: number[] = []

  let currentValue = data[0]
  let currentCount = 1

  for (let i = 1; i < data.length; i++) {
    if (data[i] === currentValue) {
      currentCount++
    } else {
      values.push(currentValue)
      counts.push(currentCount)
      currentValue = data[i]
      currentCount = 1
    }
  }

  values.push(currentValue)
  counts.push(currentCount)

  return { values: new Int32Array(values), counts: new Uint32Array(counts) }
}

export function runLengthDecode(values: Int32Array, counts: Uint32Array): Int32Array {
  const totalLength = counts.reduce((a, b) => a + b, 0)
  const result = new Int32Array(totalLength)
  let index = 0

  for (let i = 0; i < values.length; i++) {
    for (let j = 0; j < counts[i]; j++) {
      result[index++] = values[i]
    }
  }

  return result
}

export function createQuantizer(config?: Partial<QuantizationConfig>): Quantizer {
  return new Quantizer(config)
}
