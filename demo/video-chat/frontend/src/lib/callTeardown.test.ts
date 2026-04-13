import { describe, expect, it } from 'vitest'
import { callResetOptionsForBoundary } from './callTeardown'

describe('callTeardown boundary options', () => {
  it('forces full teardown for room switch boundaries', () => {
    expect(callResetOptionsForBoundary('room-switch', 'room-a')).toEqual({
      notify: true,
      roomId: 'room-a',
      stopLocalMedia: true,
    })
  })

  it('forces full teardown for sign-out boundaries', () => {
    expect(callResetOptionsForBoundary('sign-out', 'room-b')).toEqual({
      notify: true,
      roomId: 'room-b',
      stopLocalMedia: true,
    })
  })

  it('forces full teardown for unmount boundaries', () => {
    expect(callResetOptionsForBoundary('unmount', 'room-c')).toEqual({
      notify: true,
      roomId: 'room-c',
      stopLocalMedia: true,
    })
  })

  it('falls back to lobby when room id is blank', () => {
    expect(callResetOptionsForBoundary('unmount', '   ')).toEqual({
      notify: true,
      roomId: 'lobby',
      stopLocalMedia: true,
    })
  })
})
