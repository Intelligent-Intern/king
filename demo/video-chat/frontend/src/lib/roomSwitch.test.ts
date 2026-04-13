import { describe, expect, it } from 'vitest'
import { decideRoomSwitch, roomSwitchUiReset } from './roomSwitch'

describe('roomSwitch helpers', () => {
  it('normalizes room ids and marks real switches', () => {
    expect(decideRoomSwitch(' Lobby ', ' Team / Sync ')).toEqual({
      previousRoomId: 'lobby',
      nextRoomId: 'team---sync',
      shouldSwitch: true,
      shouldEmitTypingStop: true,
    })
  })

  it('returns no switch for equivalent normalized ids', () => {
    expect(decideRoomSwitch('Team-1', ' team-1 ')).toEqual({
      previousRoomId: 'team-1',
      nextRoomId: 'team-1',
      shouldSwitch: false,
      shouldEmitTypingStop: false,
    })
  })

  it('returns deterministic room-scoped ui reset values', () => {
    expect(roomSwitchUiReset()).toEqual({
      activeTab: 'chat',
      messageInput: '',
      lastInviteCode: '',
    })
  })
})
