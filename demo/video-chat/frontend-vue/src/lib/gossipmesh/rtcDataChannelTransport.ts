import type { GossipDataTransport } from './gossipController'
import { GOSSIP_IIBIN_CODEC, type GossipDataPlaneCodec } from './iibinCodec'

export interface GossipRtcDataChannelTransportOptions {
  localPeerId: string
  label?: string
  maxQueuedMessages?: number
  codec?: GossipDataPlaneCodec
  onDataMessage: (msg: any, fromPeerId: string) => void
  onStateChange?: (peerId: string, state: RTCDataChannelState, eventType: 'open' | 'close' | 'error') => void
}

interface NeighborChannel {
  channel: RTCDataChannel
  queue: ArrayBuffer[]
}

const DEFAULT_LABEL = 'king:gossipmesh:data'
const DEFAULT_MAX_QUEUED_MESSAGES = 64

/**
 * Browser neighbor transport for the GossipController data lane.
 *
 * Signaling, admission, and topology assignment stay on the server-backed ops
 * lane. Once a peer connection exists for an assigned neighbor, this adapter
 * carries data frames directly over RTCDataChannel.
 */
export class GossipRtcDataChannelTransport implements GossipDataTransport {
  private readonly localPeerId: string
  private readonly label: string
  private readonly maxQueuedMessages: number
  private readonly codec: GossipDataPlaneCodec
  private readonly onDataMessage: (msg: any, fromPeerId: string) => void
  private readonly onStateChange?: (peerId: string, state: RTCDataChannelState, eventType: 'open' | 'close' | 'error') => void
  private readonly channels: Map<string, NeighborChannel> = new Map()
  private readonly pendingQueues: Map<string, ArrayBuffer[]> = new Map()

  constructor(options: GossipRtcDataChannelTransportOptions) {
    this.localPeerId = options.localPeerId
    this.label = options.label || DEFAULT_LABEL
    this.maxQueuedMessages = Math.max(0, options.maxQueuedMessages ?? DEFAULT_MAX_QUEUED_MESSAGES)
    this.codec = options.codec || GOSSIP_IIBIN_CODEC
    this.onDataMessage = options.onDataMessage
    this.onStateChange = options.onStateChange
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

  sendData(targetPeerId: string, msg: any, fromPeerId: string): void {
    if (fromPeerId !== this.localPeerId) return
    const serialized = this.codec.encode(msg)
    const entry = this.channels.get(targetPeerId)
    if (!entry || entry.channel.readyState !== 'open') {
      this.enqueue(targetPeerId, serialized)
      return
    }
    entry.channel.send(serialized)
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
      entry.channel.send(next)
    }
  }
}
