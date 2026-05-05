const SFU_KEYFRAME_RECOVERY_ACTIVE_WINDOW_MS = 3000;
const SFU_KEYFRAME_RECOVERY_COALESCE_WINDOW_MS = 1200;

function normalizeNumber(value, fallback = 0) {
  const normalized = Number(value);
  if (!Number.isFinite(normalized)) return fallback;
  return Math.max(0, Math.floor(normalized));
}

function normalizeText(value, fallback = '') {
  const normalized = String(value || '').trim().toLowerCase();
  return normalized || fallback;
}

export function sfuKeyframeRecoveryCoordinatorKey({
  publisherId = '',
  publisherUserId = 0,
  reason = 'sfu_receiver_keyframe_required',
  trackId = '',
} = {}) {
  const normalizedPublisherId = String(publisherId || '').trim();
  const normalizedPublisherUserId = normalizeNumber(publisherUserId);
  const normalizedReason = normalizeText(reason, 'sfu_receiver_keyframe_required');
  const normalizedTrackId = String(trackId || '').trim() || 'default';
  return [
    normalizedPublisherId || `user:${normalizedPublisherUserId}`,
    normalizedReason,
    normalizedTrackId,
  ].join(':');
}

export function coordinateSfuKeyframeRecoveryRequest(owner, request = {}, nowMs = Date.now()) {
  if (!owner || typeof owner !== 'object') {
    return {
      emit: true,
      coalesced: false,
      requestKey: sfuKeyframeRecoveryCoordinatorKey(request),
      requestUntilMs: nowMs + SFU_KEYFRAME_RECOVERY_ACTIVE_WINDOW_MS,
      coalesceWindowMs: SFU_KEYFRAME_RECOVERY_COALESCE_WINDOW_MS,
    };
  }

  if (!(owner.sfuKeyframeRecoveryLastByKey instanceof Map)) {
    owner.sfuKeyframeRecoveryLastByKey = new Map();
  }
  if (!(owner.sfuKeyframeRecoveryActiveUntilByKey instanceof Map)) {
    owner.sfuKeyframeRecoveryActiveUntilByKey = new Map();
  }

  const requestKey = sfuKeyframeRecoveryCoordinatorKey(request);
  const lastRequestedAtMs = normalizeNumber(owner.sfuKeyframeRecoveryLastByKey.get(requestKey));
  const activeUntilMs = normalizeNumber(owner.sfuKeyframeRecoveryActiveUntilByKey.get(requestKey));
  const coalesceWindowMs = Math.max(
    250,
    normalizeNumber(request.coalesceWindowMs, SFU_KEYFRAME_RECOVERY_COALESCE_WINDOW_MS),
  );
  const activeWindowMs = Math.max(
    coalesceWindowMs,
    normalizeNumber(request.activeWindowMs, SFU_KEYFRAME_RECOVERY_ACTIVE_WINDOW_MS),
  );
  const requestUntilMs = Math.max(activeUntilMs, nowMs + activeWindowMs);

  if (activeUntilMs > nowMs && lastRequestedAtMs > 0 && (nowMs - lastRequestedAtMs) < coalesceWindowMs) {
    return {
      emit: false,
      coalesced: true,
      requestKey,
      requestUntilMs: activeUntilMs,
      coalesceWindowMs,
      lastRequestedAtMs,
    };
  }

  owner.sfuKeyframeRecoveryLastByKey.set(requestKey, nowMs);
  owner.sfuKeyframeRecoveryActiveUntilByKey.set(requestKey, requestUntilMs);
  owner.sfuKeyframeRecoveryRequestCount = normalizeNumber(owner.sfuKeyframeRecoveryRequestCount) + 1;

  return {
    emit: true,
    coalesced: false,
    requestKey,
    requestUntilMs,
    coalesceWindowMs,
    lastRequestedAtMs,
    requestCount: owner.sfuKeyframeRecoveryRequestCount,
  };
}

export function clearSfuKeyframeRecoveryCoordinator(owner, request = {}) {
  if (!owner || typeof owner !== 'object') return false;
  const requestKey = sfuKeyframeRecoveryCoordinatorKey(request);
  let cleared = false;
  if (owner.sfuKeyframeRecoveryActiveUntilByKey instanceof Map) {
    cleared = owner.sfuKeyframeRecoveryActiveUntilByKey.delete(requestKey) || cleared;
  }
  if (owner.sfuKeyframeRecoveryLastByKey instanceof Map) {
    cleared = owner.sfuKeyframeRecoveryLastByKey.delete(requestKey) || cleared;
  }
  return cleared;
}
