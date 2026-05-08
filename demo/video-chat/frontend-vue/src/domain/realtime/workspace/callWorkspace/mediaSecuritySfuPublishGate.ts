export function createMediaSecuritySfuPublishGate({
  callbacks,
  constants,
  state,
}) {
  const {
    currentSfuSenderKeySignaledTargetIds,
    ensureMediaSecuritySession,
    mediaSecuritySenderKeySignalKey,
    normalizeRemoteMediaSecurityUserId,
    remoteMediaSecurityEligibleTargetIds,
  } = callbacks;
  const {
    mediaSecuritySfuSenderKeyPropagationMs = 350,
  } = constants || {};

  function mediaSecuritySenderKeySentAtBySignalKey() {
    if (!(state.mediaSecuritySenderKeySentAtBySignalKey instanceof Map)) {
      state.mediaSecuritySenderKeySentAtBySignalKey = new Map();
    }
    return state.mediaSecuritySenderKeySentAtBySignalKey;
  }

  function sfuSenderKeyPropagationMs() {
    const configuredMs = Number(mediaSecuritySfuSenderKeyPropagationMs);
    if (!Number.isFinite(configuredMs) || configuredMs < 0) return 350;
    return Math.min(2000, configuredMs);
  }

  function sfuSenderKeySignalReady(signalKey, nowMs = Date.now()) {
    if (!state.mediaSecuritySenderKeySignalsSent.has(signalKey)) return false;
    const propagationMs = sfuSenderKeyPropagationMs();
    if (propagationMs <= 0) return true;
    const sentAtMs = Number(mediaSecuritySenderKeySentAtBySignalKey().get(signalKey) || 0);
    return sentAtMs > 0 && (nowMs - sentAtMs) >= propagationMs;
  }

  function currentSfuSenderKeySignalsCoverTargets(targetUserIds) {
    const signaledTargetIds = new Set(currentSfuSenderKeySignaledTargetIds(targetUserIds));
    return targetUserIds.every((userId) => signaledTargetIds.has(userId));
  }

  function canProtectCurrentSfuTargets() {
    const session = ensureMediaSecuritySession();
    if (!session?.senderKey) return false;
    const targetUserIds = remoteMediaSecurityEligibleTargetIds()
      .map((userId) => normalizeRemoteMediaSecurityUserId(userId))
      .filter((userId, index, userIds) => userId > 0 && userIds.indexOf(userId) === index);
    if (targetUserIds.length <= 0) return true;
    if (!currentSfuSenderKeySignalsCoverTargets(targetUserIds)) return false;
    const nowMs = Date.now();
    return targetUserIds.every((userId) => {
      const peer = session.peers instanceof Map ? session.peers.get(userId) : null;
      const peerState = String(peer?.state || '').trim().toLowerCase();
      if (peerState === 'blocked_capability' || peerState === 'removed') return false;
      if (!peer?.wrappingKey) return false;
      return sfuSenderKeySignalReady(mediaSecuritySenderKeySignalKey(userId, session), nowMs);
    });
  }

  function clearSenderKeySignalTimes() {
    mediaSecuritySenderKeySentAtBySignalKey().clear();
  }

  function deleteSenderKeySignalTime(signalKey) {
    mediaSecuritySenderKeySentAtBySignalKey().delete(signalKey);
  }

  function noteSenderKeySignalSent(signalKey) {
    mediaSecuritySenderKeySentAtBySignalKey().set(signalKey, Date.now());
  }

  return {
    canProtectCurrentSfuTargets,
    clearSenderKeySignalTimes,
    deleteSenderKeySignalTime,
    noteSenderKeySignalSent,
  };
}
