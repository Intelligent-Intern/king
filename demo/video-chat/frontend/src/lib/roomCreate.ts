export function normalizeRoomCreateName(value: string): string {
  return value.trim().replace(/\s+/g, ' ').slice(0, 48)
}

export function roomIdCandidateForAttempt(baseId: string, attempt: number): string {
  const normalizedBase = baseId.trim()
  if (normalizedBase === '') {
    return 'room'
  }

  if (attempt <= 0) {
    return normalizedBase
  }

  return `${normalizedBase}-${attempt + 1}`
}

export function optimisticRoomId(baseId: string, timestamp: number): string {
  const normalizedBase = baseId.trim() || 'room'
  const safeTimestamp = Number.isFinite(timestamp) && timestamp > 0
    ? Math.floor(timestamp)
    : Date.now()

  return `pending-${normalizedBase}-${safeTimestamp}`
}
