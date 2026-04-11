function normalizeInviteCode(value: string): string {
  const normalized = value.trim().toLowerCase().replace(/[^a-z0-9-_]/g, '-')
  return normalized
}

export function resolveInviteCodeFromCreatePayload(payload: unknown): string {
  const payloadObject = isRecord(payload) ? payload : null
  const payloadRoom = isRecord(payloadObject?.room) ? payloadObject.room : null

  const rawCode = typeof payloadObject?.inviteCode === 'string'
    ? payloadObject.inviteCode
    : typeof payloadRoom?.inviteCode === 'string'
      ? payloadRoom.inviteCode
      : ''

  const inviteCode = normalizeInviteCode(rawCode)
  if (inviteCode === '') {
    throw new Error('invite create payload did not include a valid invite code.')
  }

  return inviteCode
}

function isRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === 'object' && value !== null
}
