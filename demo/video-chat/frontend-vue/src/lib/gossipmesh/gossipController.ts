/**
 * Bounded gossip routing with deterministic neighbor selection.
 *
 * For each data frame:
 * 1. Check frame_id = publisher_id + track_id + media_generation + frame_sequence.
 * 2. If frame_id is in seen_window with equal or better ttl, drop it.
 * 3. Add frame_id to seen_window with the best remaining ttl seen locally.
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
  | 'carrier_state_change'
  | 'topology_change'
  | 'keyframe_request'
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
  keyframe_requests: number
  missing_frame_requests: number
  retransmits_served: number
  would_publish_frames: number
}

export interface GossipPeer {
  peer_id: string
  neighbor_set: string[]
  seen_window: Map<string, number>
  seen_ttl_window: Map<string, number>
  media_generation: number
  frame_history: Map<string, GossipTrackFrameHistory>
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

export interface GossipTrackFrameHistory {
  publisher_id: string
  track_id: string
  media_generation: number
  latest_sequence_seen: number
  latest_keyframe_sequence: number
  latest_rendered_sequence: number
  latest_arrival_ms: number
  latest_forwarded_ms: number
  latest_keyframe_frame: GossipFrameMessage | null
  recent_delta_ring_buffer: GossipFrameMessage[]
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
  sender_sent_at_ms?: number
  senderSentAtMs?: number
  received_at_ms?: number
  receivedAtMs?: number
  forwarded_at_ms?: number
  forwardedAtMs?: number
  relay_peer_id?: string
  relayPeerId?: string
  hop_count?: number
  hopCount?: number
  [key: string]: unknown
}

export interface GossipOpsMessage {
  type?: string
  ops_epoch?: number
  signal_sequence?: number
  [key: string]: unknown
}

const SEEN_WINDOW_SIZE = 512
const KEYFRAME_REQUEST_COOLDOWN_MS = 1000
const TOPOLOGY_CHANGE_COOLDOWN_MS = 3000

export class GossipController {
  private peers: Map<string, GossipPeer> = new Map()
  private events: GossipEvent[] = []
  private fanout: number = DEFAULT_FANOUT
  private dataLaneConfig: GossipDataLaneConfig = GOSSIP_DATA_LANE_CONFIG
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
      seen_ttl_window: new Map<string, number>(),
      media_generation: 0,
      frame_history: new Map<string, GossipTrackFrameHistory>(),
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
    this.logEvent(peerId, 'receive', 'ops', { message: 'peer_joined' })
  }

  removePeer(peerId: string): void {
    this.peers.delete(peerId)
    this.refreshTopology()
    this.logEvent(peerId, 'receive', 'ops', { message: 'peer_left' })
  }

  dispose(): void {
    this.peers.clear()
    this.dataListeners = []
    this.keyframeCooldowns.clear()
  }

  handleData(receivingPeerId: string, msg: GossipFrameMessage, fromPeerId = ''): void {
    const peer = this.peers.get(receivingPeerId)
    if (!peer) return

    const frameId = this.frameId(msg)
    const now = Date.now()
    const arrivalMsg = this.withRelayReceiveMetadata(msg, fromPeerId, now)

    const incomingTtl = Math.max(0, Number(msg.ttl || 0))
    const alreadyDelivered = peer.seen_window.has(frameId)
    if (this.hasSeenWithEqualOrBetterTtl(peer, frameId, incomingTtl)) {
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
        ttl: incomingTtl,
        best_seen_ttl: peer.seen_ttl_window.get(frameId) ?? 0,
      })
      return
    }

    this.addToSeenWindow(peer, frameId, now, incomingTtl)

    const historyResult = this.recordFrameHistory(peer, arrivalMsg, frameId, now)

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
      latest_arrival_ms: historyResult.history?.latest_arrival_ms,
      transport_kind: this.dataTransport.kind || 'unknown',
      hop_latency_ms: this.hopLatencyMs(arrivalMsg, now),
    })
    if (!alreadyDelivered) {
      this.emitDataDelivery(receivingPeerId, fromPeerId, frameId, arrivalMsg)
    }

    if (msg.ttl > 0) {
      const msg = arrivalMsg
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
      sender_sent_at_ms: msg.sender_sent_at_ms || msg.senderSentAtMs || msg.sent_at_ms || msg.sentAtMs || Date.now(),
      received_at_ms: msg.received_at_ms || msg.receivedAtMs || Date.now(),
      hop_count: Math.max(0, Number(msg.hop_count ?? msg.hopCount ?? 0)),
    }
    const frameId = this.frameId(outbound)
    const now = Date.now()
    const avoidedServerFanout = Math.max(0, this.peers.size - 1)

    publisher.sent++
    this.recordTelemetryCounter(fromPeerId, 'sent')
    this.recordTelemetryCounter(fromPeerId, 'server_fanout_avoided', avoidedServerFanout)
    this.addToSeenWindow(publisher, frameId, now, ttl)
    this.recordFrameHistory(publisher, outbound, frameId, now)
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
    void carrierState
    const previousState = peer.carrier_state
    peer.carrier_state = 'connected'
    peer.missed_heartbeats = 0
    peer.last_heartbeat_at_ms = Date.now()
    if (previousState !== 'connected') {
      this.logEvent(peerId, 'carrier_state_change', 'ops', {
        previous_state: previousState,
        carrier_state: 'connected',
        reason,
      })
    }
    return true
  }

  updateCarrierStateFromDataChannel(peerId: string, state: RTCDataChannelState, eventType: 'open' | 'close' | 'error'): boolean {
    const peer = this.peers.get(peerId)
    if (!peer) return false
    void state
    const previousState = peer.carrier_state
    peer.carrier_state = 'connected'
    peer.missed_heartbeats = 0
    peer.last_heartbeat_at_ms = Date.now()
    if (previousState !== 'connected') {
      this.logEvent(peerId, 'carrier_state_change', 'ops', {
        previous_state: previousState,
        carrier_state: 'connected',
        reason: eventType === 'open' ? 'rtc_datachannel_open' : 'rtc_datachannel_health_ignored',
      })
    }
    return true
  }

  checkCarrierState(peerId: string): void {
    void peerId
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
      keyframe_requests: peer.telemetry.keyframe_requests,
      missing_frame_requests: peer.telemetry.missing_frame_requests,
      retransmits_served: peer.telemetry.retransmits_served,
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
    mediaCarrierMode?: 'gossip_primary' | 'sfu_first' | 'sfu_mirror'
    rolloutStrategy?: 'gossip_primary' | 'sfu_first' | 'sfu_mirror' | 'sfu_first_explicit'
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
      media_carrier_mode: options.mediaCarrierMode || 'sfu_first',
      rollout_strategy: options.rolloutStrategy || options.mediaCarrierMode || 'sfu_first',
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

      const neighbor = this.peers.get(neighborId)
      if (!neighbor) continue

      if (this.hasSeenWithEqualOrBetterTtl(neighbor, frameId, ttl)) {
        neighbor.duplicates++
        this.logEvent(neighborId, 'drop_duplicate', 'data', {
          frame_id: frameId,
          forwarded_from: fromPeerId,
          ttl,
          best_seen_ttl: neighbor.seen_ttl_window.get(frameId) ?? 0,
        })
        continue
      }

      const now = Date.now()
      this.addToSeenWindow(neighbor, frameId, now, ttl)
      const forwardedMsg = this.withRelayForwardMetadata(msg, fromPeerId, forwardedAtMs, ttl)
      const historyResult = this.recordFrameHistory(neighbor, forwardedMsg, frameId, now, forwardedAtMs)
      neighbor.received++

      this.logEvent(neighborId, 'receive', 'data', {
        frame_id: frameId,
        publisher_id: msg.publisher_id,
        track_id: msg.track_id,
        frame_sequence: msg.frame_sequence,
        media_generation: msg.media_generation,
        ttl: ttl,
        forwarded_from: fromPeerId,
        latest_arrival_ms: historyResult.history?.latest_arrival_ms,
        latest_forwarded_ms: historyResult.history?.latest_forwarded_ms,
      })

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
      this.dataTransport.sendData(neighborId, { ...forwardedMsg, ttl, last_hop_sent_at_ms: forwardedAtMs }, fromPeerId)
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

  private hasSeenWithEqualOrBetterTtl(peer: GossipPeer, frameId: string, ttl: number): boolean {
    if (!peer.seen_window.has(frameId)) return false
    return Math.max(0, Number(peer.seen_ttl_window.get(frameId) ?? 0)) >= Math.max(0, ttl)
  }

  private addToSeenWindow(peer: GossipPeer, frameId: string, now: number, ttl: number): void {
    peer.seen_window.set(frameId, now)
    peer.seen_ttl_window.set(frameId, Math.max(Math.max(0, ttl), Number(peer.seen_ttl_window.get(frameId) ?? 0)))
    if (peer.seen_window.size > SEEN_WINDOW_SIZE) {
      const oldest = Array.from(peer.seen_window.entries())
        .sort((a, b) => a[1] - b[1])[0]
      if (oldest) {
        peer.seen_window.delete(oldest[0])
        peer.seen_ttl_window.delete(oldest[0])
      }
    }
  }

  private publisherId(msg: GossipFrameMessage): string {
    return String(msg.publisher_id || msg.publisherId || msg.publisher_user_id || '').trim()
  }

  private trackId(msg: GossipFrameMessage): string {
    return String(msg.track_id || msg.trackId || '').trim()
  }

  private mediaGeneration(msg: GossipFrameMessage): number {
    return Math.max(0, Math.floor(Number(msg.media_generation ?? msg.mediaGeneration ?? 0) || 0))
  }

  private frameSequence(msg: GossipFrameMessage): number {
    return Math.max(0, Math.floor(Number(msg.frame_sequence ?? msg.frameSequence ?? 0) || 0))
  }

  private frameType(msg: GossipFrameMessage): 'keyframe' | 'delta' {
    return String(msg.frame_type || msg.frameType || msg.type || '').trim().toLowerCase() === 'keyframe'
      ? 'keyframe'
      : 'delta'
  }

  private recordFrameHistory(
    peer: GossipPeer,
    msg: GossipFrameMessage,
    frameId: string,
    arrivalMs: number,
    forwardedMs = 0,
  ): { accepted: boolean; reason: string; current_generation: number; history: GossipTrackFrameHistory | null } {
    const publisherId = this.publisherId(msg)
    const trackId = this.trackId(msg)
    if (publisherId === '' || trackId === '') {
      return { accepted: true, reason: '', current_generation: 0, history: null }
    }

    const key = `${publisherId}::${trackId}`
    const mediaGeneration = this.mediaGeneration(msg)
    const frameSequence = this.frameSequence(msg)
    let history = peer.frame_history.get(key)
    if (!history) {
      history = {
        publisher_id: publisherId,
        track_id: trackId,
        media_generation: mediaGeneration,
        latest_sequence_seen: 0,
        latest_keyframe_sequence: 0,
        latest_rendered_sequence: 0,
        latest_arrival_ms: 0,
        latest_forwarded_ms: 0,
        latest_keyframe_frame: null,
        recent_delta_ring_buffer: [],
      }
      peer.frame_history.set(key, history)
    }

    if (mediaGeneration > history.media_generation) {
      history.media_generation = mediaGeneration
      history.latest_sequence_seen = 0
      history.latest_keyframe_sequence = 0
      history.latest_rendered_sequence = 0
      history.latest_keyframe_frame = null
      history.recent_delta_ring_buffer = []
    }

    history.latest_sequence_seen = Math.max(history.latest_sequence_seen, frameSequence)
    history.latest_arrival_ms = Math.max(history.latest_arrival_ms, arrivalMs)
    if (forwardedMs > 0) {
      history.latest_forwarded_ms = Math.max(history.latest_forwarded_ms, forwardedMs)
    }

    const historicalFrame = { ...msg, frame_id: msg.frame_id || msg.frameId || frameId }
    if (this.frameType(msg) === 'keyframe') {
      history.latest_keyframe_sequence = Math.max(history.latest_keyframe_sequence, frameSequence)
      history.latest_keyframe_frame = historicalFrame
    } else {
      history.recent_delta_ring_buffer.push(historicalFrame)
      if (history.recent_delta_ring_buffer.length > 16) {
        history.recent_delta_ring_buffer = history.recent_delta_ring_buffer.slice(-16)
      }
    }

    return { accepted: true, reason: '', current_generation: history.media_generation, history }
  }

  private withRelayReceiveMetadata(msg: GossipFrameMessage, fromPeerId: string, receivedAtMs: number): GossipFrameMessage {
    return {
      ...msg,
      received_at_ms: receivedAtMs,
      relay_peer_id: fromPeerId || msg.relay_peer_id || msg.relayPeerId || '',
      hop_count: Math.max(0, Math.floor(Number(msg.hop_count ?? msg.hopCount ?? 0) || 0)),
    }
  }

  private withRelayForwardMetadata(msg: GossipFrameMessage, relayPeerId: string, forwardedAtMs: number, ttl: number): GossipFrameMessage {
    return {
      ...msg,
      ttl,
      forwarded_at_ms: forwardedAtMs,
      last_hop_sent_at_ms: forwardedAtMs,
      relay_peer_id: relayPeerId,
      hop_count: Math.max(0, Math.floor(Number(msg.hop_count ?? msg.hopCount ?? 0) || 0)) + 1,
    }
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
      keyframe_requests: 0,
      missing_frame_requests: 0,
      retransmits_served: 0,
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
