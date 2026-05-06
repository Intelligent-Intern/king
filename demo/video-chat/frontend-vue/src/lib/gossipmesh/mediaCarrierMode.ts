export type VideochatMediaCarrierMode = 'gossip_primary' | 'sfu_first' | 'sfu_mirror'

export interface VideochatMediaCarrierConfig {
  envKey: 'VITE_VIDEOCHAT_MEDIA_CARRIER'
  mode: VideochatMediaCarrierMode
  gossipPrimary: boolean
  sfuFirst: boolean
  sfuMirror: boolean
  gossipMayPublishWithoutSfu: boolean
  sfuRequiredBeforeGossip: boolean
  sfuSendIsOptional: boolean
  sfuFallbackAllowed: boolean
  diagnosticsLabel: 'media_carrier_gossip_primary' | 'media_carrier_sfu_first' | 'media_carrier_sfu_mirror'
}

export const VIDEOCHAT_MEDIA_CARRIER_ENV_KEY = 'VITE_VIDEOCHAT_MEDIA_CARRIER'

export function normalizeVideochatMediaCarrierMode(value: unknown): VideochatMediaCarrierMode {
  const normalized = String(value || '').trim().toLowerCase()
  if (normalized === 'gossip_primary' || normalized === 'gossip-primary' || normalized === 'gossip') {
    return 'gossip_primary'
  }
  if (normalized === 'sfu_mirror' || normalized === 'sfu-mirror' || normalized === 'mirror') {
    return 'sfu_mirror'
  }
  return 'sfu_first'
}

export function resolveVideochatMediaCarrierConfig(env: Record<string, unknown> = import.meta.env): VideochatMediaCarrierConfig {
  const mode = normalizeVideochatMediaCarrierMode(env[VIDEOCHAT_MEDIA_CARRIER_ENV_KEY])
  const gossipPrimary = mode === 'gossip_primary'
  const sfuMirror = mode === 'sfu_mirror'
  const sfuFirst = mode === 'sfu_first'
  return {
    envKey: VIDEOCHAT_MEDIA_CARRIER_ENV_KEY,
    mode,
    gossipPrimary,
    sfuFirst,
    sfuMirror,
    gossipMayPublishWithoutSfu: gossipPrimary,
    sfuRequiredBeforeGossip: !gossipPrimary,
    sfuSendIsOptional: gossipPrimary || sfuMirror,
    sfuFallbackAllowed: true,
    diagnosticsLabel: gossipPrimary
      ? 'media_carrier_gossip_primary'
      : sfuMirror
        ? 'media_carrier_sfu_mirror'
        : 'media_carrier_sfu_first',
  }
}

export const VIDEOCHAT_MEDIA_CARRIER_CONFIG = Object.freeze(resolveVideochatMediaCarrierConfig())
