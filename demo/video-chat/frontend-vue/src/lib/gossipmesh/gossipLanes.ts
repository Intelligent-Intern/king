/**
 * GossipMesh two-lane model: ops lane and data lane.
 *
 * Ops lane: low-bandwidth operational signal for membership, liveness,
 * topology, pressure, recovery intent, carrier health.
 *
 * Data lane: high-bandwidth media frames and frame-adjacent metadata.
 */

export type Lane = 'ops' | 'data'

export interface OpsMessage {
  type: string
  ops_epoch: number
  signal_sequence: number
  peer_id: string
  room_id: string
  call_id: string
  carrier_state?: 'connected' | 'degraded' | 'lost'
  message_type?: string
  [key: string]: unknown
}

export interface DataMessage {
  type: 'sfu/frame'
  publisher_id: string
  track_id: string
  frame_type: 'keyframe' | 'delta'
  frame_sequence: number
  media_generation: number
  ttl: number
  route_id: string
  publisher_user_id?: string
  timestamp: number
  payload: string
  [key: string]: unknown
}

export type GossipMessage = OpsMessage | DataMessage

export interface PeerIdentity {
  peer_id: string
  room_id: string
  call_id: string
  publisher_id?: string
  track_id?: string
}

export interface LaneStats {
  lane: Lane
  sent: number
  received: number
  dropped: number
  duplicates: number
  forwarded: number
}

export class GossipLaneBus {
  private opsListeners: Array<(msg: OpsMessage, fromPeerId: string) => void> = []
  private dataListeners: Array<(msg: DataMessage, fromPeerId: string) => void> = []
  private peers: Map<string, PeerIdentity> = new Map()
  private stats: Map<string, LaneStats> = new Map()

  registerPeer(identity: PeerIdentity): void {
    this.peers.set(identity.peer_id, identity)
    if (!this.stats.has(identity.peer_id)) {
      this.stats.set(identity.peer_id, {
        lane: 'ops',
        sent: 0,
        received: 0,
        dropped: 0,
        duplicates: 0,
        forwarded: 0,
      })
    }
  }

  unregisterPeer(peerId: string): void {
    this.peers.delete(peerId)
  }

  getPeer(peerId: string): PeerIdentity | undefined {
    return this.peers.get(peerId)
  }

  getActivePeers(): PeerIdentity[] {
    return Array.from(this.peers.values())
  }

  sendOps(msg: OpsMessage, fromPeerId: string): void {
    this.recordStat(fromPeerId, 'ops', 'sent')
    for (const listener of this.opsListeners) {
      try {
        listener(msg, fromPeerId)
      } catch {}
    }
  }

  sendData(msg: DataMessage, fromPeerId: string): void {
    this.recordStat(fromPeerId, 'data', 'sent')
    for (const listener of this.dataListeners) {
      try {
        listener(msg, fromPeerId)
      } catch {}
    }
  }

  onOpsMessage(cb: (msg: OpsMessage, fromPeerId: string) => void): () => void {
    this.opsListeners.push(cb)
    return () => {
      this.opsListeners = this.opsListeners.filter((l) => l !== cb)
    }
  }

  onDataMessage(cb: (msg: DataMessage, fromPeerId: string) => void): () => void {
    this.dataListeners.push(cb)
    return () => {
      this.dataListeners = this.dataListeners.filter((l) => l !== cb)
    }
  }

  recordStat(peerId: string, lane: Lane, field: keyof LaneStats): void {
    const stats = this.stats.get(peerId)
    if (stats && field in stats) {
      (stats as any)[field] += 1
    }
  }

  recordDuplicate(peerId: string, lane: Lane): void {
    this.recordStat(peerId, lane, 'duplicates')
  }

  recordDrop(peerId: string, lane: Lane): void {
    this.recordStat(peerId, lane, 'dropped')
  }

  recordForward(peerId: string, lane: Lane): void {
    this.recordStat(peerId, lane, 'forwarded')
  }

  getStats(peerId: string): LaneStats | undefined {
    return this.stats.get(peerId)
  }

  getAllStats(): Map<string, LaneStats> {
    return new Map(this.stats)
  }

  resetStats(): void {
    for (const stats of this.stats.values()) {
      stats.sent = 0
      stats.received = 0
      stats.dropped = 0
      stats.duplicates = 0
      stats.forwarded = 0
    }
  }
}
