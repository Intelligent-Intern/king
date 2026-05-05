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
