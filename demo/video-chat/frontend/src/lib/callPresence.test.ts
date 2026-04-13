import { describe, expect, it } from 'vitest'
import { applyCallPresenceSignal, setParticipantCallJoined } from './callPresence'

describe('callPresence helpers', () => {
  it('marks existing participant as joined/left from signal events', () => {
    const base = [
      { userId: 'u-1', name: 'Ada', roomId: 'lobby', callJoined: false, connectedAt: 10 },
      { userId: 'u-2', name: 'Bea', roomId: 'lobby', callJoined: true, connectedAt: 12 },
    ]

    const joined = applyCallPresenceSignal(base, 'call/joined', { userId: 'u-1', name: 'Ada' }, 'lobby')
    expect(joined.find((entry) => entry.userId === 'u-1')?.callJoined).toBe(true)

    const left = applyCallPresenceSignal(joined, 'call/left', { userId: 'u-2', name: 'Bea' }, 'lobby')
    expect(left.find((entry) => entry.userId === 'u-2')?.callJoined).toBe(false)
  })

  it('adds missing participant only for join signals', () => {
    const base = [
      { userId: 'u-1', name: 'Ada', roomId: 'lobby', callJoined: false, connectedAt: 10 },
    ]

    const joined = applyCallPresenceSignal(base, 'call/joined', { userId: 'u-2', name: 'Bea' }, 'lobby', 42)
    expect(joined.find((entry) => entry.userId === 'u-2')).toEqual({
      userId: 'u-2',
      name: 'Bea',
      roomId: 'lobby',
      callJoined: true,
      connectedAt: 42,
    })

    const left = applyCallPresenceSignal(base, 'call/left', { userId: 'u-3', name: 'Cam' }, 'lobby', 99)
    expect(left).toEqual(base)
  })

  it('sets local participant call state idempotently', () => {
    const base = [
      { userId: 'u-1', name: 'Ada', roomId: 'lobby', callJoined: false, connectedAt: 10 },
      { userId: 'u-2', name: 'Bea', roomId: 'lobby', callJoined: true, connectedAt: 12 },
    ]

    expect(setParticipantCallJoined(base, 'u-1', true)).toEqual([
      { userId: 'u-1', name: 'Ada', roomId: 'lobby', callJoined: true, connectedAt: 10 },
      { userId: 'u-2', name: 'Bea', roomId: 'lobby', callJoined: true, connectedAt: 12 },
    ])

    expect(setParticipantCallJoined(base, '  ', false)).toEqual(base)
  })
})
