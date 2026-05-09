import type {
  GossipDataTransport,
  GossipFrameMessage,
  GossipTelemetryCounters,
  GossipTransportKind,
} from './gossipController'
import { GOSSIP_IIBIN_CODEC, type GossipDataPlaneCodec } from './iibinCodec'

export interface GossipDirectTransportOptions {
  roomId: string
  callId: string
  localPeerId: string
  codec?: GossipDataPlaneCodec
  onDataMessage: (msg: GossipFrameMessage, fromPeerId: string) => void
  onStateChange?: (peerId: string, state: 'open' | 'closed', eventType: 'open' | 'close') => void
  onTelemetry?: (event: GossipDirectTransportTelemetryEvent) => void
}

export interface GossipDirectTransportTelemetryEvent {
  peerId: string
  targetPeerId?: string
  counter: keyof GossipTelemetryCounters
  increment: number
  transport_kind: GossipTransportKind
}

const transportsByScope = new Map<string, Map<string, GossipDirectTransport>>()

function safeId(value: unknown): string {
  return String(value || '').trim()
}

function scopeKey(roomId: string, callId: string): string {
  return `${safeId(roomId) || 'lobby'}:${safeId(callId) || 'call'}`
}

export class GossipDirectTransport implements GossipDataTransport {
  readonly kind = 'in_memory_harness' as const
  private readonly roomId: string
  private readonly callId: string
  private readonly localPeerId: string
  private readonly codec: GossipDataPlaneCodec
  private readonly onDataMessage: (msg: GossipFrameMessage, fromPeerId: string) => void
  private readonly onStateChange?: (peerId: string, state: 'open' | 'closed', eventType: 'open' | 'close') => void
  private readonly onTelemetry?: (event: GossipDirectTransportTelemetryEvent) => void

  constructor(options: GossipDirectTransportOptions) {
    this.roomId = safeId(options.roomId) || 'lobby'
    this.callId = safeId(options.callId) || 'call'
    this.localPeerId = safeId(options.localPeerId)
    this.codec = options.codec || GOSSIP_IIBIN_CODEC
    this.onDataMessage = options.onDataMessage
    this.onStateChange = options.onStateChange
    this.onTelemetry = options.onTelemetry
    if (this.localPeerId) {
      let scoped = transportsByScope.get(this.scope)
      if (!scoped) {
        scoped = new Map()
        transportsByScope.set(this.scope, scoped)
      }
      scoped.set(this.localPeerId, this)
    }
  }

  get scope(): string {
    return scopeKey(this.roomId, this.callId)
  }

  sendData(targetPeerId: string, msg: GossipFrameMessage, fromPeerId: string): void {
    if (safeId(fromPeerId) !== this.localPeerId) return
    const targetId = safeId(targetPeerId)
    const target = transportsByScope.get(this.scope)?.get(targetId)
    if (!target || target === this) {
      this.emitTelemetry('dropped', 1, targetId)
      return
    }
    try {
      const serialized = this.codec.encode(msg)
      target.onDataMessage(target.codec.decode(serialized), this.localPeerId)
      this.emitTelemetry('in_memory_harness_sends', 1, targetId)
    } catch {
      this.emitTelemetry('dropped', 1, targetId)
      this.emitTelemetry('late_drops', 1, targetId)
    }
  }

  broadcastData(msg: GossipFrameMessage, fromPeerId: string): void {
    if (safeId(fromPeerId) !== this.localPeerId) return
    const scoped = transportsByScope.get(this.scope)
    if (!scoped) {
      this.emitTelemetry('dropped', 1)
      return
    }
    for (const targetId of scoped.keys()) {
      if (targetId === this.localPeerId) continue
      this.sendData(targetId, msg, fromPeerId)
    }
  }

  connectPeer(peerId: string): boolean {
    const targetId = safeId(peerId)
    if (!targetId || targetId === this.localPeerId) return false
    const connected = Boolean(transportsByScope.get(this.scope)?.get(targetId))
    if (connected) this.onStateChange?.(targetId, 'open', 'open')
    return connected
  }

  close(peerId?: string): void {
    if (peerId) {
      this.onStateChange?.(safeId(peerId), 'closed', 'close')
      return
    }
    const scoped = transportsByScope.get(this.scope)
    if (scoped?.get(this.localPeerId) === this) scoped.delete(this.localPeerId)
    if (scoped && scoped.size === 0) transportsByScope.delete(this.scope)
  }

  hasOpenChannel(peerId: string): boolean {
    const targetId = safeId(peerId)
    return Boolean(targetId && targetId !== this.localPeerId && transportsByScope.get(this.scope)?.get(targetId))
  }

  hasAnyOpenChannel(): boolean {
    const scoped = transportsByScope.get(this.scope)
    if (!scoped) return false
    for (const peerId of scoped.keys()) {
      if (peerId !== this.localPeerId) return true
    }
    return false
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
}
