import { describe, expect, it } from 'vitest'
import { canJoinFromPreview, mapPreviewAccessError } from './prejoinPreview'

describe('prejoinPreview helpers', () => {
  it('allows joining only when preview is ready', () => {
    expect(canJoinFromPreview('ready')).toBe(true)
    expect(canJoinFromPreview('idle')).toBe(false)
    expect(canJoinFromPreview('requesting')).toBe(false)
    expect(canJoinFromPreview('error')).toBe(false)
  })

  it('maps permission and security errors to explicit guidance', () => {
    expect(mapPreviewAccessError({ name: 'NotAllowedError' })).toContain('permission')
    expect(mapPreviewAccessError({ name: 'SecurityError' })).toContain('permission')
  })

  it('maps device and availability errors to explicit guidance', () => {
    expect(mapPreviewAccessError({ name: 'NotFoundError' })).toContain('No compatible')
    expect(mapPreviewAccessError({ name: 'NotReadableError' })).toContain('unavailable')
  })

  it('falls back to generic message for unknown errors', () => {
    expect(mapPreviewAccessError(new Error('boom'))).toBe(
      'Local preview could not start right now. Please retry.'
    )
  })
})
