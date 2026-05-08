function normalizeUserId(value) {
  const userId = Number(value || 0);
  return Number.isInteger(userId) && userId > 0 ? userId : 0;
}

export function mediaSecurityParticipantSignatureIds(session) {
  return String(session?.participantSignature || '')
    .split(',')
    .map((value) => normalizeUserId(value))
    .filter((userId, index, userIds) => userId > 0 && userIds.indexOf(userId) === index);
}

export function mediaSecurityParticipantIdsFromSignature(signature) {
  return String(signature || '')
    .split(',')
    .map((userId) => normalizeUserId(userId))
    .filter((userId) => userId > 0);
}

export function mediaSecurityParticipantSetDelta(previousUserIds, nextUserIds) {
  const previous = new Set(Array.isArray(previousUserIds) ? previousUserIds : []);
  const next = new Set(Array.isArray(nextUserIds) ? nextUserIds : []);
  return {
    added: Array.from(next).filter((userId) => !previous.has(userId)),
    removed: Array.from(previous).filter((userId) => !next.has(userId)),
  };
}

export function shouldForceMediaSecurityRekeyForParticipantSetDelta(delta, requestedForceRekey = false) {
  if (requestedForceRekey) return true;
  return Array.isArray(delta?.removed) && delta.removed.length > 0;
}

export function shouldRecoverMediaSecuritySignalSender({
  hasRealtimeRoomSync = false,
  targetUserIds = [],
  senderUserId = 0,
} = {}) {
  const normalizedSenderUserId = normalizeUserId(senderUserId);
  if (normalizedSenderUserId <= 0) return false;
  if (hasRealtimeRoomSync !== true) return true;
  return (Array.isArray(targetUserIds) ? targetUserIds : [])
    .map((userId) => normalizeUserId(userId))
    .includes(normalizedSenderUserId);
}

export function mergeMediaSecurityParticipantIds(session, targetUserIds = [], extraUserId = 0) {
  return Array.from(new Set([
    ...mediaSecurityParticipantSignatureIds(session),
    ...(Array.isArray(targetUserIds) ? targetUserIds : []),
    extraUserId,
  ]
    .map((value) => normalizeUserId(value))
    .filter((userId) => userId > 0)))
    .sort((left, right) => left - right);
}
