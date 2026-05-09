export type GossipDataLaneMode = 'off' | 'shadow' | 'active'

export {
  VIDEOCHAT_MEDIA_CARRIER_CONFIG,
  VIDEOCHAT_MEDIA_CARRIER_ENV_KEY,
  normalizeVideochatMediaCarrierMode,
  resolveVideochatMediaCarrierConfig,
} from './mediaCarrierMode'
export type {
  VideochatMediaCarrierConfig,
  VideochatMediaCarrierMode,
} from './mediaCarrierMode'

export interface GossipDataLaneConfig {
  mode: GossipDataLaneMode
  enabled: boolean
  publish: boolean
  receive: boolean
  diagnosticsLabel: 'gossip_data_off' | 'gossip_data_shadow' | 'gossip_data_active'
}

export interface GossipServerRelayConfig {
  mode: 'off' | 'mirror' | 'primary'
  enabled: boolean
  primary: boolean
  diagnosticsLabel: 'gossip_server_relay_off' | 'gossip_server_relay_mirror' | 'gossip_server_relay_primary'
}

const GOSSIP_DATA_LANE_ENV_KEY = 'VITE_VIDEOCHAT_GOSSIP_DATA_LANE'
const GOSSIP_SERVER_RELAY_ENV_KEY = 'VITE_VIDEOCHAT_GOSSIP_SERVER_RELAY'

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

function normalizeGossipServerRelayMode(value: unknown): GossipServerRelayConfig['mode'] {
  const normalized = String(value ?? 'primary').trim().toLowerCase()
  if (normalized === '0' || normalized === 'false' || normalized === 'off') return 'off'
  if (normalized === 'mirror' || normalized === '1' || normalized === 'true' || normalized === 'active') return 'mirror'
  return 'primary'
}

export function resolveGossipServerRelayConfig(env: Record<string, unknown> = import.meta.env): GossipServerRelayConfig {
  const mode = normalizeGossipServerRelayMode(env[GOSSIP_SERVER_RELAY_ENV_KEY])
  return {
    mode,
    enabled: mode !== 'off',
    primary: mode === 'primary',
    diagnosticsLabel: mode === 'primary'
      ? 'gossip_server_relay_primary'
      : mode === 'mirror'
        ? 'gossip_server_relay_mirror'
        : 'gossip_server_relay_off',
  }
}

export const GOSSIP_DATA_LANE_CONFIG = Object.freeze(resolveGossipDataLaneConfig())
export const GOSSIP_SERVER_RELAY_CONFIG = Object.freeze(resolveGossipServerRelayConfig())
