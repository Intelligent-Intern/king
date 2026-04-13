export type TypingSignalType = 'typing/start' | 'typing/stop'
const DEFAULT_TYPING_IDLE_MS = 3000

export function applyTypingSignal(
  roomState: Record<string, number>,
  signalType: TypingSignalType,
  userId: string,
  signalTime: number,
  now: number = Date.now()
): void {
  const normalizedUserId = userId.trim()
  if (normalizedUserId === '') {
    return
  }

  if (signalType === 'typing/stop') {
    delete roomState[normalizedUserId]
    return
  }

  roomState[normalizedUserId] = normalizeSignalTime(signalTime, now)
}

export function activeTypingUsers(
  roomState: Record<string, number>,
  maxIdleMs: number,
  now: number = Date.now()
): string[] {
  const idleMs = Number.isFinite(maxIdleMs) ? Math.max(0, maxIdleMs) : DEFAULT_TYPING_IDLE_MS
  const threshold = now - idleMs
  const activeEntries: Array<{ userId: string; startedAt: number }> = []

  for (const [userId, startedAt] of Object.entries(roomState)) {
    const normalizedStart = Number(startedAt)
    if (!Number.isFinite(normalizedStart) || normalizedStart < threshold) {
      delete roomState[userId]
      continue
    }

    activeEntries.push({ userId, startedAt: normalizedStart })
  }

  activeEntries.sort((a, b) => {
    if (a.startedAt !== b.startedAt) {
      return a.startedAt - b.startedAt
    }
    return a.userId.localeCompare(b.userId, undefined, { sensitivity: 'base', numeric: true })
  })

  return activeEntries.map((entry) => entry.userId)
}

function normalizeSignalTime(signalTime: number, now: number): number {
  const parsed = Number(signalTime)
  if (!Number.isFinite(parsed) || parsed <= 0) {
    return now
  }

  return parsed
}
