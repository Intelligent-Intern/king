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

import { DEFAULT_FANOUT, computeTtl, selectNeighbors as selectDeterministicNeighbors } from './routing'
import { GOSSIP_DATA_LANE_CONFIG, type GossipDataLaneConfig } from './featureFlags'
import type { TopologyHintMessage } from './wireContract'

export type GossipEventType =
  | 'send'
  | 'receive'
  | 'drop'
  | 'drop_duplicate'
  | 'drop_stale'
  | 'stale_generation_drop'
  | 'late_drop'
  | 'ttl_exhausted'
  | 'forward'
  | 'server_fanout_avoided'
  | 'peer_outbound_fanout'
  | 'rtc_datachannel_send'
  | 'in_memory_harness_send'
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
  transport_kind?: GossipTransportKind
  hop_latency_ms?: number
  reason?: string
  carrier_state?: string
  reconnect_allowed?: boolean
  payload?: Record<string, unknown>
}

export type GossipTransportKind = 'in_memory_harness' | 'rtc_datachannel' | 'server_fanout' | 'unknown'

export interface GossipTelemetryCounters {
  sent: number
  received: number
  forwarded: number
  dropped: number
  duplicates: number
  ttl_exhausted: number
  late_drops: number
  stale_generation_drops: number
  server_fanout_avoided: number
  peer_outbound_fanout: number
  rtc_datachannel_sends: number
  in_memory_harness_sends: number
  topology_repairs_requested: number
  would_publish_frames: number
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
  telemetry: GossipTelemetryCounters
}

export interface GossipDelivery {
  receiving_peer_id: string
  from_peer_id: string
  frame_id: string
  message: GossipFrameMessage
}

export interface GossipDataTransport {
  readonly kind?: GossipTransportKind
  sendData(targetPeerId: string, msg: GossipFrameMessage, fromPeerId: string): void
}

export type GossipDataListener = (delivery: GossipDelivery) => void

export interface GossipFrameMessage {
  type?: string
  frame_id?: string
  frameId?: string
  publisher_id?: string
  publisherId?: string
  track_id?: string
  trackId?: string
  frame_sequence?: number
  frameSequence?: number
  media_generation?: number
  mediaGeneration?: number
  ttl?: number
  route_id?: string
  routeId?: string
  timestamp?: number
  sent_at_ms?: number
  sentAtMs?: number
  [key: string]: unknown
}

export interface GossipOpsMessage {
  type?: string
  ops_epoch?: number
  signal_sequence?: number
  [key: string]: unknown
}

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
    kind: 'in_memory_harness',
    sendData: (targetPeerId, msg, fromPeerId) => {
      this.recordTelemetryCounter(fromPeerId, 'in_memory_harness_sends')
      this.logEvent(fromPeerId, 'in_memory_harness_send', 'data', {
        target_peer: targetPeerId,
        transport_kind: 'in_memory_harness',
      })
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
      telemetry: this.emptyTelemetryCounters(),
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

  handleData(receivingPeerId: string, msg: GossipFrameMessage, fromPeerId = ''): void {
    const peer = this.peers.get(receivingPeerId)
    if (!peer) return

    const frameId = this.frameId(msg)
    const now = Date.now()

    if (peer.seen_window.has(frameId)) {
      peer.duplicates++
      this.recordTelemetryCounter(receivingPeerId, 'duplicates')
      this.logEvent(receivingPeerId, 'drop_duplicate', 'data', {
        frame_id: frameId,
        publisher_id: msg.publisher_id,
        track_id: msg.track_id,
        frame_sequence: msg.frame_sequence,
        media_generation: msg.media_generation,
        transport_kind: this.dataTransport.kind || 'unknown',
        hop_latency_ms: this.hopLatencyMs(msg, now),
      })
      return
    }

    this.addToSeenWindow(peer, frameId, now)

    if (msg.media_generation > 0 && msg.media_generation < peer.media_generation) {
      peer.dropped++
      this.recordTelemetryCounter(receivingPeerId, 'dropped')
      this.recordTelemetryCounter(receivingPeerId, 'stale_generation_drops')
      this.logEvent(receivingPeerId, 'drop', 'data', {
        frame_id: frameId,
        reason: 'stale_generation',
        transport_kind: this.dataTransport.kind || 'unknown',
        hop_latency_ms: this.hopLatencyMs(msg, now),
      })
      this.logEvent(receivingPeerId, 'stale_generation_drop', 'data', {
        frame_id: frameId,
        media_generation: msg.media_generation,
        current_generation: peer.media_generation,
        transport_kind: this.dataTransport.kind || 'unknown',
        hop_latency_ms: this.hopLatencyMs(msg, now),
      })
      this.logEvent(receivingPeerId, 'drop_stale', 'data', {
        frame_id: frameId,
        media_generation: msg.media_generation,
        current_generation: peer.media_generation,
        transport_kind: this.dataTransport.kind || 'unknown',
        hop_latency_ms: this.hopLatencyMs(msg, now),
      })
      return
    }

    if (msg.media_generation > peer.media_generation) {
      peer.media_generation = msg.media_generation
    }

    peer.received++
    this.recordTelemetryCounter(receivingPeerId, 'received')
    this.logEvent(receivingPeerId, 'receive', 'data', {
      frame_id: frameId,
      publisher_id: msg.publisher_id,
      track_id: msg.track_id,
      frame_sequence: msg.frame_sequence,
      media_generation: msg.media_generation,
      ttl: msg.ttl,
      forwarded_from: fromPeerId || undefined,
      transport_kind: this.dataTransport.kind || 'unknown',
      hop_latency_ms: this.hopLatencyMs(msg, now),
    })
    this.emitDataDelivery(receivingPeerId, fromPeerId, frameId, msg)

    if (msg.ttl > 0) {
      this.forward(receivingPeerId, msg, frameId, fromPeerId)
    }
  }

  publishFrame(fromPeerId: string, msg: GossipFrameMessage): void {
    const publisher = this.peers.get(fromPeerId)
    if (!publisher) return
    if (!this.dataLaneConfig.publish) {
      publisher.dropped++
      this.recordTelemetryCounter(fromPeerId, 'dropped')
      this.logEvent(fromPeerId, 'drop', 'data', {
        reason: 'gossip_data_lane_disabled',
        transport_kind: this.dataTransport.kind || 'unknown',
      })
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
    const avoidedServerFanout = Math.max(0, this.peers.size - 1)

    publisher.sent++
    this.recordTelemetryCounter(fromPeerId, 'sent')
    this.recordTelemetryCounter(fromPeerId, 'server_fanout_avoided', avoidedServerFanout)
    this.addToSeenWindow(publisher, frameId, now)
    this.logEvent(fromPeerId, 'server_fanout_avoided', 'data', {
      frame_id: frameId,
      transport_kind: this.dataTransport.kind || 'unknown',
      fanout: avoidedServerFanout,
    })
    this.logEvent(fromPeerId, 'send', 'data', {
      frame_id: frameId,
      publisher_id: outbound.publisher_id,
      track_id: outbound.track_id,
      frame_sequence: outbound.frame_sequence,
      media_generation: outbound.media_generation,
      ttl: outbound.ttl,
      transport_kind: this.dataTransport.kind || 'unknown',
      data_lane_mode: this.dataLaneConfig.mode,
      diagnostics_label: this.dataLaneConfig.diagnosticsLabel,
      server_fanout_avoided: true,
    })

    this.forward(fromPeerId, outbound, frameId)
  }

  handleOps(peerId: string, msg: GossipOpsMessage): void {
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

  getStats(): Record<string, Record<string, unknown>> {
    const stats: Record<string, Record<string, unknown>> = {}
    for (const [peerId, peer] of this.peers.entries()) {
      stats[peerId] = {
        sent: peer.sent,
        received: peer.received,
        forwarded: peer.telemetry.forwarded,
        dropped: peer.dropped,
        duplicates: peer.duplicates,
        ttl_exhausted: peer.telemetry.ttl_exhausted,
        late_drops: peer.telemetry.late_drops,
        stale_generation_drops: peer.telemetry.stale_generation_drops,
        server_fanout_avoided: peer.telemetry.server_fanout_avoided,
        peer_outbound_fanout: peer.telemetry.peer_outbound_fanout,
        rtc_datachannel_sends: peer.telemetry.rtc_datachannel_sends,
        in_memory_harness_sends: peer.telemetry.in_memory_harness_sends,
        topology_repairs_requested: peer.telemetry.topology_repairs_requested,
        telemetry: { ...peer.telemetry },
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

  createTelemetrySnapshot(peerId: string, options: {
    dataLaneMode?: 'off' | 'shadow' | 'active'
    diagnosticsLabel?: string
    rolloutStrategy?: 'sfu_first_explicit'
  } = {}): Record<string, unknown> | null {
    const peer = this.peers.get(peerId)
    if (!peer) return null
    return {
      kind: 'gossip_telemetry_snapshot',
      room_id: this.roomId,
      call_id: this.callId,
      peer_id: peerId,
      transport_kind: this.dataTransport.kind || 'unknown',
      data_lane_mode: options.dataLaneMode || this.dataLaneConfig.mode,
      diagnostics_label: options.diagnosticsLabel || this.dataLaneConfig.diagnosticsLabel,
      rollout_strategy: options.rolloutStrategy || 'sfu_first_explicit',
      neighbor_count: peer.neighbor_set.length,
      topology_epoch: peer.topology_epoch,
      counters: { ...peer.telemetry },
    }
  }

  private forward(fromPeerId: string, msg: GossipFrameMessage, frameId: string, previousHopPeerId = ''): void {
    const peer = this.peers.get(fromPeerId)
    if (!peer) return
    if (!this.dataLaneConfig.publish) return
    if ((msg.ttl || 0) <= 0) {
      this.recordTelemetryCounter(fromPeerId, 'ttl_exhausted')
      this.logEvent(fromPeerId, 'ttl_exhausted', 'data', {
        frame_id: frameId,
        ttl: Math.max(0, Number(msg.ttl || 0)),
        transport_kind: this.dataTransport.kind || 'unknown',
      })
      return
    }
    const ttl = Math.max(0, (msg.ttl || 0) - 1)
    const neighbors = peer.neighbor_set.filter((n) => n !== previousHopPeerId)
    const fanoutCount = Math.min(this.fanout, neighbors.length)
    this.recordTelemetryCounter(fromPeerId, 'peer_outbound_fanout', fanoutCount)
    this.logEvent(fromPeerId, 'peer_outbound_fanout', 'data', {
      frame_id: frameId,
      fanout: fanoutCount,
      neighbor_count: neighbors.length,
      transport_kind: this.dataTransport.kind || 'unknown',
    })
    const forwardedAtMs = Date.now()

    for (let i = 0; i < fanoutCount; i++) {
      const neighborId = neighbors[i]
      if (!neighborId) continue

      this.recordTelemetryCounter(fromPeerId, 'forwarded')
      this.logEvent(fromPeerId, 'forward', 'data', {
        frame_id: frameId,
        fanout: fanoutCount,
        neighbor_count: neighbors.length,
        target_peer: neighborId,
        ttl,
        transport_kind: this.dataTransport.kind || 'unknown',
        hop_latency_ms: this.hopLatencyMs(msg, forwardedAtMs),
      })
      this.dataTransport.sendData(neighborId, { ...msg, ttl, last_hop_sent_at_ms: forwardedAtMs }, fromPeerId)
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

  private frameId(msg: GossipFrameMessage): string {
    return `${msg.publisher_id}:${msg.track_id}:${msg.media_generation}:${msg.frame_sequence}`
  }

  private emitDataDelivery(receivingPeerId: string, fromPeerId: string, frameId: string, msg: GossipFrameMessage): void {
    const delivery: GossipDelivery = {
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

  private emptyTelemetryCounters(): GossipTelemetryCounters {
    return {
      sent: 0,
      received: 0,
      forwarded: 0,
      dropped: 0,
      duplicates: 0,
      ttl_exhausted: 0,
      late_drops: 0,
      stale_generation_drops: 0,
      server_fanout_avoided: 0,
      peer_outbound_fanout: 0,
      rtc_datachannel_sends: 0,
      in_memory_harness_sends: 0,
      topology_repairs_requested: 0,
      would_publish_frames: 0,
    }
  }

  recordTransportTelemetry(peerId: string, counter: keyof GossipTelemetryCounters, increment = 1): void {
    this.recordTelemetryCounter(peerId, counter, increment)
    if (counter === 'rtc_datachannel_sends') {
      this.logEvent(peerId, 'rtc_datachannel_send', 'data', {
        transport_kind: 'rtc_datachannel',
      })
    } else if (counter === 'late_drops') {
      this.logEvent(peerId, 'late_drop', 'data', {
        transport_kind: this.dataTransport.kind || 'unknown',
      })
    } else if (counter === 'dropped') {
      this.logEvent(peerId, 'drop', 'data', {
        transport_kind: this.dataTransport.kind || 'unknown',
      })
    }
  }

  private recordTelemetryCounter(peerId: string, counter: keyof GossipTelemetryCounters, increment = 1): void {
    const peer = this.peers.get(peerId)
    if (!peer) return
    if (typeof peer.telemetry[counter] !== 'number') return
    peer.telemetry[counter] += increment
  }

  private hopLatencyMs(msg: GossipFrameMessage, now: number): number | undefined {
    const sentAt = Number(msg?.last_hop_sent_at_ms || msg?.sender_sent_at_ms || msg?.sent_at_ms || 0)
    if (!Number.isFinite(sentAt) || sentAt <= 0) return undefined
    return Math.max(0, now - sentAt)
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
