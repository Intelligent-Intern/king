export interface FanoutChatMessage {
  id: string
  roomId: string
  sender: {
    userId: string
    name: string
  }
  text: string
  serverTime: number
}

export function normalizeFanoutChatMessage(
  payload: unknown,
  fallbackRoomId: string,
  now: number = Date.now()
): FanoutChatMessage | null {
  if (!isRecord(payload)) {
    return null
  }

  const id = String(payload.id ?? '').trim()
  if (id === '') {
    return null
  }

  const roomId = normalizeRoomId(String(payload.roomId ?? fallbackRoomId))
  const sender = isRecord(payload.sender) ? payload.sender : null
  const senderUserId = String(sender?.userId ?? '').trim()
  if (senderUserId === '') {
    return null
  }

  const text = String(payload.text ?? '').trim()
  if (text === '') {
    return null
  }

  const serverTime = Number(payload.serverTime ?? now)
  return {
    id,
    roomId,
    sender: {
      userId: senderUserId,
      name: String(sender?.name ?? 'User').trim() || 'User',
    },
    text,
    serverTime: Number.isFinite(serverTime) && serverTime > 0 ? serverTime : now,
  }
}

export function appendFanoutChatMessage<T extends { id: string }>(
  list: T[],
  message: T
): boolean {
  if (list.some((entry) => entry.id === message.id)) {
    return false
  }

  list.push(message)
  return true
}

function normalizeRoomId(value: string): string {
  const normalized = value.trim().toLowerCase().replace(/[^a-z0-9-_]/g, '-')
  return normalized || 'lobby'
}

function isRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === 'object' && value !== null
}
