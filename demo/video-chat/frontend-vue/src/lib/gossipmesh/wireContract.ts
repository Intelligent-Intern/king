/**
 * GossipMesh wire contract constants and types.
 * Separates ops vs data lane messages.
 */

export const LANE_OPS = 'ops' as const
export const LANE_DATA = 'data' as const
export type Lane = typeof LANE_OPS | typeof LANE_DATA

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
