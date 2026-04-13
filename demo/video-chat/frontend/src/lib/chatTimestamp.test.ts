import { describe, expect, it } from 'vitest'
import { formatChatTimestamp } from './chatTimestamp'

describe('chatTimestamp helpers', () => {
  it('formats timestamps in deterministic UTC clock form', () => {
    expect(formatChatTimestamp(Date.UTC(2026, 3, 11, 7, 5, 0))).toBe('07:05 UTC')
    expect(formatChatTimestamp(Date.UTC(2026, 11, 24, 23, 59, 59))).toBe('23:59 UTC')
  })

  it('returns fallback label for invalid timestamp inputs', () => {
    expect(formatChatTimestamp(Number.NaN)).toBe('--:-- UTC')
    expect(formatChatTimestamp(0)).toBe('--:-- UTC')
    expect(formatChatTimestamp(-1)).toBe('--:-- UTC')
  })
})
