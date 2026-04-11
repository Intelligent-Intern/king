import { describe, expect, it } from 'vitest'
import { normalizeRoomDirectory } from './roomDirectory'

describe('roomDirectory', () => {
  it('sorts directory deterministically with lobby first', () => {
    const rooms = normalizeRoomDirectory([
      { id: 'zeta', name: 'Alpha', memberCount: 2, createdAt: 20 },
      { id: 'lobby', name: 'Lobby', memberCount: 5, createdAt: 1 },
      { id: 'alpha-2', name: 'alpha', memberCount: 1, createdAt: 30 },
      { id: 'alpha-1', name: 'Alpha', memberCount: 1, createdAt: 10 },
    ])

    expect(rooms.map((room) => room.id)).toEqual([
      'lobby',
      'alpha-1',
      'zeta',
      'alpha-2',
    ])
  })

  it('normalizes malformed entries and member counters', () => {
    const rooms = normalizeRoomDirectory([
      { id: ' Team / Room ', name: ' Team One ', memberCount: '3.8', createdAt: '12' },
      { id: '', name: '', memberCount: -4 },
    ])

    expect(rooms[0]).toEqual({
      id: 'lobby',
      name: 'lobby',
      inviteCode: 'lobby',
      memberCount: 0,
      createdAt: 0,
    })

    expect(rooms[1]).toEqual({
      id: 'team---room',
      name: 'Team One',
      inviteCode: 'team---room',
      memberCount: 3,
      createdAt: 12,
    })
  })
})
