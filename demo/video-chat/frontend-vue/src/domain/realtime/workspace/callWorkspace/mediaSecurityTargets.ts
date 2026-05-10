import { isScreenShareUserId } from '../../screenShareIdentity.js';
import { GOSSIP_DATA_LANE_CONFIG, VIDEOCHAT_MEDIA_CARRIER_CONFIG } from '../../../../lib/gossipmesh/featureFlags';

function normalizedMediaSecurityTransportLabel(value) {
  return String(value || '').trim().toLowerCase().replace(/-/g, '_');
}

export function isPlannedGossipMediaSecurityTransport(context = {}) {
  const source = context && typeof context === 'object' ? context : { mediaRuntimePath: context };
  const labels = [
    source.mediaRuntimePath,
    source.mediaSecurityRuntimePath,
    source.runtimePath,
    source.transportPath,
    source.carrierMode,
  ].map(normalizedMediaSecurityTransportLabel);
  if (labels.some((label) => (
    label === 'gossip'
    || label === 'gossip_primary'
    || label === 'gossip_primary_direct'
    || label === 'gossip_rtc_datachannel'
    || label === 'planned_gossip'
  ))) {
    return true;
  }
  const carrierIsGossipPrimary = Object.prototype.hasOwnProperty.call(source, 'gossipPrimary')
    ? source.gossipPrimary === true
    : VIDEOCHAT_MEDIA_CARRIER_CONFIG.gossipPrimary;
  const dataLaneMode = normalizedMediaSecurityTransportLabel(source.gossipDataLaneMode);
  const dataLaneIsActive = source.gossipDataLaneActive === true
    || dataLaneMode === 'active'
    || (!Object.prototype.hasOwnProperty.call(source, 'gossipDataLaneActive')
      && dataLaneMode === ''
      && GOSSIP_DATA_LANE_CONFIG.mode === 'active');
  return carrierIsGossipPrimary && dataLaneIsActive;
}

export function createMediaSecurityGossipParking({
  captureClientDiagnostic = () => {},
  currentMediaSecurityRuntimePath = () => '',
  mediaDebugLog = () => {},
  mediaRuntimePath = {},
  state = {},
} = {}) {
  function mediaRuntimePathValue() {
    const value = mediaRuntimePath && typeof mediaRuntimePath === 'object' && 'value' in mediaRuntimePath
      ? mediaRuntimePath.value
      : mediaRuntimePath;
    return String(value || '').trim();
  }

  function mediaSecurityPlannedGossipParkingLastByKey() {
    if (!(state.mediaSecurityPlannedGossipParkingLastByKey instanceof Map)) {
      state.mediaSecurityPlannedGossipParkingLastByKey = new Map();
    }
    return state.mediaSecurityPlannedGossipParkingLastByKey;
  }

  function shouldParkMediaSecurityForPlannedGossip(reason = '') {
    if (!isPlannedGossipMediaSecurityTransport({
      mediaRuntimePath: mediaRuntimePathValue(),
      mediaSecurityRuntimePath: currentMediaSecurityRuntimePath(),
    })) {
      return false;
    }
    const normalizedReason = String(reason || '').trim().toLowerCase();
    if (normalizedReason === '') return true;
    return [
      'downgrade_attempt',
      'hello_participant_set',
      'media_security_sync_hint',
      'participant_set',
      'protect_frame_unavailable',
      'publisher_recovery',
      'receiver_media_frame_error',
      'remote_sync',
      'sender_key',
      'sfu_publish_security_gate',
      'signal_failed',
      'sync_participant',
      'wrong_epoch',
      'wrong_key_id',
    ].some((prefix) => normalizedReason.startsWith(prefix));
  }

  function diagnoseMediaSecurityPlannedGossipParking(reason = 'unspecified', extraPayload = {}) {
    const normalizedReason = String(reason || 'unspecified').trim().toLowerCase() || 'unspecified';
    const payload = extraPayload && typeof extraPayload === 'object' ? { ...extraPayload } : {};
    const diagnosticKey = [
      normalizedReason,
      Number(payload.sender_user_id || payload.target_user_id || 0),
      String(payload.error_code || ''),
      String(payload.track_id || ''),
    ].join(':');
    const nowMs = Date.now();
    const parkingDiagnostics = mediaSecurityPlannedGossipParkingLastByKey();
    const lastDiagnosticMs = Number(parkingDiagnostics.get(diagnosticKey) || 0);
    if ((nowMs - lastDiagnosticMs) < 1000) return true;
    parkingDiagnostics.set(diagnosticKey, nowMs);
    mediaDebugLog('[MediaSecurity] planned Gossip parking', { reason: normalizedReason, ...payload });
    captureClientDiagnostic({
      category: 'media',
      level: 'warning',
      eventType: 'media_security_planned_gossip_parking',
      code: 'media_security_planned_gossip_parking',
      message: 'MediaSecurity condition was diagnosed but did not block planned Gossip media transport.',
      payload: {
        reason: normalizedReason,
        media_runtime_path: mediaRuntimePathValue(),
        media_security_runtime_path: currentMediaSecurityRuntimePath(),
        planned_transport: 'gossip',
        ...payload,
      },
    });
    return true;
  }

  return {
    diagnoseMediaSecurityPlannedGossipParking,
    shouldParkMediaSecurityForPlannedGossip,
  };
}

export function defaultNativeAudioBridgeFailureMessage() {
  return 'Audio is unavailable because protected audio transform setup failed on this device.';
}

export function createMediaSecurityTargetHelpers({
  connectedParticipantUsers,
  currentUserId,
  hasRealtimeRoomSync,
  isWlvcRuntimePath,
  nativePeerConnectionsRef,
  mediaRuntimeCapabilities,
  mediaSecuritySfuPublisherFirstSeenAtByUserId,
  mediaSecuritySfuTargetSettleMs,
  sfuRuntimeEnabled,
  supportsNativeTransforms,
}) {
  function mediaSecurityTargetIds() {
    if (sfuRuntimeEnabled && isWlvcRuntimePath() && hasRealtimeRoomSync?.value !== true) {
      return [];
    }
    return connectedParticipantUsers.value
      .filter((row) => {
        const mediaPeerSource = String(row?.mediaPeerSource || '').trim();
        return mediaPeerSource === '' || row?.hasSnapshotConnection === true;
      })
      .map((row) => Number(row?.userId || 0))
      .filter((userId) => (
        Number.isInteger(userId)
        && userId > 0
        && userId !== currentUserId.value
        && !isScreenShareUserId(userId)
      ));
  }

  function noteMediaSecuritySfuPublisherSeen(userId) {
    const normalizedUserId = Number(userId || 0);
    if (!Number.isInteger(normalizedUserId) || normalizedUserId <= 0 || normalizedUserId === currentUserId.value) return false;
    if (mediaSecuritySfuPublisherFirstSeenAtByUserId.has(normalizedUserId)) return false;
    mediaSecuritySfuPublisherFirstSeenAtByUserId.set(normalizedUserId, Date.now());
    return true;
  }

  function clearMediaSecuritySfuPublisherSeen(userId) {
    const normalizedUserId = Number(userId || 0);
    if (!Number.isInteger(normalizedUserId) || normalizedUserId <= 0) return false;
    return mediaSecuritySfuPublisherFirstSeenAtByUserId.delete(normalizedUserId);
  }

  function mediaSecurityEligibleTargetIds() {
    const targetUserIds = mediaSecurityTargetIds();
    if (!sfuRuntimeEnabled || !isWlvcRuntimePath()) return targetUserIds;

    // SFU receivers need sender keys even when their own camera is off or their
    // publisher announcement is delayed. Publisher discovery is still tracked
    // for diagnostics, but it must not gate the media-security participant set.
    void mediaSecuritySfuPublisherFirstSeenAtByUserId;
    void mediaSecuritySfuTargetSettleMs;
    return targetUserIds;
  }

  function nativeAudioBridgeBlockedReason(targetUserIds = []) {
    const normalizedTargetIds = Array.from(new Set((Array.isArray(targetUserIds) ? targetUserIds : [])
      .map((userId) => Number(userId))
      .filter((userId) => Number.isInteger(userId) && userId > 0 && userId !== currentUserId.value)));
    if (normalizedTargetIds.length <= 0) return '';
    if (!sfuRuntimeEnabled || !isWlvcRuntimePath()) return '';
    if (!Boolean(mediaRuntimeCapabilities.value.stageB)) {
      return 'Audio is unavailable because this browser cannot run the native WebRTC audio bridge required for protected audio.';
    }
    if (!supportsNativeTransforms()) {
      return 'Audio is unavailable because this browser cannot run native protected audio bridging.';
    }
    return '';
  }

  function nativeAudioBridgePeerStatusMessage(
    targetUserIds = [],
    nativeAudioBridgeFailureMessage = defaultNativeAudioBridgeFailureMessage
  ) {
    const normalizedTargetIds = Array.from(new Set((Array.isArray(targetUserIds) ? targetUserIds : [])
      .map((userId) => Number(userId))
      .filter((userId) => Number.isInteger(userId) && userId > 0 && userId !== currentUserId.value)));
    for (const userId of normalizedTargetIds) {
      const peer = nativePeerConnectionsRef.value.get(userId);
      if (!peer || typeof peer !== 'object') continue;
      const state = String(peer.audioBridgeState || '').trim().toLowerCase();
      if (state === 'blocked_playback') {
        return 'Audio is blocked by the browser autoplay policy on this device.';
      }
      if (state === 'transform_attach_failed') {
        return String(peer.audioBridgeErrorMessage || '').trim() || nativeAudioBridgeFailureMessage();
      }
      if (state === 'stalled_no_track') {
        return 'Audio is unavailable because no protected remote audio track arrived from the other participant.';
      }
      if (state === 'play_failed') {
        return 'Audio is unavailable because protected remote audio could not start playback on this device.';
      }
    }
    return '';
  }

  return {
    clearMediaSecuritySfuPublisherSeen,
    mediaSecurityEligibleTargetIds,
    mediaSecurityTargetIds,
    nativeAudioBridgeBlockedReason,
    nativeAudioBridgePeerStatusMessage,
    noteMediaSecuritySfuPublisherSeen,
  };
}
