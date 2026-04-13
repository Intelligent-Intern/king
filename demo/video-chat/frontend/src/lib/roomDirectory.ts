import type { Room } from './types'

export function normalizeRoomDirectory(input: unknown): Room[] {
  const rows = Array.isArray(input) ? input : []
  const mapped = rows.map((room): Room => {
    const id = sanitizeRoomId(room?.id)
    const name = sanitizeRoomName(room?.name, id)

    return {
      id,
      name,
      inviteCode: typeof room?.inviteCode === 'string' ? room.inviteCode : id,
      memberCount: toMemberCount(room?.memberCount),
      createdAt: toCreatedAt(room?.createdAt),
      createdBy: typeof room?.createdBy === 'string' ? room.createdBy : '',
    }
  })

  return mapped.sort(compareRooms)
}

function compareRooms(a: Room, b: Room): number {
  if (a.id === 'lobby' && b.id !== 'lobby') {
    return -1
  }
  if (b.id === 'lobby' && a.id !== 'lobby') {
    return 1
  }

  const nameOrder = a.name.localeCompare(b.name, undefined, { sensitivity: 'base', numeric: true })
  if (nameOrder !== 0) {
    return nameOrder
  }

  if (a.createdAt !== b.createdAt) {
    return a.createdAt - b.createdAt
  }

  return a.id.localeCompare(b.id, undefined, { sensitivity: 'base', numeric: true })
}

function sanitizeRoomId(value: unknown): string {
  if (typeof value !== 'string') {
    return 'lobby'
  }

  const normalized = value.trim().toLowerCase().replace(/[^a-z0-9-_]/g, '-')
  return normalized || 'lobby'
}

function sanitizeRoomName(value: unknown, fallback: string): string {
  if (typeof value !== 'string') {
    return fallback
  }

  const cleaned = value.trim()
  return cleaned === '' ? fallback : cleaned.slice(0, 48)
}

function toMemberCount(value: unknown): number {
  const numeric = Number(value)
  if (!Number.isFinite(numeric) || numeric < 0) {
    return 0
  }
  return Math.floor(numeric)
}

function toCreatedAt(value: unknown): number {
  const numeric = Number(value)
  if (!Number.isFinite(numeric) || numeric <= 0) {
    return 0
  }
  return Math.floor(numeric)
}
