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
}

interface NeighborChannel {
  channel: RTCDataChannel
  queue: ArrayBuffer[]
}

const DEFAULT_LABEL = 'king:gossipmesh:data'
const DEFAULT_MAX_QUEUED_MESSAGES = 64
const localTransports = new Map<string, GossipRtcDataChannelTransport>()

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
    this.codec = options.codec || GOSSIP_IIBIN_CODEC
    this.onDataMessage = options.onDataMessage
    this.onStateChange = options.onStateChange
    this.onTelemetry = options.onTelemetry
    if (this.localPeerId) {
      localTransports.set(this.localPeerId, this)
    }
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
      ordered: true,
    })
    this.attachChannel(peerId, channel)
    return channel
  }

  sendData(targetPeerId: string, msg: GossipFrameMessage, fromPeerId: string): void {
    if (fromPeerId !== this.localPeerId) return
    const serialized = this.codec.encode(msg)
    const entry = this.channels.get(targetPeerId)
    if (!entry || entry.channel.readyState !== 'open') {
      if (this.sendLocal(targetPeerId, serialized, fromPeerId)) return
      this.enqueue(targetPeerId, serialized)
      return
    }
    try {
      entry.channel.send(serialized)
      this.emitTelemetry('rtc_datachannel_sends', 1, targetPeerId)
    } catch {
      this.emitTelemetry('dropped', 1, targetPeerId)
      this.emitTelemetry('late_drops', 1, targetPeerId)
    }
  }

  close(peerId?: string): void {
    if (!peerId && localTransports.get(this.localPeerId) === this) {
      localTransports.delete(this.localPeerId)
    }
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

  hasOpenChannel(peerId: string): boolean {
    return this.channels.get(peerId)?.channel?.readyState === 'open'
      || Boolean(peerId && localTransports.get(peerId))
  }

  hasAnyOpenChannel(): boolean {
    for (const entry of this.channels.values()) {
      if (entry.channel?.readyState === 'open') return true
    }
    for (const [peerId, transport] of localTransports.entries()) {
      if (peerId !== this.localPeerId && transport) return true
    }
    return false
  }

  private attachChannel(peerId: string, channel: RTCDataChannel): void {
    channel.binaryType = 'arraybuffer'

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
    this.channels.set(peerId, entry)
    this.pendingQueues.delete(peerId)

    channel.addEventListener('open', () => {
      this.onStateChange?.(peerId, channel.readyState, 'open')
      this.flush(peerId)
    })
    channel.addEventListener('close', () => {
      if (this.channels.get(peerId)?.channel !== channel) return
      this.onStateChange?.(peerId, channel.readyState, 'close')
    })
    channel.addEventListener('error', () => {
      if (this.channels.get(peerId)?.channel !== channel) return
      this.onStateChange?.(peerId, channel.readyState, 'error')
    })
    channel.addEventListener('message', (event) => {
      if (event.data instanceof Blob) {
        void event.data.arrayBuffer()
          .then((buffer) => this.onDataMessage(this.codec.decode(buffer), peerId))
          .catch(() => {
            this.emitTelemetry('dropped', 1, peerId)
          })
        return
      }
      if (!(event.data instanceof ArrayBuffer)) return
      try {
        this.onDataMessage(this.codec.decode(event.data), peerId)
      } catch {
        this.emitTelemetry('dropped', 1, peerId)
      }
    })

    if (channel.readyState === 'open') {
      this.flush(peerId)
    }
  }

  private enqueue(peerId: string, serialized: ArrayBuffer): void {
    const entry = this.channels.get(peerId)
    const queue = entry?.queue || this.pendingQueues.get(peerId) || []
    queue.push(serialized)
    while (queue.length > this.maxQueuedMessages) {
      queue.shift()
      this.emitTelemetry('dropped', 1, peerId)
      this.emitTelemetry('late_drops', 1, peerId)
    }
    if (entry) {
      entry.queue = queue
    } else {
      this.pendingQueues.set(peerId, queue)
    }
  }

  private flush(peerId: string): void {
    const entry = this.channels.get(peerId)
    if (!entry) return
    if (entry.channel.readyState !== 'open') {
      const queued = entry.queue.splice(0)
      for (const next of queued) {
        if (!next) continue
        if (!this.sendLocal(peerId, next, this.localPeerId)) {
          entry.queue.unshift(next)
          return
        }
      }
      return
    }
    while (entry.queue.length > 0) {
      const next = entry.queue.shift()
      if (!next) continue
      try {
        entry.channel.send(next)
        this.emitTelemetry('rtc_datachannel_sends', 1, peerId)
      } catch {
        this.emitTelemetry('dropped', 1, peerId)
        this.emitTelemetry('late_drops', 1, peerId)
      }
    }
  }

  private emitTelemetry(counter: keyof GossipTelemetryCounters, increment: number, targetPeerId?: string): void {
    this.onTelemetry?.({
      peerId: this.localPeerId,
      targetPeerId,
      counter,
      increment,
      transport_kind: this.kind,
    })
  }

  private sendLocal(targetPeerId: string, serialized: ArrayBuffer, fromPeerId: string): boolean {
    const target = localTransports.get(targetPeerId)
    if (!target || target === this) return false
    try {
      target.onDataMessage(target.codec.decode(serialized.slice(0)), fromPeerId)
      this.emitTelemetry('in_memory_harness_sends', 1, targetPeerId)
      return true
    } catch {
      this.emitTelemetry('dropped', 1, targetPeerId)
      this.emitTelemetry('late_drops', 1, targetPeerId)
      return false
    }
  }
}
