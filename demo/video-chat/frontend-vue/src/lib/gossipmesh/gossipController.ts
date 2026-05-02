/**
 * Bounded gossip routing with deterministic neighbor selection.
 *
 * For each data frame:
 * 1. Check frame_id = publisher_id + track_id + media_generation + frame_sequence.
 * 2. If frame_id is in seen_window, drop it (duplicate).
 * 3. Add frame_id to seen_window.
 * 4. If useful locally, deliver to local decoder.
 * 5. If ttl > 0, forward to up to fanout neighbors.
 * 6. Decrement ttl.
 *
 * This controller acts as a CENTRAL gossip router for all peers.
 */

export type GossipEventType =
  | 'send'
  | 'receive'
  | 'drop_duplicate'
  | 'drop_stale'
  | 'forward'
  | 'heartbeat'
  | 'heartbeat_ack'
  | 'carrier_state_change'
  | 'topology_change'
  | 'keyframe_request'
  | 'reconnect_requested'
  | 'reconnect_allowed'

export interface GossipEvent {
  timestamp: number
  peer_id: string
  event: GossipEventType
  lane: 'ops' | 'data'
  frame_id?: string
  publisher_id?: string
  track_id?: string
  frame_sequence?: number
  media_generation?: number
  ttl?: number
  fanout?: number
  neighbor_count?: number
  reason?: string
  carrier_state?: string
  reconnect_allowed?: boolean
  payload?: Record<string, unknown>
}

export interface GossipPeer {
  peer_id: string
  neighbor_set: string[]
  seen_window: Map<string, number>
  media_generation: number
  carrier_state: 'connected' | 'degraded' | 'lost'
  missed_heartbeats: number
  last_heartbeat_at_ms: number
  ops_epoch: number
  signal_sequence: number
  sent: number
  received: number
  dropped: number
  duplicates: number
}

const DEFAULT_FANOUT = 2
const DEFAULT_TTL_BASE = 2
const SEEN_WINDOW_SIZE = 512
const HEARTBEAT_INTERVAL_MS = 1000
const DEGRADED_AFTER = 3
const LOST_AFTER = 6
const KEYFRAME_REQUEST_COOLDOWN_MS = 1000
const TOPOLOGY_CHANGE_COOLDOWN_MS = 3000

export class GossipController {
  private peers: Map<string, GossipPeer> = new Map()
  private events: GossipEvent[] = []
  private fanout: number = DEFAULT_FANOUT
  private heartbeatTimers: Map<string, ReturnType<typeof setInterval>> = new Map()
  private keyframeCooldowns: Map<string, number> = new Map()

  constructor(
    private readonly roomId: string,
    private readonly callId: string,
  ) {}

  addPeer(peerId: string): void {
    if (this.peers.has(peerId)) return
    this.peers.set(peerId, {
      peer_id: peerId,
      neighbor_set: [],
      seen_window: new Map<string, number>(),
      media_generation: 0,
      carrier_state: 'connected',
      missed_heartbeats: 0,
      last_heartbeat_at_ms: Date.now(),
      ops_epoch: 0,
      signal_sequence: 0,
      sent: 0,
      received: 0,
      dropped: 0,
      duplicates: 0,
    })
    this.selectNeighbors(peerId)
    this.startHeartbeat(peerId)
    this.logEvent(peerId, 'receive', 'ops', { message: 'peer_joined' })
  }

  removePeer(peerId: string): void {
    const timer = this.heartbeatTimers.get(peerId)
    if (timer) clearInterval(timer)
    this.heartbeatTimers.delete(peerId)
    this.peers.delete(peerId)
    for (const peer of this.peers.values()) {
      peer.neighbor_set = peer.neighbor_set.filter((n) => n !== peerId)
    }
    this.logEvent(peerId, 'receive', 'ops', { message: 'peer_left' })
  }

  handleData(receivingPeerId: string, msg: any): void {
    const peer = this.peers.get(receivingPeerId)
    if (!peer) return

    const frameId = `${msg.publisher_id}:${msg.track_id}:${msg.media_generation}:${msg.frame_sequence}`
    const now = Date.now()

    if (peer.seen_window.has(frameId)) {
      peer.duplicates++
      this.logEvent(receivingPeerId, 'drop_duplicate', 'data', {
        frame_id: frameId,
        publisher_id: msg.publisher_id,
        track_id: msg.track_id,
        frame_sequence: msg.frame_sequence,
        media_generation: msg.media_generation,
      })
      return
    }

    this.addToSeenWindow(peer, frameId, now)

    if (msg.media_generation > 0 && msg.media_generation < peer.media_generation) {
      peer.dropped++
      this.logEvent(receivingPeerId, 'drop_stale', 'data', {
        frame_id: frameId,
        media_generation: msg.media_generation,
        current_generation: peer.media_generation,
      })
      return
    }

    if (msg.media_generation > peer.media_generation) {
      peer.media_generation = msg.media_generation
    }

    peer.received++
    this.logEvent(receivingPeerId, 'receive', 'data', {
      frame_id: frameId,
      publisher_id: msg.publisher_id,
      track_id: msg.track_id,
      frame_sequence: msg.frame_sequence,
      media_generation: msg.media_generation,
      ttl: msg.ttl,
    })

    if (msg.ttl > 0) {
      this.forward(receivingPeerId, msg, frameId)
    }
  }

  publishFrame(fromPeerId: string, msg: any): void {
    const publisher = this.peers.get(fromPeerId)
    if (!publisher) return

    const frameId = `${msg.publisher_id}:${msg.track_id}:${msg.media_generation}:${msg.frame_sequence}`
    const now = Date.now()

    publisher.sent++
    this.logEvent(fromPeerId, 'send', 'data', {
      frame_id: frameId,
      publisher_id: msg.publisher_id,
      track_id: msg.track_id,
      frame_sequence: msg.frame_sequence,
      media_generation: msg.media_generation,
      ttl: msg.ttl,
    })

    for (const [peerId, peer] of this.peers.entries()) {
      if (peerId === fromPeerId) continue

      if (peer.seen_window.has(frameId)) {
        peer.duplicates++
        this.logEvent(peerId, 'drop_duplicate', 'data', {
          frame_id: frameId,
          publisher_id: msg.publisher_id,
        })
        continue
      }

      this.addToSeenWindow(peer, frameId, now)
      peer.received++

      this.logEvent(peerId, 'receive', 'data', {
        frame_id: frameId,
        publisher_id: msg.publisher_id,
        track_id: msg.track_id,
        frame_sequence: msg.frame_sequence,
        media_generation: msg.media_generation,
        ttl: msg.ttl,
      })

      if (msg.ttl > 0) {
        this.forward(peerId, msg, frameId)
      }
    }
  }

  handleOps(peerId: string, msg: any): void {
    const peer = this.peers.get(peerId)
    if (!peer) return

    if (msg.type === 'heartbeat' || msg.type === 'heartbeat_ack') {
      peer.last_heartbeat_at_ms = Date.now()
      peer.missed_heartbeats = 0
      if (peer.carrier_state !== 'connected') {
        const prev = peer.carrier_state
        peer.carrier_state = 'connected'
        this.logEvent(peerId, 'carrier_state_change', 'ops', {
          previous_state: prev,
          carrier_state: 'connected',
          reason: 'heartbeat_received',
        })
      }
    }

    this.logEvent(peerId, 'receive', 'ops', {
      message_type: msg.type,
      ops_epoch: msg.ops_epoch,
      signal_sequence: msg.signal_sequence,
      carrier_state: peer.carrier_state,
    })
  }

  requestKeyframe(peerId: string, publisherId: string, trackId: string): boolean {
    const peer = this.peers.get(peerId)
    if (!peer) return false
    const now = Date.now()
    const cooldownKey = `${peerId}:${publisherId}:${trackId}`
    const lastRequest = this.keyframeCooldowns.get(cooldownKey) || 0
    if ((now - lastRequest) < KEYFRAME_REQUEST_COOLDOWN_MS) return false

    this.keyframeCooldowns.set(cooldownKey, now)
    this.logEvent(peerId, 'keyframe_request', 'ops', {
      publisher_id: publisherId,
      track_id: trackId,
    })
    return true
  }

  checkCarrierState(peerId: string): void {
    const peer = this.peers.get(peerId)
    if (!peer) return
    const now = Date.now()
    const elapsed = now - peer.last_heartbeat_at_ms
    const missed = Math.floor(elapsed / HEARTBEAT_INTERVAL_MS)

    if (missed > LOST_AFTER) {
      if (peer.carrier_state !== 'lost') {
        const prev = peer.carrier_state
        peer.carrier_state = 'lost'
        peer.missed_heartbeats = missed
        this.logEvent(peerId, 'carrier_state_change', 'ops', {
          previous_state: prev,
          carrier_state: 'lost',
          reason: 'heartbeat_timeout',
          missed_heartbeats: missed,
        })
        this.logEvent(peerId, 'reconnect_requested', 'ops', {
          reconnect_allowed: true,
          carrier_state: 'lost',
        })
      }
    } else if (missed > DEGRADED_AFTER) {
      if (peer.carrier_state === 'connected') {
        const prev = peer.carrier_state
        peer.carrier_state = 'degraded'
        peer.missed_heartbeats = missed
        this.logEvent(peerId, 'carrier_state_change', 'ops', {
          previous_state: prev,
          carrier_state: 'degraded',
          reason: 'heartbeat_degraded',
          missed_heartbeats: missed,
        })
      }
    }
  }

  getPeer(peerId: string): GossipPeer | undefined {
    return this.peers.get(peerId)
  }

  getEvents(): GossipEvent[] {
    return [...this.events]
  }

  getStats() {
    const stats: Record<string, any> = {}
    for (const [peerId, peer] of this.peers.entries()) {
      stats[peerId] = {
        sent: peer.sent,
        received: peer.received,
        dropped: peer.dropped,
        duplicates: peer.duplicates,
        neighbor_count: peer.neighbor_set.length,
        seen_window_size: peer.seen_window.size,
        media_generation: peer.media_generation,
        carrier_state: peer.carrier_state,
        missed_heartbeats: peer.missed_heartbeats,
      }
    }
    return stats
  }

  private forward(fromPeerId: string, msg: any, frameId: string): void {
    const peer = this.peers.get(fromPeerId)
    if (!peer) return
    const ttl = Math.max(0, (msg.ttl || 0) - 1)
    const neighbors = peer.neighbor_set.filter((n) => n !== msg.publisher_id)
    const fanoutCount = Math.min(this.fanout, neighbors.length)

    for (let i = 0; i < fanoutCount; i++) {
      const neighborId = neighbors[i]
      if (!neighborId) continue
      
      const neighbor = this.peers.get(neighborId)
      if (!neighbor) continue

      if (neighbor.seen_window.has(frameId)) {
        neighbor.duplicates++
        this.logEvent(neighborId, 'drop_duplicate', 'data', {
          frame_id: frameId,
          forwarded_from: fromPeerId,
        })
        continue
      }

      const now = Date.now()
      this.addToSeenWindow(neighbor, frameId, now)
      neighbor.received++

      this.logEvent(neighborId, 'receive', 'data', {
        frame_id: frameId,
        publisher_id: msg.publisher_id,
        track_id: msg.track_id,
        frame_sequence: msg.frame_sequence,
        media_generation: msg.media_generation,
        ttl: ttl,
        forwarded_from: fromPeerId,
      })

      this.logEvent(fromPeerId, 'forward', 'data', {
        frame_id: frameId,
        fanout: fanoutCount,
        neighbor_count: neighbors.length,
        target_peer: neighborId,
      })
    }
  }

  private selectNeighbors(peerId: string): void {
    const peer = this.peers.get(peerId)
    if (!peer) return
    const allPeers = Array.from(this.peers.keys()).filter((p) => p !== peerId)
    peer.neighbor_set = allPeers
      .sort(() => Math.random() - 0.5)
      .slice(0, Math.min(this.fanout + 1, allPeers.length))
  }

  private addToSeenWindow(peer: GossipPeer, frameId: string, now: number): void {
    peer.seen_window.set(frameId, now)
    if (peer.seen_window.size > SEEN_WINDOW_SIZE) {
      const oldest = Array.from(peer.seen_window.entries())
        .sort((a, b) => a[1] - b[1])[0]
      if (oldest) peer.seen_window.delete(oldest[0])
    }
  }

  private startHeartbeat(peerId: string): void {
    const timer = setInterval(() => {
      const peer = this.peers.get(peerId)
      if (!peer) return
      peer.ops_epoch += 1
      peer.signal_sequence += 1
      this.logEvent(peerId, 'heartbeat', 'ops', {
        ops_epoch: peer.ops_epoch,
        signal_sequence: peer.signal_sequence,
      })
    }, HEARTBEAT_INTERVAL_MS)
    this.heartbeatTimers.set(peerId, timer)
  }

  private logEvent(peerId: string, event: GossipEventType, lane: 'ops' | 'data', extra: Record<string, unknown> = {}): void {
    this.events.push({
      timestamp: Date.now(),
      peer_id: peerId,
      event,
      lane,
      ...extra,
    })
  }
}
