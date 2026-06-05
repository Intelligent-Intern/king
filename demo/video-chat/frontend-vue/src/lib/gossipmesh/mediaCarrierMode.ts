export type VideochatMediaCarrierMode = 'gossip_primary'

export interface VideochatMediaCarrierConfig {
  envKey: 'VITE_VIDEOCHAT_MEDIA_CARRIER'
  mode: VideochatMediaCarrierMode
  gossipPrimary: boolean
  gossipOnlyMediaTransport: boolean
  diagnosticsLabel: 'media_carrier_gossip_primary'
}

export const VIDEOCHAT_MEDIA_CARRIER_ENV_KEY = 'VITE_VIDEOCHAT_MEDIA_CARRIER'

export function normalizeVideochatMediaCarrierMode(value: unknown): VideochatMediaCarrierMode {
  const normalized = String(value || '').trim().toLowerCase()
  if (normalized === 'gossip_primary' || normalized === 'gossip-primary' || normalized === 'gossip') {
    return 'gossip_primary'
  }
  return 'gossip_primary'
}

export function resolveVideochatMediaCarrierConfig(env: Record<string, unknown> = import.meta.env): VideochatMediaCarrierConfig {
  const mode = normalizeVideochatMediaCarrierMode(env[VIDEOCHAT_MEDIA_CARRIER_ENV_KEY])
  const gossipPrimary = mode === 'gossip_primary'
  return {
    envKey: VIDEOCHAT_MEDIA_CARRIER_ENV_KEY,
    mode,
    gossipPrimary,
    gossipOnlyMediaTransport: true,
    diagnosticsLabel: 'media_carrier_gossip_primary',
  }
}

export const VIDEOCHAT_MEDIA_CARRIER_CONFIG = Object.freeze(resolveVideochatMediaCarrierConfig())
