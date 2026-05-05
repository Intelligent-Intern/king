export type GossipDataLaneMode = 'off' | 'shadow' | 'active'

export interface GossipDataLaneConfig {
  mode: GossipDataLaneMode
  enabled: boolean
  publish: boolean
  receive: boolean
  diagnosticsLabel: 'gossip_data_off' | 'gossip_data_shadow' | 'gossip_data_active'
}

const GOSSIP_DATA_LANE_ENV_KEY = 'VITE_VIDEOCHAT_GOSSIP_DATA_LANE'

function normalizeGossipDataLaneMode(value: unknown): GossipDataLaneMode {
  const normalized = String(value || '').trim().toLowerCase()
  if (normalized === '1' || normalized === 'true' || normalized === 'active') return 'active'
  if (normalized === 'shadow' || normalized === 'observe' || normalized === 'diagnostic') return 'shadow'
  return 'off'
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

export const GOSSIP_DATA_LANE_CONFIG = Object.freeze(resolveGossipDataLaneConfig())
