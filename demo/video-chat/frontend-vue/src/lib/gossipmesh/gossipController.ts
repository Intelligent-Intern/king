/**
 * Bounded gossip routing with deterministic neighbor selection.
 *
 * For each data frame:
 * 1. Check frame_id = publisher_id + track_id + media_generation + frame_sequence.
 * 2. If frame_id is in seen_window, drop it (duplicate).
 * 3. Add frame_id to seen_window.
 * 4. If useful locally, deliver to local decoder.
 * 5. If ttl > 0, forward to up to fanout neighbors over the data transport.
 * 6. Decrement ttl.
 *
 * The default transport is an in-memory neighbor link for the harness. Production
 * callers can replace it with RTCDataChannel sends so the server only supplies
 * identity, admission, and topology hints.
 */

import { computeTtl, selectNeighbors as selectDeterministicNeighbors } from './routing'
import { GOSSIP_DATA_LANE_CONFIG, type GossipDataLaneConfig } from './featureFlags'
import type { TopologyHintMessage } from './wireContract'

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
  topology_epoch: number
  sent: number
  received: number
  dropped: number
  duplicates: number
}

export interface GossipDelivery {
  receiving_peer_id: string
  from_peer_id: string
  frame_id: string
  message: any
}

export interface GossipDataTransport {
  sendData(targetPeerId: string, msg: any, fromPeerId: string): void
}

export type GossipDataListener = (delivery: GossipDelivery) => void

const DEFAULT_FANOUT = 2
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
  private dataLaneConfig: GossipDataLaneConfig = GOSSIP_DATA_LANE_CONFIG
  private heartbeatTimers: Map<string, ReturnType<typeof setInterval>> = new Map()
  private keyframeCooldowns: Map<string, number> = new Map()
  private dataListeners: GossipDataListener[] = []
  private dataTransport: GossipDataTransport = {
    sendData: (targetPeerId, msg, fromPeerId) => {
      this.handleData(targetPeerId, msg, fromPeerId)
    },
  }

  constructor(
    private readonly roomId: string,
    private readonly callId: string,
  ) {}

  setDataTransport(transport: GossipDataTransport): void {
    this.dataTransport = transport
  }

  setDataLaneConfig(config: GossipDataLaneConfig): void {
    this.dataLaneConfig = config
  }

  onDataMessage(listener: GossipDataListener): () => void {
    this.dataListeners.push(listener)
    return () => {
      this.dataListeners = this.dataListeners.filter((entry) => entry !== listener)
    }
  }

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
      topology_epoch: 0,
      sent: 0,
      received: 0,
      dropped: 0,
      duplicates: 0,
    })
    this.refreshTopology()
    this.startHeartbeat(peerId)
    this.logEvent(peerId, 'receive', 'ops', { message: 'peer_joined' })
  }

  removePeer(peerId: string): void {
    const timer = this.heartbeatTimers.get(peerId)
    if (timer) clearInterval(timer)
    this.heartbeatTimers.delete(peerId)
    this.peers.delete(peerId)
    this.refreshTopology()
    this.logEvent(peerId, 'receive', 'ops', { message: 'peer_left' })
  }

  dispose(): void {
    for (const timer of this.heartbeatTimers.values()) {
      clearInterval(timer)
    }
    this.heartbeatTimers.clear()
    this.peers.clear()
    this.dataListeners = []
    this.keyframeCooldowns.clear()
  }

  handleData(receivingPeerId: string, msg: any, fromPeerId = ''): void {
    const peer = this.peers.get(receivingPeerId)
    if (!peer) return

    const frameId = this.frameId(msg)
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
      forwarded_from: fromPeerId || undefined,
    })
    this.emitDataDelivery(receivingPeerId, fromPeerId, frameId, msg)

    if (msg.ttl > 0) {
      this.forward(receivingPeerId, msg, frameId, fromPeerId)
    }
  }

  publishFrame(fromPeerId: string, msg: any): void {
    const publisher = this.peers.get(fromPeerId)
    if (!publisher) return
    if (!this.dataLaneConfig.publish) {
      publisher.dropped++
      this.logEvent(fromPeerId, 'drop_stale', 'data', {
        reason: 'gossip_data_lane_disabled',
        data_lane_mode: this.dataLaneConfig.mode,
        diagnostics_label: this.dataLaneConfig.diagnosticsLabel,
      })
      return
    }

    const ttl = Number.isFinite(Number(msg.ttl)) ? Number(msg.ttl) : computeTtl(this.peers.size)
    const outbound = {
      ...msg,
      publisher_id: msg.publisher_id || fromPeerId,
      ttl,
      route_id: msg.route_id || `${this.callId}:${fromPeerId}:${Date.now()}:${msg.frame_sequence ?? publisher.sent + 1}`,
    }
    const frameId = this.frameId(outbound)
    const now = Date.now()

    publisher.sent++
    this.addToSeenWindow(publisher, frameId, now)
    this.logEvent(fromPeerId, 'send', 'data', {
      frame_id: frameId,
      publisher_id: outbound.publisher_id,
      track_id: outbound.track_id,
      frame_sequence: outbound.frame_sequence,
      media_generation: outbound.media_generation,
      ttl: outbound.ttl,
      data_lane_mode: this.dataLaneConfig.mode,
      diagnostics_label: this.dataLaneConfig.diagnosticsLabel,
    })

    this.forward(fromPeerId, outbound, frameId)
  }

  handleOps(peerId: string, msg: any): void {
    const peer = this.peers.get(peerId)
    if (!peer) return

    if (msg.type === 'topology_hint') {
      this.applyTopologyHint(peerId, msg as TopologyHintMessage)
    }

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

  applyTopologyHint(peerId: string, msg: TopologyHintMessage): boolean {
    const peer = this.peers.get(peerId)
    if (!peer) return false
    if (msg.type !== 'topology_hint') return false
    if (msg.room_id !== this.roomId || msg.call_id !== this.callId) return false
    if (msg.peer_id !== peerId) return false
    if (!Number.isFinite(Number(msg.topology_epoch))) return false
    if (Number(msg.topology_epoch) < peer.topology_epoch) return false
    if (!Array.isArray(msg.neighbors)) return false

    const nextNeighbors = msg.neighbors
      .map((entry) => String(entry?.peer_id || '').trim())
      .filter((neighborId, index, all) => {
        if (!neighborId || neighborId === peerId) return false
        if (!this.peers.has(neighborId)) return false
        return all.indexOf(neighborId) === index
      })
      .slice(0, this.fanout)

    const changed = peer.neighbor_set.join(',') !== nextNeighbors.join(',')
    peer.neighbor_set = nextNeighbors
    peer.topology_epoch = Number(msg.topology_epoch)
    if (changed) {
      this.logEvent(peerId, 'topology_change', 'ops', {
        neighbor_count: nextNeighbors.length,
        neighbors: nextNeighbors,
        topology_epoch: peer.topology_epoch,
        reconnect_reason: msg.reconnect_reason,
      })
    }
    return true
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

  setCarrierState(peerId: string, carrierState: GossipPeer['carrier_state'], reason = 'carrier_state_update'): boolean {
    const peer = this.peers.get(peerId)
    if (!peer) return false
    const nextState = carrierState === 'lost'
      ? 'lost'
      : (carrierState === 'degraded' ? 'degraded' : 'connected')
    const previousState = peer.carrier_state
    peer.carrier_state = nextState
    if (nextState === 'connected') {
      peer.missed_heartbeats = 0
      peer.last_heartbeat_at_ms = Date.now()
    }
    if (previousState !== nextState) {
      this.logEvent(peerId, 'carrier_state_change', 'ops', {
        previous_state: previousState,
        carrier_state: nextState,
        reason,
      })
    }
    if (nextState === 'lost') {
      this.logEvent(peerId, 'reconnect_requested', 'ops', {
        reconnect_allowed: true,
        carrier_state: 'lost',
        reason,
      })
    }
    return true
  }

  updateCarrierStateFromDataChannel(peerId: string, state: RTCDataChannelState, eventType: 'open' | 'close' | 'error'): boolean {
    const peer = this.peers.get(peerId)
    if (!peer) return false
    const previousState = peer.carrier_state
    if (eventType === 'open' && state === 'open') {
      peer.carrier_state = 'connected'
      peer.missed_heartbeats = 0
      peer.last_heartbeat_at_ms = Date.now()
      if (previousState !== 'connected') {
        this.logEvent(peerId, 'carrier_state_change', 'ops', {
          previous_state: previousState,
          carrier_state: 'connected',
          reason: 'rtc_datachannel_open',
        })
      }
      return true
    }

    peer.carrier_state = 'lost'
    if (previousState !== 'lost') {
      this.logEvent(peerId, 'carrier_state_change', 'ops', {
        previous_state: previousState,
        carrier_state: 'lost',
        reason: 'rtc_datachannel_lost',
      })
    }
    this.logEvent(peerId, 'reconnect_requested', 'ops', {
      reconnect_allowed: true,
      carrier_state: 'lost',
      reason: 'rtc_datachannel_lost',
      datachannel_state: state,
      datachannel_event_type: eventType,
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
        topology_epoch: peer.topology_epoch,
      }
    }
    return stats
  }

  private forward(fromPeerId: string, msg: any, frameId: string, previousHopPeerId = ''): void {
    const peer = this.peers.get(fromPeerId)
    if (!peer) return
    if (!this.dataLaneConfig.publish) return
    if ((msg.ttl || 0) <= 0) return
    const ttl = Math.max(0, (msg.ttl || 0) - 1)
    const neighbors = peer.neighbor_set.filter((n) => n !== previousHopPeerId)
    const fanoutCount = Math.min(this.fanout, neighbors.length)

    for (let i = 0; i < fanoutCount; i++) {
      const neighborId = neighbors[i]
      if (!neighborId) continue

      this.logEvent(fromPeerId, 'forward', 'data', {
        frame_id: frameId,
        fanout: fanoutCount,
        neighbor_count: neighbors.length,
        target_peer: neighborId,
        ttl,
      })
      this.dataTransport.sendData(neighborId, { ...msg, ttl }, fromPeerId)
    }
  }

  private refreshTopology(): void {
    const allPeers = Array.from(this.peers.keys())
    for (const peerId of allPeers) {
      const peer = this.peers.get(peerId)
      if (!peer) continue
      const nextNeighbors = selectDeterministicNeighbors(allPeers, this.callId, this.roomId, peerId, this.fanout)
      const changed = peer.neighbor_set.join(',') !== nextNeighbors.join(',')
      peer.neighbor_set = nextNeighbors
      if (changed) {
        this.logEvent(peerId, 'topology_change', 'ops', {
          neighbor_count: nextNeighbors.length,
          neighbors: nextNeighbors,
        })
      }
    }
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

  private frameId(msg: any): string {
    return `${msg.publisher_id}:${msg.track_id}:${msg.media_generation}:${msg.frame_sequence}`
  }

  private emitDataDelivery(receivingPeerId: string, fromPeerId: string, frameId: string, msg: any): void {
    const delivery = {
      receiving_peer_id: receivingPeerId,
      from_peer_id: fromPeerId,
      frame_id: frameId,
      message: msg,
    }
    for (const listener of this.dataListeners) {
      try {
        listener(delivery)
      } catch {}
    }
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
