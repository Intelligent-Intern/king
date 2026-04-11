import { describe, expect, it, vi } from 'vitest'
import { copyTextWithFallback } from './copyText'

describe('copyText helpers', () => {
  it('uses clipboard api when available', async () => {
    const clipboardWriteText = vi.fn().mockResolvedValue(undefined)
    const legacyCopy = vi.fn().mockReturnValue(true)

    const copied = await copyTextWithFallback(' invite-code ', {
      clipboardWriteText,
      legacyCopy,
    })

    expect(copied).toBe(true)
    expect(clipboardWriteText).toHaveBeenCalledWith('invite-code')
    expect(legacyCopy).not.toHaveBeenCalled()
  })

  it('falls back to legacy copy when clipboard write fails', async () => {
    const clipboardWriteText = vi.fn().mockRejectedValue(new Error('clipboard disabled'))
    const legacyCopy = vi.fn().mockReturnValue(true)

    const copied = await copyTextWithFallback('code-1', {
      clipboardWriteText,
      legacyCopy,
    })

    expect(copied).toBe(true)
    expect(legacyCopy).toHaveBeenCalledWith('code-1')
  })

  it('returns false when neither copy mechanism succeeds', async () => {
    const copied = await copyTextWithFallback('code-2', {
      clipboardWriteText: null,
      legacyCopy: () => false,
    })

    expect(copied).toBe(false)
  })

  it('rejects empty values before invoking copy methods', async () => {
    const clipboardWriteText = vi.fn().mockResolvedValue(undefined)
    const legacyCopy = vi.fn().mockReturnValue(true)

    const copied = await copyTextWithFallback('   ', {
      clipboardWriteText,
      legacyCopy,
    })

    expect(copied).toBe(false)
    expect(clipboardWriteText).not.toHaveBeenCalled()
    expect(legacyCopy).not.toHaveBeenCalled()
  })
})
