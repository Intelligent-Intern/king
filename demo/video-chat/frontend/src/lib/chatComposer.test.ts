import { describe, expect, it } from 'vitest'
import { CHAT_COMPOSER_MAX_LENGTH, clampComposerDraft, resolveComposerPayload } from './chatComposer'

describe('chatComposer helpers', () => {
  it('clamps drafts to configured max length', () => {
    expect(clampComposerDraft('hello', 10)).toBe('hello')
    expect(clampComposerDraft('abcdefgh', 4)).toBe('abcd')
  })

  it('falls back to default max length for invalid limits', () => {
    const oversized = 'x'.repeat(CHAT_COMPOSER_MAX_LENGTH + 5)
    expect(clampComposerDraft(oversized, Number.NaN)).toHaveLength(CHAT_COMPOSER_MAX_LENGTH)
    expect(clampComposerDraft(oversized, 0)).toHaveLength(CHAT_COMPOSER_MAX_LENGTH)
  })

  it('returns null for empty or whitespace-only payloads', () => {
    expect(resolveComposerPayload('')).toBeNull()
    expect(resolveComposerPayload('   \n\t  ')).toBeNull()
  })

  it('returns trimmed payload within max length bounds', () => {
    expect(resolveComposerPayload('  hello world  ', 30)).toBe('hello world')
    expect(resolveComposerPayload('  abcdef  ', 4)).toBe('abcd')
  })
})
