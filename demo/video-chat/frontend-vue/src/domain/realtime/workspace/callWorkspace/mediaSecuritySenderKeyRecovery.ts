export const MEDIA_SECURITY_PARTICIPANT_MISMATCH_RECOVERY_WINDOW_MS = 60000;

export function normalizeMediaSecurityParticipantSetHash(hash) {
  const normalized = String(hash || '').trim();
  return normalized === '' ? 'unknown' : normalized;
}

export function mediaSecurityParticipantMismatchRecoveryKey({
  activeRoomId = '',
  runtimePath = '',
  direction = '',
  senderUserId = 0,
} = {}) {
  return [
    'participant-set-mismatch',
    activeRoomId,
    runtimePath,
    String(direction || 'unknown').trim().toLowerCase() || 'unknown',
    Number(senderUserId || 0),
  ].join(':');
}

export function shouldRunMediaSecurityParticipantMismatchRecovery(
  lastRecoveryByKey,
  recoveryKey,
  nowMs = Date.now(),
) {
  if (!(lastRecoveryByKey instanceof Map)) return true;
  const lastRecoveryMs = Number(lastRecoveryByKey.get(recoveryKey) || 0);
  if ((nowMs - lastRecoveryMs) < MEDIA_SECURITY_PARTICIPANT_MISMATCH_RECOVERY_WINDOW_MS) return false;
  lastRecoveryByKey.set(recoveryKey, nowMs);
  return true;
}

export function isSenderKeyParticipantSetSignalResult(result) {
  const normalizedResult = String(result || '').trim();
  return normalizedResult === 'stale_participant_set'
    || normalizedResult === 'future_participant_set_pending'
    || normalizedResult === 'participant_set_mismatch';
}
