const SFU_FRAME_CHUNK_BACKPRESSURE_LOW_WATER_BYTES = 192 * 1024
export const SFU_FRAME_CHUNK_BACKPRESSURE_MAX_WAIT_MS = 160
export const SFU_FRAME_WIRE_BUDGET_WINDOW_MS = 1000

interface ProjectedBufferBudgetDecision {
  drop: boolean
  bufferedBudgetBytes: number
  projectedBufferedAfterSendBytes: number
}

interface WireBudgetDecision {
  ok: boolean
  currentWindowBytes: number
  projectedWindowBytes: number
  maxWireBytesPerSecond: number
  retryAfterMs: number
  windowMs: number
}

interface WireBudgetSample {
  atMs: number
  wireBytes: number
}

function normalizedBudgetNumber(value: unknown): number {
  const normalized = Number(value)
  return Number.isFinite(normalized) ? Math.max(0, normalized) : 0
}

export function resolveSfuSendDrainTargetBytes(metrics: Record<string, unknown>): number {
  const bufferedBudgetBytes = normalizedBudgetNumber(metrics.budget_max_buffered_bytes)
  if (bufferedBudgetBytes <= 0) return SFU_FRAME_CHUNK_BACKPRESSURE_LOW_WATER_BYTES
  return Math.max(
    SFU_FRAME_CHUNK_BACKPRESSURE_LOW_WATER_BYTES,
    Math.floor(bufferedBudgetBytes * 0.5),
  )
}

export function shouldDropProjectedSfuFrameForBufferBudget(
  metrics: Record<string, unknown>,
  bufferedBeforeSend: number,
  projectedWirePayloadBytes: number,
): ProjectedBufferBudgetDecision {
  const bufferedBudgetBytes = normalizedBudgetNumber(metrics.budget_max_buffered_bytes)
  const projectedBufferedAfterSendBytes = Math.max(0, bufferedBeforeSend) + Math.max(0, projectedWirePayloadBytes)
  return {
    drop: bufferedBudgetBytes > 0
      && projectedWirePayloadBytes > 0
      && projectedBufferedAfterSendBytes > bufferedBudgetBytes,
    bufferedBudgetBytes,
    projectedBufferedAfterSendBytes,
  }
}

export class SfuOutboundWireBudget {
  private samples: WireBudgetSample[] = []

  reset(): void {
    this.samples = []
  }

  decide(metrics: Record<string, unknown>, projectedWireBytes: number, nowMs = Date.now()): WireBudgetDecision {
    this.prune(nowMs)
    const maxWireBytesPerSecond = normalizedBudgetNumber(metrics.budget_max_wire_bytes_per_second)
    const normalizedProjectedWireBytes = Math.max(0, Number(projectedWireBytes || 0))
    const currentWindowBytes = this.currentWindowBytes()
    const projectedWindowBytes = currentWindowBytes + normalizedProjectedWireBytes
    if (maxWireBytesPerSecond <= 0 || normalizedProjectedWireBytes <= 0 || projectedWindowBytes <= maxWireBytesPerSecond) {
      return {
        ok: true,
        currentWindowBytes,
        projectedWindowBytes,
        maxWireBytesPerSecond,
        retryAfterMs: 0,
        windowMs: SFU_FRAME_WIRE_BUDGET_WINDOW_MS,
      }
    }

    const oldestSampleAtMs = Math.min(...this.samples.map((sample) => sample.atMs))
    const retryAfterMs = Number.isFinite(oldestSampleAtMs)
      ? Math.max(16, SFU_FRAME_WIRE_BUDGET_WINDOW_MS - Math.max(0, nowMs - oldestSampleAtMs))
      : SFU_FRAME_WIRE_BUDGET_WINDOW_MS
    return {
      ok: false,
      currentWindowBytes,
      projectedWindowBytes,
      maxWireBytesPerSecond,
      retryAfterMs,
      windowMs: SFU_FRAME_WIRE_BUDGET_WINDOW_MS,
    }
  }

  record(wireBytes: number, nowMs = Date.now()): void {
    const normalizedWireBytes = Math.max(0, Number(wireBytes || 0))
    if (normalizedWireBytes <= 0) return
    this.prune(nowMs)
    this.samples.push({ atMs: nowMs, wireBytes: normalizedWireBytes })
  }

  private currentWindowBytes(): number {
    return this.samples.reduce((sum, sample) => sum + sample.wireBytes, 0)
  }

  private prune(nowMs: number): void {
    const cutoffMs = nowMs - SFU_FRAME_WIRE_BUDGET_WINDOW_MS
    while (this.samples.length > 0 && this.samples[0].atMs <= cutoffMs) {
      this.samples.shift()
    }
  }
}
