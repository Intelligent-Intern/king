const SFU_STALL_RECOVERY_RESUBSCRIBE_BACKOFF_MS = 4000;
const SFU_STALL_RECOVERY_KEYFRAME_BACKOFF_MS = 4000;
const SFU_STALL_RECOVERY_SECURITY_BACKOFF_MS = 6000;

function normalizeNumber(value, fallback = 0) {
  const normalized = Number(value);
  if (!Number.isFinite(normalized)) return fallback;
  return Math.max(0, Math.floor(normalized));
}

function normalizeReason(reason, fallback = 'remote_video_stall') {
  const normalized = String(reason || '').trim().toLowerCase();
  return normalized || fallback;
}

function recoveryKey(publisherId, reason) {
  return `${String(publisherId || '').trim()}:${normalizeReason(reason)}`;
}

function shouldRunStep(peer, key, nowMs, backoffMs) {
  if (!peer || typeof peer !== 'object') return true;
  if (!(peer.sfuPublisherStallRecoveryLastByKey instanceof Map)) {
    peer.sfuPublisherStallRecoveryLastByKey = new Map();
  }
  const lastAtMs = normalizeNumber(peer.sfuPublisherStallRecoveryLastByKey.get(key));
  if (lastAtMs > 0 && (nowMs - lastAtMs) < backoffMs) return false;
  peer.sfuPublisherStallRecoveryLastByKey.set(key, nowMs);
  return true;
}

export function runSfuPublisherStallRecoveryLadder({
  captureClientDiagnostic,
  peer,
  publisherId,
  reason,
  nowMs = Date.now(),
  payload = {},
  requestKeyframe,
  resubscribe,
  securityResync,
} = {}) {
  const normalizedReason = normalizeReason(reason);
  const normalizedPublisherId = String(publisherId || '').trim();
  const basePayload = {
    lane: 'data',
    ...payload,
    publisher_id: normalizedPublisherId,
    publisher_user_id: normalizeNumber(peer?.userId || payload.publisher_user_id),
    recovery_reason: normalizedReason,
  };
  const steps = [];
  const emitDiagnostic = (step, attempted, sent, backoffMs = 0) => {
    steps.push({ step, attempted, sent, backoffMs });
    if (typeof captureClientDiagnostic !== 'function') return;
    captureClientDiagnostic({
      category: 'media',
      level: sent ? 'warning' : 'info',
      eventType: 'sfu_publisher_stall_recovery_ladder',
      code: 'sfu_publisher_stall_recovery_ladder',
      message: 'SFU publisher stall recovery attempted a targeted step before full socket reconnect.',
      payload: {
        ...basePayload,
        recovery_ladder_step: step,
        recovery_ladder_attempted: attempted,
        recovery_ladder_sent: sent,
        recovery_ladder_backoff_ms: backoffMs,
      },
      immediate: sent,
    });
  };

  const resubscribeKey = recoveryKey(normalizedPublisherId, `${normalizedReason}:resubscribe`);
  if (shouldRunStep(peer, resubscribeKey, nowMs, SFU_STALL_RECOVERY_RESUBSCRIBE_BACKOFF_MS)) {
    const sent = typeof resubscribe === 'function' ? Boolean(resubscribe(normalizedPublisherId, normalizedReason, nowMs)) : false;
    emitDiagnostic('resubscribe', true, sent, SFU_STALL_RECOVERY_RESUBSCRIBE_BACKOFF_MS);
    if (sent) return { recovered: true, step: 'resubscribe', steps };
  } else {
    emitDiagnostic('resubscribe', false, false, SFU_STALL_RECOVERY_RESUBSCRIBE_BACKOFF_MS);
  }

  const keyframeKey = recoveryKey(normalizedPublisherId, `${normalizedReason}:keyframe`);
  if (shouldRunStep(peer, keyframeKey, nowMs, SFU_STALL_RECOVERY_KEYFRAME_BACKOFF_MS)) {
    const sent = typeof requestKeyframe === 'function' ? Boolean(requestKeyframe(normalizedPublisherId, normalizedReason, nowMs)) : false;
    emitDiagnostic('keyframe', true, sent, SFU_STALL_RECOVERY_KEYFRAME_BACKOFF_MS);
    if (sent) return { recovered: true, step: 'keyframe', steps };
  } else {
    emitDiagnostic('keyframe', false, false, SFU_STALL_RECOVERY_KEYFRAME_BACKOFF_MS);
  }

  const securityKey = recoveryKey(normalizedPublisherId, `${normalizedReason}:security_resync`);
  if (shouldRunStep(peer, securityKey, nowMs, SFU_STALL_RECOVERY_SECURITY_BACKOFF_MS)) {
    const sent = typeof securityResync === 'function' ? Boolean(securityResync(normalizedPublisherId, normalizedReason, nowMs)) : false;
    emitDiagnostic('security_resync', true, sent, SFU_STALL_RECOVERY_SECURITY_BACKOFF_MS);
    if (sent) return { recovered: true, step: 'security_resync', steps };
  } else {
    emitDiagnostic('security_resync', false, false, SFU_STALL_RECOVERY_SECURITY_BACKOFF_MS);
  }

  return { recovered: false, step: 'reconnect', steps };
}
