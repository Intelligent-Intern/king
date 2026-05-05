/**
 * Bounded gossip routing with deterministic neighbor selection.
 *
 * Constants from planning doc:
 * - fanout = 3 minimum for expander-style overlays; hard-capped by runtime policy
 * - ttl = ceil(log2(active_peer_count + 1)), clamped to 2..6
 * - seen_window = 512 frame ids per publisher track
 * - ops heartbeat interval: 1000ms
 * - carrier degraded after 3 missed heartbeats
 * - carrier lost after 6 missed heartbeats or no ack quorum for 8000ms
 * - data keyframe request cooldown: 1000ms per publisher track
 * - topology change cooldown: 3000ms
 */

export const MIN_EXPANDER_FANOUT = 3
export const DEFAULT_FANOUT = 4
export const MAX_FANOUT = 5
export const DEFAULT_TTL_BASE = 2
export const MAX_TTL = 6
export const MIN_TTL = 2
export const SEEN_WINDOW_SIZE = 512
export const HEARTBEAT_INTERVAL_MS = 1000
export const DEGRADED_AFTER_MISSED = 3
export const LOST_AFTER_MISSED = 6
export const ACK_QUORUM_WINDOW_MS = 8000
export const KEYFRAME_REQUEST_COOLDOWN_MS = 1000
export const TOPOLOGY_CHANGE_COOLDOWN_MS = 3000

export function computeTtl(activePeerCount: number): number {
  const raw = Math.ceil(Math.log2(activePeerCount + 1))
  return Math.min(MAX_TTL, Math.max(MIN_TTL, raw))
}

export function selectNeighbors(
  allPeerIds: string[],
  callId: string,
  roomId: string,
  peerId: string,
  fanout: number,
): string[] {
  const candidates = allPeerIds.filter((p) => p !== peerId)
  if (candidates.length === 0) return []

  const seed = hashString(`${callId}:${roomId}:${peerId}`)
  const shuffled = [...candidates].sort((a, b) => {
    const ha = hashString(`${seed}:${a}`)
    const hb = hashString(`${seed}:${b}`)
    return ha - hb
  })

  const boundedFanout = Math.min(MAX_FANOUT, Math.max(MIN_EXPANDER_FANOUT, Math.floor(Number(fanout) || DEFAULT_FANOUT)))
  return shuffled.slice(0, Math.min(boundedFanout, shuffled.length))
}

function hashString(input: string): number {
  let hash = 0
  for (let i = 0; i < input.length; i += 1) {
    const char = input.charCodeAt(i)
    hash = ((hash << 5) - hash + char) | 0
  }
  return hash
}

export interface RoutePressure {
  peer_id: string
  pressure_score: number
  last_updated_ms: number
}

export function selectHealthyNeighbors(
  candidates: string[],
  neighborSet: string[],
  pressureMap: Map<string, RoutePressure>,
): string[] {
  const withPressure = candidates.map((id) => ({
    id,
    pressure: pressureMap.get(id)?.pressure_score ?? 0,
  }))

  withPressure.sort((a, b) => a.pressure - b.pressure)

  const existing = new Set(neighborSet)
  const oneNew = withPressure.find((p) => !existing.has(p.id))
  if (oneNew) {
    return [oneNew.id, ...withPressure.filter((p) => p.id !== oneNew.id).slice(0, DEFAULT_FANOUT - 1)]
  }

  return withPressure.slice(0, DEFAULT_FANOUT).map((p) => p.id)
}
