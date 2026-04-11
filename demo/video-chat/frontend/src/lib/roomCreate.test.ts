import { describe, expect, it } from 'vitest'
import { normalizeRoomCreateName, optimisticRoomId, roomIdCandidateForAttempt } from './roomCreate'

describe('roomCreate helpers', () => {
  it('normalizes room names to one-space trimmed values', () => {
    expect(normalizeRoomCreateName('  Team   Sync  ')).toBe('Team Sync')
    expect(normalizeRoomCreateName('')).toBe('')
  })

  it('builds deterministic room-id candidates for conflict retries', () => {
    expect(roomIdCandidateForAttempt('alpha', 0)).toBe('alpha')
    expect(roomIdCandidateForAttempt('alpha', 1)).toBe('alpha-2')
    expect(roomIdCandidateForAttempt('alpha', 2)).toBe('alpha-3')
    expect(roomIdCandidateForAttempt(' ', 0)).toBe('room')
  })

  it('builds stable optimistic room identifiers', () => {
    expect(optimisticRoomId('alpha', 123)).toBe('pending-alpha-123')
    expect(optimisticRoomId('', 123)).toBe('pending-room-123')
  })
})
