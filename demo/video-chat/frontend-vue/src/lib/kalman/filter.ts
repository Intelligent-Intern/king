/**
 * Kalman Filter for Video Frame Prediction
 * Implements motion-compensated temporal prediction
 */

export interface KalmanConfig {
  processNoise: number
  measurementNoise: number
  initialCovariance: number
  blockSize: number
}

export interface MotionVector {
  dx: number
  dy: number
  confidence: number
}

export interface BlockState {
  x: number
  y: number
  vx: number
  vy: number
  variance: number
}

const DEFAULT_KALMAN_CONFIG: KalmanConfig = {
  processNoise: 0.001,
  measurementNoise: 0.1,
  initialCovariance: 1.0,
  blockSize: 16,
}

export class KalmanFilter2D {
  private x: number
  private y: number
  private vx: number
  private vy: number
  private P: number[][]
  private Q: number
  private R: number

  constructor(
    initialX = 0,
    initialY = 0,
    config: Partial<KalmanConfig> = {}
  ) {
    const cfg = { ...DEFAULT_KALMAN_CONFIG, ...config }
    
    this.x = initialX
    this.y = initialY
    this.vx = 0
    this.vy = 0
    this.Q = cfg.processNoise
    this.R = cfg.measurementNoise
    this.P = [
      [cfg.initialCovariance, 0, 0, 0],
      [0, cfg.initialCovariance, 0, 0],
      [0, 0, cfg.initialCovariance, 0],
      [0, 0, 0, cfg.initialCovariance],
    ]
  }

  predict(dt = 1): { x: number; y: number } {
    this.x += this.vx * dt
    this.y += this.vy * dt

    const F = [
      [1, 0, dt, 0],
      [0, 1, 0, dt],
      [0, 0, 1, 0],
      [0, 0, 0, 1],
    ]

    this.P = this.multiplyMatrix(this.P, this.transpose(F))
    this.P = this.addMatrix(this.P, this.scaleMatrix(this.getProcessNoiseMatrix(dt), this.Q))

    return { x: this.x, y: this.y }
  }

  update(measurementX: number, measurementY: number): { x: number; y: number } {
    const z = [measurementX, measurementY]
    const H = [
      [1, 0, 0, 0],
      [0, 1, 0, 0],
    ]

    const y = [
      z[0] - (this.x + this.vx),
      z[1] - (this.y + this.vy),
    ]

    const S = this.addMatrix(
      this.multiplyMatrix(this.multiplyMatrix(H, this.P), this.transpose(H)),
      [[this.R, 0], [0, this.R]]
    )

    const det = S[0][0] * S[1][1] - S[0][1] * S[1][0]
    if (Math.abs(det) > 1e-10) {
      const SInv = [
        [S[1][1] / det, -S[0][1] / det],
        [-S[1][0] / det, S[0][0] / det],
      ]
      const K = this.multiplyMatrix(this.multiplyMatrix(this.P, this.transpose(H)), SInv)

      this.x += K[0][0] * y[0] + K[0][1] * y[1]
      this.y += K[1][0] * y[0] + K[1][1] * y[1]
      this.vx += K[2][0] * y[0] + K[2][1] * y[1]
      this.vy += K[3][0] * y[0] + K[3][1] * y[1]

      const I = [[1, 0, 0, 0], [0, 1, 0, 0], [0, 0, 1, 0], [0, 0, 0, 1]]
      this.P = this.subtractMatrix(I, this.multiplyMatrix(K, H))
    }

    return { x: this.x, y: this.y }
  }

  getState(): { x: number; y: number; vx: number; vy: number; variance: number } {
    return {
      x: this.x,
      y: this.y,
      vx: this.vx,
      vy: this.vy,
      variance: (this.P[0][0] + this.P[1][1]) / 2,
    }
  }

  reset(x = 0, y = 0): void {
    this.x = x
    this.y = y
    this.vx = 0
    this.vy = 0
  }

  private getProcessNoiseMatrix(dt: number): number[][] {
    const dt2 = dt * dt / 2
    const dt3 = dt * dt * dt / 2
    const dt4 = dt2 * dt2
    return [
      [dt4, 0, dt3, 0],
      [0, dt4, 0, dt3],
      [dt3, 0, dt2, 0],
      [0, dt3, 0, dt2],
    ]
  }

  private multiplyMatrix(a: number[][], b: number[][]): number[][] {
    const rowsA = a.length
    const colsA = a[0].length
    const colsB = b[0].length
    const result: number[][] = Array(rowsA).fill(null).map(() => Array(colsB).fill(0))

    for (let i = 0; i < rowsA; i++) {
      for (let j = 0; j < colsB; j++) {
        for (let k = 0; k < colsA; k++) {
          result[i][j] += a[i][k] * b[k][j]
        }
      }
    }

    return result
  }

  private transpose(a: number[][]): number[][] {
    return a[0].map((_, i) => a.map(row => row[i]))
  }

  private addMatrix(a: number[][], b: number[][]): number[][] {
    return a.map((row, i) => row.map((val, j) => val + b[i][j]))
  }

  private subtractMatrix(a: number[][], b: number[][]): number[][] {
    return a.map((row, i) => row.map((val, j) => val - b[i][j]))
  }

  private scaleMatrix(a: number[][], s: number): number[][] {
    return a.map(row => row.map(val => val * s))
  }
}

export class VideoKalmanFilter {
  private config: KalmanConfig
  private blockFilters: Map<string, KalmanFilter2D>
  private previousFrame: Float32Array | null
  private frameWidth: number
  private frameHeight: number
  private blockWidth: number
  private blockHeight: number

  constructor(config: Partial<KalmanConfig> = {}) {
    this.config = { ...DEFAULT_KALMAN_CONFIG, ...config }
    this.blockFilters = new Map()
    this.previousFrame = null
    this.frameWidth = 0
    this.frameHeight = 0
    this.blockWidth = this.config.blockSize
    this.blockHeight = this.config.blockSize
  }

  setFrameSize(width: number, height: number): void {
    this.frameWidth = width
    this.frameHeight = height
  }

  predictFrame(): Float32Array | null {
    if (!this.previousFrame) return null

    const predicted = new Float32Array(this.previousFrame.length)

    for (let by = 0; by < this.frameHeight; by += this.blockHeight) {
      for (let bx = 0; bx < this.frameWidth; bx += this.blockWidth) {
        const blockKey = `${bx},${by}`
        const filter = this.blockFilters.get(blockKey)

        if (filter) {
          const { x, y } = filter.predict()
          this.copyBlockAt(predicted, this.previousFrame, bx + Math.round(x), by + Math.round(y), bx, by)
        } else {
          this.copyBlock(predicted, this.previousFrame, bx, by, bx, by)
        }
      }
    }

    return predicted
  }

  updateWithFrame(frame: Float32Array): { residuals: Float32Array; motionVectors: Map<string, MotionVector> } {
    const predicted = this.predictFrame()
    const residuals = new Float32Array(frame.length)
    const motionVectors = new Map<string, MotionVector>()

    for (let by = 0; by < this.frameHeight; by += this.blockHeight) {
      for (let bx = 0; bx < this.frameWidth; bx += this.blockWidth) {
        const blockKey = `${bx},${by}`

        let bestDx = 0
        let bestDy = 0
        let minSAD = Infinity

        for (let dy = -8; dy <= 8; dy += 2) {
          for (let dx = -8; dx <= 8; dx += 2) {
            const sad = this.computeSAD(frame, this.previousFrame, bx, by, dx, dy)
            if (sad < minSAD) {
              minSAD = sad
              bestDx = dx
              bestDy = dy
            }
          }
        }

        let filter = this.blockFilters.get(blockKey)
        if (!filter) {
          filter = new KalmanFilter2D(bx, by, this.config)
          this.blockFilters.set(blockKey, filter)
        }

        filter.predict()
        filter.update(bx + bestDx, by + bestDy)

        const state = filter.getState()
        motionVectors.set(blockKey, {
          dx: state.vx,
          dy: state.vy,
          confidence: 1 / (1 + state.variance),
        })

        if (predicted) {
          for (let y = by; y < Math.min(by + this.blockHeight, this.frameHeight); y++) {
            for (let x = bx; x < Math.min(bx + this.blockWidth, this.frameWidth); x++) {
              const idx = y * this.frameWidth + x
              const predIdx = y * this.frameWidth + x
              residuals[idx] = frame[idx] - (predicted[predIdx] || 0)
            }
          }
        }
      }
    }

    this.previousFrame = new Float32Array(frame)
    return { residuals, motionVectors }
  }

  private computeSAD(
    current: Float32Array,
    reference: Float32Array | null,
    bx: number,
    by: number,
    dx: number,
    dy: number
  ): number {
    if (!reference) return Infinity

    let sad = 0
    let count = 0

    for (let y = by; y < Math.min(by + this.blockHeight, this.frameHeight); y++) {
      for (let x = bx; x < Math.min(bx + this.blockWidth, this.frameWidth); x++) {
        const refX = x + dx
        const refY = y + dy

        if (refX >= 0 && refX < this.frameWidth && refY >= 0 && refY < this.frameHeight) {
          sad += Math.abs(current[y * this.frameWidth + x] - reference[refY * this.frameWidth + refX])
          count++
        }
      }
    }

    return count > 0 ? sad / count : Infinity
  }

  private copyBlock(dst: Float32Array, src: Float32Array, srcX: number, srcY: number, dstX: number, dstY: number): void {
    for (let y = 0; y < this.blockHeight; y++) {
      for (let x = 0; x < this.blockWidth; x++) {
        const srcIdx = (srcY + y) * this.frameWidth + (srcX + x)
        const dstIdx = (dstY + y) * this.frameWidth + (dstX + x)
        if (srcIdx >= 0 && srcIdx < src.length && dstIdx < dst.length) {
          dst[dstIdx] = src[srcIdx]
        }
      }
    }
  }

  private copyBlockAt(dst: Float32Array, src: Float32Array | null, srcX: number, srcY: number, dstX: number, dstY: number): void {
    if (!src) return
    this.copyBlock(dst, src, srcX, srcY, dstX, dstY)
  }

  enhanceFrame(frame: Float32Array, quality: number = 0.5): Float32Array {
    const enhanced = new Float32Array(frame.length)

    for (let i = 0; i < frame.length; i++) {
      const center = frame[i]
      let sum = center
      let count = 1

      if (i > 0) {
        sum += frame[i - 1] * quality
        count += quality
      }
      if (i < frame.length - 1) {
        sum += frame[i + 1] * quality
        count += quality
      }

      enhanced[i] = sum / count
    }

    return enhanced
  }

  reset(): void {
    this.blockFilters.clear()
    this.previousFrame = null
  }
}

export function createKalmanFilter(config?: Partial<KalmanConfig>): VideoKalmanFilter {
  return new VideoKalmanFilter(config)
}
