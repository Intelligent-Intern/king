export interface CallPresenceParticipant {
  userId: string
  name: string
  roomId: string
  callJoined: boolean
  connectedAt: number
}

export interface CallPresenceUser {
  userId: string
  name: string
}

export type CallPresenceSignalType = 'call/joined' | 'call/left'

export function applyCallPresenceSignal(
  participants: CallPresenceParticipant[],
  signalType: CallPresenceSignalType,
  user: unknown,
  roomId: string,
  serverTime: number = Date.now()
): CallPresenceParticipant[] {
  const normalizedUser = normalizeCallPresenceUser(user)
  if (!normalizedUser) {
    return participants
  }

  const nextJoined = signalType === 'call/joined'
  const existing = participants.find((entry) => entry.userId === normalizedUser.userId)

  if (existing) {
    const updated = participants.map((entry) => {
      if (entry.userId !== normalizedUser.userId) {
        return entry
      }

      return {
        ...entry,
        name: normalizedUser.name,
        roomId,
        callJoined: nextJoined,
      }
    })

    return sortParticipants(updated)
  }

  if (!nextJoined) {
    return participants
  }

  return sortParticipants([
    ...participants,
    {
      userId: normalizedUser.userId,
      name: normalizedUser.name,
      roomId,
      callJoined: true,
      connectedAt: normalizeServerTime(serverTime),
    },
  ])
}

export function setParticipantCallJoined(
  participants: CallPresenceParticipant[],
  userId: string,
  joined: boolean
): CallPresenceParticipant[] {
  const normalizedUserId = userId.trim()
  if (normalizedUserId === '') {
    return participants
  }

  return participants.map((entry) => (
    entry.userId === normalizedUserId
      ? { ...entry, callJoined: joined }
      : entry
  ))
}

function normalizeCallPresenceUser(user: unknown): CallPresenceUser | null {
  if (typeof user !== 'object' || user === null) {
    return null
  }

  const record = user as Record<string, unknown>
  const userId = String(record.userId ?? '').trim()
  if (userId === '') {
    return null
  }

  return {
    userId,
    name: String(record.name ?? 'User').trim() || 'User',
  }
}

function normalizeServerTime(value: number): number {
  const parsed = Number(value)
  if (!Number.isFinite(parsed) || parsed <= 0) {
    return 0
  }

  return parsed
}

function sortParticipants(participants: CallPresenceParticipant[]): CallPresenceParticipant[] {
  return [...participants].sort((a, b) => {
    const nameOrder = a.name.localeCompare(b.name, undefined, { sensitivity: 'base', numeric: true })
    if (nameOrder !== 0) {
      return nameOrder
    }

    if (a.connectedAt !== b.connectedAt) {
      return a.connectedAt - b.connectedAt
    }

    return a.userId.localeCompare(b.userId, undefined, { sensitivity: 'base', numeric: true })
  })
}
