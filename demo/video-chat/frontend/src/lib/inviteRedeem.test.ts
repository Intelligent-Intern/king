import { describe, expect, it } from 'vitest'
import { resolveRoomIdFromInviteRedeemPayload } from './inviteRedeem'

describe('inviteRedeem helpers', () => {
  it('extracts and normalizes the room id from redeem payload', () => {
    expect(resolveRoomIdFromInviteRedeemPayload({
      room: { id: ' Team / Room ' },
    })).toBe('team---room')
  })

  it('throws when redeem payload is missing room id', () => {
    expect(() => resolveRoomIdFromInviteRedeemPayload({ room: {} })).toThrowError(
      'invite redeem payload did not include a valid room id.'
    )
  })
})
