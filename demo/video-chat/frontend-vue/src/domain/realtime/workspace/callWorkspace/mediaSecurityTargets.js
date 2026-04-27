export function defaultNativeAudioBridgeFailureMessage() {
  return 'Audio is unavailable because protected audio transform setup failed on this device.';
}

export function createMediaSecurityTargetHelpers({
  connectedParticipantUsers,
  currentUserId,
  isWlvcRuntimePath,
  nativePeerConnectionsRef,
  mediaRuntimeCapabilities,
  mediaSecuritySfuPublisherFirstSeenAtByUserId,
  mediaSecuritySfuTargetSettleMs,
  sfuRuntimeEnabled,
  supportsNativeTransforms,
}) {
  function mediaSecurityTargetIds() {
    return connectedParticipantUsers.value
      .map((row) => Number(row?.userId || 0))
      .filter((userId) => Number.isInteger(userId) && userId > 0 && userId !== currentUserId.value);
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
    if (!sfuRuntimeEnabled || !isWlvcRuntimePath()) {
      return targetUserIds;
    }
    const nowMs = Date.now();
    return targetUserIds.filter((userId) => {
      const firstSeenAtMs = Number(mediaSecuritySfuPublisherFirstSeenAtByUserId.get(userId) || 0);
      return firstSeenAtMs > 0 && (nowMs - firstSeenAtMs) >= mediaSecuritySfuTargetSettleMs;
    });
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
