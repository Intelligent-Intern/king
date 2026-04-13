import { describe, expect, it } from 'vitest'
import { resolveInviteCodeFromCreatePayload } from './inviteCreate'

describe('inviteCreate helpers', () => {
  it('returns normalized invite code from top-level payload field', () => {
    expect(resolveInviteCodeFromCreatePayload({
      inviteCode: ' Team / Room ',
    })).toBe('team---room')
  })

  it('falls back to room inviteCode when top-level value is missing', () => {
    expect(resolveInviteCodeFromCreatePayload({
      room: { inviteCode: 'Alpha-1' },
    })).toBe('alpha-1')
  })

  it('throws when payload does not contain a usable invite code', () => {
    expect(() => resolveInviteCodeFromCreatePayload({})).toThrowError(
      'invite create payload did not include a valid invite code.'
    )
  })
})
