import { describe, expect, it } from 'vitest'
import { normalizeParticipantRosterSnapshot } from './participantRoster'

describe('participantRoster helpers', () => {
  it('normalizes roster entries from room snapshot payload', () => {
    const roster = normalizeParticipantRosterSnapshot([
      { userId: 'u-2', name: 'Bea', callJoined: 1, connectedAt: 3 },
      { userId: 'u-1', name: 'Ada', callJoined: 0, connectedAt: '2' },
    ], 'lobby')

    expect(roster).toEqual([
      { userId: 'u-1', name: 'Ada', roomId: 'lobby', callJoined: false, connectedAt: 2 },
      { userId: 'u-2', name: 'Bea', roomId: 'lobby', callJoined: true, connectedAt: 3 },
    ])
  })

  it('drops malformed rows and deduplicates users by latest connectedAt', () => {
    const roster = normalizeParticipantRosterSnapshot([
      { userId: 'u-1', name: 'Ada', connectedAt: 4 },
      { userId: 'u-1', name: 'Ada Prime', connectedAt: 7, callJoined: true },
      { userId: '', name: 'missing-id' },
      null,
    ], 'alpha')

    expect(roster).toEqual([
      { userId: 'u-1', name: 'Ada Prime', roomId: 'alpha', callJoined: true, connectedAt: 7 },
    ])
  })

  it('returns empty roster for non-array payloads', () => {
    expect(normalizeParticipantRosterSnapshot({}, 'lobby')).toEqual([])
  })
})
