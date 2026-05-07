export type GossipDataLaneMode = 'off' | 'shadow' | 'active'
export type VideochatMediaCarrierMode = 'gossip_primary' | 'sfu_first' | 'sfu_mirror'

export interface GossipDataLaneConfig {
  mode: GossipDataLaneMode
  enabled: boolean
  publish: boolean
  receive: boolean
  diagnosticsLabel: 'gossip_data_off' | 'gossip_data_shadow' | 'gossip_data_active'
}

export interface VideochatMediaCarrierConfig {
  mode: VideochatMediaCarrierMode
  gossipPrimary: boolean
  sfuFirst: boolean
  sfuMirror: boolean
  sfuSendRequiredForGossip: boolean
  diagnosticsLabel: 'media_carrier_gossip_primary' | 'media_carrier_sfu_first' | 'media_carrier_sfu_mirror'
}

const GOSSIP_DATA_LANE_ENV_KEY = 'VITE_VIDEOCHAT_GOSSIP_DATA_LANE'
const MEDIA_CARRIER_ENV_KEY = 'VITE_VIDEOCHAT_MEDIA_CARRIER'

function normalizeGossipDataLaneMode(value: unknown): GossipDataLaneMode {
  const normalized = String(value || '').trim().toLowerCase()
  if (normalized === '1' || normalized === 'true' || normalized === 'active') return 'active'
  if (normalized === 'shadow' || normalized === 'observe' || normalized === 'diagnostic') return 'shadow'
  return 'off'
}

function normalizeVideochatMediaCarrierMode(value: unknown): VideochatMediaCarrierMode {
  const normalized = String(value || '').trim().toLowerCase().replace(/[-\s]+/g, '_')
  if (normalized === 'gossip' || normalized === 'gossip_primary' || normalized === 'gossip_first') return 'gossip_primary'
  if (normalized === 'sfu_first' || normalized === 'sfu_primary') return 'sfu_first'
  if (normalized === 'mirror' || normalized === 'sfu_mirror' || normalized === 'gossip_mirror') return 'sfu_mirror'
  return 'sfu_mirror'
}

export function resolveGossipDataLaneConfig(env: Record<string, unknown> = import.meta.env): GossipDataLaneConfig {
  const mode = normalizeGossipDataLaneMode(env[GOSSIP_DATA_LANE_ENV_KEY])
  return {
    mode,
    enabled: mode !== 'off',
    publish: mode === 'active',
    receive: mode === 'active',
    diagnosticsLabel: mode === 'active'
      ? 'gossip_data_active'
      : mode === 'shadow'
        ? 'gossip_data_shadow'
        : 'gossip_data_off',
  }
}

export function resolveVideochatMediaCarrierConfig(env: Record<string, unknown> = import.meta.env): VideochatMediaCarrierConfig {
  const mode = normalizeVideochatMediaCarrierMode(env[MEDIA_CARRIER_ENV_KEY])
  return {
    mode,
    gossipPrimary: mode === 'gossip_primary',
    sfuFirst: mode === 'sfu_first',
    sfuMirror: mode === 'sfu_mirror',
    sfuSendRequiredForGossip: mode === 'sfu_mirror',
    diagnosticsLabel: mode === 'gossip_primary'
      ? 'media_carrier_gossip_primary'
      : mode === 'sfu_first'
        ? 'media_carrier_sfu_first'
        : 'media_carrier_sfu_mirror',
  }
}

export const GOSSIP_DATA_LANE_CONFIG = Object.freeze(resolveGossipDataLaneConfig())
export const MEDIA_CARRIER_CONFIG = Object.freeze(resolveVideochatMediaCarrierConfig())
