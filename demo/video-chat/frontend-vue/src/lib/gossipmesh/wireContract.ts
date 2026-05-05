/**
 * GossipMesh wire contract constants and types.
 * Separates ops vs data lane messages.
 */

export const LANE_OPS = 'ops' as const
export const LANE_DATA = 'data' as const
export type Lane = typeof LANE_OPS | typeof LANE_DATA
export const GOSSIP_DATA_CODEC_IIBIN = 'iibin' as const
export const GOSSIP_DATA_ENVELOPE_CONTRACT = 'king-video-chat-gossipmesh-iibin-media-envelope' as const
export const GOSSIP_CONTROL_OBJECT_STORE_CONTRACT = 'king-object-store-gossipmesh-control-plane' as const
export const GOSSIP_NATIVE_TRANSPORT_PRIORITY = Object.freeze([
  'rtc_datachannel',
  'king_lsquic_http3',
  'king_websocket_binary',
] as const)

export const OPS_MESSAGE_TYPES = Object.freeze([
  'hello',
  'heartbeat',
  'heartbeat_ack',
  'membership_delta',
  'topology_hint',
  'pressure_signal',
  'keyframe_request',
  'layer_request',
  'carrier_lost',
  'carrier_restored',
  'leave',
])

export const DATA_MESSAGE_TYPES = Object.freeze([
  'media_keyframe',
  'media_delta',
  'media_layer',
  'data_pressure_sample',
])

export interface LaneTaggedMessage {
  lane?: Lane
  type: string
  [key: string]: unknown
}

export interface TopologyHintNeighbor {
  peer_id: string
  transport?: 'rtc_datachannel' | 'in_memory' | 'king_lsquic_http3' | 'king_websocket_binary'
  codec?: typeof GOSSIP_DATA_CODEC_IIBIN
  envelope_contract?: typeof GOSSIP_DATA_ENVELOPE_CONTRACT
  priority?: number
}

export interface TopologyHintMessage extends LaneTaggedMessage {
  lane: typeof LANE_OPS
  type: 'topology_hint'
  room_id: string
  call_id: string
  peer_id: string
  topology_epoch: number
  neighbors: TopologyHintNeighbor[]
  reconnect_reason?: string
}

/**
 * Classify a message into ops or data lane.
 * Unknown lane values fail closed (return null).
 */
export function classifyMessage(msg: Record<string, unknown>): Lane | null {
  if (!msg || typeof msg !== 'object') return null
  if (typeof msg.lane === 'string') {
    if (msg.lane === LANE_OPS || OPS_MESSAGE_TYPES.includes(msg.lane as string)) return LANE_OPS
    if (msg.lane === LANE_DATA || DATA_MESSAGE_TYPES.includes(msg.lane as string)) return LANE_DATA
    return null
  }
  if (OPS_MESSAGE_TYPES.includes(msg.type as string)) return LANE_OPS
  if (DATA_MESSAGE_TYPES.includes(msg.type as string)) return LANE_DATA
  if (String(msg.type || '').startsWith('sfu/')) {
    const sfuType = String(msg.type)
    if (sfuType === 'sfu/frame') return LANE_DATA
    return LANE_OPS
  }
  return null
}

export function assertLane(msg: Record<string, unknown>, expectedLane: Lane): boolean {
  const actual = classifyMessage(msg)
  return actual === expectedLane
}
