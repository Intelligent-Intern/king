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

export function plannedGossipOneConnectMediaRecoveryPayload(reason: unknown, payload: Record<string, any> = {}) {
  return {
    ...plannedGossipSfuRecoveryPayload(reason, payload),
    one_connect_media_policy: 'gossip_primary_new_participant_only',
    automatic_media_restart_allowed: false,
    automatic_repair_allowed: false,
    next_connect_cycle_requires_new_participant: true,
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

export function diagnosePlannedGossipOneConnectMediaRecoveryParked({
  captureClientDiagnostic,
  reason = 'planned_gossip_one_connect_media_recovery_parked',
  payload = {},
  eventType = 'planned_gossip_one_connect_media_recovery_parked',
  message = 'Gossip-primary one-connect media policy parked automatic reconnect or repair; the next connect cycle requires a new participant.',
  level = 'warning',
  immediate = true,
}: Record<string, any> = {}) {
  if (!plannedGossipTransportActive(payload)) return false;
  if (typeof captureClientDiagnostic === 'function') {
    captureClientDiagnostic({
      category: 'media',
      level,
      eventType,
      code: eventType,
      message,
      payload: plannedGossipOneConnectMediaRecoveryPayload(reason, payload),
      immediate,
    });
  }
  return true;
}
