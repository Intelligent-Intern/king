import { describe, expect, it } from 'vitest'
import { activeTypingUsers, applyTypingSignal } from './typingPresence'

describe('typingPresence helpers', () => {
  it('tracks start and stop events by room-scoped user id', () => {
    const state: Record<string, number> = {}
    applyTypingSignal(state, 'typing/start', 'u-1', 100)
    applyTypingSignal(state, 'typing/start', 'u-2', 120)

    expect(activeTypingUsers(state, 200, 200)).toEqual(['u-1', 'u-2'])

    applyTypingSignal(state, 'typing/stop', 'u-1', 201)
    expect(activeTypingUsers(state, 200, 210)).toEqual(['u-2'])
  })

  it('auto-clears stale typing entries beyond idle window', () => {
    const state: Record<string, number> = {}
    applyTypingSignal(state, 'typing/start', 'u-1', 100)
    applyTypingSignal(state, 'typing/start', 'u-2', 300)

    expect(activeTypingUsers(state, 150, 500)).toEqual([])
    expect(state).toEqual({})
  })

  it('ignores invalid user ids and non-finite idle windows safely', () => {
    const state: Record<string, number> = {}
    applyTypingSignal(state, 'typing/start', '  ', 100)
    applyTypingSignal(state, 'typing/start', 'u-1', Number.NaN, 150)

    expect(state).toEqual({ 'u-1': 150 })
    expect(activeTypingUsers(state, Number.NaN, 200)).toEqual(['u-1'])
  })
})
