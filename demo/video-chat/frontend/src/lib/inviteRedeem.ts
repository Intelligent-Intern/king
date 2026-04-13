function normalizeRoomId(value: string): string {
  const normalized = value.trim().toLowerCase().replace(/[^a-z0-9-_]/g, '-')
  return normalized
}

export function resolveRoomIdFromInviteRedeemPayload(payload: unknown): string {
  const payloadObject = isRecord(payload) ? payload : null
  const room = isRecord(payloadObject?.room) ? payloadObject.room : null
  const roomId = normalizeRoomId(typeof room?.id === 'string' ? room.id : '')

  if (roomId === '') {
    throw new Error('invite redeem payload did not include a valid room id.')
  }

  return roomId
}

function isRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === 'object' && value !== null
}
