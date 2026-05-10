import {
  GOSSIP_DATA_LANE_CONFIG,
  VIDEOCHAT_MEDIA_CARRIER_CONFIG,
} from '../../../../lib/gossipmesh/featureFlags';
import { activeMediaSessionPlanHasGossipTransport } from './mediaCapabilityPlanBridge.ts';

function normalizedReason(reason: unknown): string {
  const text = String(reason || '').trim().toLowerCase();
  return text === '' ? 'planned_gossip_sfu_recovery_parked' : text;
}

export function plannedGossipTransportActive(sourcePayload: Record<string, any> = {}): boolean {
  return VIDEOCHAT_MEDIA_CARRIER_CONFIG.gossipPrimary
    || (
      GOSSIP_DATA_LANE_CONFIG.mode === 'active'
      && activeMediaSessionPlanHasGossipTransport(sourcePayload)
    );
}

export function plannedGossipSfuRecoveryPayload(reason: unknown, payload: Record<string, any> = {}) {
  return {
    lane: 'data',
    ...payload,
    reason: normalizedReason(reason),
    planned_transport: 'gossip',
    media_carrier_mode: VIDEOCHAT_MEDIA_CARRIER_CONFIG.mode,
    gossip_data_lane_mode: GOSSIP_DATA_LANE_CONFIG.mode,
    sfu_recovery_parked: true,
  };
}

export function diagnosePlannedGossipSfuRecoveryParked({
  captureClientDiagnostic,
  reason = 'planned_gossip_sfu_recovery_parked',
  payload = {},
  eventType = 'planned_gossip_sfu_recovery_parked',
  message = 'Planned Gossip media parked SFU recovery; the active path must not reconnect or fall back through SFU.',
  level = 'info',
  immediate = false,
}: Record<string, any> = {}) {
  if (!plannedGossipTransportActive(payload)) return false;
  if (typeof captureClientDiagnostic === 'function') {
    captureClientDiagnostic({
      category: 'media',
      level,
      eventType,
      code: eventType,
      message,
      payload: plannedGossipSfuRecoveryPayload(reason, payload),
      immediate,
    });
  }
  return true;
}
