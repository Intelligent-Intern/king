import { describe, expect, it } from 'vitest'
import { requiresDirectCallTarget, shouldAcceptInboundCallSignal } from './callNegotiationRouting'

describe('callNegotiationRouting helpers', () => {
  it('marks offer/answer/ice as strictly targeted negotiation signals', () => {
    expect(requiresDirectCallTarget('call/offer')).toBe(true)
    expect(requiresDirectCallTarget('call/answer')).toBe(true)
    expect(requiresDirectCallTarget('call/ice')).toBe(true)
    expect(requiresDirectCallTarget('call/hangup')).toBe(false)
  })

  it('accepts targeted offer/answer only for intended peer', () => {
    expect(shouldAcceptInboundCallSignal({
      type: 'call/offer',
      roomId: 'lobby',
      activeRoomId: 'lobby',
      senderUserId: 'u-2',
      currentUserId: 'u-1',
      targetUserId: 'u-1',
    })).toBe(true)

    expect(shouldAcceptInboundCallSignal({
      type: 'call/answer',
      roomId: 'lobby',
      activeRoomId: 'lobby',
      senderUserId: 'u-2',
      currentUserId: 'u-1',
      targetUserId: '',
    })).toBe(false)
  })

  it('rejects signals for wrong room, wrong target, or self sender', () => {
    expect(shouldAcceptInboundCallSignal({
      type: 'call/offer',
      roomId: 'alpha',
      activeRoomId: 'lobby',
      senderUserId: 'u-2',
      currentUserId: 'u-1',
      targetUserId: 'u-1',
    })).toBe(false)

    expect(shouldAcceptInboundCallSignal({
      type: 'call/ice',
      roomId: 'lobby',
      activeRoomId: 'lobby',
      senderUserId: 'u-2',
      currentUserId: 'u-1',
      targetUserId: 'u-3',
    })).toBe(false)

    expect(shouldAcceptInboundCallSignal({
      type: 'call/hangup',
      roomId: 'lobby',
      activeRoomId: 'lobby',
      senderUserId: 'u-1',
      currentUserId: 'u-1',
      targetUserId: '',
    })).toBe(false)
  })

  it('requires targeted ice but allows untargeted hangup for active room participants', () => {
    expect(shouldAcceptInboundCallSignal({
      type: 'call/ice',
      roomId: 'lobby',
      activeRoomId: 'lobby',
      senderUserId: 'u-2',
      currentUserId: 'u-1',
      targetUserId: '',
    })).toBe(false)

    expect(shouldAcceptInboundCallSignal({
      type: 'call/ice',
      roomId: 'lobby',
      activeRoomId: 'lobby',
      senderUserId: 'u-2',
      currentUserId: 'u-1',
      targetUserId: 'u-1',
    })).toBe(true)

    expect(shouldAcceptInboundCallSignal({
      type: 'call/hangup',
      roomId: 'lobby',
      activeRoomId: 'lobby',
      senderUserId: 'u-2',
      currentUserId: 'u-1',
      targetUserId: '',
    })).toBe(true)
  })
})
