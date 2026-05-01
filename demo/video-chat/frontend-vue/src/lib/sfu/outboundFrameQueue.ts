import type { PreparedSfuOutboundFramePayload } from './framePayload'

type QueueDiagnosticLevel = 'info' | 'warning' | 'error'

interface SfuOutboundFrameQueueOptions {
  canSend: () => boolean
  sendPreparedFrame: (prepared: PreparedSfuOutboundFramePayload, queuedAgeMs: number) => Promise<boolean>
  reportFrameDiagnostic: (
    eventType: string,
    level: QueueDiagnosticLevel,
    message: string,
    prepared: PreparedSfuOutboundFramePayload,
    extraPayload?: Record<string, unknown>,
    immediate?: boolean,
  ) => void
}

interface PendingOutboundFrame {
  prepared: PreparedSfuOutboundFramePayload
  queuedAtMs: number
  resolve: (ok: boolean) => void
}

const SFU_FRAME_SEND_QUEUE_MAX_FRAMES = 3
const SFU_FRAME_SEND_QUEUE_MAX_PAYLOAD_CHARS = 12 * 1024 * 1024
const SFU_FRAME_SEND_QUEUE_DELTA_MAX_AGE_MS = 750
const SFU_FRAME_SEND_QUEUE_BACKGROUND_SNAPSHOT_MAX_AGE_MS = 1500

export class SfuOutboundFrameQueue {
  private readonly options: SfuOutboundFrameQueueOptions
  private queue: PendingOutboundFrame[] = []
  private draining = false
  private queuedPayloadChars = 0
  private activePayloadChars = 0

  constructor(options: SfuOutboundFrameQueueOptions) {
    this.options = options
  }

  pressureBytes(): number {
    return this.queuedPayloadChars + this.activePayloadChars
  }

  length(): number {
    return this.queue.length
  }

  queuedBytes(): number {
    return this.queuedPayloadChars
  }

  activeBytes(): number {
    return this.activePayloadChars
  }

  clear(): number {
    if (this.queue.length === 0) {
      this.queuedPayloadChars = 0
      return 0
    }
    const droppedCount = this.queue.length
    this.queue.splice(0).forEach((entry) => entry.resolve(false))
    this.queuedPayloadChars = 0
    return droppedCount
  }

  enqueue(prepared: PreparedSfuOutboundFramePayload): Promise<boolean> {
    if (!this.options.canSend()) return Promise.resolve(false)

    if (this.isBackgroundSnapshot(prepared)) {
      this.dropQueuedBackgroundSnapshots('replaced_by_newer_background_snapshot', prepared.trackId)
    } else {
      this.dropQueuedBackgroundSnapshots('foreground_or_fullframe_priority', prepared.trackId)
    }

    if (prepared.frameType === 'delta') {
      this.dropQueuedDeltaFrames('replaced_by_newer_delta', prepared.trackId)
    } else {
      this.dropQueuedDeltaFrames('keyframe_priority')
    }

    let wouldExceedQueue = this.queue.length >= SFU_FRAME_SEND_QUEUE_MAX_FRAMES
      || (this.queuedPayloadChars + prepared.payloadChars) > SFU_FRAME_SEND_QUEUE_MAX_PAYLOAD_CHARS
    if (wouldExceedQueue && !this.isBackgroundSnapshot(prepared)) {
      this.dropQueuedBackgroundSnapshots('bounded_queue_background_preempted', prepared.trackId)
      wouldExceedQueue = this.queue.length >= SFU_FRAME_SEND_QUEUE_MAX_FRAMES
        || (this.queuedPayloadChars + prepared.payloadChars) > SFU_FRAME_SEND_QUEUE_MAX_PAYLOAD_CHARS
    }
    if (wouldExceedQueue) {
      const isBackgroundSnapshot = this.isBackgroundSnapshot(prepared)
      const isKeyframe = prepared.frameType === 'keyframe' && !isBackgroundSnapshot
      this.options.reportFrameDiagnostic(
        isBackgroundSnapshot
          ? 'sfu_frame_send_queue_background_snapshot_dropped'
          : (isKeyframe ? 'sfu_frame_send_queue_keyframe_blocked' : 'sfu_frame_send_queue_dropped'),
        isKeyframe ? 'error' : 'warning',
        isBackgroundSnapshot
          ? 'SFU background snapshot was dropped because the bounded send queue is full.'
          : (isKeyframe
          ? 'SFU keyframe was not queued because the bounded send queue is still full.'
          : 'SFU delta frame was dropped because the bounded send queue is full.'),
        prepared,
        { drop_reason: isBackgroundSnapshot ? 'background_snapshot_deprioritized' : 'bounded_queue_full' },
        true,
      )
      return Promise.resolve(false)
    }

    return new Promise((resolve) => {
      this.queue.push({
        prepared,
        queuedAtMs: Date.now(),
        resolve,
      })
      this.queuedPayloadChars += prepared.payloadChars
      this.drain()
    })
  }

  private drain(): void {
    if (this.draining) return
    this.draining = true
    void this.drainLoop()
  }

  private async drainLoop(): Promise<void> {
    try {
      while (this.options.canSend() && this.queue.length > 0) {
        const next = this.queue.shift()
        if (!next) continue
        this.queuedPayloadChars = Math.max(0, this.queuedPayloadChars - next.prepared.payloadChars)
        const queuedAgeMs = Math.max(0, Date.now() - next.queuedAtMs)
        if (next.prepared.frameType === 'delta' && queuedAgeMs > SFU_FRAME_SEND_QUEUE_DELTA_MAX_AGE_MS) {
          this.options.reportFrameDiagnostic(
            'sfu_frame_send_queue_dropped',
            'warning',
            'SFU delta frame was dropped because it aged out before send.',
            next.prepared,
            { drop_reason: 'stale_delta', queued_age_ms: queuedAgeMs },
          )
          next.resolve(false)
          continue
        }
        if (this.isBackgroundSnapshot(next.prepared) && queuedAgeMs > SFU_FRAME_SEND_QUEUE_BACKGROUND_SNAPSHOT_MAX_AGE_MS) {
          this.options.reportFrameDiagnostic(
            'sfu_frame_send_queue_background_snapshot_dropped',
            'warning',
            'SFU background snapshot was dropped because it aged out before send.',
            next.prepared,
            { drop_reason: 'stale_background_snapshot', queued_age_ms: queuedAgeMs },
          )
          next.resolve(false)
          continue
        }

        this.activePayloadChars = next.prepared.payloadChars
        const sent = await this.options.sendPreparedFrame(next.prepared, queuedAgeMs)
        this.activePayloadChars = 0
        next.resolve(sent)
      }
      if (!this.options.canSend()) {
        this.clear()
      }
    } finally {
      this.draining = false
      this.activePayloadChars = 0
      if (this.options.canSend() && this.queue.length > 0) {
        this.drain()
      }
    }
  }

  private dropQueuedDeltaFrames(reason: string, trackId = ''): number {
    let droppedCount = 0
    const kept: PendingOutboundFrame[] = []
    for (const entry of this.queue) {
      const sameTrack = trackId === '' || entry.prepared.trackId === trackId
      if (sameTrack && entry.prepared.frameType === 'delta') {
        droppedCount += 1
        this.queuedPayloadChars = Math.max(0, this.queuedPayloadChars - entry.prepared.payloadChars)
        entry.resolve(false)
        continue
      }
      kept.push(entry)
    }
    if (droppedCount > 0) {
      this.queue = kept
      this.options.reportFrameDiagnostic(
        'sfu_frame_send_queue_dropped',
        'warning',
        'SFU queued delta frames were dropped by the bounded send queue.',
        {
          publisherId: '',
          trackId,
          timestamp: Date.now(),
          payload: {},
          chunkField: null,
          chunkValue: '',
          rawByteLength: 0,
          payloadChars: 0,
          chunkCount: 0,
          frameType: 'delta',
          protectionMode: 'transport_only',
          frameSequence: 0,
          senderSentAtMs: Date.now(),
          metrics: {},
        },
        {
          drop_reason: reason,
          dropped_delta_count: droppedCount,
          track_id: trackId,
        },
      )
    }
    return droppedCount
  }

  private dropQueuedBackgroundSnapshots(reason: string, trackId = ''): number {
    let droppedCount = 0
    const kept: PendingOutboundFrame[] = []
    for (const entry of this.queue) {
      const sameTrack = trackId === '' || entry.prepared.trackId === trackId
      if (sameTrack && this.isBackgroundSnapshot(entry.prepared)) {
        droppedCount += 1
        this.queuedPayloadChars = Math.max(0, this.queuedPayloadChars - entry.prepared.payloadChars)
        entry.resolve(false)
        continue
      }
      kept.push(entry)
    }
    if (droppedCount > 0) {
      this.queue = kept
      this.options.reportFrameDiagnostic(
        'sfu_frame_send_queue_background_snapshot_dropped',
        'warning',
        'Queued SFU background snapshots were dropped by queue prioritization.',
        {
          publisherId: '',
          trackId,
          timestamp: Date.now(),
          payload: {},
          chunkField: null,
          chunkValue: '',
          rawByteLength: 0,
          payloadChars: 0,
          chunkCount: 0,
          frameType: 'keyframe',
          protectionMode: 'transport_only',
          frameSequence: 0,
          senderSentAtMs: Date.now(),
          metrics: { layout_mode: 'background_snapshot', layer_id: 'background' },
          projectedBinaryEnvelopeBytes: 0,
          tilePatch: null,
        },
        {
          drop_reason: reason,
          dropped_background_snapshot_count: droppedCount,
          track_id: trackId,
        },
      )
    }
    return droppedCount
  }

  private isBackgroundSnapshot(prepared: PreparedSfuOutboundFramePayload): boolean {
    return String(prepared.metrics?.layout_mode || prepared.payload?.layout_mode || '') === 'background_snapshot'
  }
}
