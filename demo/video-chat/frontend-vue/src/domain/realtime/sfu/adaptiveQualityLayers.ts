import {
  REMOTE_RENDER_SURFACE_ROLES,
  normalizeRemoteRenderSurfaceRole,
  remoteRenderSurfaceRoleForPeer,
} from './remoteRenderScheduler.ts';

export const SFU_ADAPTIVE_LAYER_PREFERENCES = Object.freeze({
  PRIMARY: 'primary',
  THUMBNAIL: 'thumbnail',
});

export const SFU_ADAPTIVE_LAYER_ACTIONS = Object.freeze({
  PRIMARY: 'prefer_primary_video_layer',
  THUMBNAIL: 'prefer_thumbnail_video_layer',
});

export const SFU_ADAPTIVE_LAYER_QUALITY_PROFILE = Object.freeze({
  [SFU_ADAPTIVE_LAYER_PREFERENCES.PRIMARY]: 'balanced',
  [SFU_ADAPTIVE_LAYER_PREFERENCES.THUMBNAIL]: 'realtime',
});

export const SFU_LAYER_PREFERENCE_MIN_INTERVAL_MS = 3000;

function normalizePositiveInteger(value, fallback = 0) {
  const normalized = Number(value || 0);
  return Number.isFinite(normalized) && normalized > 0 ? Math.floor(normalized) : fallback;
}

export function visibleParticipantCountForPeer(peer) {
  return normalizePositiveInteger(peer?.decodedCanvas?.dataset?.callVideoVisibleParticipantCount, 0);
}

export function sfuLayerPreferenceForRemoteSurfaceRole(role, {
  visibleParticipantCount = 0,
} = {}) {
  const normalizedRole = normalizeRemoteRenderSurfaceRole(role);
  if (
    normalizedRole === REMOTE_RENDER_SURFACE_ROLES.FULLSCREEN
    || normalizedRole === REMOTE_RENDER_SURFACE_ROLES.MAIN
  ) {
    return SFU_ADAPTIVE_LAYER_PREFERENCES.PRIMARY;
  }
  if (
    normalizedRole === REMOTE_RENDER_SURFACE_ROLES.GRID
    && normalizePositiveInteger(visibleParticipantCount, 0) > 0
    && normalizePositiveInteger(visibleParticipantCount, 0) <= 2
  ) {
    return SFU_ADAPTIVE_LAYER_PREFERENCES.PRIMARY;
  }
  return SFU_ADAPTIVE_LAYER_PREFERENCES.THUMBNAIL;
}

export function sfuLayerPreferenceForPeer(peer) {
  return sfuLayerPreferenceForRemoteSurfaceRole(remoteRenderSurfaceRoleForPeer(peer), {
    visibleParticipantCount: visibleParticipantCountForPeer(peer),
  });
}

function sfuLayerPreferenceTrackKey(publisherId, frame = null) {
  return [
    String(publisherId || frame?.publisherId || '').trim() || 'unknown',
    String(frame?.trackId || '').trim() || 'default',
  ].join(':');
}

function ensureSfuLayerPreferenceState(peer) {
  if (!peer || typeof peer !== 'object') return null;
  if (!peer.sfuLayerPreferenceStateByTrack || typeof peer.sfuLayerPreferenceStateByTrack !== 'object') {
    peer.sfuLayerPreferenceStateByTrack = {};
  }
  return peer.sfuLayerPreferenceStateByTrack;
}

export function shouldSendSfuLayerPreference(peer, publisherId, frame, layerPreference, nowMs = Date.now()) {
  const state = ensureSfuLayerPreferenceState(peer);
  if (!state) return false;
  const normalizedLayerPreference = String(layerPreference || '').trim().toLowerCase();
  if (!Object.values(SFU_ADAPTIVE_LAYER_PREFERENCES).includes(normalizedLayerPreference)) return false;
  const key = sfuLayerPreferenceTrackKey(publisherId, frame);
  const previous = state[key] || null;
  if (!previous || previous.layerPreference !== normalizedLayerPreference) return true;
  return (Math.max(0, Number(nowMs || 0)) - Math.max(0, Number(previous.sentAtMs || 0))) >= SFU_LAYER_PREFERENCE_MIN_INTERVAL_MS;
}

export function markSfuLayerPreferenceSent(peer, publisherId, frame, layerPreference, nowMs = Date.now()) {
  const state = ensureSfuLayerPreferenceState(peer);
  if (!state) return false;
  const normalizedLayerPreference = String(layerPreference || '').trim().toLowerCase();
  const key = sfuLayerPreferenceTrackKey(publisherId, frame);
  state[key] = {
    layerPreference: normalizedLayerPreference,
    sentAtMs: Math.max(0, Number(nowMs || 0)),
  };
  peer.lastSfuLayerPreference = normalizedLayerPreference;
  peer.lastSfuLayerPreferenceSentAtMs = Math.max(0, Number(nowMs || 0));
  return true;
}

export function buildSfuLayerPreferencePayload({
  frame = null,
  layerPreference,
  renderSurfaceRole,
  visibleParticipantCount = 0,
} = {}) {
  const normalizedLayerPreference = String(layerPreference || '').trim().toLowerCase();
  const action = normalizedLayerPreference === SFU_ADAPTIVE_LAYER_PREFERENCES.PRIMARY
    ? SFU_ADAPTIVE_LAYER_ACTIONS.PRIMARY
    : SFU_ADAPTIVE_LAYER_ACTIONS.THUMBNAIL;
  return {
    requested_action: action,
    requested_video_layer: normalizedLayerPreference,
    requested_video_quality_profile: SFU_ADAPTIVE_LAYER_QUALITY_PROFILE[normalizedLayerPreference] || '',
    automatic_layer_preference: true,
    render_surface_role: normalizeRemoteRenderSurfaceRole(renderSurfaceRole),
    visible_participant_count: normalizePositiveInteger(visibleParticipantCount, 0),
    frame_sequence: normalizePositiveInteger(frame?.frameSequence, 0),
    frame_timestamp: normalizePositiveInteger(frame?.timestamp, 0),
    outgoing_video_quality_profile: String(frame?.outgoingVideoQualityProfile || ''),
  };
}
