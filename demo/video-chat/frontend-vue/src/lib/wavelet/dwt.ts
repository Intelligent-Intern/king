/**
 * Discrete Wavelet Transform (DWT) implementation
 * Supports Haar and Daubechies D4 wavelets
 */

export type WaveletType = 'haar' | 'db4' | 'cdf97'

export interface DWTConfig {
  wavelet: WaveletType
  levels: number
  mode: 'zero' | 'symmetric' | 'constant' | 'periodic'
}

const DEFAULT_CONFIG: DWTConfig = {
  wavelet: 'haar',
  levels: 4,
  mode: 'symmetric',
}

export const WAVELET_COEFFICIENTS = {
  haar: {
    loD: [1 / Math.SQRT2, 1 / Math.SQRT2],
    hiD: [-1 / Math.SQRT2, 1 / Math.SQRT2],
    loR: [1 / Math.SQRT2, 1 / Math.SQRT2],
    hiR: [1 / Math.SQRT2, -1 / Math.SQRT2],
  },
  db4: {
    loD: [
      -0.12940952255092145, 0.2241438680420134, 0.8365163037378079, 0.4829629131445341,
    ],
    hiD: [
      -0.4829629131445341, 0.8365163037378079, -0.2241438680420134, -0.12940952255092145,
    ],
    loR: [
      0.4829629131445341, 0.8365163037378079, 0.2241438680420134, -0.12940952255092145,
    ],
    hiR: [
      -0.12940952255092145, -0.2241438680420134, 0.8365163037378079, -0.4829629131445341,
    ],
  },
  cdf97: {
    loD: [
      0.02674875741080976, -0.01686411844207523, -0.07822326652898785, 0.2668641188868723,
      0.6029490182363579, 0.2668641188868723, -0.07822326652898785, -0.01686411844207523,
    ],
    hiD: [
      0.04533532278885496, 0.028771763114248522, -0.29563588155731283, 0.5829335064230156,
      -0.5829335064230156, -0.29563588155731283, 0.028771763114248522, 0.04533532278885496,
    ],
    loR: [
      0.04533532278885496, -0.028771763114248522, -0.29563588155731283, -0.5829335064230156,
      0.5829335064230156, -0.29563588155731283, 0.028771763114248522, -0.04533532278885496,
    ],
    hiR: [
      0.02674875741080976, 0.01686411844207523, -0.07822326652898785, -0.2668641188868723,
      0.6029490182363579, -0.2668641188868723, -0.07822326652898785, 0.01686411844207523,
    ],
  },
}

export class WaveletTransform {
  private config: DWTConfig
  private coefficients: typeof WAVELET_COEFFICIENTS.haar

  constructor(config: Partial<DWTConfig> = {}) {
    this.config = { ...DEFAULT_CONFIG, ...config }
    this.coefficients = WAVELET_COEFFICIENTS[this.config.wavelet]
  }

  private extendSignal(signal: Float32Array, filter: number[], length: number): Float32Array {
    const filterLen = filter.length
    const halfLen = Math.floor(filterLen / 2)
    const extended = new Float32Array(length + filterLen - 1)

    switch (this.config.mode) {
      case 'symmetric':
        for (let i = 0; i < halfLen; i++) {
          extended[i] = signal[halfLen - 1 - i]
          extended[extended.length - 1 - i] = signal[signal.length - 1 - halfLen + i]
        }
        break
      case 'zero':
      default:
        break
    }

    extended.set(signal, halfLen)
    return extended
  }

  private convolve(signal: Float32Array, filter: number[]): Float32Array {
    const outputLen = signal.length - filter.length + 1
    const output = new Float32Array(outputLen)
    const filterLen = filter.length

    for (let i = 0; i < outputLen; i++) {
      let sum = 0
      for (let j = 0; j < filterLen; j++) {
        sum += signal[i + j] * filter[j]
      }
      output[i] = sum
    }

    return output
  }

  private convolvePeriodic(signal: Float32Array, filter: number[]): Float32Array {
    const output = new Float32Array(signal.length)
    const filterLen = filter.length

    for (let i = 0; i < signal.length; i++) {
      let sum = 0
      for (let j = 0; j < filterLen; j++) {
        sum += signal[(i + j) % signal.length] * filter[j]
      }
      output[i] = sum
    }

    return output
  }

  private downsample(arr: Float32Array, step: number): Float32Array {
    const result = new Float32Array(Math.ceil(arr.length / step))
    for (let i = 0; i < result.length; i++) {
      result[i] = arr[i * step]
    }
    return result
  }

  private upsample(arr: Float32Array, length: number, step: number): Float32Array {
    const result = new Float32Array(length)
    for (let i = 0; i < arr.length; i++) {
      result[i * step] = arr[i]
    }
    return result
  }

  private padToPowerOf2(signal: Float32Array): { data: Float32Array; paddedLength: number; originalLength: number } {
    const originalLength = signal.length
    let paddedLength = 1
    while (paddedLength < signal.length) {
      paddedLength *= 2
    }

    if (paddedLength === originalLength) {
      return { data: signal, paddedLength, originalLength }
    }

    const padded = new Float32Array(paddedLength)
    padded.set(signal)
    return { data: padded, paddedLength, originalLength }
  }

  transform1D(signal: Float32Array): { approximation: Float32Array; details: Float32Array[] } {
    const { data: paddedSignal, originalLength } = this.padToPowerOf2(signal)
    const details: Float32Array[] = []
    let approx = paddedSignal

    for (let level = 0; level < this.config.levels && approx.length >= 4; level++) {
      const loD = this.convolvePeriodic(approx, this.coefficients.loD)
      const hiD = this.convolvePeriodic(approx, this.coefficients.hiD)

      const approxDown = this.downsample(loD, 2)
      const detailsDown = this.downsample(hiD, 2)

      details.unshift(detailsDown)
      approx = approxDown
    }

    const resultApprox = new Float32Array(originalLength)
    resultApprox.set(approx.slice(0, Math.ceil(originalLength / Math.pow(2, this.config.levels))))

    return { approximation: resultApprox, details }
  }

  inverseTransform1D(approximation: Float32Array, details: Float32Array[]): Float32Array {
    let approx = approximation

    for (let level = 0; level < details.length; level++) {
      const detail = details[level]
      const targetLength = approx.length * 2

      const upApprox = this.upsample(approx, targetLength, 2)
      const upDetail = this.upsample(detail, targetLength, 2)

      const loR = this.convolvePeriodic(upApprox, this.coefficients.loR)
      const hiR = this.convolvePeriodic(upDetail, this.coefficients.hiR)

      approx = new Float32Array(loR.length)
      for (let i = 0; i < approx.length; i++) {
        approx[i] = loR[i] + hiR[i]
      }
    }

    return approx
  }

  transform2D(image: Float32Array[], width: number, height: number): Float32Array[] {
    const { approximation: approxRow, details: detailsRows } = this.transform1D(
      this.flattenImage(image, width, height)
    )

    const coeffs: Float32Array[] = [approxRow, ...detailsRows]

    const coeffHeight = Math.ceil(height / Math.pow(2, this.config.levels))
    const tempImage: Float32Array[] = []
    for (let y = 0; y < coeffHeight; y++) {
      const row = new Float32Array(width)
      for (let x = 0; x < width; x++) {
        row[x] = approxRow[y * width + x]
      }
      tempImage.push(row)
    }

    for (const detail of detailsRows) {
      const detailImage: Float32Array[] = []
      for (let y = 0; y < coeffHeight; y++) {
        const row = new Float32Array(width)
        for (let x = 0; x < width; x++) {
          row[x] = detail[y * width + x]
        }
        detailImage.push(row)
      }
      const flatResult = new Float32Array(coeffHeight * width)
      let idx = 0
      for (const row of detailImage) {
        for (let x = 0; x < width; x++) {
          flatResult[idx++] = row[x]
        }
      }
      coeffs.push(flatResult)
    }

    return coeffs
  }

  private flattenImage(image: Float32Array[], width: number, height: number): Float32Array {
    const result = new Float32Array(width * height)
    for (let y = 0; y < Math.min(height, image.length); y++) {
      result.set(image[y].slice(0, width), y * width)
    }
    return result
  }
}

export function rgbToYuv(r: number, g: number, b: number): { y: number; u: number; v: number } {
  return {
    y: 0.299 * r + 0.587 * g + 0.114 * b,
    u: -0.147 * r - 0.289 * g + 0.436 * b,
    v: 0.615 * r - 0.515 * g - 0.100 * b,
  }
}

export function yuvToRgb(y: number, u: number, v: number): { r: number; g: number; b: number } {
  return {
    r: y + 1.13983 * v,
    g: y - 0.39465 * u - 0.58060 * v,
    b: y + 2.03211 * u,
  }
}

export function createWaveletTransform(config?: Partial<DWTConfig>): WaveletTransform {
  return new WaveletTransform(config)
}
