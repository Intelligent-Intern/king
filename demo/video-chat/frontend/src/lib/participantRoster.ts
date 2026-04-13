export interface ParticipantRosterEntry {
  userId: string
  name: string
  roomId: string
  callJoined: boolean
  connectedAt: number
}

export function normalizeParticipantRosterSnapshot(
  participants: unknown,
  roomId: string
): ParticipantRosterEntry[] {
  if (!Array.isArray(participants)) {
    return []
  }

  const byUserId = new Map<string, ParticipantRosterEntry>()

  for (const entry of participants) {
    const candidate = normalizeParticipantEntry(entry, roomId)
    if (!candidate) {
      continue
    }

    const existing = byUserId.get(candidate.userId)
    if (!existing || candidate.connectedAt >= existing.connectedAt) {
      byUserId.set(candidate.userId, candidate)
    }
  }

  return [...byUserId.values()].sort(compareParticipantRoster)
}

function normalizeParticipantEntry(value: unknown, roomId: string): ParticipantRosterEntry | null {
  if (!isRecord(value)) {
    return null
  }

  const userId = String(value.userId ?? '').trim()
  if (userId === '') {
    return null
  }

  const name = String(value.name ?? 'User').trim() || 'User'
  const callJoined = Boolean(value.callJoined)
  const connectedAt = Number(value.connectedAt ?? 0)

  return {
    userId,
    name,
    roomId,
    callJoined,
    connectedAt: Number.isFinite(connectedAt) && connectedAt > 0 ? connectedAt : 0,
  }
}

function compareParticipantRoster(a: ParticipantRosterEntry, b: ParticipantRosterEntry): number {
  const nameOrder = a.name.localeCompare(b.name, undefined, { sensitivity: 'base', numeric: true })
  if (nameOrder !== 0) {
    return nameOrder
  }

  if (a.connectedAt !== b.connectedAt) {
    return a.connectedAt - b.connectedAt
  }

  return a.userId.localeCompare(b.userId, undefined, { sensitivity: 'base', numeric: true })
}

function isRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === 'object' && value !== null
}
