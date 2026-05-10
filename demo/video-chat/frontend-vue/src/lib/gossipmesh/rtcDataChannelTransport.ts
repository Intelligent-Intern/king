import type {
  GossipDataTransport,
  GossipFrameMessage,
  GossipTelemetryCounters,
  GossipTransportKind,
} from './gossipController'
import { GOSSIP_IIBIN_CODEC, type GossipDataPlaneCodec } from './iibinCodec'

export interface GossipRtcDataChannelTransportOptions {
  localPeerId: string
  label?: string
  maxQueuedMessages?: number
  maxBufferedBytes?: number
  codec?: GossipDataPlaneCodec
  onDataMessage: (msg: GossipFrameMessage, fromPeerId: string) => void
  onStateChange?: (peerId: string, state: RTCDataChannelState, eventType: 'open' | 'close' | 'error') => void
  onTelemetry?: (event: GossipTransportTelemetryEvent) => void
}

export interface GossipTransportTelemetryEvent {
  peerId: string
  targetPeerId?: string
  counter: keyof GossipTelemetryCounters
  increment: number
  transport_kind: GossipTransportKind
  reason?: string
  buffered_amount?: number
  queue_depth?: number
  max_queue_depth?: number
}

interface NeighborChannel {
  channel: RTCDataChannel
  queue: ArrayBuffer[]
}

const DEFAULT_LABEL = 'king:gossipmesh:data'
const DEFAULT_MAX_QUEUED_MESSAGES = 64
const DEFAULT_MAX_BUFFERED_BYTES = 512 * 1024
const GOSSIP_DATACHANNEL_LOW_WATER_RATIO = 0.5
const GOSSIP_DATACHANNEL_DROP_QUEUE_RATIO = 0.25
const GOSSIP_DATACHANNEL_STUCK_NOT_SENDING_REASON = 'gossip_datachannel_stuck_not_sending'

/**
 * Browser neighbor transport for the GossipController data lane.
 *
 * Signaling, admission, and topology assignment stay on the server-backed ops
 * lane. Once a peer connection exists for an assigned neighbor, this adapter
 * carries data frames directly over RTCDataChannel.
 */
export class GossipRtcDataChannelTransport implements GossipDataTransport {
  readonly kind = 'rtc_datachannel' as const
  private readonly localPeerId: string
  private readonly label: string
  private readonly maxQueuedMessages: number
  private readonly maxBufferedBytes: number
  private readonly codec: GossipDataPlaneCodec
  private readonly onDataMessage: (msg: GossipFrameMessage, fromPeerId: string) => void
  private readonly onStateChange?: (peerId: string, state: RTCDataChannelState, eventType: 'open' | 'close' | 'error') => void
  private readonly onTelemetry?: (event: GossipTransportTelemetryEvent) => void
  private readonly channels: Map<string, NeighborChannel> = new Map()
  private readonly pendingQueues: Map<string, ArrayBuffer[]> = new Map()

  constructor(options: GossipRtcDataChannelTransportOptions) {
    this.localPeerId = options.localPeerId
    this.label = options.label || DEFAULT_LABEL
    this.maxQueuedMessages = Math.max(0, options.maxQueuedMessages ?? DEFAULT_MAX_QUEUED_MESSAGES)
    this.maxBufferedBytes = Math.max(1, options.maxBufferedBytes ?? DEFAULT_MAX_BUFFERED_BYTES)
    this.codec = options.codec || GOSSIP_IIBIN_CODEC
    this.onDataMessage = options.onDataMessage
    this.onStateChange = options.onStateChange
    this.onTelemetry = options.onTelemetry
  }

  bindPeerConnection(peerId: string, pc: RTCPeerConnection, initiator: boolean): RTCDataChannel | null {
    if (!peerId || !pc || pc.signalingState === 'closed') return null

    pc.addEventListener('datachannel', (event) => {
      const channel = event.channel
      if (channel?.label !== this.label) return
      this.attachChannel(peerId, channel)
    })

    if (!initiator) return this.channels.get(peerId)?.channel || null
    const existing = this.channels.get(peerId)?.channel
    if (existing && existing.readyState !== 'closed') return existing

    const channel = pc.createDataChannel(this.label, {
      ordered: false,
      maxRetransmits: 0,
    })
    this.attachChannel(peerId, channel)
    return channel
  }

  sendData(targetPeerId: string, msg: GossipFrameMessage, fromPeerId: string): void {
    if (fromPeerId !== this.localPeerId) return
    const serialized = this.codec.encode(msg)
    const entry = this.channels.get(targetPeerId)
    if (!entry || entry.channel.readyState !== 'open') {
      this.enqueue(targetPeerId, serialized)
      return
    }
    if (this.shouldQueueForBufferedAmount(entry, serialized)) {
      this.enqueue(targetPeerId, serialized, 'gossip_datachannel_buffered_amount_pressure')
      return
    }
    entry.channel.send(serialized)
    this.emitTelemetry('rtc_datachannel_sends', 1, targetPeerId)
  }

  close(peerId?: string): void {
    const ids = peerId ? [peerId] : Array.from(this.channels.keys())
    for (const id of ids) {
      const entry = this.channels.get(id)
      if (!entry) {
        this.pendingQueues.delete(id)
        continue
      }
      try {
        entry.channel.close()
      } catch {}
      this.channels.delete(id)
      this.pendingQueues.delete(id)
    }
  }

  private attachChannel(peerId: string, channel: RTCDataChannel): void {
    const previous = this.channels.get(peerId)
    if (previous?.channel && previous.channel !== channel) {
      try {
        previous.channel.close()
      } catch {}
    }

    const entry = {
      channel,
      queue: previous?.queue || this.pendingQueues.get(peerId) || [],
    }
    channel.bufferedAmountLowThreshold = Math.max(
      0,
      Math.floor(this.maxBufferedBytes * GOSSIP_DATACHANNEL_LOW_WATER_RATIO),
    )
    this.channels.set(peerId, entry)
    this.pendingQueues.delete(peerId)

    channel.addEventListener('open', () => {
      this.onStateChange?.(peerId, channel.readyState, 'open')
      this.flush(peerId)
    })
    channel.addEventListener('close', () => {
      this.onStateChange?.(peerId, channel.readyState, 'close')
    })
    channel.addEventListener('error', () => {
      this.onStateChange?.(peerId, channel.readyState, 'error')
    })
    channel.addEventListener('message', (event) => {
      if (!(event.data instanceof ArrayBuffer)) return
      try {
        this.onDataMessage(this.codec.decode(event.data), peerId)
      } catch {}
    })
    channel.addEventListener('bufferedamountlow', () => {
      this.flush(peerId)
    })

    if (channel.readyState === 'open') {
      this.flush(peerId)
    }
  }

  private enqueue(peerId: string, serialized: ArrayBuffer, reason = 'gossip_datachannel_queue'): void {
    const entry = this.channels.get(peerId)
    const queue = entry?.queue || this.pendingQueues.get(peerId) || []
    queue.push(serialized)
    const pressureReason = this.queuePressureReason(queue.length, reason)
    while (queue.length > this.maxQueuedMessages) {
      queue.shift()
      this.emitTelemetry('dropped', 1, peerId, pressureReason, entry)
      // Contract marker: this.emitTelemetry('late_drops', 1, peerId)
      this.emitTelemetry('late_drops', 1, peerId, pressureReason, entry)
    }
    if (entry) {
      entry.queue = queue
    } else {
      this.pendingQueues.set(peerId, queue)
    }
  }

  private flush(peerId: string): void {
    const entry = this.channels.get(peerId)
    if (!entry || entry.channel.readyState !== 'open') return
    while (entry.queue.length > 0) {
      const next = entry.queue.shift()
      if (!next) continue
      if (this.shouldQueueForBufferedAmount(entry, next)) {
        entry.queue.unshift(next)
        this.emitTelemetry('late_drops', 0, peerId, GOSSIP_DATACHANNEL_STUCK_NOT_SENDING_REASON, entry)
        return
      }
      entry.channel.send(next)
      this.emitTelemetry('rtc_datachannel_sends', 1, peerId)
    }
  }

  private shouldQueueForBufferedAmount(entry: NeighborChannel, serialized: ArrayBuffer): boolean {
    const bufferedAmount = Math.max(0, Number(entry.channel.bufferedAmount || 0))
    const projectedBufferedAmount = bufferedAmount + Math.max(0, Number(serialized.byteLength || 0))
    return projectedBufferedAmount >= this.maxBufferedBytes
  }

  private queuePressureReason(queueDepth: number, fallbackReason: string): string {
    if (queueDepth >= this.maxQueuedMessages) return GOSSIP_DATACHANNEL_STUCK_NOT_SENDING_REASON
    if (queueDepth >= Math.ceil(this.maxQueuedMessages * GOSSIP_DATACHANNEL_LOW_WATER_RATIO)) {
      return 'gossip_datachannel_queue_50_percent'
    }
    if (queueDepth >= Math.ceil(this.maxQueuedMessages * GOSSIP_DATACHANNEL_DROP_QUEUE_RATIO)) {
      return 'gossip_datachannel_queue_25_percent'
    }
    return fallbackReason
  }

  private emitTelemetry(
    counter: keyof GossipTelemetryCounters,
    increment: number,
    targetPeerId?: string,
    reason?: string,
    entry?: NeighborChannel,
  ): void {
    this.onTelemetry?.({
      peerId: this.localPeerId,
      targetPeerId,
      counter,
      increment,
      transport_kind: this.kind,
      reason,
      buffered_amount: Math.max(0, Number(entry?.channel?.bufferedAmount || 0)),
      queue_depth: Math.max(0, Number(entry?.queue?.length || 0)),
      max_queue_depth: this.maxQueuedMessages,
    })
  }
}
